<?php
/**
 * Focused regression checks for Hub validation and safe configuration defaults.
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

function atsdn_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function atsdn_test_call_private( $object, $method, array $arguments ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $arguments );
}

$config = array(
	'db' => array(
		'dsn'      => 'sqlite::memory:',
		'user'     => 'test-user',
		'password' => str_repeat( 'd', 32 ),
	),
	'app_secret' => str_repeat( 'a', 32 ),
	'client_keys' => array(
		'site-test-key' => str_repeat( 'c', 32 ),
	),
	'privacy_min_sites' => 10,
	'privacy_min_events' => 100,
	'aggregate_cache_seconds' => 300,
	'ingest_rate_limit_per_minute' => 6,
	'ingest_rate_limit_per_day' => 24,
	'revoke_rate_limit_per_day' => 3,
);

atsdn_hub_validate_config( $config );
$hub = new Atshift_Semantic_Deterrence_Hub( new PDO( 'sqlite::memory:' ), $config );

$event = array(
	'schema_version'       => '1',
	'site_pseudonym'       => '123e4567-e89b-42d3-a456-426614174000',
	'plugin_version'       => '0.1.1',
	'policy_version'       => 'local-2026-08-27.1',
	'variant'              => 'combined_notice',
	'experiment_arm'       => 'fixed_series',
	'response_fingerprint' => str_repeat( 'a', 64 ),
	'response_catalog_id'  => 'response_5_combined_notice:plain:recorded',
	'http_status'          => 403,
	'category'             => 'secret_config',
	'level'                => 3,
	'outcome'              => 'observed_ceased',
	'event_count'          => 4,
	'follow_up_count'      => 0,
	'time_bucket'          => '0-10m',
	'observed_date'        => gmdate( 'Y-m-d' ),
);
$payload = array(
	'schema_version' => '1',
	'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
	'days'           => 30,
	'events'         => array( $event ),
);

$result = atsdn_test_call_private( $hub, 'validate_batch_payload', array( $payload ) );
atsdn_test_assert( true === $result['ok'], 'valid plugin payload should pass' );

$invalid = $payload;
$invalid['domain'] = 'example.test';
$result = atsdn_test_call_private( $hub, 'validate_batch_payload', array( $invalid ) );
atsdn_test_assert( false === $result['ok'], 'unknown or forbidden top-level fields should fail' );

$invalid = $payload;
$invalid['events'][0]['response_catalog_id'] = 'example.test';
$result = atsdn_test_call_private( $hub, 'validate_batch_payload', array( $invalid ) );
atsdn_test_assert( false === $result['ok'], 'free-text catalog identifiers should fail' );

$invalid = $payload;
$invalid['events'][0]['site_pseudonym'] = 'site-one';
$result = atsdn_test_call_private( $hub, 'validate_batch_payload', array( $invalid ) );
atsdn_test_assert( false === $result['ok'], 'unbounded site pseudonyms should fail' );

$invalid = $config;
$invalid['privacy_min_sites'] = 0;
try {
	atsdn_hub_validate_config( $invalid );
	atsdn_test_assert( false, 'unsafe privacy threshold should fail closed' );
} catch ( RuntimeException $error ) {
	atsdn_test_assert( true, 'unsafe threshold rejected' );
}

echo 'PASS Hub validation regression checks' . PHP_EOL;
