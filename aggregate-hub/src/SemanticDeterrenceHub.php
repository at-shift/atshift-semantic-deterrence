<?php
/**
 * Minimal anonymous aggregate hub for Semantic Deterrence pilots.
 */

class Atshift_Semantic_Deterrence_Hub {
	const MAX_BODY_BYTES = 1048576;
	const SIGNATURE_TOLERANCE_SECONDS = 600;
	const MAX_BATCH_ROWS = 500;
	const MAX_BATCH_EVENTS = 100000;

	/** @var PDO */
	private $db;

	/** @var array<string,mixed> */
	private $config;

	public function __construct( PDO $db, array $config ) {
		$this->db     = $db;
		$this->config = $config;
	}

	public function handle( $route, array $server, $body ) {
		if ( '/v1/events/batch' === $route ) {
			return $this->handle_batch( $server, (string) $body );
		}

		if ( '/v1/aggregates/current' === $route ) {
			return $this->handle_current( $server );
		}

		if ( '/v1/aggregates/variants' === $route ) {
			return $this->handle_variants( $server );
		}

		if ( '/v1/site/revoke' === $route ) {
			return $this->handle_revoke( $server, (string) $body );
		}

		return $this->json_response( 404, array( 'status' => 'not_found' ) );
	}

	private function handle_batch( array $server, $body ) {
		if ( 'POST' !== $this->request_method( $server ) ) {
			return $this->method_not_allowed( 'POST' );
		}
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return $this->json_response( 413, array( 'status' => 'rejected', 'message' => 'Batch is too large.' ) );
		}

		$auth = $this->authenticate( $server, $body );
		if ( ! $auth['ok'] ) {
			return $this->json_response( $auth['code'], array( 'status' => 'rejected', 'message' => $auth['message'] ) );
		}

		if ( ! $this->consume_rate_limit( 'ingest:' . $auth['key_id'], $this->config_int( 'ingest_rate_limit_per_minute', 6 ) ) ) {
			return $this->json_response(
				429,
				array( 'status' => 'rate_limited', 'message' => 'Please retry later.' ),
				array( 'Retry-After' => '120' )
			);
		}

		if ( ! $this->consume_rate_limit( 'ingest-day:' . $auth['key_id'], $this->config_int( 'ingest_rate_limit_per_day', 24 ), 'day' ) ) {
			return $this->json_response( 429, array( 'status' => 'rate_limited', 'message' => 'Daily upload limit reached.' ), array( 'Retry-After' => '3600' ) );
		}

		$payload = json_decode( $body, true, 16 );
		if ( ! is_array( $payload ) ) {
			return $this->json_response( 400, array( 'status' => 'rejected', 'message' => 'Invalid JSON.' ) );
		}

		$validation = $this->validate_batch_payload( $payload );
		if ( ! $validation['ok'] ) {
			return $this->json_response( 422, array( 'status' => 'rejected', 'message' => $validation['message'] ) );
		}

		$site_key_hash = $this->site_key_hash( $payload['events'][0]['site_pseudonym'] );
		$payload_hash = hash( 'sha256', $body );
		$existing    = $this->find_existing_batch( $auth['key_id'], $payload_hash );
		if ( $existing ) {
			return $this->json_response(
				200,
				array(
					'schema_version' => '1',
					'status'         => 'duplicate',
					'batch_id'       => (int) $existing['id'],
				)
			);
		}

		$this->db->beginTransaction();
		try {
			if ( ! $this->claim_site( $auth['key_id'], $site_key_hash ) ) {
				$this->db->rollBack();
				return $this->json_response( 403, array( 'status' => 'rejected', 'message' => 'Client key is bound to a different site.' ) );
			}
			if ( $this->is_site_revoked( $auth['key_id'], $site_key_hash ) ) {
				$this->db->rollBack();
				return $this->json_response( 403, array( 'status' => 'revoked', 'message' => 'Site sharing has been revoked.' ) );
			}

			$this->delete_client_batches( $auth['key_id'] );
			$batch_id = $this->insert_batch( $auth['key_id'], $site_key_hash, $payload_hash, count( $payload['events'] ) );
			foreach ( $payload['events'] as $event ) {
				$this->insert_event( $batch_id, $site_key_hash, $event );
			}
			$this->clear_cache();
			$this->increment_generation();
			$this->db->commit();
		} catch ( Throwable $error ) {
			if ( $this->db->inTransaction() ) {
				$this->db->rollBack();
			}
			throw $error;
		}

