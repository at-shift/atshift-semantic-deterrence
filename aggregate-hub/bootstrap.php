<?php
/**
 * Bootstrap for the Semantic Deterrence aggregate hub.
 */

require_once __DIR__ . '/src/SemanticDeterrenceHub.php';

function atsdn_hub_load_config() {
	$config_path = getenv( 'ATSDN_HUB_CONFIG' );
	if ( ! $config_path ) {
		$config_path = dirname( __DIR__ ) . '/semantic-deterrence-hub-config.php';
	}

	if ( ! is_readable( $config_path ) ) {
		throw new RuntimeException( 'Aggregate hub configuration is unavailable.' );
	}

	$config = require $config_path;
	if ( ! is_array( $config ) ) {
		throw new RuntimeException( 'Aggregate hub configuration is invalid.' );
	}

	atsdn_hub_validate_config( $config );

	return $config;
}

function atsdn_hub_validate_config( array $config ) {
	$placeholders = array(
		'replace-with-db-user',
		'replace-with-db-password',
		'replace-with-a-long-random-secret',
		'replace-with-a-long-random-client-secret',
	);

	if ( empty( $config['db']['dsn'] ) || empty( $config['db']['user'] ) || empty( $config['db']['password'] ) ) {
		throw new RuntimeException( 'Database configuration is incomplete.' );
	}

	if ( in_array( (string) $config['db']['user'], $placeholders, true ) || in_array( (string) $config['db']['password'], $placeholders, true ) ) {
		throw new RuntimeException( 'Database placeholders must be replaced.' );
	}

	if ( empty( $config['app_secret'] ) || strlen( (string) $config['app_secret'] ) < 32 || in_array( (string) $config['app_secret'], $placeholders, true ) ) {
		throw new RuntimeException( 'Application secret must be a random value of at least 32 characters.' );
	}

	if ( empty( $config['client_keys'] ) || ! is_array( $config['client_keys'] ) ) {
		throw new RuntimeException( 'At least one client key is required.' );
	}

	foreach ( $config['client_keys'] as $key_id => $secret ) {
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,80}$/', (string) $key_id ) || strlen( (string) $secret ) < 32 || in_array( (string) $secret, $placeholders, true ) ) {
			throw new RuntimeException( 'Every client key must use a bounded ID and a random secret of at least 32 characters.' );
		}
	}

	$integer_rules = array(
		'privacy_min_sites'              => array( 3, 10000, 10 ),
		'privacy_min_events'             => array( 20, 10000000, 100 ),
		'aggregate_cache_seconds'        => array( 30, 3600, 300 ),
		'ingest_rate_limit_per_minute'   => array( 1, 120, 6 ),
		'ingest_rate_limit_per_day'      => array( 1, 500, 24 ),
		'revoke_rate_limit_per_day'      => array( 1, 20, 3 ),
	);

	foreach ( $integer_rules as $key => $rule ) {
		$value = array_key_exists( $key, $config ) ? $config[ $key ] : $rule[2];
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) ) {
			throw new RuntimeException( 'Invalid integer configuration: ' . $key );
		}
		$value = (int) $value;
		if ( $value < $rule[0] || $value > $rule[1] ) {
			throw new RuntimeException( 'Configuration is outside the safe range: ' . $key );
		}
	}
}

function atsdn_hub_create_pdo( $config ) {
	return new PDO(
		$config['db']['dsn'],
		$config['db']['user'],
		$config['db']['password'],
		array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		)
	);
}

function atsdn_hub_run( $route ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: no-referrer' );

	try {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		$body   = '';
		if ( 'POST' === $method ) {
			$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
			if ( $content_length > Atshift_Semantic_Deterrence_Hub::MAX_BODY_BYTES ) {
				http_response_code( 413 );
				echo json_encode( array( 'schema_version' => '1', 'status' => 'rejected', 'message' => 'Request body is too large.' ), JSON_UNESCAPED_SLASHES );
				return;
			}

			$body = file_get_contents( 'php://input', false, null, 0, Atshift_Semantic_Deterrence_Hub::MAX_BODY_BYTES + 1 );
			if ( false === $body || strlen( $body ) > Atshift_Semantic_Deterrence_Hub::MAX_BODY_BYTES ) {
				http_response_code( 413 );
				echo json_encode( array( 'schema_version' => '1', 'status' => 'rejected', 'message' => 'Request body is too large.' ), JSON_UNESCAPED_SLASHES );
				return;
			}
		}

		$config = atsdn_hub_load_config();
		$hub    = new Atshift_Semantic_Deterrence_Hub( atsdn_hub_create_pdo( $config ), $config );
		$result = $hub->handle( $route, $_SERVER, $body );
		http_response_code( $result['code'] );
		foreach ( $result['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( 304 !== $result['code'] ) {
			echo json_encode( $result['body'], JSON_UNESCAPED_SLASHES );
		}
	} catch ( Throwable $error ) {
		error_log( 'Semantic Deterrence Hub: ' . $error->getMessage() );
		http_response_code( 503 );
		echo json_encode(
			array(
				'schema_version' => '1',
				'status'         => 'unavailable',
				'message'        => 'Aggregate hub is temporarily unavailable.',
			),
			JSON_UNESCAPED_SLASHES
		);
	}
}
