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

$aggregate_db = new PDO( 'sqlite::memory:' );
$aggregate_db->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
$aggregate_db->exec(
	'CREATE TABLE atsdn_hub_events (
		observed_date TEXT NOT NULL,
		site_key_hash TEXT NOT NULL,
		variant TEXT NOT NULL,
		experiment_arm TEXT NOT NULL,
		response_catalog_id TEXT NOT NULL,
		response_fingerprint TEXT NOT NULL,
		outcome TEXT NOT NULL,
		event_count INTEGER NOT NULL,
		follow_up_count INTEGER NOT NULL
	)'
);
$aggregate_db->exec(
	'CREATE TABLE atsdn_hub_cache (
		cache_key TEXT PRIMARY KEY,
		etag TEXT NOT NULL,
		body_json TEXT NOT NULL,
		generated_at TEXT NOT NULL,
		expires_at TEXT NOT NULL
	)'
);
$aggregate_db->exec( 'CREATE TABLE atsdn_hub_state (id INTEGER PRIMARY KEY, aggregate_generation INTEGER NOT NULL, updated_at TEXT NOT NULL)' );
$aggregate_db->exec( "INSERT INTO atsdn_hub_state (id, aggregate_generation, updated_at) VALUES (1, 1, '2026-01-01 00:00:00')" );

$insert = $aggregate_db->prepare(
	'INSERT INTO atsdn_hub_events
	(observed_date, site_key_hash, variant, experiment_arm, response_catalog_id, response_fingerprint, outcome, event_count, follow_up_count)
	VALUES (:observed_date, :site_key_hash, :variant, :experiment_arm, :response_catalog_id, :response_fingerprint, :outcome, :event_count, 0)'
);
foreach ( range( 1, 10 ) as $site_number ) {
	foreach (
		array(
			array( 'control_generic', 'control_generic:plain:recorded', 'observed_ceased', 6 ),
			array( 'control_generic', 'control_generic:plain:recorded', 'continued_same', 4 ),
			array( 'combined_notice', 'response_5_combined_notice:plain:recorded', 'observed_ceased', 8 ),
			array( 'combined_notice', 'response_5_combined_notice:plain:recorded', 'continued_same', 2 ),
		) as $aggregate_row
	) {
		$insert->execute(
			array(
				':observed_date'        => gmdate( 'Y-m-d' ),
				':site_key_hash'        => hash( 'sha256', 'site-' . $site_number ),
				':variant'              => $aggregate_row[0],
				':experiment_arm'       => 'fixed_series',
				':response_catalog_id'  => $aggregate_row[1],
				':response_fingerprint' => hash( 'sha256', $aggregate_row[1] ),
				':outcome'              => $aggregate_row[2],
				':event_count'          => $aggregate_row[3],
			)
		);
	}
}

$aggregate_hub = new Atshift_Semantic_Deterrence_Hub( $aggregate_db, $config );
$current       = $aggregate_hub->handle( '/v1/aggregates/current', array( 'REQUEST_METHOD' => 'GET' ), '' );
atsdn_test_assert( 200 === $current['code'], 'current aggregate endpoint should succeed' );
atsdn_test_assert( 2 === count( $current['body']['variants'] ), 'current aggregate should include thresholded response comparisons' );
atsdn_test_assert( 'combined_notice' === $current['body']['best_variant']['variant'], 'best response should exclude generic 403 control' );
atsdn_test_assert( 80.0 === $current['body']['best_variant']['non_continuation_rate'], 'best response should expose the observed rate' );

echo 'PASS Hub validation regression checks' . PHP_EOL;
