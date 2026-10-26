<?php
/** Local measurement fence for administrator-initiated server block changes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Atshift_Semantic_Deterrence_Server_Blocks {
	const API_VERSION = 1;
	const OPTION = 'atsdn_server_block_measurement';

	private static function read( $fresh = true ) {
		global $wpdb;
		// Read through the database: an in-flight request may hold an older option cache.
		$raw = $fresh ? $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ) : get_option( self::OPTION, null );
		if ( $fresh && '' !== $wpdb->last_error ) { return new WP_Error( 'measurement_state', 'SDN measurement state could not be read.' ); }
		if ( null === $raw ) { return array( 'revision' => '', 'active' => false, 'started_at' => '', 'raw' => null ); }
		$state = is_string( $raw ) && strlen( $raw ) < 1024 ? json_decode( $raw, true, 4 ) : null;
		if ( ! is_array( $state ) || count( $state ) !== 3 || ! is_bool( $state['active'] ?? null ) ||
			! is_string( $state['revision'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $state['revision'] ) ||
			! is_string( $state['started_at'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $state['started_at'] ) ) {
			return new WP_Error( 'measurement_state', 'SDN measurement state is invalid.' );
		}
		$state['raw'] = $raw;
		return $state;
	}

	public static function snapshot( $fresh = true ) {
		$state = self::read( $fresh );
		if ( ! is_wp_error( $state ) ) { unset( $state['raw'] ); }
		return $state;
	}

	public static function interrupted( $before ) {
		$after = self::snapshot();
		return is_wp_error( $before ) || is_wp_error( $after ) || ! is_array( $before ) ||
			! isset( $before['revision'], $before['active'] ) || $before['active'] || $after['active'] ||
			$before['revision'] !== $after['revision'];
	}

	private static function transition( $before, $after ) {
		global $wpdb;
		$json = wp_json_encode( $after );
		if ( null === $before['raw'] ) {
			$ok = add_option( self::OPTION, $json, '', false );
		} else {
			// Compare-and-swap prevents overlapping writers from replacing a pending fence.
			$ok = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $json, self::OPTION, $before['raw'] ) );
		}
		wp_cache_delete( self::OPTION, 'options' );
		return $ok;
	}

	private static function censor( $started_at ) {
		global $wpdb;
		$table = $wpdb->prefix . 'atsdn_events';
		// No IP/UA lookup: conservatively censor all still-open, undecided windows.
		return false !== $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET outcome = 'rate_limited' WHERE responded = 1 AND window_ends_at >= %s AND outcome IN ('unknown', 'observed_ceased')",
			$started_at
		) );
	}

	public static function begin_change() {
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) { return new WP_Error( 'forbidden', 'Administrator access on a single site is required.' ); }
		$before = self::read();
		if ( is_wp_error( $before ) ) { return $before; }
		if ( $before['active'] ) { return new WP_Error( 'measurement_pending', 'Finish the interrupted server block change before starting another.' ); }
		$state = array( 'revision' => bin2hex( random_bytes( 16 ) ), 'active' => true, 'started_at' => current_time( 'mysql' ) );
		if ( ! self::transition( $before, $state ) ) { return new WP_Error( 'measurement_busy', 'SDN measurement could not be protected. No server changes were started.' ); }
		// On failure leave the fence active; never silently restore confident outcomes.
		if ( ! self::censor( $state['started_at'] ) ) { return new WP_Error( 'measurement_censor', 'SDN observation windows could not be marked as interrupted.' ); }
		return $state['revision'];
	}

	public static function finish_change( $token ) {
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) { return new WP_Error( 'forbidden', 'Administrator access on a single site is required.' ); }
		$state = self::read();
		if ( is_wp_error( $state ) ) { return $state; }
		if ( ! is_string( $token ) || '' === $state['revision'] || ! hash_equals( $state['revision'], $token ) ) { return new WP_Error( 'measurement_token', 'The measurement change token does not match.' ); }
		if ( ! $state['active'] ) { return true; }
		if ( ! self::censor( $state['started_at'] ) ) { return new WP_Error( 'measurement_censor', 'SDN observation windows could not be marked as interrupted.' ); }
		$next = array( 'revision' => $state['revision'], 'active' => false, 'started_at' => $state['started_at'] );
		return self::transition( $state, $next ) ? true : new WP_Error( 'measurement_finish', 'SDN measurement remains interrupted. Review the server file and resume measurement.' );
	}
}
