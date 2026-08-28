<?php
/**
 * Install Semantic Deterrence Hub schema into the configured MySQL database.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/bootstrap.php';

$config_path = getenv( 'ATSDN_HUB_CONFIG' );
if ( ! $config_path ) {
	$config_path = dirname( __DIR__, 2 ) . '/semantic-deterrence-hub-config.php';
}

if ( ! is_readable( $config_path ) ) {
	fwrite( STDERR, "Config file is not readable: {$config_path}\n" );
	exit( 1 );
}

$config = require $config_path;
atsdn_hub_validate_config( $config );

$schema_path = dirname( __DIR__ ) . '/schema.sql';
$schema      = file_get_contents( $schema_path );
if ( false === $schema ) {
	fwrite( STDERR, "Schema file is not readable: {$schema_path}\n" );
	exit( 1 );
}

$pdo = new PDO(
	$config['db']['dsn'],
	$config['db']['user'],
	$config['db']['password'],
	array(
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES   => false,
	)
);

$statements = array_filter( array_map( 'trim', explode( ';', $schema ) ) );
foreach ( $statements as $statement ) {
	$pdo->exec( $statement );
}

$event_indexes = $pdo->query( "SHOW INDEX FROM atsdn_hub_events WHERE Key_name = 'received_at'" )->fetchAll();
if ( empty( $event_indexes ) ) {
	$pdo->exec( 'ALTER TABLE atsdn_hub_events ADD KEY received_at (received_at)' );
}

$legacy_payload_index = $pdo->query( "SHOW INDEX FROM atsdn_hub_batches WHERE Key_name = 'payload_hash'" )->fetchAll();
if ( ! empty( $legacy_payload_index ) ) {
	$pdo->exec( 'ALTER TABLE atsdn_hub_batches DROP INDEX payload_hash' );
}

$key_payload_index = $pdo->query( "SHOW INDEX FROM atsdn_hub_batches WHERE Key_name = 'key_payload_hash'" )->fetchAll();
if ( empty( $key_payload_index ) ) {
	$pdo->exec( 'ALTER TABLE atsdn_hub_batches ADD UNIQUE KEY key_payload_hash (key_id, payload_hash)' );
}

echo 'Semantic Deterrence Hub schema installed.' . PHP_EOL;
