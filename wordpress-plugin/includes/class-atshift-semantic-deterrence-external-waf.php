<?php
/** Local-only external WAF reference observations, never experiment events. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atshift_Semantic_Deterrence_External_Waf {
	const OPTION_SAMPLES = 'atsdn_external_waf_samples';
	const OPTION_CLOUDSECURE = 'atsdn_external_waf_cloudsecure';
	const MAX_SAMPLES = 6000;
	const PROVIDERS = array( 'bbq', 'wordfence', 'ninjafirewall', 'siteguard' );
	const CATEGORIES = array( 'sql_injection', 'xss', 'command_injection', 'code_injection', 'mail_injection', 'other' );
	const ACTIONS = array( 'block_decision', 'detected', 'learning', 'unknown' );

	public static function init() {
		// Register at file load, before any provider's plugins_loaded callback can exit.
		add_action( 'bbq_response', array( __CLASS__, 'observe_bbq' ), 10, 1 );
		add_action( 'atsdn_external_waf_observation', array( __CLASS__, 'observe' ), 10, 1 );
	}

	public static function enabled() {
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		return '1' === ( $settings['external_waf_enabled'] ?? '0' );
	}

	public static function normalize( $event ) {
		if ( ! is_array( $event ) || count( $event ) !== 3 || array_diff( array_keys( $event ), array( 'provider', 'category', 'action' ) ) ) {
			return false;
		}
		if ( ! in_array( $event['provider'] ?? null, self::PROVIDERS, true ) ||
			! in_array( $event['category'] ?? null, self::CATEGORIES, true ) ||
			! in_array( $event['action'] ?? null, self::ACTIONS, true ) ) {
			return false;
		}
		return array( 'provider' => $event['provider'], 'category' => $event['category'], 'action' => $event['action'] );
	}

	public static function observe_bbq( $matches ) {
		try {
			if ( ! defined( 'BBQ_VERSION' ) || ! self::enabled() ) {
				return;
			}
			$match = is_array( $matches ) && isset( $matches[0] ) && is_string( $matches[0] ) ? substr( $matches[0], 0, 256 ) : '';
			$category = 'other';
			if ( preg_match( '/union|select|benchmark|concat|outfile/i', $match ) ) {
				$category = 'sql_injection';
			} elseif ( preg_match( '/script|javascript|onerror/i', $match ) ) {
				$category = 'xss';
			} elseif ( preg_match( '/base64|eval|php|file_(?:get|put)_contents|passthru|exec|system/i', $match ) ) {
				$category = 'code_injection';
			}
			self::observe( array( 'provider' => 'bbq', 'category' => $category, 'action' => 'block_decision' ) );
		} catch ( Throwable $error ) {
			// Reference collection must never interfere with the provider's block response.
		}
	}

	public static function observe( $event ) {
		global $wpdb;
		$lock = false;
		$fingerprint = '';
		$previous = null;
		try {
			$event = self::normalize( $event );
			if ( false === $event || ! self::enabled() ) {
				return false;
			}
			$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
			$epoch = (string) $settings['runtime_epoch'];
			$fingerprint = 'external-waf|' . $epoch;
			$cache_key = hash( 'sha256', $fingerprint . '|' . $event['provider'] );
			if ( ! wp_cache_add( $cache_key, 1, 'atsdn_external_waf', 60 ) ) {
				return false;
			}
			$previous = $wpdb->suppress_errors( true );
			// One sample per provider per 60 seconds, not one row per attacking request.
			if ( false === Atshift_Semantic_Deterrence_Storage::acquire_request_claim( 'external_sample', $cache_key, 60 ) ) {
				return false;
			}
			$lock = Atshift_Semantic_Deterrence_Storage::acquire_request_claim( 'external_write', $fingerprint, 5 );
			if ( false === $lock ) {
				return false;
			}
			$now = time();
			$state = get_option( self::OPTION_SAMPLES, array() );
			$rows = is_array( $state ) && ( $state['epoch'] ?? '' ) === $epoch ? self::valid_samples( $state['rows'] ?? array(), $now ) : array();
			$rows[] = array_merge( $event, array( 'at' => $now ) );
			$latest = Atshift_Semantic_Deterrence_Storage::get_settings();
			if ( '1' !== ( $latest['external_waf_enabled'] ?? '0' ) || $epoch !== (string) $latest['runtime_epoch'] ) {
				return false;
			}
			return update_option( self::OPTION_SAMPLES, array( 'epoch' => $epoch, 'rows' => array_slice( $rows, -self::MAX_SAMPLES ) ), false );
		} catch ( Throwable $error ) {
			return false;
		} finally {
			if ( false !== $lock ) {
				try {
					Atshift_Semantic_Deterrence_Storage::release_request_claim( 'external_write', $fingerprint, $lock );
				} catch ( Throwable $error ) {
					// The lock expires even when its early release fails.
				}
			}
			if ( null !== $previous ) {
				$wpdb->suppress_errors( $previous );
			}
		}
	}

	private static function valid_samples( $rows, $now ) {
		$valid = array();
		foreach ( is_array( $rows ) ? array_slice( $rows, -self::MAX_SAMPLES ) : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['at'] ) || ! is_int( $row['at'] ) || $row['at'] < $now - DAY_IN_SECONDS || $row['at'] > $now ) {
				continue;
			}
			$event = $row;
			unset( $event['at'] );
			$event = self::normalize( $event );
			if ( false !== $event ) {
				$valid[] = array_merge( $event, array( 'at' => $row['at'] ) );
			}
		}
		return $valid;
	}

	public static function get_reference() {
		$result = array( 'cloudsecure' => null, 'samples' => array() );
		if ( ! self::enabled() ) {
			return $result;
		}
		$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
		$state = get_option( self::OPTION_SAMPLES, array() );
		if ( is_array( $state ) && ( $state['epoch'] ?? '' ) === (string) $settings['runtime_epoch'] ) {
			foreach ( self::valid_samples( $state['rows'] ?? array(), time() ) as $row ) {
				$provider = $row['provider'];
				$result['samples'][$provider] = ( $result['samples'][$provider] ?? 0 ) + 1;
			}
		}
		$snapshot = get_option( self::OPTION_CLOUDSECURE, array() );
		if ( is_array( $snapshot ) && ( $snapshot['epoch'] ?? '' ) === (string) $settings['runtime_epoch'] &&
			isset( $snapshot['at'], $snapshot['count'] ) && is_int( $snapshot['at'] ) && is_int( $snapshot['count'] ) &&
			$snapshot['at'] >= time() - 15 * MINUTE_IN_SECONDS && $snapshot['at'] <= time() &&
			$snapshot['count'] >= 0 && $snapshot['count'] <= 10000 ) {
			$result['cloudsecure'] = $snapshot['count'];
		}
		return $result;
	}

	public static function refresh_cloudsecure() {
		global $wpdb;
		if ( ! self::enabled() || ! class_exists( 'CloudSecureWP_Waf', false ) ) {
			return;
		}
		$previous = null;
		try {
			$previous = $wpdb->suppress_errors( true );
			$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
			$epoch = (string) $settings['runtime_epoch'];
			$claim = Atshift_Semantic_Deterrence_Storage::acquire_request_claim( 'external_pull', $epoch, 5 * MINUTE_IN_SECONDS );
			if ( false === $claim ) {
				return;
			}
			$table = $wpdb->prefix . 'cloudsecurewp_waf_log';
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return;
			}
			// Bounded, read-only count; never SELECT the provider's IP, URL or matched payload.
			$count = $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM (SELECT access_at FROM %i ORDER BY id DESC LIMIT 10000) AS retained WHERE access_at >= %s AND access_at <= %s',
				$table, wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), current_time( 'mysql' )
			) );
			$latest = Atshift_Semantic_Deterrence_Storage::get_settings();
			if ( '' === $wpdb->last_error && null !== $count && is_numeric( $count ) &&
				'1' === ( $latest['external_waf_enabled'] ?? '0' ) && $epoch === (string) $latest['runtime_epoch'] ) {
				update_option( self::OPTION_CLOUDSECURE, array( 'epoch' => $epoch, 'at' => time(), 'count' => min( 10000, max( 0, (int) $count ) ) ), false );
			}
		} catch ( Throwable $error ) {
			// No payloads or raw database errors are persisted.
		} finally {
			if ( null !== $previous ) {
				$wpdb->suppress_errors( $previous );
			}
		}
	}

	public static function maintain() {
		global $wpdb;
		if ( ! self::enabled() ) {
			return;
		}
		$lock = false;
		$previous = null;
		try {
			$previous = $wpdb->suppress_errors( true );
			$settings = Atshift_Semantic_Deterrence_Storage::get_settings();
			$fingerprint = 'external-waf|' . $settings['runtime_epoch'];
			$lock = Atshift_Semantic_Deterrence_Storage::acquire_request_claim( 'external_write', $fingerprint, 5 );
			if ( false !== $lock ) {
				$state = get_option( self::OPTION_SAMPLES, array() );
				if ( is_array( $state ) && ( $state['epoch'] ?? '' ) === (string) $settings['runtime_epoch'] ) {
					$state['rows'] = self::valid_samples( $state['rows'] ?? array(), time() );
					update_option( self::OPTION_SAMPLES, $state, false );
				}
			}
		} catch ( Throwable $error ) {
			// Optional reference maintenance must not stop experiment maintenance.
		} finally {
			if ( false !== $lock ) {
				try {
					Atshift_Semantic_Deterrence_Storage::release_request_claim( 'external_write', $fingerprint, $lock );
				} catch ( Throwable $error ) {
					// The short-lived lock will expire without intervention.
				}
			}
			if ( null !== $previous ) {
				$wpdb->suppress_errors( $previous );
			}
		}
		self::refresh_cloudsecure();
	}

	public static function clear() {
		delete_option( self::OPTION_SAMPLES );
		delete_option( self::OPTION_CLOUDSECURE );
	}
}
