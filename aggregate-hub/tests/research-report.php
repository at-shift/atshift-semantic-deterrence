<?php
/** Regression tests for private diagnostics, sample accounting and identifier exclusion. */

require_once dirname( __DIR__ ) . '/src/ResearchReport.php';

function report_assert( $ok, $message ) {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$db = new PDO( 'sqlite::memory:' );
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$db->exec( 'CREATE TABLE atsdn_hub_events (
	site_key_hash TEXT, observed_date TEXT, received_at TEXT, variant TEXT,
	experiment_arm TEXT, response_catalog_id TEXT, response_fingerprint TEXT,
	plugin_version TEXT, http_status INTEGER, category TEXT, level INTEGER,
	outcome TEXT, event_count INTEGER, follow_up_count INTEGER)' );
$config = array( 'client_keys' => array( 'private-key-identifier' => 'private-secret' ), 'privacy_min_sites' => 10, 'privacy_min_events' => 100 );
$builder = new Atshift_Semantic_Deterrence_Research_Report( $db, $config );
$empty = $builder->build();
report_assert( 0 === $empty['snapshot_summary']['total_events'], 'empty DB has zero total' );
report_assert( null === $empty['site_contribution']['median_events_per_site'], 'empty DB has no median' );
report_assert( array() === $empty['response_groups']['rows'], 'empty DB has no responses' );

$insert = $db->prepare( 'INSERT INTO atsdn_hub_events VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' );
$today = gmdate( 'Y-m-d' );
foreach ( array(
	array( 'private-site-a', 'control_generic', 'fixed_series', 'observed_ceased', 6, 403 ),
	array( 'private-site-a', 'control_generic', 'fixed_series', 'continued_same', 4, 403 ),
	array( 'private-site-b', 'combined_notice', 'fixed_series', 'observed_ceased', 8, 403 ),
	array( 'private-site-b', 'combined_notice', 'fixed_series', 'continued_alternate', 2, 403 ),
	array( 'private-site-b', 'combined_notice', 'fixed_series', 'unknown', 1, 403 ),
	array( 'private-site-b', 'combined_notice', 'fixed_series', 'rate_limited', 1, 403 ),
	array( 'private-site-b', 'combined_notice', 'sequence_series', 'intensified', 3, 403 ),
	array( 'private-site-b', 'combined_notice', '', 'observed_ceased', 2, 429 ),
	array( 'private-site-b', 'observe_only', '', 'unknown', 20, 404 ),
) as $event ) {
	$insert->execute( array( $event[0], $today, $today . ' 12:00:00', $event[1], $event[2], $event[1] . ':' . $event[5], hash( 'sha256', $event[1] . $event[5] ), '0.1.5', $event[5], 'secret_config', 3, $event[3], $event[4], 0 ) );
}
$insert->execute( array( 'expired-site', gmdate( 'Y-m-d', time() - 31 * 86400 ), $today . ' 12:00:00', 'combined_notice', 'fixed_series', 'expired', 'expired', '0.1.1', 403, 'secret_config', 3, 'observed_ceased', 9999, 0 ) );
$before = $db->query( 'SELECT * FROM atsdn_hub_events' )->fetchAll( PDO::FETCH_ASSOC );
$db->exec( 'PRAGMA query_only = ON' );
$db->beginTransaction();
$report = $builder->build( 30 );
$db->rollBack();
report_assert( $before === $db->query( 'SELECT * FROM atsdn_hub_events' )->fetchAll( PDO::FETCH_ASSOC ), 'report does not modify event rows' );
report_assert( 47 === $report['snapshot_summary']['total_events'], 'old rows are excluded, snapshots are summed once' );
report_assert( 2 === $report['snapshot_summary']['site_count'], 'active sites are distinct' );
report_assert( 20 === $report['snapshot_summary']['observation_only_events'], 'observation exposure remains separate' );
report_assert( 4 === count( $report['response_groups']['rows'] ), 'arms and content fingerprints stay separate' );
$combined = array_values( array_filter( $report['response_groups']['rows'], function ( $row ) {
	return 'combined_notice' === $row['variant'] && 'fixed_series' === $row['experiment_arm'];
} ) )[0];
report_assert( 80.0 === $combined['non_continuation_rate_percent'], 'rate excludes indeterminate outcomes' );
report_assert( 16.67 === $combined['indeterminate_rate_percent'], 'unknown and rate-limited fraction is reported' );
report_assert( 9 === $combined['missing_sites'] && 88 === $combined['missing_events'], 'threshold deficits match exact response group' );
report_assert( false === $combined['public_threshold_met'], 'private rows below threshold remain flagged' );
report_assert( 23.5 === $report['site_contribution']['median_events_per_site'], 'even-site median is correct' );
report_assert( 37 === $report['site_contribution']['max_events_per_site'], 'largest contribution is correct' );
report_assert( 1 === count( $report['controls_by_arm_and_status']['rows'] ), 'control subset has no semantic or observation events' );
$json = json_encode( $report );
foreach ( array( 'private-site-a', 'private-site-b', 'private-key-identifier', 'private-secret', 'expired-site', 'site_key_hash' ) as $forbidden ) {
	report_assert( false === strpos( $json, $forbidden ), 'output excludes ' . $forbidden );
}
foreach ( array( 0, 91, '30' ) as $invalid_days ) {
	try {
		$builder->build( $invalid_days );
		report_assert( false, 'invalid window must fail' );
	} catch ( InvalidArgumentException $error ) {
	}
}
$db->exec( 'PRAGMA query_only = OFF' );
foreach ( range( 1, 10 ) as $site ) {
	$insert->execute( array( 'threshold-site-' . $site, $today, $today . ' 12:00:00', 'control_generic', 'fixed_series', 'control_threshold', hash( 'sha256', 'control-threshold' ), '0.1.5', 403, 'secret_config', 3, 'observed_ceased', 10, 0 ) );
}
$ready = $builder->build();
$eligible = array_values( array_filter( $ready['response_groups']['rows'], function ( $row ) { return $row['public_threshold_met']; } ) );
report_assert( 1 === count( $eligible ), 'only complete groups meet publication threshold' );
report_assert( 0 === $eligible[0]['missing_sites'] && 0 === $eligible[0]['missing_events'], 'threshold deficits stop at zero' );
require_once dirname( __DIR__ ) . '/src/SemanticDeterrenceHub.php';
$public = ( new Atshift_Semantic_Deterrence_Hub( $db, $config ) )->build_variants_body();
report_assert( count( $public['variants'] ) === count( $eligible ), 'readiness matches actual public Hub grouping' );
report_assert( $public['variants'][0]['response_fingerprint'] === $eligible[0]['response_fingerprint'], 'same content passes public and private grouping' );
report_assert( $public['variants'][0]['total_events'] === $eligible[0]['total_events'], 'public and private event totals agree' );
for ( $i = 0; $i < 1001; $i++ ) {
	$insert->execute( array( 'private-site-b', $today, $today . ' 12:00:00', 'policy_notice', 'fixed_series', 'response_1', hash( 'sha256', (string) $i ), '0.1.5', 403, 'secret_config', 3, 'observed_ceased', 1, 0 ) );
}
$bounded = $builder->build();
report_assert( true === $bounded['response_groups']['truncated'], 'high-cardinality groups are explicitly flagged' );
report_assert( 1000 === count( $bounded['response_groups']['rows'] ), 'group output is bounded' );
echo 'PASS private research report regression checks' . PHP_EOL;
