<?php
/**
 * GitHub Release updater.
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Atshift_Semantic_Deterrence_Updater {
	const API_URL = 'https://api.github.com/repos/at-shift/atshift-semantic-deterrence/releases/latest';
	const ASSET_NAME = 'atshift-semantic-deterrence.zip';
	const CACHE_KEY = 'atsdn_github_release_v1';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 10, 4 );
	}

	public static function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $plugin_data, $locales );

		if ( plugin_basename( ATSHIFT_SEMANTIC_DETERRENCE_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::get_release();
		if ( ! $release || ! version_compare( $release['version'], ATSHIFT_SEMANTIC_DETERRENCE_VERSION, '>' ) ) {
			return false;
		}

		return array(
			'slug'         => 'atshift-semantic-deterrence',
			'version'      => $release['version'],
			'url'          => $release['release_url'],
			'package'      => $release['package_url'],
			'tested'       => '6.6',
			'requires_php' => '7.4',
			'autoupdate'   => false,
		);
	}

	public static function verify_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );

		if ( false !== $reply ) {
			return $reply;
		}

		$release = self::get_release();
		if ( ! $release || ! hash_equals( $release['package_url'], (string) $package ) ) {
			return false;
		}

		$checksum = self::fetch_checksum( $release['checksum_url'] );
		if ( ! $checksum ) {
			return new WP_Error( 'atsdn_checksum_unavailable', 'The update package checksum could not be verified.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$downloaded = download_url( $package, 300 );
		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		$actual = hash_file( 'sha256', $downloaded );
		if ( ! is_string( $actual ) || ! hash_equals( $checksum, strtolower( $actual ) ) ) {
			wp_delete_file( $downloaded );
			return new WP_Error( 'atsdn_checksum_mismatch', 'The update package failed its SHA-256 verification.' );
		}

		return $downloaded;
	}

	private static function get_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			self::API_URL,
			array(
				'headers'             => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'atshift-semantic-deterrence/' . ATSHIFT_SEMANTIC_DETERRENCE_VERSION,
				),
				'limit_response_size' => 262144,
				'redirection'         => 3,
				'timeout'             => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true, 8 );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) || empty( $body['html_url'] ) || empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
			return false;
		}

		$version = ltrim( sanitize_text_field( $body['tag_name'] ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
			return false;
		}

		$package_url  = '';
		$checksum_url = '';
		foreach ( $body['assets'] as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			if ( self::ASSET_NAME === $asset['name'] ) {
				$package_url = esc_url_raw( $asset['browser_download_url'] );
			} elseif ( self::ASSET_NAME . '.sha256' === $asset['name'] ) {
				$checksum_url = esc_url_raw( $asset['browser_download_url'] );
			}
		}

		if ( '' === $package_url || '' === $checksum_url ) {
			return false;
		}
		if ( ! self::is_release_asset_url( $package_url ) || ! self::is_release_asset_url( $checksum_url ) ) {
			return false;
		}

		$release = array(
			'version'      => $version,
			'release_url'  => esc_url_raw( $body['html_url'] ),
			'package_url'  => $package_url,
			'checksum_url' => $checksum_url,
		);
		set_site_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	private static function fetch_checksum( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'headers'             => array( 'User-Agent' => 'atshift-semantic-deterrence/' . ATSHIFT_SEMANTIC_DETERRENCE_VERSION ),
				'limit_response_size' => 4096,
				'redirection'         => 5,
				'timeout'             => 15,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		if ( ! preg_match( '/\b([a-f0-9]{64})\b/i', wp_remote_retrieve_body( $response ), $matches ) ) {
			return false;
		}

		return strtolower( $matches[1] );
	}

	private static function is_release_asset_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'github.com' !== ( $parts['host'] ?? '' ) ) {
			return false;
		}

		return 0 === strpos( $parts['path'] ?? '', '/at-shift/atshift-semantic-deterrence/releases/download/' );
	}
}
