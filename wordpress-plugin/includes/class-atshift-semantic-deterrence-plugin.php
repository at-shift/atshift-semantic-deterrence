<?php
/**
 * Main runtime for atshift Semantic Deterrence.
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atshift_Semantic_Deterrence_Plugin {
	const CRON_HOOK = 'atshift_semantic_deterrence_maintenance';
	const EVENT_THROTTLE_SECONDS = 60;
	const FOLLOW_UP_THROTTLE_SECONDS = 30;
	const EVENT_WRITE_BUDGET_PER_MINUTE = 120;
	const HUB_AGGREGATE_MAX_BYTES = 262144;
	const HUB_ACK_MAX_BYTES = 4096;

	/** @var self|null */
	private static $instance;

	/** @var Atshift_Semantic_Deterrence_Storage */
	private $storage;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules_static' ) );

		$storage = new Atshift_Semantic_Deterrence_Storage();
		$storage->create_tables();
		Atshift_Semantic_Deterrence_Storage::ensure_secret();

		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'atsdn_five_minutes', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	private function __construct() {
		$this->storage = new Atshift_Semantic_Deterrence_Storage();
		$this->storage->maybe_upgrade_schema();

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_maintenance' ) );
		add_action( 'template_redirect', array( $this, 'observe_request' ), 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( ATSHIFT_SEMANTIC_DETERRENCE_FILE ), array( $this, 'filter_plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 4 );

		if ( is_admin() ) {
			new Atshift_Semantic_Deterrence_Admin( $this->storage );
		}
	}

	public function add_cron_schedules( $schedules ) {
		return self::add_cron_schedules_static( $schedules );
	}

	public static function add_cron_schedules_static( $schedules ) {
		if ( ! isset( $schedules['atsdn_five_minutes'] ) ) {
			$schedules['atsdn_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( '5分ごと', 'atshift-semantic-deterrence' ),
			);
		}

		return $schedules;
	}

	public function run_maintenance() {
		$this->storage->finalize_windows();
		$this->storage->purge_old_data();
		$this->maybe_share_anonymous_batch();
		$this->maybe_pull_aggregate_data();
	}

	public function observe_request() {
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		$detector = new Atshift_Semantic_Deterrence_Detector( $settings );
		$classification = $detector->classify_current_request();

		if ( empty( $classification['level'] ) ) {
			return;
		}

		$category        = sanitize_key( $classification['category'] );
		$level           = absint( $classification['level'] );
		$series_hmac     = sanitize_text_field( $classification['series_hmac'] );
		$source_hmac     = sanitize_text_field( $classification['source_hmac'] );
		$experiment_hmac = sanitize_text_field( $classification['experiment_hmac'] );
		$follow_ups      = 0;
		if ( ! $this->is_follow_up_throttled( $source_hmac, $category, $settings ) ) {
			$follow_ups = $this->storage->mark_follow_up( $source_hmac, $category, $level );
			if ( $follow_ups > 0 ) {
				$this->throttle_follow_up( $source_hmac, $category, $settings );
			}
		}
		$should_respond  = $this->should_respond( $settings, $level );
		$experiment_arm  = $should_respond ? $this->choose_experiment_arm( $settings, $experiment_hmac ) : '';
		$attempt_index   = $should_respond && $this->uses_sequence_arm( $experiment_arm ) ? $this->storage->get_recent_response_count_for_source( $source_hmac ) : 0;
		$variant         = $should_respond ? $this->choose_variant( $settings, $experiment_hmac, $experiment_arm, $attempt_index ) : 'observe_only';
		$status          = $this->get_observed_status();
		$responded       = false;
		$response_profile = array(
			'label'       => 'observe_only',
			'fingerprint' => '',
		);

		if ( $should_respond ) {
			$status           = $this->should_rate_limit( $settings, $source_hmac, $follow_ups ) ? 429 : 403;
			$responded        = true;
			$response_profile = Atshift_Semantic_Deterrence_Detector::get_response_profile(
				$variant,
				true,
				'deter_limit' === $settings['mode'],
				$status
			);
		}

		if ( $this->should_record_event( $source_hmac, $category, $variant, $status, $settings ) ) {
			$this->storage->record_event(
				array(
					'series_hmac'          => $series_hmac,
					'source_hmac'          => $source_hmac,
					'category'             => $category,
					'level'                => $level,
					'variant'              => $variant,
					'experiment_arm'       => $experiment_arm,
					'http_status'          => $status,
					'policy_version'       => $settings['policy_version'],
					'responded'            => $responded ? 1 : 0,
					'response_fingerprint' => $response_profile['fingerprint'],
					'response_label'       => $response_profile['label'],
				)
			);
		}

		if ( $responded ) {
			$this->send_deterrence_response( $status, $variant, $settings );
		}
	}

	private function maybe_share_anonymous_batch() {
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		if ( '1' !== $settings['sharing_enabled'] ) {
			return;
		}

		if ( ! $this->has_hub_endpoint( $settings ) || '' === $settings['aggregate_hub_key_id'] || ! Atshift_Semantic_Deterrence_Storage::has_hub_secret() ) {
			$this->update_share_status( $settings, 'missing_credentials', __( 'Hub URL、Key ID、共有秘密鍵が必要です。', 'atshift-semantic-deterrence' ) );
			return;
		}

		$next_share_at = $this->get_next_share_timestamp( $settings );
		if ( $next_share_at > current_time( 'timestamp' ) ) {
			return;
		}

		$this->storage->finalize_windows();
		$payload = $this->storage->get_anonymous_aggregate_batch( 30 );
		if ( empty( $payload['events'] ) ) {
			$settings['last_share_attempt_at'] = current_time( 'mysql' );
			$settings['last_share_status'] = 'no_data';
			$settings['last_share_message'] = __( '送信対象の匿名集計はありません。', 'atshift-semantic-deterrence' );
			$settings['last_share_response_code'] = '';
			$settings['next_share_after'] = Atshift_Semantic_Deterrence_Storage::calculate_next_share_after( $settings, current_time( 'timestamp' ) + MINUTE_IN_SECONDS );
			Atshift_Semantic_Deterrence_Storage::update_settings( $settings );
			return;
		}

		$body      = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 24, false, false );
		$signature = base64_encode( hash_hmac( 'sha256', $timestamp . "\n" . $nonce . "\n" . $body, Atshift_Semantic_Deterrence_Storage::get_hub_secret(), true ) );
		$settings['last_share_attempt_at'] = current_time( 'mysql' );
		$settings['next_share_after']      = Atshift_Semantic_Deterrence_Storage::calculate_next_share_after( $settings, current_time( 'timestamp' ) + MINUTE_IN_SECONDS );
		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		$response = wp_safe_remote_post(
			$this->hub_url( $settings, '/v1/events/batch/' ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::HUB_ACK_MAX_BYTES,
				'headers'             => array(
					'Content-Type'        => 'application/json',
					'X-ATSDN-Key-Id'      => $settings['aggregate_hub_key_id'],
					'X-ATSDN-Timestamp'   => $timestamp,
					'X-ATSDN-Nonce'       => $nonce,
					'X-ATSDN-Signature'   => $signature,
				),
				'body'                => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$settings['last_share_status'] = 'failed';
			$settings['last_share_message'] = $response->get_error_message();
			$settings['last_share_response_code'] = '';
		} else {
			$code = absint( wp_remote_retrieve_response_code( $response ) );
			$settings['last_share_response_code'] = (string) $code;
			$settings['last_share_status'] = $code >= 200 && $code < 300 ? 'accepted' : 'failed';
			$settings['last_share_message'] = wp_remote_retrieve_response_message( $response );
		}

		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );
	}

	private function maybe_pull_aggregate_data() {
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		if ( '1' !== $settings['aggregate_read_enabled'] || ! $this->has_hub_endpoint( $settings ) ) {
			return;
		}

		$last_pull = $this->parse_local_datetime( $settings['last_aggregate_pull'] );
		if ( $last_pull && current_time( 'timestamp' ) - $last_pull < 30 * MINUTE_IN_SECONDS ) {
			return;
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $settings['last_aggregate_etag'] ) {
			$headers['If-None-Match'] = $settings['last_aggregate_etag'];
		}
		if ( '' !== $settings['last_aggregate_modified'] ) {
			$headers['If-Modified-Since'] = $settings['last_aggregate_modified'];
		}

		$settings['last_aggregate_pull'] = current_time( 'mysql' );
		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		$response = wp_safe_remote_get(
			$this->hub_url( $settings, '/v1/aggregates/current/' ),
			array(
				'timeout'             => 12,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::HUB_AGGREGATE_MAX_BYTES,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$settings['last_aggregate_status'] = 'failed';
		} else {
			$code = absint( wp_remote_retrieve_response_code( $response ) );
			$settings['last_aggregate_status'] = 304 === $code ? 'not_modified' : ( 200 === $code ? 'updated' : 'failed' );
			if ( 200 === $code ) {
				$normalized = $this->normalize_aggregate_response( $response );
				if ( false === $normalized ) {
					$settings['last_aggregate_status'] = 'invalid_response';
					Atshift_Semantic_Deterrence_Storage::update_settings( $settings );
					return;
				}
				$settings['last_aggregate_json']     = $normalized;
				$settings['last_aggregate_etag']     = $this->response_header_value( $response, 'etag' );
				$settings['last_aggregate_modified'] = $this->response_header_value( $response, 'last-modified' );
			}
		}

		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );
	}

	private function update_share_status( $settings, $status, $message ) {
		$settings['last_share_attempt_at'] = current_time( 'mysql' );
		$settings['last_share_status'] = sanitize_key( $status );
		$settings['last_share_message'] = sanitize_text_field( $message );
		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );
	}

	private function has_hub_endpoint( $settings ) {
		if ( empty( $settings['aggregate_hub_url'] ) || false === wp_http_validate_url( $settings['aggregate_hub_url'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $settings['aggregate_hub_url'], PHP_URL_SCHEME ) );
		return 'https' === $scheme || ( 'http' === $scheme && defined( 'ATSHIFT_SEMANTIC_DETERRENCE_ALLOW_INSECURE_HUB' ) && ATSHIFT_SEMANTIC_DETERRENCE_ALLOW_INSECURE_HUB );
	}

	private function normalize_aggregate_response( $response ) {
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body         = (string) wp_remote_retrieve_body( $response );
		if ( 0 !== strpos( $content_type, 'application/json' ) || '' === $body || strlen( $body ) >= self::HUB_AGGREGATE_MAX_BYTES ) {
			return false;
		}

		$decoded = json_decode( $body, true, 16 );
		if ( ! is_array( $decoded ) || '1' !== (string) ( $decoded['schema_version'] ?? '' ) ) {
			return false;
		}

		$normalized = array(
			'schema_version' => '1',
			'generated_at'   => sanitize_text_field( $decoded['generated_at'] ?? '' ),
			'days'           => min( 90, absint( $decoded['days'] ?? 0 ) ),
			'best_variant'   => null,
			'policy'         => array(
				'remote_control'  => false,
				'executable_code' => false,
				'forced_blocking' => false,
			),
		);

		if ( isset( $decoded['best_variant'] ) && is_array( $decoded['best_variant'] ) ) {
			$variant = sanitize_key( $decoded['best_variant']['variant'] ?? '' );
			$arm     = sanitize_key( $decoded['best_variant']['experiment_arm'] ?? '' );
			if ( in_array( $variant, Atshift_Semantic_Deterrence_Detector::get_variant_ids(), true ) && in_array( $arm, array( '', 'fixed_series', 'sequence_series' ), true ) ) {
				$rate = $decoded['best_variant']['non_continuation_rate'] ?? null;
				$normalized['best_variant'] = array(
					'variant'               => $variant,
					'experiment_arm'        => $arm,
					'total_events'          => min( 1000000000, absint( $decoded['best_variant']['total_events'] ?? 0 ) ),
					'non_continuation_rate' => is_numeric( $rate ) ? min( 100, max( 0, (float) $rate ) ) : null,
				);
			}
		}

		return wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES );
	}

	private function hub_url( $settings, $path ) {
		return trailingslashit( untrailingslashit( $settings['aggregate_hub_url'] ) ) . ltrim( $path, '/' );
	}

	private function get_next_share_timestamp( $settings ) {
		$timestamp = $this->parse_local_datetime( $settings['next_share_after'] );
		if ( $timestamp ) {
			return $timestamp;
		}

		$settings['next_share_after'] = Atshift_Semantic_Deterrence_Storage::calculate_next_share_after( $settings );
		Atshift_Semantic_Deterrence_Storage::update_settings( $settings );

		return $this->parse_local_datetime( $settings['next_share_after'] );
	}

	private function parse_local_datetime( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime ) {
			return 0;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, wp_timezone() );
		if ( ! $parsed ) {
			return 0;
		}

		return $parsed->getTimestamp();
	}

	private function response_header_value( $response, $name ) {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return sanitize_text_field( (string) $value );
	}

	public function filter_plugin_action_links( $links ) {
		$actions = array(
			'settings' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=atshift-semantic-deterrence-settings' ) ),
				esc_html__( '設定', 'atshift-semantic-deterrence' )
			),
		);

		return array_merge( $actions, $links );
	}

	public function filter_plugin_row_meta( $links, $plugin_file, $plugin_data, $status ) {
		unset( $status );

		if ( plugin_basename( ATSHIFT_SEMANTIC_DETERRENCE_FILE ) !== $plugin_file ) {
			return $links;
		}

		return array(
			sprintf(
				/* translators: %s: Plugin version. */
				esc_html__( 'バージョン %s', 'atshift-semantic-deterrence' ),
				esc_html( isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : ATSHIFT_SEMANTIC_DETERRENCE_VERSION )
			),
			sprintf(
				/* translators: %s: Plugin author. */
				__( '作者: %s', 'atshift-semantic-deterrence' ),
				'<a href="' . esc_url( 'https://cfs.at-shift.net/' ) . '" target="_blank" rel="noopener noreferrer">@shift</a>'
			),
		);
	}

	private function should_respond( $settings, $level ) {
		if ( absint( $level ) < 2 ) {
			return false;
		}

		if ( 'observe' === $settings['mode'] ) {
			return false;
		}

		if ( 'experiment' === $settings['mode'] && '1' !== $settings['experiment_enabled'] ) {
			return false;
		}

		return in_array( $settings['mode'], array( 'deter', 'deter_limit', 'experiment' ), true );
	}

	private function should_rate_limit( $settings, $source_hmac, $follow_ups ) {
		if ( 'deter_limit' !== $settings['mode'] ) {
			return false;
		}

		return $follow_ups > 0 || $this->storage->has_recent_continuation( $source_hmac );
	}

	private function is_follow_up_throttled( $source_hmac, $category, $settings ) {
		$key = 'atsdn_follow_' . substr( hash( 'sha256', $settings['runtime_epoch'] . '|' . $source_hmac . '|' . $category ), 0, 32 );
		return (bool) get_transient( $key );
	}

	private function throttle_follow_up( $source_hmac, $category, $settings ) {
		$key = 'atsdn_follow_' . substr( hash( 'sha256', $settings['runtime_epoch'] . '|' . $source_hmac . '|' . $category ), 0, 32 );
		set_transient( $key, '1', self::FOLLOW_UP_THROTTLE_SECONDS );
	}

	private function should_record_event( $source_hmac, $category, $variant, $status, $settings ) {
		$key = 'atsdn_event_' . substr( hash( 'sha256', $settings['runtime_epoch'] . '|' . $source_hmac . '|' . $category . '|' . $variant . '|' . absint( $status ) ), 0, 32 );
		if ( get_transient( $key ) ) {
			return false;
		}

		/**
		 * Filters the duplicate-event throttle window.
		 *
		 * Returning a lower value increases measurement detail at the cost of
		 * more local database writes during suspicious request floods.
		 *
		 * @param int $seconds Throttle window in seconds.
		 */
		if ( ! $this->consume_event_write_budget( $settings ) ) {
			return false;
		}

		$seconds = absint( apply_filters( 'atshift_semantic_deterrence_event_throttle_seconds', self::EVENT_THROTTLE_SECONDS ) );
		set_transient( $key, '1', max( 5, $seconds ) );

		return true;
	}

	private function consume_event_write_budget( $settings ) {
		$minute = gmdate( 'YmdHi' );
		$key    = 'atsdn_event_budget_' . substr( hash( 'sha256', $settings['runtime_epoch'] . '|' . $minute ), 0, 20 );
		$count  = absint( get_transient( $key ) );
		$limit  = absint( apply_filters( 'atshift_semantic_deterrence_event_budget_per_minute', self::EVENT_WRITE_BUDGET_PER_MINUTE ) );
		if ( $count >= max( 1, $limit ) ) {
			return false;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	private function choose_experiment_arm( $settings, $experiment_hmac ) {
		if ( 'experiment' !== $settings['mode'] || '1' !== $settings['experiment_enabled'] ) {
			return '';
		}

		$strategy = isset( $settings['experiment_assignment_strategy'] ) ? sanitize_key( $settings['experiment_assignment_strategy'] ) : 'fixed_series';

		if ( 'sequence_series' === $strategy ) {
			return 'sequence_series';
		}

		if ( 'mixed_fixed_and_sequence' === $strategy ) {
			$bucket = hexdec( substr( hash( 'sha256', $experiment_hmac . '|arm' ), 0, 8 ) ) % 2;
			return 0 === $bucket ? 'fixed_series' : 'sequence_series';
		}

		return 'fixed_series';
	}

	private function uses_sequence_arm( $experiment_arm ) {
		return in_array( $experiment_arm, array( 'sequence_series' ), true );
	}

	private function choose_variant( $settings, $experiment_hmac, $experiment_arm, $attempt_index ) {
		if ( 'experiment' !== $settings['mode'] || '1' !== $settings['experiment_enabled'] ) {
			return $settings['preferred_variant'];
		}

		if ( $this->uses_sequence_arm( $experiment_arm ) ) {
			return $this->choose_sequence_variant( $experiment_hmac, $attempt_index );
		}

		$variants = array(
			'control_generic',
			'control_generic',
			'control_generic',
			'control_generic',
			'control_generic',
			'policy_notice',
			'policy_notice',
			'policy_notice',
			'policy_notice',
			'evidence_notice',
			'evidence_notice',
			'evidence_notice',
			'evidence_notice',
			'utility_notice',
			'utility_notice',
			'utility_notice',
			'utility_notice',
			'machine_notice',
			'machine_notice',
			'machine_notice',
			'machine_notice',
			'combined_notice',
			'combined_notice',
			'combined_notice',
			'combined_notice',
		);
		$index = hexdec( substr( hash( 'sha256', $experiment_hmac . '|variant' ), 0, 8 ) ) % count( $variants );

		return $variants[ $index ];
	}

	private function choose_sequence_variant( $experiment_hmac, $attempt_index ) {
		$variants = Atshift_Semantic_Deterrence_Detector::get_semantic_variant_ids();
		$offset   = hexdec( substr( hash( 'sha256', $experiment_hmac . '|sequence' ), 0, 8 ) ) % count( $variants );
		$ordered  = array_merge( array_slice( $variants, $offset ), array_slice( $variants, 0, $offset ) );
		$index    = min( count( $ordered ) - 1, max( 0, absint( $attempt_index ) ) );

		return $ordered[ $index ];
	}

	private function get_observed_status() {
		if ( is_404() ) {
			return 404;
		}

		if ( is_search() || is_archive() || is_singular() || is_front_page() || is_home() ) {
			return 200;
		}

		return 0;
	}

	private function send_deterrence_response( $status, $variant, $settings ) {
		status_header( $status );
		nocache_headers();

		if ( 'control_generic' === $variant ) {
			exit;
		}

		$profile = Atshift_Semantic_Deterrence_Detector::get_response_profile(
			$variant,
			true,
			'deter_limit' === $settings['mode'],
			$status
		);
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Cache-Control: no-store', true );
		header( 'X-Automation-Policy: prohibited' );
		header( 'X-Security-Event: recorded' );

		if ( $status >= 429 || 'deter_limit' === $settings['mode'] ) {
			header( 'Retry-After: ' . absint( $settings['limit_seconds'] ) );
		}

		echo esc_html( $profile['body'] );
		exit;
	}
}
