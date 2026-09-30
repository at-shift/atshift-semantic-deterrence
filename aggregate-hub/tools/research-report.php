<?php
/** Output private aggregate diagnostics without changing Hub data or caches. */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/src/ResearchReport.php';

$arguments = array_slice( $argv, 1 );
if ( array( '--help' ) === $arguments ) {
	echo "Usage: php research-report.php [--days=1..90]\nPrivate operator-only JSON. Default: --days=30.\n";
	exit( 0 );
}
if ( count( $arguments ) > 1 || ( $arguments && ! preg_match( '/^--days=([1-9]|[1-8][0-9]|90)$/', $arguments[0], $matches ) ) ) {
	fwrite( STDERR, "Usage: php research-report.php [--days=1..90]\n" );
	exit( 1 );
}
$days = $arguments ? (int) $matches[1] : 30;
$db = null;
try {
	$config = atsdn_hub_load_config();
	$db = atsdn_hub_create_pdo( $config );
	if ( 'mysql' !== $db->getAttribute( PDO::ATTR_DRIVER_NAME ) ) {
		throw new RuntimeException( 'This command requires MySQL.' );
	}
	$db->exec( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
	$db->exec( 'SET TRANSACTION READ ONLY' );
	$db->beginTransaction();
	$report = ( new Atshift_Semantic_Deterrence_Research_Report( $db, $config ) )->build( $days );
	$db->rollBack();
	$json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	fwrite( STDERR, "Private report: includes statistics below public privacy thresholds. Keep outside the web root.\n" );
	echo $json . PHP_EOL;
} catch ( Throwable $error ) {
	if ( $db && $db->inTransaction() ) {
		$db->rollBack();
	}
	// PDO errors can include connection details; do not print credentials or raw errors.
	fwrite( STDERR, "Research report failed. Check database access, schema and configuration on the Hub server.\n" );
	exit( 1 );
}
