<?php
/**
 * Request classification for semantic deterrence.
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atshift_Semantic_Deterrence_Detector {
	const DAILY_SALT_PREFIX = 'atshift_semantic_deterrence_salt_';
	const NOT_FOUND_BUDGET_PER_MINUTE = 300;

	/** @var array<string,mixed> */
	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public static function get_categories() {
		return array(
			'secret_config',
			'backup_archive',
			'version_control',
			'unrelated_admin_surface',
			'path_traversal_like',
			'repeated_not_found',
			'method_anomaly',
			'other_high_confidence',
		);
	}

	public static function get_variant_ids() {
		return array(
			'control_generic',
			'policy_notice',
			'evidence_notice',
			'utility_notice',
			'machine_notice',
			'combined_notice',
		);
	}

	public static function get_semantic_variant_ids() {
		return array(
			'policy_notice',
			'evidence_notice',
			'utility_notice',
			'machine_notice',
			'combined_notice',
		);
	}

	public static function get_response_options() {
		return array(
			'policy_notice'   => __( '1. 方針通知', 'atshift-semantic-deterrence' ),
			'evidence_notice' => __( '2. 検知とローカル記録の通知', 'atshift-semantic-deterrence' ),
			'utility_notice'  => __( '3. 継続しても得るものが少ない通知', 'atshift-semantic-deterrence' ),
			'machine_notice'  => __( '4. 機械可読通知', 'atshift-semantic-deterrence' ),
			'combined_notice' => __( '5. 統合通知', 'atshift-semantic-deterrence' ),
		);
	}

	public static function get_builtin_path_rules() {
		return array(
			array(
				'id'       => 'secret_config',
				'category' => 'secret_config',
				'patterns' => array( '#(^|/)(\.env|\.env\.[^/]+|wp-config\.php|config\.php|configuration\.php|settings\.php|secrets?\.json|credentials?\.json|\.aws)(/|$)#' ),
			),
			array(
				'id'       => 'version_control',
				'category' => 'version_control',
				'patterns' => array( '#(^|/)(\.git|\.svn|\.hg|\.bzr)(/|$)#' ),
			),
			array(
				'id'       => 'path_traversal_like',
				'category' => 'path_traversal_like',
				'patterns' => array( '#(\.\./|\.\.\\\\|%2e%2e|/(etc/passwd|proc/self|windows/win\.ini))#' ),
			),
			array(
				'id'       => 'unrelated_admin_surface',
				'category' => 'unrelated_admin_surface',
				'patterns' => array( '#(^|/)(phpmyadmin|pma|adminer|manager/html|jmx-console|web-console|vendor/phpunit)(/|$)#' ),
			),
			array(
				'id'           => 'backup_archive',
				'category'     => 'backup_archive',
				'patterns_all' => array(
					'#\.(sql|sqlite|db|bak|backup|old|orig|save|tar|tgz|gz|zip|7z|rar)$#',
					'#(backup|dump|database|db|wordpress|wp-content|site|www|htdocs|public_html|mysql|sql)#',
				),
			),
		);
	}

	public function classify_current_request() {
		if ( $this->is_excluded_request() ) {
			return array( 'level' => 0 );
		}

		$path     = $this->get_current_path();
		$method   = $this->get_request_method();
		$category = $this->classify_path( $path, $method );
		$level    = $this->level_for_category( $category );

		if ( is_404() && ( '' === $category || 'method_anomaly' === $category ) ) {
			$not_found = $this->classify_repeated_not_found();
			if ( $not_found['level'] > $level ) {
				$category = $not_found['category'];
				$level    = $not_found['level'];
			}
		}

		/**
		 * Filters the request classification before it is recorded or acted on.
		 *
		 * Implementations must not return raw IPs, full URLs, request bodies,
		 * cookies, or other sensitive data in the classification array.
		 *
		 * @param array<string,mixed> $classification Category and level.
		 * @param string              $path           Current request path without query string.
		 * @param string              $method         HTTP method.
		 */
		$series_hmac     = $this->get_series_hmac();
		$source_hmac     = $this->get_source_hmac();
		$experiment_hmac = $this->get_experiment_hmac();

		$classification = apply_filters(
			'atshift_semantic_deterrence_classification',
			array(
				'category'    => $category,
				'level'       => $level,
				'series_hmac' => $series_hmac,
				'method'      => $method,
			),
			$path,
			$method
		);

		$category = isset( $classification['category'] ) ? sanitize_key( $classification['category'] ) : '';
		$level    = isset( $classification['level'] ) ? absint( $classification['level'] ) : 0;

		if ( ! in_array( $category, self::get_categories(), true ) ) {
			$category = '';
			$level    = 0;
		}

		if ( $level < 1 ) {
			return array( 'level' => 0 );
		}

		return array(
			'category'        => $category,
			'level'           => min( 3, $level ),
			'series_hmac'     => isset( $classification['series_hmac'] ) ? sanitize_text_field( $classification['series_hmac'] ) : $series_hmac,
			'source_hmac'     => $source_hmac,
			'experiment_hmac' => $experiment_hmac,
			'method'          => $method,
		);
	}

	public function get_series_hmac() {
		$secret = Atshift_Semantic_Deterrence_Storage::ensure_secret();
		$salt   = $this->get_daily_salt();
		$input  = wp_json_encode(
			array(
				$this->get_remote_address_for_local_hmac(),
				$this->get_header_value( 'HTTP_USER_AGENT' ),
				$this->get_header_value( 'HTTP_ACCEPT_LANGUAGE' ),
				$this->get_request_method(),
			),
			JSON_UNESCAPED_SLASHES
		);

		return hash_hmac( 'sha256', $salt . '|' . $input, $secret );
	}

	public function get_source_hmac() {
		$input = 'source|' . $this->get_remote_address_for_local_hmac();
		return hash_hmac( 'sha256', $this->get_daily_salt() . '|' . $input, Atshift_Semantic_Deterrence_Storage::ensure_secret() );
	}

	public function get_experiment_hmac() {
		$policy_version = isset( $this->settings['policy_version'] ) ? sanitize_text_field( $this->settings['policy_version'] ) : '';
		$input          = 'experiment|' . $policy_version . '|' . $this->get_remote_address_for_local_hmac();
		return hash_hmac( 'sha256', $input, Atshift_Semantic_Deterrence_Storage::ensure_secret() );
	}

	public static function get_response_body( $variant, $recording_enabled, $limiting_enabled ) {
		$recorded = $recording_enabled ? ' This request was rejected and recorded by this site.' : ' This request was rejected by this site.';
		$limited  = $limiting_enabled ? "\nRelated requests are subject to rate limiting." : '';

		switch ( $variant ) {
			case 'policy_notice':
				return "Unauthorized automated probing is not permitted on this host.\nStop automated probing of this host.\n";
			case 'evidence_notice':
				return "Unauthorized automated probing was detected.\n" . trim( $recorded ) . "\nStop automated probing of this host.\n";
			case 'utility_notice':
				return "Continuing is unlikely to provide additional information.\nStop automated probing of this host.\n";
			case 'machine_notice':
				return "automation_policy=prohibited\nsecurity_event=detected\nrecommended_action=stop\n";
			case 'combined_notice':
			default:
				return "Unauthorized automated probing was detected.\n" . trim( $recorded ) . $limited . "\nContinuing is unlikely to provide additional information.\nStop automated probing of this host.\n";
		}
	}

	public static function get_response_profile( $variant, $recording_enabled, $limiting_enabled, $status = 403 ) {
		$variant = in_array( $variant, self::get_variant_ids(), true ) ? $variant : 'combined_notice';
		$status  = absint( $status );

		if ( 'control_generic' === $variant ) {
			$headers = array( 'HTTP/1.1 ' . $status . ' Forbidden' );
			$body    = '';
		} else {
			$headers = array(
				'HTTP/1.1 ' . $status . ( 429 === $status ? ' Too Many Requests' : ' Forbidden' ),
				'Content-Type: text/plain; charset=UTF-8',
				'Cache-Control: no-store',
				'X-Automation-Policy: prohibited',
				'X-Security-Event: recorded',
			);

			if ( $limiting_enabled ) {
				$headers[] = 'Retry-After: configured-limit-seconds';
			}

			$body = self::get_response_body( $variant, $recording_enabled, $limiting_enabled );
		}

		$catalog_ids = array(
			'control_generic' => 'control_0_generic_403',
			'policy_notice'   => 'response_1_policy_notice',
			'evidence_notice' => 'response_2_evidence_notice',
			'utility_notice'  => 'response_3_utility_notice',
			'machine_notice'  => 'response_4_machine_notice',
			'combined_notice' => 'response_5_combined_notice',
		);
		$label = ( $catalog_ids[ $variant ] ?? 'response_5_combined_notice' ) . ':' . ( $limiting_enabled ? 'limit' : 'plain' ) . ':' . ( $recording_enabled ? 'recorded' : 'not_recorded' );
		$hash_input = wp_json_encode(
			array(
				'schema_version' => '1',
				'variant'        => $variant,
				'status'         => $status,
				'headers'        => $headers,
				'body'           => $body,
			)
		);

		return array(
			'label'       => $label,
			'fingerprint' => hash( 'sha256', (string) $hash_input ),
			'headers'     => $headers,
			'body'        => $body,
		);
	}

	private function classify_path( $path, $method ) {
		$path = strtolower( rawurldecode( $path ) );

		foreach ( $this->get_lines_setting( 'custom_high_confidence_paths' ) as $custom_path ) {
			if ( $this->path_matches_prefix( $path, $custom_path ) ) {
				return 'other_high_confidence';
			}
		}

		/**
		 * Filters built-in and developer-provided path classification rules.
		 *
		 * Rules accept `category` plus either `patterns` where any regex may
		 * match, or `patterns_all` where every regex must match. Admin-entered
		 * custom paths intentionally use simple prefixes instead of regex.
		 *
		 * @param array<int,array<string,mixed>> $rules  Path rules.
		 * @param string                         $path   Lowercase decoded request path.
		 * @param string                         $method HTTP method.
		 */
		$rules = apply_filters( 'atshift_semantic_deterrence_path_rules', self::get_builtin_path_rules(), $path, $method );
		foreach ( (array) $rules as $rule ) {
			$category = isset( $rule['category'] ) ? sanitize_key( $rule['category'] ) : '';
			if ( ! in_array( $category, self::get_categories(), true ) || 'repeated_not_found' === $category || 'method_anomaly' === $category ) {
				continue;
			}

			if ( $this->rule_matches_path( $rule, $path ) ) {
				return $category;
			}
		}

		if ( ! in_array( $method, array( 'GET', 'POST', 'HEAD', 'OPTIONS' ), true ) ) {
			return 'method_anomaly';
		}

		return '';
	}

	private function rule_matches_path( $rule, $path ) {
		if ( isset( $rule['patterns_all'] ) && is_array( $rule['patterns_all'] ) ) {
			foreach ( $rule['patterns_all'] as $pattern ) {
				if ( ! is_string( $pattern ) || '' === $pattern || false === @preg_match( $pattern, $path ) || ! preg_match( $pattern, $path ) ) {
					return false;
				}
			}

			return true;
		}

		if ( isset( $rule['patterns'] ) && is_array( $rule['patterns'] ) ) {
			foreach ( $rule['patterns'] as $pattern ) {
				if ( is_string( $pattern ) && '' !== $pattern && false !== @preg_match( $pattern, $path ) && preg_match( $pattern, $path ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function level_for_category( $category ) {
		$base = array(
			'secret_config'           => 2,
			'backup_archive'          => 2,
			'version_control'         => 2,
			'unrelated_admin_surface' => 2,
			'path_traversal_like'     => 2,
			'repeated_not_found'      => 2,
			'method_anomaly'          => 1,
			'other_high_confidence'   => 2,
			''                        => 0,
		);

		$level = $base[ $category ] ?? 0;
		if ( 'cautious' === $this->settings['sensitivity'] && in_array( $category, array( 'backup_archive', 'unrelated_admin_surface' ), true ) ) {
			$level = 1;
		}
		if ( 'strong' === $this->settings['sensitivity'] && 'method_anomaly' === $category ) {
			$level = 2;
		}

		return $level;
	}

	private function classify_repeated_not_found() {
		$source    = $this->get_source_hmac();
		$epoch     = isset( $this->settings['runtime_epoch'] ) ? sanitize_key( $this->settings['runtime_epoch'] ) : '';
		$transient = 'atsdn_404_' . substr( hash( 'sha256', $epoch . '|' . $source ), 0, 32 );
		$now       = time();
		$bucket    = get_transient( $transient );
		$bucket    = is_array( $bucket ) ? $bucket : array();
		$first     = isset( $bucket['first'] ) ? absint( $bucket['first'] ) : $now;
		$count     = isset( $bucket['count'] ) ? absint( $bucket['count'] ) : 0;
		$last      = isset( $bucket['last'] ) ? absint( $bucket['last'] ) : 0;

		if ( $first < $now - 5 * MINUTE_IN_SECONDS ) {
			$first = $now;
			$count = 0;
			$last  = 0;
		}

		/**
		 * Filters how often repeated-404 tracking may update its transient.
		 *
		 * This deliberately avoids a database write for every 404 during a
		 * high-rate scan on hosts without a persistent object cache.
		 *
		 * @param int $seconds Minimum seconds between transient writes.
		 */
		$minimum_write_interval = absint( apply_filters( 'atshift_semantic_deterrence_404_write_interval', 2 ) );
		if ( $last && $now - $last < max( 1, $minimum_write_interval ) ) {
			return array(
				'category' => '',
				'level'    => 0,
			);
		}

		$claim = Atshift_Semantic_Deterrence_Storage::acquire_request_claim( '404', $epoch . '|' . $source, max( 1, $minimum_write_interval ) );
		if ( false === $claim ) {
			return array(
				'category' => '',
				'level'    => 0,
			);
		}

		$limit = absint( apply_filters( 'atshift_semantic_deterrence_404_budget_per_minute', self::NOT_FOUND_BUDGET_PER_MINUTE ) );
		if ( ! Atshift_Semantic_Deterrence_Storage::consume_request_budget( '404', $epoch, $limit ) ) {
			return array(
				'category' => '',
				'level'    => 0,
			);
		}

		++$count;
		set_transient(
			$transient,
			array(
				'first' => $first,
				'count' => $count,
				'last'  => $now,
			),
			10 * MINUTE_IN_SECONDS
		);

		$threshold = 'cautious' === $this->settings['sensitivity'] ? 6 : 4;
		$threshold = 'strong' === $this->settings['sensitivity'] ? 3 : $threshold;

		if ( $count >= $threshold ) {
			return array(
				'category' => 'repeated_not_found',
				'level'    => 2,
			);
		}

		return array(
			'category' => '',
			'level'    => 0,
		);
	}

	private function is_excluded_request() {
		if ( is_admin() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		$path = $this->get_current_path();
		if ( '/.well-known/security.txt' === $path ) {
			return true;
		}

		foreach ( $this->get_lines_setting( 'excluded_paths' ) as $excluded_path ) {
			if ( $this->path_matches_prefix( $path, $excluded_path ) ) {
				return true;
			}
		}

		$remote_address = $this->get_remote_address_for_local_hmac();
		foreach ( $this->get_lines_setting( 'excluded_ips' ) as $excluded_ip ) {
			if ( '' !== $excluded_ip && hash_equals( $excluded_ip, $remote_address ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_lines_setting( $key ) {
		$value = isset( $this->settings[ $key ] ) ? (string) $this->settings[ $key ] : '';
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		return array_filter( array_map( 'trim', $lines ) );
	}

	private function path_matches_prefix( $path, $prefix ) {
		$path   = '/' . ltrim( strtolower( rawurldecode( (string) $path ) ), '/' );
		$prefix = '/' . ltrim( strtolower( rawurldecode( trim( (string) $prefix ) ) ), '/' );

		if ( '/' === $prefix || '' === $prefix ) {
			return false;
		}

		return 0 === strpos( $path, $prefix );
	}

	private function get_current_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( '' === $path ) {
			$path = '/';
		}

		$path = wp_check_invalid_utf8( substr( $path, 0, 2048 ) );
		$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );

		return '/' . ltrim( (string) $path, '/' );
	}

	private function get_request_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		return strtoupper( sanitize_key( $method ) );
	}

	private function get_remote_address_for_local_hmac() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $remote_addr );
	}

	private function get_header_value( $key ) {
		$value = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : '';
		return substr( sanitize_text_field( $value ), 0, 512 );
	}

	private function get_daily_salt() {
		$date       = current_time( 'Ymd' );
		$option_key = self::DAILY_SALT_PREFIX . $date;
		$salt       = (string) get_option( $option_key, '' );

		if ( '' === $salt ) {
			$salt = wp_generate_password( 32, true, true );
			update_option( $option_key, $salt, false );
			$this->delete_old_salts( $date );
		}

		return $salt;
	}

	private function delete_old_salts( $current_date ) {
		global $wpdb;

		$like = $wpdb->esc_like( self::DAILY_SALT_PREFIX ) . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 20",
				$like
			)
		);

		foreach ( $keys as $key ) {
			if ( self::DAILY_SALT_PREFIX . $current_date !== $key ) {
				delete_option( $key );
			}
		}
	}
}