		return $this->json_response(
			202,
			array(
				'schema_version' => '1',
				'status'         => 'accepted',
				'batch_id'       => $batch_id,
				'events'         => count( $payload['events'] ),
			)
		);
	}

	private function handle_current( array $server ) {
		if ( 'GET' !== $this->request_method( $server ) ) {
			return $this->method_not_allowed( 'GET' );
		}

		$variants = $this->build_or_read_cached_json( $this->variants_cache_key(), array( $this, 'build_variants_body' ) );
		$best     = null;
		foreach ( $variants['body']['variants'] as $row ) {
			if ( 'control_generic' === $row['variant'] ) {
				continue;
			}
			if ( null === $best || $row['non_continuation_rate'] > $best['non_continuation_rate'] ) {
				$best = $row;
			}
		}

		$body = array(
			'schema_version' => '1',
			'generated_at'   => $variants['generated_at'],
			'days'           => 30,
			'privacy_thresholds' => $this->privacy_thresholds(),
			'best_variant'   => $best,
			'variants_url'   => '/v1/aggregates/variants',
			'policy'         => array(
				'remote_control' => false,
				'executable_code' => false,
				'forced_blocking' => false,
			),
		);

		return $this->cacheable_json_response( $server, $body );
	}

	private function handle_variants( array $server ) {
		if ( 'GET' !== $this->request_method( $server ) ) {
			return $this->method_not_allowed( 'GET' );
		}

		$cached = $this->build_or_read_cached_json( $this->variants_cache_key(), array( $this, 'build_variants_body' ) );
		return $this->etag_response( $server, $cached['etag'], $cached['body'], $cached['generated_at'] );
	}

	private function handle_revoke( array $server, $body ) {
		if ( 'POST' !== $this->request_method( $server ) ) {
			return $this->method_not_allowed( 'POST' );
		}
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return $this->json_response( 413, array( 'status' => 'rejected', 'message' => 'Request body is too large.' ) );
		}

		$auth = $this->authenticate( $server, $body );
		if ( ! $auth['ok'] ) {
			return $this->json_response( $auth['code'], array( 'status' => 'rejected', 'message' => $auth['message'] ) );
		}

		if ( ! $this->consume_rate_limit( 'revoke-day:' . $auth['key_id'], $this->config_int( 'revoke_rate_limit_per_day', 3 ), 'day' ) ) {
			return $this->json_response( 429, array( 'status' => 'rate_limited', 'message' => 'Daily revocation limit reached.' ), array( 'Retry-After' => '3600' ) );
		}

		$payload = json_decode( $body, true, 8 );
		if ( ! is_array( $payload ) || ! $this->has_exact_keys( $payload, array( 'schema_version', 'site_pseudonym' ) ) || '1' !== (string) $payload['schema_version'] ) {
			return $this->json_response( 400, array( 'status' => 'rejected', 'message' => 'Missing site pseudonym.' ) );
		}

		if ( $this->contains_forbidden_keys( $payload ) ) {
			return $this->json_response( 422, array( 'status' => 'rejected', 'message' => 'Payload contains disallowed fields.' ) );
		}

		if ( ! $this->valid_site_pseudonym( (string) $payload['site_pseudonym'] ) ) {
			return $this->json_response( 422, array( 'status' => 'rejected', 'message' => 'Invalid site pseudonym.' ) );
		}

		$site_key_hash = $this->site_key_hash( $payload['site_pseudonym'] );
		$this->db->beginTransaction();
		try {
			if ( ! $this->site_is_owned_by( $auth['key_id'], $site_key_hash ) ) {
				$this->db->rollBack();
				return $this->json_response( 403, array( 'status' => 'rejected', 'message' => 'Client key does not own this site.' ) );
			}

			$stmt = $this->db->prepare(
				'INSERT INTO atsdn_hub_revoked_sites (site_key_hash, key_id, revoked_at)
				VALUES (:site_key_hash, :key_id, :revoked_at)
				ON DUPLICATE KEY UPDATE revoked_at = VALUES(revoked_at)'
			);
			$stmt->execute( array( ':site_key_hash' => $site_key_hash, ':key_id' => $auth['key_id'], ':revoked_at' => gmdate( 'Y-m-d H:i:s' ) ) );

			$this->delete_site_events( $auth['key_id'], $site_key_hash );
			$this->clear_cache();
			$this->increment_generation();
			$this->db->commit();
		} catch ( Throwable $error ) {
			if ( $this->db->inTransaction() ) {
				$this->db->rollBack();
			}
			throw $error;
		}

		return $this->json_response( 200, array( 'schema_version' => '1', 'status' => 'revoked' ) );
	}

	private function authenticate( array $server, $body ) {
		$key_id    = $this->header( $server, 'HTTP_X_ATSDN_KEY_ID' );
		$timestamp = $this->header( $server, 'HTTP_X_ATSDN_TIMESTAMP' );
		$nonce     = $this->header( $server, 'HTTP_X_ATSDN_NONCE' );
		$signature = $this->header( $server, 'HTTP_X_ATSDN_SIGNATURE' );

		if ( '' === $key_id || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return array( 'ok' => false, 'code' => 401, 'message' => 'Missing signature headers.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,80}$/', $key_id ) || ! preg_match( '/^\d{10}$/', $timestamp ) ) {
			return array( 'ok' => false, 'code' => 400, 'message' => 'Invalid signature headers.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9+\/]{43}=$/', $signature ) ) {
			return array( 'ok' => false, 'code' => 400, 'message' => 'Invalid signature encoding.' );
		}

		$client_keys = isset( $this->config['client_keys'] ) && is_array( $this->config['client_keys'] ) ? $this->config['client_keys'] : array();
		if ( empty( $client_keys[ $key_id ] ) ) {
			return array( 'ok' => false, 'code' => 403, 'message' => 'Unknown key.' );
		}

		if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_TOLERANCE_SECONDS ) {
			return array( 'ok' => false, 'code' => 401, 'message' => 'Signature timestamp is outside the allowed window.' );
		}

		if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,120}$/', $nonce ) ) {
			return array( 'ok' => false, 'code' => 400, 'message' => 'Invalid nonce.' );
		}

		$expected = base64_encode( hash_hmac( 'sha256', $timestamp . "\n" . $nonce . "\n" . $body, (string) $client_keys[ $key_id ], true ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return array( 'ok' => false, 'code' => 401, 'message' => 'Invalid signature.' );
		}

		if ( ! $this->consume_nonce( $key_id, $nonce, (int) $timestamp ) ) {
			return array( 'ok' => false, 'code' => 409, 'message' => 'Nonce was already used.' );
		}

		return array( 'ok' => true, 'key_id' => $key_id );
	}

	private function validate_batch_payload( array $payload ) {
		if ( $this->contains_forbidden_keys( $payload ) ) {
			return array( 'ok' => false, 'message' => 'Payload contains disallowed fields.' );
		}
		if ( ! $this->has_exact_keys( $payload, array( 'schema_version', 'generated_at', 'days', 'events' ) ) || '1' !== (string) $payload['schema_version'] ) {
			return array( 'ok' => false, 'message' => 'Invalid batch schema.' );
		}
		if ( ! is_int( $payload['days'] ) || $payload['days'] < 1 || $payload['days'] > 90 || ! $this->valid_datetime( (string) $payload['generated_at'] ) ) {
			return array( 'ok' => false, 'message' => 'Invalid batch window.' );
		}

		if ( empty( $payload['events'] ) || ! is_array( $payload['events'] ) ) {
			return array( 'ok' => false, 'message' => 'Batch must include events.' );
		}

		if ( count( $payload['events'] ) > self::MAX_BATCH_ROWS ) {
			return array( 'ok' => false, 'message' => 'Batch contains too many aggregate rows.' );
		}

		$site_pseudonym = '';
		$total_events   = 0;
		foreach ( $payload['events'] as $event ) {
			if ( ! is_array( $event ) ) {
				return array( 'ok' => false, 'message' => 'Each event must be an object.' );
			}

			$required = array(
				'schema_version',
				'site_pseudonym',
				'plugin_version',
				'policy_version',
				'variant',
				'response_fingerprint',
				'response_catalog_id',
				'http_status',
				'category',
				'level',
				'outcome',
				'event_count',
				'follow_up_count',
				'time_bucket',
				'observed_date',
			);
			$allowed = array_merge( $required, array( 'experiment_arm' ) );
			if ( ! $this->has_required_and_allowed_keys( $event, $required, $allowed ) || '1' !== (string) $event['schema_version'] ) {
				return array( 'ok' => false, 'message' => 'Invalid event schema.' );
			}

			if ( '' === $site_pseudonym ) {
				$site_pseudonym = (string) $event['site_pseudonym'];
			} elseif ( $site_pseudonym !== (string) $event['site_pseudonym'] ) {
				return array( 'ok' => false, 'message' => 'A batch may contain one site pseudonym only.' );
			}
			if ( ! $this->valid_site_pseudonym( (string) $event['site_pseudonym'] ) ) {
				return array( 'ok' => false, 'message' => 'Invalid site pseudonym.' );
			}
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9.+-]{0,39}$/', (string) $event['plugin_version'] ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', (string) $event['policy_version'] ) ) {
				return array( 'ok' => false, 'message' => 'Invalid version identifier.' );
			}

			if ( ! $this->is_allowed_value( (string) $event['variant'], $this->allowed_variants() ) ) {
				return array( 'ok' => false, 'message' => 'Invalid variant.' );
			}

			if ( ! $this->is_allowed_value( (string) ( $event['experiment_arm'] ?? '' ), array( '', 'fixed_series', 'sequence_series' ) ) ) {
				return array( 'ok' => false, 'message' => 'Invalid experiment arm.' );
			}

			if ( ! $this->is_allowed_value( (string) $event['category'], $this->allowed_categories() ) ) {
				return array( 'ok' => false, 'message' => 'Invalid category.' );
			}

			if ( ! $this->is_allowed_value( (string) $event['outcome'], $this->allowed_outcomes() ) ) {
				return array( 'ok' => false, 'message' => 'Invalid outcome.' );
			}

			if ( ! $this->valid_observed_date( (string) $event['observed_date'] ) ) {
				return array( 'ok' => false, 'message' => 'Invalid observed date.' );
			}

			if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $event['response_fingerprint'] ) && '' !== (string) $event['response_fingerprint'] ) {
				return array( 'ok' => false, 'message' => 'Invalid response fingerprint.' );
			}

			if ( ! $this->valid_response_catalog_id( (string) $event['response_catalog_id'] ) || '0-10m' !== (string) $event['time_bucket'] ) {
				return array( 'ok' => false, 'message' => 'Invalid response catalog data.' );
			}
			if ( ! is_int( $event['http_status'] ) || $event['http_status'] < 100 || $event['http_status'] > 599 || ! is_int( $event['level'] ) || $event['level'] < 1 || $event['level'] > 3 ) {
				return array( 'ok' => false, 'message' => 'Invalid status or level.' );
			}
			if ( ! is_int( $event['event_count'] ) || $event['event_count'] < 1 || $event['event_count'] > self::MAX_BATCH_EVENTS ) {
				return array( 'ok' => false, 'message' => 'Invalid event count.' );
			}
			if ( ! is_int( $event['follow_up_count'] ) || $event['follow_up_count'] < 0 || $event['follow_up_count'] > self::MAX_BATCH_EVENTS ) {
				return array( 'ok' => false, 'message' => 'Invalid follow-up count.' );
			}
			$total_events += $event['event_count'];
			if ( $total_events > self::MAX_BATCH_EVENTS ) {
				return array( 'ok' => false, 'message' => 'Batch event total is too large.' );
			}
		}

		return array( 'ok' => true );
	}

	private function insert_batch( $key_id, $site_key_hash, $payload_hash, $event_count ) {
		$stmt = $this->db->prepare(
			'INSERT INTO atsdn_hub_batches (key_id, site_key_hash, payload_hash, event_count, received_at)
			VALUES (:key_id, :site_key_hash, :payload_hash, :event_count, :received_at)'
		);
		$stmt->execute(
			array(
				':key_id'        => $this->clean_text( $key_id, 80 ),
				':site_key_hash' => $site_key_hash,
				':payload_hash'  => $payload_hash,
				':event_count'   => (int) $event_count,
				':received_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) $this->db->lastInsertId();
	}

	private function insert_event( $batch_id, $site_key_hash, array $event ) {
		$stmt = $this->db->prepare(
			'INSERT INTO atsdn_hub_events
			(batch_id, received_at, observed_date, site_key_hash, plugin_version, policy_version, variant, experiment_arm, response_catalog_id, response_fingerprint, http_status, category, level, outcome, time_bucket, event_count, follow_up_count)
			VALUES
			(:batch_id, :received_at, :observed_date, :site_key_hash, :plugin_version, :policy_version, :variant, :experiment_arm, :response_catalog_id, :response_fingerprint, :http_status, :category, :level, :outcome, :time_bucket, :event_count, :follow_up_count)'
		);
		$stmt->execute(
			array(
				':batch_id'             => (int) $batch_id,
				':received_at'          => gmdate( 'Y-m-d H:i:s' ),
				':observed_date'        => $event['observed_date'],
				':site_key_hash'        => $site_key_hash,
				':plugin_version'       => $this->clean_text( $event['plugin_version'], 40 ),
				':policy_version'       => $this->clean_text( $event['policy_version'], 80 ),
				':variant'              => $this->clean_key( $event['variant'], 40 ),
				':experiment_arm'       => $this->clean_key( $event['experiment_arm'] ?? '', 40 ),
				':response_catalog_id'  => $this->clean_text( $event['response_catalog_id'], 120 ),
				':response_fingerprint' => $this->clean_hash( $event['response_fingerprint'] ),
				':http_status'          => (int) $event['http_status'],
				':category'             => $this->clean_key( $event['category'], 40 ),
				':level'                => (int) $event['level'],
				':outcome'              => $this->clean_key( $event['outcome'], 40 ),
				':time_bucket'          => $this->clean_text( $event['time_bucket'], 20 ),
				':event_count'          => (int) $event['event_count'],
				':follow_up_count'      => (int) $event['follow_up_count'],
			)
		);
	}

	public function build_variants_body() {
		$days   = 30;
		$since  = gmdate( 'Y-m-d', time() - $days * 86400 );
		$min    = $this->privacy_thresholds();
		$stmt   = $this->db->prepare(
			"SELECT
				variant,
				experiment_arm,
				response_catalog_id,
				response_fingerprint,
				COUNT(DISTINCT site_key_hash) AS site_count,
				SUM(event_count) AS total_events,
				SUM(CASE WHEN outcome = 'observed_ceased' THEN event_count ELSE 0 END) AS observed_ceased,
				SUM(CASE WHEN outcome IN ('continued_same', 'continued_alternate', 'intensified') THEN event_count ELSE 0 END) AS continued,
				SUM(CASE WHEN outcome IN ('unknown', 'rate_limited') THEN event_count ELSE 0 END) AS unknown_count,
				SUM(follow_up_count) AS follow_up_count
			FROM atsdn_hub_events
			WHERE observed_date >= :since
			GROUP BY variant, experiment_arm, response_catalog_id, response_fingerprint
			HAVING site_count >= :min_sites AND total_events >= :min_events
			ORDER BY total_events DESC, variant ASC, experiment_arm ASC"
		);
		$stmt->execute(
			array(
				':since'      => $since,
				':min_sites'  => $min['sites'],
				':min_events' => $min['events'],
			)
		);

		$variants = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$denominator = (int) $row['observed_ceased'] + (int) $row['continued'];
			$rate        = $denominator > 0 ? round( ( (int) $row['observed_ceased'] / $denominator ) * 100, 1 ) : null;
			$variants[]  = array(
				'variant'               => $row['variant'],
				'experiment_arm'        => $row['experiment_arm'],
				'response_catalog_id'   => $row['response_catalog_id'],
				'response_fingerprint'  => $row['response_fingerprint'],
				'site_count'            => (int) $row['site_count'],
				'total_events'          => (int) $row['total_events'],
				'observed_ceased'       => (int) $row['observed_ceased'],
				'continued'             => (int) $row['continued'],
				'unknown'               => (int) $row['unknown_count'],
				'follow_up_count'       => (int) $row['follow_up_count'],
				'non_continuation_rate' => $rate,
			);
		}

		return array(
			'schema_version' => '1',
			'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
			'days'           => $days,
			'privacy_thresholds' => $min,
			'variants'       => $variants,
		);
	}

	private function build_or_read_cached_json( $cache_key, $builder ) {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$stmt = $this->db->prepare( 'SELECT etag, body_json, generated_at FROM atsdn_hub_cache WHERE cache_key = :cache_key AND expires_at > :now LIMIT 1' );
		$stmt->execute( array( ':cache_key' => $cache_key, ':now' => $now ) );
		$row = $stmt->fetch();
		if ( $row ) {
			return array(
				'etag'         => $row['etag'],
				'body'         => json_decode( $row['body_json'], true ),
				'generated_at' => $row['generated_at'],
			);
		}

		$body       = call_user_func( $builder );
		$body_json  = json_encode( $body, JSON_UNESCAPED_SLASHES );
		$etag       = '"' . hash( 'sha256', $body_json ) . '"';
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->config_int( 'aggregate_cache_seconds', 300 ) );
		$stmt       = $this->db->prepare(
			'REPLACE INTO atsdn_hub_cache (cache_key, etag, body_json, generated_at, expires_at)
			VALUES (:cache_key, :etag, :body_json, :generated_at, :expires_at)'
		);
		$stmt->execute(
			array(
				':cache_key'    => $cache_key,
				':etag'         => $etag,
				':body_json'    => $body_json,
				':generated_at' => $body['generated_at'],
				':expires_at'   => $expires_at,
			)
		);

		return array( 'etag' => $etag, 'body' => $body, 'generated_at' => $body['generated_at'] );
	}

	private function cacheable_json_response( array $server, array $body ) {
		$body_json = json_encode( $body, JSON_UNESCAPED_SLASHES );
		$etag      = '"' . hash( 'sha256', $body_json ) . '"';
		return $this->etag_response( $server, $etag, $body, $body['generated_at'] );
	}

	private function etag_response( array $server, $etag, array $body, $generated_at ) {
		$headers = array(
			'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
			'ETag'          => $etag,
			'Last-Modified' => gmdate( 'D, d M Y H:i:s', strtotime( $generated_at ) ) . ' GMT',
		);

		if ( isset( $server['HTTP_IF_NONE_MATCH'] ) && trim( $server['HTTP_IF_NONE_MATCH'] ) === $etag ) {
			return array( 'code' => 304, 'headers' => $headers, 'body' => array() );
		}

		return $this->json_response( 200, $body, $headers );
	}

	private function json_response( $code, array $body, array $headers = array() ) {
		if ( ! isset( $body['schema_version'] ) ) {
			$body = array_merge( array( 'schema_version' => '1' ), $body );
		}

		return array(
			'code'    => (int) $code,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	private function method_not_allowed( $allowed ) {
		return $this->json_response( 405, array( 'status' => 'method_not_allowed' ), array( 'Allow' => $allowed ) );
	}

	private function consume_nonce( $key_id, $nonce, $signed_timestamp ) {
		$nonce_hash = hash_hmac( 'sha256', $nonce, (string) $this->config['app_secret'] );
		$stmt = $this->db->prepare(
			'INSERT IGNORE INTO atsdn_hub_nonces (key_id, nonce_hash, created_at, expires_at)
			VALUES (:key_id, :nonce_hash, :created_at, :expires_at)'
		);
		$stmt->execute(
			array(
				':key_id'     => $this->clean_text( $key_id, 80 ),
				':nonce_hash' => $nonce_hash,
				':created_at' => gmdate( 'Y-m-d H:i:s' ),
				':expires_at' => gmdate( 'Y-m-d H:i:s', max( time(), (int) $signed_timestamp ) + self::SIGNATURE_TOLERANCE_SECONDS + 1 ),
			)
		);

		return 1 === $stmt->rowCount();
	}

	private function consume_rate_limit( $bucket, $limit, $window = 'minute' ) {
		$window_key = 'day' === $window ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m-d H:i' );
		$key    = hash_hmac( 'sha256', $bucket . '|' . $window_key, (string) $this->config['app_secret'] );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$stmt   = $this->db->prepare(
			'INSERT INTO atsdn_hub_rate_limits (bucket_key, request_count, window_started_at, updated_at)
			VALUES (:bucket_key, 1, :window_started_at, :updated_at)
			ON DUPLICATE KEY UPDATE request_count = request_count + 1, updated_at = VALUES(updated_at)'
		);
		$stmt->execute(
			array(
				':bucket_key'        => $key,
				':window_started_at' => 'day' === $window ? gmdate( 'Y-m-d 00:00:00' ) : gmdate( 'Y-m-d H:i:00' ),
				':updated_at'        => $now,
			)
		);

		$stmt = $this->db->prepare( 'SELECT request_count FROM atsdn_hub_rate_limits WHERE bucket_key = :bucket_key' );
		$stmt->execute( array( ':bucket_key' => $key ) );

		return (int) $stmt->fetchColumn() <= (int) $limit;
	}

	public function cleanup() {
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->db->prepare( 'DELETE FROM atsdn_hub_nonces WHERE expires_at < :now LIMIT 5000' )->execute( array( ':now' => $now ) );
		$this->db->prepare( 'DELETE FROM atsdn_hub_rate_limits WHERE updated_at < :cutoff LIMIT 5000' )->execute( array( ':cutoff' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ) );
		$this->db->prepare( 'DELETE FROM atsdn_hub_events WHERE received_at < :cutoff LIMIT 5000' )->execute( array( ':cutoff' => gmdate( 'Y-m-d H:i:s', time() - 90 * 86400 ) ) );
		$this->db->exec( 'DELETE FROM atsdn_hub_batches WHERE id IN (SELECT id FROM (SELECT b.id FROM atsdn_hub_batches b LEFT JOIN atsdn_hub_events e ON e.batch_id = b.id WHERE e.id IS NULL LIMIT 5000) orphaned)' );
	}

	private function clear_cache() {
		$this->db->exec( 'DELETE FROM atsdn_hub_cache' );
	}

	private function find_existing_batch( $key_id, $payload_hash ) {
		$stmt = $this->db->prepare( 'SELECT id FROM atsdn_hub_batches WHERE key_id = :key_id AND payload_hash = :payload_hash LIMIT 1' );
		$stmt->execute( array( ':key_id' => $key_id, ':payload_hash' => $payload_hash ) );
		return $stmt->fetch();
	}

	private function claim_site( $key_id, $site_key_hash ) {
		$stmt = $this->db->prepare(
			'INSERT IGNORE INTO atsdn_hub_sites (site_key_hash, key_id, registered_at, last_seen_at)
			VALUES (:site_key_hash, :key_id, :registered_at, :last_seen_at)'
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		$stmt->execute( array( ':site_key_hash' => $site_key_hash, ':key_id' => $key_id, ':registered_at' => $now, ':last_seen_at' => $now ) );

		if ( ! $this->site_is_owned_by( $key_id, $site_key_hash ) ) {
			return false;
		}

		$stmt = $this->db->prepare( 'UPDATE atsdn_hub_sites SET last_seen_at = :last_seen_at WHERE site_key_hash = :site_key_hash AND key_id = :key_id' );
		$stmt->execute( array( ':last_seen_at' => $now, ':site_key_hash' => $site_key_hash, ':key_id' => $key_id ) );
		return true;
	}

	private function site_is_owned_by( $key_id, $site_key_hash ) {
		$stmt = $this->db->prepare( 'SELECT site_key_hash FROM atsdn_hub_sites WHERE site_key_hash = :site_key_hash AND key_id = :key_id LIMIT 1 FOR UPDATE' );
		$stmt->execute( array( ':site_key_hash' => $site_key_hash, ':key_id' => $key_id ) );
		return (bool) $stmt->fetch();
	}

	private function is_site_revoked( $key_id, $site_key_hash ) {
		$stmt = $this->db->prepare( 'SELECT site_key_hash FROM atsdn_hub_revoked_sites WHERE site_key_hash = :site_key_hash AND key_id = :key_id LIMIT 1' );
		$stmt->execute( array( ':site_key_hash' => $site_key_hash, ':key_id' => $key_id ) );
		return (bool) $stmt->fetch();
	}

	private function delete_site_events( $key_id, $site_key_hash ) {
		$stmt = $this->db->prepare( 'DELETE FROM atsdn_hub_batches WHERE key_id = :key_id AND site_key_hash = :site_key_hash' );
		$stmt->execute( array( ':key_id' => $key_id, ':site_key_hash' => $site_key_hash ) );
	}

	private function delete_client_batches( $key_id ) {
		$stmt = $this->db->prepare( 'DELETE FROM atsdn_hub_batches WHERE key_id = :key_id' );
		$stmt->execute( array( ':key_id' => $key_id ) );
	}

	private function site_key_hash( $site_pseudonym ) {
		return hash_hmac( 'sha256', (string) $site_pseudonym, (string) $this->config['app_secret'] );
	}

	private function privacy_thresholds() {
		return array(
			'sites'  => $this->config_int( 'privacy_min_sites', 10 ),
			'events' => $this->config_int( 'privacy_min_events', 100 ),
		);
	}

	private function contains_forbidden_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$forbidden = array(
			'ip',
			'ip_address',
			'remote_addr',
			'url',
			'full_url',
			'path',
			'query',
			'domain',
			'host',
			'cookie',
			'cookies',
			'body',
			'request_body',
			'user_agent',
			'ua',
			'email',
		);

		foreach ( $value as $key => $child ) {
			if ( in_array( strtolower( (string) $key ), $forbidden, true ) ) {
				return true;
			}
			if ( $this->contains_forbidden_keys( $child ) ) {
				return true;
			}
		}

		return false;
	}

	private function allowed_variants() {
		return array( 'observe_only', 'control_generic', 'policy_notice', 'evidence_notice', 'utility_notice', 'machine_notice', 'combined_notice' );
	}

	private function allowed_categories() {
		return array( 'secret_config', 'backup_archive', 'version_control', 'unrelated_admin_surface', 'path_traversal_like', 'repeated_not_found', 'method_anomaly', 'other_high_confidence' );
	}

	private function allowed_outcomes() {
		return array( 'observed_ceased', 'continued_same', 'continued_alternate', 'intensified', 'rate_limited', 'unknown' );
	}

	private function is_allowed_value( $value, array $allowed ) {
		return in_array( $value, $allowed, true );
	}

	private function clean_key( $value, $max_length ) {
		return substr( preg_replace( '/[^a-z0-9_:-]/', '', strtolower( (string) $value ) ), 0, $max_length );
	}

	private function clean_text( $value, $max_length ) {
		return substr( preg_replace( '/[^\pL\pN\s._:+-]/u', '', (string) $value ), 0, $max_length );
	}

	private function clean_hash( $value ) {
		$value = strtolower( (string) $value );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private function request_method( array $server ) {
		return isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : '';
	}

	private function header( array $server, $key ) {
		return isset( $server[ $key ] ) ? trim( (string) $server[ $key ] ) : '';
	}

	private function has_exact_keys( array $value, array $expected ) {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );
		return $actual === $expected;
	}

	private function has_required_and_allowed_keys( array $value, array $required, array $allowed ) {
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		}
		return array() === array_diff( array_keys( $value ), $allowed );
	}

	private function valid_site_pseudonym( $value ) {
		return (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value );
	}

	private function valid_datetime( $value ) {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function valid_observed_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return false;
		}
		$today = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
		return $date >= $today->modify( '-90 days' ) && $date <= $today->modify( '+1 day' );
	}

	private function valid_response_catalog_id( $value ) {
		if ( 'observe_only' === $value ) {
			return true;
		}
		return (bool) preg_match( '/^(control_0_generic_403|response_1_policy_notice|response_2_evidence_notice|response_3_utility_notice|response_4_machine_notice|response_5_combined_notice):(plain|limit):(recorded|not_recorded)$/', $value );
	}

	private function variants_cache_key() {
		$min = $this->privacy_thresholds();
		return 'variants:30:g' . $this->current_generation() . ':s' . $min['sites'] . ':e' . $min['events'];
	}

	private function current_generation() {
		$stmt = $this->db->query( 'SELECT aggregate_generation FROM atsdn_hub_state WHERE id = 1' );
		return (int) $stmt->fetchColumn();
	}

	private function increment_generation() {
		$this->db->exec( 'UPDATE atsdn_hub_state SET aggregate_generation = aggregate_generation + 1, updated_at = UTC_TIMESTAMP() WHERE id = 1' );
	}

	private function config_int( $key, $default ) {
		return isset( $this->config[ $key ] ) ? (int) $this->config[ $key ] : (int) $default;
	}
}
