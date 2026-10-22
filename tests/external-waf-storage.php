<?php
/** Verify the real storage/export boundary independently of adapter test doubles. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
$options = array(
	'atsdn_external_waf_samples' => array( 'rows' => array( array( 'provider' => 'bbq', 'category' => 'code_injection', 'at' => time() ) ) ),
	'atsdn_external_waf_cloudsecure' => array( 'count' => 5000 ),
	'cloudsecure-provider-owned' => 'untouched',
);
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function delete_option( $key ) { global $options; unset( $options[$key] ); }
function wp_parse_args( $values, $defaults ) { return array_merge( $defaults, $values ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $format ) { return 'timestamp' === $format ? time() : gmdate( 'Y-m-d H:i:s' ); }
class External_Storage_Test_DB {
	public $prefix = 'wp_';
	public $queries = array();
	public function prepare( $query, ...$values ) { return $query; }
	public function get_results( $query, $format ) { $this->queries[] = $query; return array(); }
	public function query( $query ) { $this->queries[] = $query; return 1; }
}
$wpdb = new External_Storage_Test_DB();
require dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-storage.php';
require dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-external-waf.php';
function boundary_assert( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$storage = new Atshift_Semantic_Deterrence_Storage();
boundary_assert( '0' === Atshift_Semantic_Deterrence_Storage::get_settings()['external_waf_enabled'], 'real storage default remains opt-out' );
$batch = $storage->get_anonymous_aggregate_batch();
boundary_assert( array() === $batch['events'], 'reference-only data cannot become experiment rows or shared events' );
boundary_assert( false === strpos( json_encode( $batch ), 'cloudsecure' ) && false === strpos( json_encode( $batch ), 'bbq' ), 'no external source data in export' );
boundary_assert( 1 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], 'wp_atsdn_events' ), 'export reads only experiment table' );
$storage->delete_all_events();
boundary_assert( array( 'cloudsecure-provider-owned' => 'untouched' ) === $options, 'real local deletion clears owned reference records only' );
boundary_assert( 'TRUNCATE TABLE wp_atsdn_events' === $wpdb->queries[1], 'provider table never altered' );
echo 'PASS real storage defaults, export isolation and deletion boundary' . PHP_EOL;
