<?php
/**
 * Local aggregate storage for semantic deterrence observations.
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atshift_Semantic_Deterrence_Storage {
	const OPTION_SETTINGS = 'atshift_semantic_deterrence_settings';
	const OPTION_SECRET   = 'atshift_semantic_deterrence_secret';
	const OPTION_HUB_SECRET = 'atshift_semantic_deterrence_hub_secret';
	const OPTION_SCHEMA_VERSION = 'atshift_semantic_deterrence_schema_version';
	const SCHEMA_VERSION = 4;
	const OUTCOME_UNKNOWN = 'unknown';
	const GUARD_CLAIM_PREFIX = 'atsdn_guard_claim_';
	const GUARD_BUDGET_PREFIX = 'atsdn_guard_budget_';

	/** @var wpdb */
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function table_name() {
		return $this->wpdb->prefix . 'atsdn_events';
	}

	/**
	 * Atomically reserve a short-lived request-processing claim.
	 *
	 * @param string $namespace Claim namespace.
	 * @param string $fingerprint Privacy-bounded request fingerprint.
	 * @param int    $ttl Claim lifetime in seconds.
	 * @return string|false Claim token or false when another request owns it.
	 */
	public static function acquire_request_claim( $namespace, $fingerprint, $ttl ) {
		global $wpdb;

		$option_name = self::guard_option_name( self::GUARD_CLAIM_PREFIX, $namespace, $fingerprint );
		$now         = time();
		$token       = wp_generate_uuid4();
		$value       = ( $now + max( 1, absint( $ttl ) ) ) . ':' . $token;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
				$option_name,
				$now
			)
		);
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				$value
			)
		);
		wp_cache_delete( $option_name, 'options' );

		return 1 === $inserted ? $token : false;
	}

	/**
	 * Release a request claim only when it is still owned by the caller.
	 *
	 * @param string $namespace Claim namespace.
	 * @param string $fingerprint Privacy-bounded request fingerprint.
	 * @param string $token Claim token.
	 * @return void
	 */
	public static function release_request_claim( $namespace, $fingerprint, $token ) {
		global $wpdb;

		$option_name = self::guard_option_name( self::GUARD_CLAIM_PREFIX, $namespace, $fingerprint );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$option_name,
				'%:' . $wpdb->esc_like( (string) $token )
			)
		);
		wp_cache_delete( $option_name, 'options' );
	}

	/**
	 * Atomically consume one write from a per-minute request budget.
	 *
	 * @param string $namespace Budget namespace.
	 * @param string $fingerprint Privacy-bounded budget fingerprint.
	 * @param int    $limit Maximum writes per minute.
	 * @return bool
	 */
	public static function consume_request_budget( $namespace, $fingerprint, $limit ) {
		global $wpdb;

		$limit       = max( 1, absint( $limit ) );
		$minute      = gmdate( 'YmdHi' );
		$option_name = self::guard_option_name( self::GUARD_BUDGET_PREFIX . $minute . '_', $namespace, $fingerprint );

		add_option( $option_name, 0, '', false );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$option_name,
				$limit
			)
		);
		wp_cache_delete( $option_name, 'options' );

		return 1 === $updated;
	}

	/** @return void */
	public static function purge_request_guards() {
		global $wpdb;

		$now                = time();
		$claim_like         = $wpdb->esc_like( self::GUARD_CLAIM_PREFIX ) . '%';
		$budget_like        = $wpdb->esc_like( self::GUARD_BUDGET_PREFIX ) . '%';
		$current_budget_like = $wpdb->esc_like( self::GUARD_BUDGET_PREFIX . gmdate( 'YmdHi' ) . '_' ) . '%';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
				$claim_like,
				$now
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$budget_like,
				$current_budget_like
			)
		);
	}

	/** @return void */
	public static function purge_all_request_guards() {
		global $wpdb;

		$claim_like  = $wpdb->esc_like( self::GUARD_CLAIM_PREFIX ) . '%';
		$budget_like = $wpdb->esc_like( self::GUARD_BUDGET_PREFIX ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$claim_like,
				$budget_like
			)
		);
	}

	private static function guard_option_name( $prefix, $namespace, $fingerprint ) {
		$namespace = substr( sanitize_key( $namespace ), 0, 20 );
		return $prefix . $namespace . '_' . substr( hash( 'sha256', (string) $fingerprint ), 0, 32 );
	}

	public static function get_default_settings() {
		return array(
			'mode'                  => 'observe',
			'sensitivity'           => 'standard',
			'limit_seconds'         => 86400,
			'excluded_ips'          => '',
			'excluded_paths'        => "/.well-known/security.txt\n/wp-json/\n/wp-admin/admin-ajax.php",
			'custom_high_confidence_paths' => '',
			'country_header_source' => 'disabled',
			'local_detail_log'      => '0',
			'sharing_enabled'       => '0',
			'aggregate_read_enabled' => '0',
			'experiment_enabled'    => '0',
			'onboarding_completed'  => '0',
			'experiment_assignment_strategy' => 'fixed_series',
			'experiment_assignment_locked' => '0',
			'experiment_assignment_locked_at' => '',
			'preferred_variant'     => 'combined_notice',
			'delete_on_uninstall'   => '1',
			'policy_version'        => 'local-2026-08-27.1',
			'aggregate_hub_url'     => 'https://aggregate.at-shift.net',
			'aggregate_hub_key_id'  => '',
			'site_pseudonym'        => '',
			'last_aggregate_pull'   => '',
			'last_aggregate_status' => '',
			'last_aggregate_etag'   => '',
			'last_aggregate_modified' => '',
			'last_aggregate_json'   => '',
			'last_aggregate_version' => '',
			'last_share_attempt_at' => '',
			'last_share_status'     => '',
			'last_share_message'    => '',
			'last_share_response_code' => '',
			'next_share_after'      => '',
			'share_schedule_seed'   => '',
			'share_jitter_hours'    => 8,
			'runtime_epoch'         => '',
		);
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, self::get_default_settings() );
		if ( '' === $settings['aggregate_hub_url'] ) {
			$settings['aggregate_hub_url'] = self::get_default_settings()['aggregate_hub_url'];
		}

		return $settings;
	}

	public static function update_settings( $settings ) {
		$defaults = self::get_default_settings();
		$settings = wp_parse_args( $settings, $defaults );

		$settings['mode']                = in_array( $settings['mode'], array( 'observe', 'deter', 'deter_limit', 'experiment' ), true ) ? $settings['mode'] : 'observe';
		$settings['sensitivity']         = in_array( $settings['sensitivity'], array( 'cautious', 'standard', 'strong' ), true ) ? $settings['sensitivity'] : 'standard';
		$settings['preferred_variant']   = in_array( $settings['preferred_variant'], Atshift_Semantic_Deterrence_Detector::get_semantic_variant_ids(), true ) ? $settings['preferred_variant'] : 'combined_notice';
		$settings['limit_seconds']       = min( DAY_IN_SECONDS, max( 60, absint( $settings['limit_seconds'] ) ) );
		$settings['excluded_ips']        = sanitize_textarea_field( $settings['excluded_ips'] );
		$settings['excluded_paths']      = sanitize_textarea_field( $settings['excluded_paths'] );
		$settings['custom_high_confidence_paths'] = sanitize_textarea_field( $settings['custom_high_confidence_paths'] );
		$settings['country_header_source'] = in_array( $settings['country_header_source'], array( 'disabled', 'cloudflare' ), true ) ? $settings['country_header_source'] : 'disabled';
		$settings['local_detail_log']    = empty( $settings['local_detail_log'] ) ? '0' : '1';
		$settings['sharing_enabled']     = empty( $settings['sharing_enabled'] ) ? '0' : '1';
		$settings['aggregate_read_enabled'] = empty( $settings['aggregate_read_enabled'] ) ? '0' : '1';
		$settings['experiment_enabled']  = empty( $settings['experiment_enabled'] ) ? '0' : '1';
		$settings['onboarding_completed'] = empty( $settings['onboarding_completed'] ) ? '0' : '1';
		$settings['experiment_assignment_strategy'] = in_array( $settings['experiment_assignment_strategy'], array( 'fixed_series', 'mixed_fixed_and_sequence', 'sequence_series' ), true ) ? $settings['experiment_assignment_strategy'] : 'fixed_series';
		$settings['experiment_assignment_locked'] = empty( $settings['experiment_assignment_locked'] ) ? '0' : '1';
		$settings['experiment_assignment_locked_at'] = sanitize_text_field( $settings['experiment_assignment_locked_at'] );
		$settings['delete_on_uninstall'] = empty( $settings['delete_on_uninstall'] ) ? '0' : '1';
		$allowed_hub_protocols = defined( 'ATSHIFT_SEMANTIC_DETERRENCE_ALLOW_INSECURE_HUB' ) && ATSHIFT_SEMANTIC_DETERRENCE_ALLOW_INSECURE_HUB ? array( 'http', 'https' ) : array( 'https' );
		$settings['aggregate_hub_url'] = esc_url_raw( $settings['aggregate_hub_url'], $allowed_hub_protocols );
		if ( '' === $settings['aggregate_hub_url'] ) {
			$settings['aggregate_hub_url'] = $defaults['aggregate_hub_url'];
		}
		$settings['aggregate_hub_key_id'] = sanitize_text_field( $settings['aggregate_hub_key_id'] );
		$settings['last_aggregate_status'] = sanitize_key( $settings['last_aggregate_status'] );
		$settings['last_aggregate_etag'] = sanitize_text_field( $settings['last_aggregate_etag'] );
		$settings['last_aggregate_modified'] = sanitize_text_field( $settings['last_aggregate_modified'] );
		$settings['last_aggregate_json'] = is_string( $settings['last_aggregate_json'] ) ? substr( wp_check_invalid_utf8( $settings['last_aggregate_json'] ), 0, 262144 ) : '';
		$settings['next_share_after']    = sanitize_text_field( $settings['next_share_after'] );
		$settings['last_share_attempt_at'] = sanitize_text_field( $settings['last_share_attempt_at'] );
		$settings['last_share_status']   = sanitize_key( $settings['last_share_status'] );
		$settings['last_share_message']  = sanitize_text_field( $settings['last_share_message'] );
		$settings['last_share_response_code'] = sanitize_text_field( $settings['last_share_response_code'] );
		$settings['share_schedule_seed'] = sanitize_text_field( $settings['share_schedule_seed'] );
		$settings['share_jitter_hours']  = min( 24, max( 1, absint( $settings['share_jitter_hours'] ) ) );

		if ( '' === $settings['site_pseudonym'] ) {
			$settings['site_pseudonym'] = wp_generate_uuid4();
		} else {
			$settings['site_pseudonym'] = sanitize_text_field( $settings['site_pseudonym'] );
		}

		if ( '' === $settings['share_schedule_seed'] ) {
			$settings['share_schedule_seed'] = wp_generate_uuid4();
		}

		if ( '' === $settings['runtime_epoch'] ) {
			$settings['runtime_epoch'] = wp_generate_uuid4();
		} else {
			$settings['runtime_epoch'] = sanitize_key( $settings['runtime_epoch'] );
		}

		update_option( self::OPTION_SETTINGS, $settings, false );
	}

	public static function get_hub_secret() {
		return (string) get_option( self::OPTION_HUB_SECRET, '' );
	}

	public static function update_hub_secret( $secret ) {
		$secret = trim( (string) $secret );
		if ( '' === $secret ) {
			return;
		}

		update_option( self::OPTION_HUB_SECRET, sanitize_text_field( $secret ), false );
	}

	public static function has_hub_secret() {
		return '' !== self::get_hub_secret();
	}

	public static function ensure_secret() {
		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::OPTION_SECRET, $secret, false );
		}

		return $secret;
	}

	public function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->table_name();
		$charset = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			observed_date date NOT NULL,
			series_hmac char(64) NOT NULL,
			source_hmac char(64) NOT NULL DEFAULT '',
			country_code char(2) NOT NULL DEFAULT '',
			category varchar(40) NOT NULL,
			level tinyint(3) unsigned NOT NULL DEFAULT 0,
			variant varchar(40) NOT NULL,
			experiment_arm varchar(40) NOT NULL DEFAULT '',
			response_fingerprint char(64) NOT NULL DEFAULT '',
			response_label varchar(80) NOT NULL DEFAULT '',
			http_status smallint(5) unsigned NOT NULL DEFAULT 0,
			follow_up_count int(10) unsigned NOT NULL DEFAULT 0,
			outcome varchar(40) NOT NULL DEFAULT 'unknown',
			policy_version varchar(40) NOT NULL DEFAULT '',
			plugin_version varchar(20) NOT NULL DEFAULT '',
			responded tinyint(1) unsigned NOT NULL DEFAULT 0,
			window_ends_at datetime NOT NULL,
			last_seen_at datetime NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY observed_date (observed_date),
			KEY series_hmac (series_hmac),
			KEY source_hmac (source_hmac),
			KEY country_code (country_code),
			KEY outcome (outcome),
			KEY variant_category (variant, category),
			KEY experiment_arm (experiment_arm),
			KEY response_fingerprint (response_fingerprint),
			KEY window_ends_at (window_ends_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	public function maybe_upgrade_schema() {
		if ( absint( get_option( self::OPTION_SCHEMA_VERSION, 0 ) ) >= self::SCHEMA_VERSION ) {
			return;
		}

		$this->create_tables();
	}

	public function record_event( $event ) {
		$now      = current_time( 'mysql' );
		$defaults = array(
			'created_at'     => $now,
			'observed_date'  => current_time( 'Y-m-d' ),
			'series_hmac'    => '',
			'source_hmac'    => '',
			'country_code'   => '',
			'category'       => 'other_high_confidence',
			'level'          => 0,
			'variant'        => 'observe_only',
			'experiment_arm' => '',
			'response_fingerprint' => '',
			'response_label' => '',
			'http_status'    => 0,
			'follow_up_count'=> 0,
			'outcome'        => self::OUTCOME_UNKNOWN,
			'policy_version' => '',
			'plugin_version' => ATSHIFT_SEMANTIC_DETERRENCE_VERSION,
			'responded'      => 0,
			'window_ends_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 10 * MINUTE_IN_SECONDS ),
			'last_seen_at'   => null,
		);
		$event        = wp_parse_args( $event, $defaults );
		$country_code = strtoupper( sanitize_text_field( $event['country_code'] ) );
		if ( ! preg_match( '/^(?:[A-Z]{2}|T1)$/', $country_code ) ) {
			$country_code = '';
		}

		$this->wpdb->insert(
			$this->table_name(),
			array(
				'created_at'      => sanitize_text_field( $event['created_at'] ),
				'observed_date'   => sanitize_text_field( $event['observed_date'] ),
				'series_hmac'     => sanitize_text_field( $event['series_hmac'] ),
				'source_hmac'     => sanitize_text_field( $event['source_hmac'] ),
				'country_code'    => $country_code,
				'category'        => sanitize_key( $event['category'] ),
				'level'           => absint( $event['level'] ),
				'variant'         => sanitize_key( $event['variant'] ),
				'experiment_arm'  => sanitize_key( $event['experiment_arm'] ),
				'response_fingerprint' => sanitize_text_field( $event['response_fingerprint'] ),
				'response_label'  => sanitize_text_field( $event['response_label'] ),
				'http_status'     => absint( $event['http_status'] ),
				'follow_up_count' => absint( $event['follow_up_count'] ),
				'outcome'         => sanitize_key( $event['outcome'] ),
				'policy_version'  => sanitize_text_field( $event['policy_version'] ),
				'plugin_version'  => sanitize_text_field( $event['plugin_version'] ),
				'responded'       => empty( $event['responded'] ) ? 0 : 1,
				'window_ends_at'  => sanitize_text_field( $event['window_ends_at'] ),
				'last_seen_at'    => $event['last_seen_at'] ? sanitize_text_field( $event['last_seen_at'] ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	public function mark_follow_up( $source_hmac, $category, $level ) {
		$table       = $this->table_name();
		$now         = current_time( 'mysql' );
		$category    = sanitize_key( $category );
		$level       = absint( $level );
		$source_hmac = sanitize_text_field( $source_hmac );

		$pending = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, category, level, outcome FROM {$table}
				WHERE source_hmac = %s
					AND responded = 1
					AND window_ends_at >= %s
					AND outcome IN ('unknown', 'observed_ceased')
				ORDER BY id ASC
				LIMIT 20",
				$source_hmac,
				$now
			)
		);

		foreach ( $pending as $row ) {
			if ( $level > (int) $row->level ) {
				$outcome = 'intensified';
			} elseif ( $category === $row->category ) {
				$outcome = 'continued_same';
			} else {
				$outcome = 'continued_alternate';
			}

			$this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$table}
					SET follow_up_count = follow_up_count + 1,
						last_seen_at = %s,
						outcome = %s
					WHERE id = %d",
					$now,
					$outcome,
					(int) $row->id
				)
			);
		}

		return count( $pending );
	}

	public function has_recent_continuation( $source_hmac ) {
		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );

		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE source_hmac = %s
					AND responded = 1
					AND last_seen_at >= %s
					AND outcome IN ('continued_same', 'continued_alternate', 'intensified')
				LIMIT 1",
				sanitize_text_field( $source_hmac ),
				$since
			)
		);
	}

	public function get_recent_response_count_for_source( $source_hmac, $hours = 24 ) {
		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - max( 1, absint( $hours ) ) * HOUR_IN_SECONDS );

		return absint(
			$this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE source_hmac = %s
						AND responded = 1
						AND created_at >= %s",
					sanitize_text_field( $source_hmac ),
					$since
				)
			)
		);
	}

	public function finalize_windows() {
		$table = $this->table_name();
		$now   = current_time( 'mysql' );

		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table}
				SET outcome = 'observed_ceased'
				WHERE responded = 1
					AND outcome = 'unknown'
					AND window_ends_at < %s",
				$now
			)
		);
	}

	public function purge_old_data() {
		$table = $this->table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);
	}

	public function get_summary( $days = 30 ) {
		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - absint( $days ) * DAY_IN_SECONDS );

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT
					COUNT(*) AS detected,
					SUM(CASE WHEN responded = 1 AND variant <> 'control_generic' THEN 1 ELSE 0 END) AS warnings,
					SUM(CASE WHEN responded = 1 AND outcome = 'observed_ceased' THEN 1 ELSE 0 END) AS observed_ceased,
					SUM(CASE WHEN responded = 1 AND outcome IN ('continued_same', 'continued_alternate', 'intensified') THEN 1 ELSE 0 END) AS continued,
					SUM(CASE WHEN responded = 1 AND outcome IN ('unknown', 'rate_limited') THEN 1 ELSE 0 END) AS unknown,
					SUM(CASE WHEN responded = 1 AND outcome = 'continued_alternate' THEN 1 ELSE 0 END) AS continued_alternate,
					SUM(CASE WHEN responded = 1 AND outcome = 'intensified' THEN 1 ELSE 0 END) AS intensified
				FROM {$table}
				WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		$summary = array_merge(
			array(
				'detected'            => 0,
				'warnings'            => 0,
				'observed_ceased'     => 0,
				'continued'           => 0,
				'unknown'             => 0,
				'continued_alternate' => 0,
				'intensified'         => 0,
			),
			(array) $row
		);

		foreach ( $summary as $key => $value ) {
			$summary[ $key ] = absint( $value );
		}

		return $summary;
	}

	public function get_local_activity_counts() {
		$table         = $this->table_name();
		$one_hour_ago  = current_datetime()->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );
		$day_ago       = current_datetime()->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		$row           = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS one_hour,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS twenty_four_hours
				FROM {$table}
				WHERE created_at >= %s",
				$one_hour_ago,
				$day_ago,
				$day_ago
			),
			ARRAY_A
		);

		return array(
			'one_hour'          => absint( $row['one_hour'] ?? 0 ),
			'twenty_four_hours' => absint( $row['twenty_four_hours'] ?? 0 ),
		);
	}

	public function get_hourly_activity( $hours = 24 ) {
		$table = $this->table_name();
		$hours = min( 48, max( 1, absint( $hours ) ) );
		$end   = current_datetime()->setTime( absint( current_datetime()->format( 'H' ) ), 0, 0 );
		$start = $end->modify( '-' . ( $hours - 1 ) . ' hours' );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00') AS hour_bucket, COUNT(*) AS event_count
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY hour_bucket
				ORDER BY hour_bucket ASC",
				$start->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['hour_bucket'] ] = absint( $row['event_count'] );
		}

		$activity = array();
		for ( $index = 0; $index < $hours; $index++ ) {
			$hour       = $start->modify( '+' . $index . ' hours' );
			$bucket     = $hour->format( 'Y-m-d H:00:00' );
			$activity[] = array(
				'label' => $hour->format( 'H:00' ),
				'count' => $counts[ $bucket ] ?? 0,
			);
		}

		return $activity;
	}

	public function get_country_activity( $hours = 24, $limit = 8 ) {
		$table = $this->table_name();
		$since = current_datetime()->modify( '-' . min( 168, max( 1, absint( $hours ) ) ) . ' hours' )->format( 'Y-m-d H:i:s' );
		$limit = min( 20, max( 1, absint( $limit ) ) );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT country_code, COUNT(*) AS event_count
				FROM {$table}
				WHERE created_at >= %s
					AND country_code <> ''
				GROUP BY country_code
				ORDER BY event_count DESC, country_code ASC
				LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);
	}

	public function get_variant_stats( $days = 30 ) {
		$table = $this->table_name();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - absint( $days ) * DAY_IN_SECONDS );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT
					variant,
					experiment_arm,
					response_fingerprint,
					response_label,
					COUNT(*) AS total,
					SUM(CASE WHEN outcome = 'observed_ceased' THEN 1 ELSE 0 END) AS ceased,
					SUM(CASE WHEN outcome IN ('continued_same', 'continued_alternate', 'intensified') THEN 1 ELSE 0 END) AS continued,
					SUM(CASE WHEN outcome IN ('unknown', 'rate_limited') THEN 1 ELSE 0 END) AS unknown_count
				FROM {$table}
				WHERE created_at >= %s
					AND responded = 1
				GROUP BY variant, experiment_arm, response_fingerprint, response_label
				ORDER BY total DESC, variant ASC, experiment_arm ASC",
				$since
			),
			ARRAY_A
		);
	}

	public function get_recent_events( $limit = 10 ) {
		$table = $this->table_name();
		$limit = min( 50, max( 1, absint( $limit ) ) );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT
					created_at,
					category,
					level,
					variant,
					experiment_arm,
					response_fingerprint,
					response_label,
					http_status,
					follow_up_count,
					outcome,
					responded,
					last_seen_at
				FROM {$table}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	public function get_anonymous_aggregate_batch( $days = 30 ) {
		$table    = $this->table_name();
		$days     = min( 90, max( 1, absint( $days ) ) );
		$since    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$settings = self::get_settings();
		$rows     = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT
					observed_date,
					plugin_version,
					policy_version,
					variant,
					experiment_arm,
					response_fingerprint,
					response_label,
					http_status,
					category,
					level,
					outcome,
					COUNT(*) AS event_count,
					SUM(follow_up_count) AS follow_up_count
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY observed_date, plugin_version, policy_version, variant, experiment_arm, response_fingerprint, response_label, http_status, category, level, outcome
				ORDER BY observed_date ASC, variant ASC, experiment_arm ASC, category ASC, outcome ASC",
				$since
			),
			ARRAY_A
		);

		$events = array();
		foreach ( (array) $rows as $row ) {
			$event = array(
				'schema_version'  => '1',
				'site_pseudonym'  => sanitize_text_field( $settings['site_pseudonym'] ),
				'plugin_version'  => sanitize_text_field( $row['plugin_version'] ),
				'policy_version'  => sanitize_text_field( $row['policy_version'] ),
				'variant'         => sanitize_key( $row['variant'] ),
				'experiment_arm'  => sanitize_key( $row['experiment_arm'] ),
				'response_fingerprint' => sanitize_text_field( $row['response_fingerprint'] ),
				'response_catalog_id' => sanitize_text_field( $row['response_label'] ),
				'http_status'     => absint( $row['http_status'] ),
				'category'        => sanitize_key( $row['category'] ),
				'level'           => absint( $row['level'] ),
				'outcome'         => sanitize_key( $row['outcome'] ),
				'event_count'     => absint( $row['event_count'] ),
				'follow_up_count' => absint( $row['follow_up_count'] ),
				'time_bucket'     => '0-10m',
				'observed_date'   => sanitize_text_field( $row['observed_date'] ),
			);

			$events[] = $event;
		}

		return array(
			'schema_version' => '1',
			'generated_at'   => current_time( 'mysql' ),
			'days'           => $days,
			'events'         => $events,
		);
	}

	public static function calculate_next_share_after( $settings = null, $from_timestamp = null ) {
		$settings       = is_array( $settings ) ? wp_parse_args( $settings, self::get_default_settings() ) : self::get_settings();
		$from_timestamp = $from_timestamp ? absint( $from_timestamp ) : current_time( 'timestamp' );
		$timezone       = wp_timezone();
		$from           = new DateTimeImmutable( '@' . $from_timestamp );
		$from           = $from->setTimezone( $timezone );
		$jitter_hours   = min( 24, max( 1, absint( $settings['share_jitter_hours'] ) ) );
		$seed           = (string) $settings['share_schedule_seed'];

		if ( '' === $seed ) {
			$seed = (string) $settings['site_pseudonym'];
		}

		for ( $day_offset = 0; $day_offset < 3; $day_offset++ ) {
			$day      = $from->modify( '+' . $day_offset . ' days' );
			$date_key = $day->format( 'Y-m-d' );
			$hash     = hexdec( substr( hash( 'sha256', $seed . '|' . $date_key ), 0, 8 ) );
			$offset   = $hash % ( $jitter_hours * HOUR_IN_SECONDS );
			$run_at   = new DateTimeImmutable( $date_key . ' 00:00:00', $timezone );
			$run_at   = $run_at->modify( '+' . $offset . ' seconds' );

			if ( $run_at->getTimestamp() > $from_timestamp ) {
				return $run_at->format( 'Y-m-d H:i:s' );
			}
		}

		return $from->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
	}

	public function drop_tables() {
		$table = $this->table_name();
		$this->wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function delete_all_events() {
		$table = $this->table_name();
		$this->wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
