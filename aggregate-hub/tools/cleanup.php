<?php
/**
 * Run bounded Hub retention cleanup outside public request handling.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/bootstrap.php';

$config = atsdn_hub_load_config();
$hub    = new Atshift_Semantic_Deterrence_Hub( atsdn_hub_create_pdo( $config ), $config );
$hub->cleanup();

echo 'Semantic Deterrence Hub cleanup completed.' . PHP_EOL;
