<?php
$paths = array(
	getenv( 'ATSDN_HUB_BOOTSTRAP' ),
	getenv( 'HOME' ) ? getenv( 'HOME' ) . '/semantic-deterrence-hub/bootstrap.php' : '',
	dirname( __DIR__, 4 ) . '/bootstrap.php',
	dirname( __DIR__, 4 ) . '/../semantic-deterrence-hub/bootstrap.php',
	dirname( __DIR__, 6 ) . '/semantic-deterrence-hub/bootstrap.php',
);
foreach ( $paths as $path ) {
	if ( $path && is_readable( $path ) ) {
		require_once $path;
		atsdn_hub_run( '/v1/events/batch' );
		return;
	}
}
http_response_code( 503 );
header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode( array( 'schema_version' => '1', 'status' => 'unavailable', 'message' => 'Aggregate hub bootstrap is unavailable.' ) );
return;
