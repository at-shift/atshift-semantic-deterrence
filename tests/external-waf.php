<?php
/** Isolated external WAF adapters: no real database, requests, or attack execution. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'BBQ_VERSION', 'test-fixture' );
$options = array();
$cache = array();
$hooks = array();
$writes = 0;
$fail_write = false;
function add_action( $name, $callback, $priority = 10, $args = 1 ) { global $hooks; $hooks[$name] = array( $callback, $priority, $args ); }
function get_option( $name, $default = false ) { global $options; return $options[$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) {
	global $options, $writes, $fail_write;
	if ( $fail_write ) { return false; }
	waf_assert( false === $autoload, 'reference options never autoload' );
	$options[$name] = $value; ++$writes; return true;
}
function delete_option( $name ) { global $options; unset( $options[$name] ); }
function wp_cache_add( $key, $value, $group, $ttl ) { global $cache; if ( isset( $cache[$key] ) ) { return false; } $cache[$key] = true; return true; }
function wp_date( $format, $at ) { return gmdate( $format, $at ); }
function current_time( $format ) { return gmdate( 'Y-m-d H:i:s' ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return esc_html( $text ); }
function sanitize_html_class( $text ) { return preg_replace( '/[^a-z0-9_-]/i', '', $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
class Atshift_Semantic_Deterrence_Storage {
	public static $settings = array( 'external_waf_enabled' => '0', 'runtime_epoch' => 'test-epoch' );
	public static $claims = array();
	public static $fail_claim = false;
	public static $fail_release = false;
	public static function get_settings() { return self::$settings; }
	public static function acquire_request_claim( $namespace, $fingerprint, $ttl ) {
		if ( self::$fail_claim ) { throw new RuntimeException( 'private-database-failure' ); }
		$key = $namespace . ':' . $fingerprint;
		if ( isset( self::$claims[$key] ) ) { return false; }
		self::$claims[$key] = $ttl; return 'owned-token';
	}
	public static function release_request_claim( $namespace, $fingerprint, $token ) {
		if ( self::$fail_release ) { throw new RuntimeException( 'private-release-failure' ); }
		unset( self::$claims[$namespace . ':' . $fingerprint] );
	}
}
class Waf_Test_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	public $suppressed = false;
	public $table_exists = true;
	public $count = 42;
	public $fail = false;
	public function suppress_errors( $value ) { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $prepared ) {
		$this->queries[] = $prepared;
		if ( $this->fail ) { $this->last_error = 'raw-provider-error-secret'; return null; }
		if ( 0 === strpos( $prepared[0], 'SHOW TABLES' ) ) { return $this->table_exists ? 'wp_cloudsecurewp_waf_log' : null; }
		return $this->count;
	}
}
$wpdb = new Waf_Test_DB();
require dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-external-waf.php';
require dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-admin.php';
function waf_assert( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function waf_reset_claims() { global $cache; $cache = array(); Atshift_Semantic_Deterrence_Storage::$claims = array(); }
function waf_render( $enabled ) {
	$admin = new Atshift_Semantic_Deterrence_Admin( new Atshift_Semantic_Deterrence_Storage() );
	$method = new ReflectionMethod( $admin, 'render_external_waf_reference' );
	$method->setAccessible( true );
	ob_start(); $method->invoke( $admin, array( 'external_waf_enabled' => $enabled ) ); return ob_get_clean();
}
$event = array( 'provider' => 'bbq', 'category' => 'code_injection', 'action' => 'block_decision' );
Atshift_Semantic_Deterrence_External_Waf::init();
waf_assert( array( 'bbq_response', 'atsdn_external_waf_observation' ) === array_keys( $hooks ), 'only internal actions, no public ingestion endpoint' );
waf_assert( false === Atshift_Semantic_Deterrence_External_Waf::observe( $event ), 'off by default' );
Atshift_Semantic_Deterrence_External_Waf::observe_bbq( array( 'base64_decode(private-marker)' ) );
Atshift_Semantic_Deterrence_External_Waf::maintain();
waf_assert( 0 === $writes && array() === $wpdb->queries, 'disabled adapter makes no writes or provider queries' );
waf_assert( '' === waf_render( '0' ), 'disabled section absent' );
foreach ( array(
	array_merge( $event, array( 'ip' => '192.0.2.1' ) ),
	array_merge( $event, array( 'url' => '/private' ) ),
	array_merge( $event, array( 'at' => time() ) ),
	array_merge( $event, array( 'category' => array() ) ),
	array_merge( $event, array( 'provider' => 'arbitrary' ) ),
	array_merge( $event, array( 'action' => 'executed' ) ),
	array( 'provider' => 'bbq' ), null, 'payload',
) as $invalid ) {
	waf_assert( false === Atshift_Semantic_Deterrence_External_Waf::normalize( $invalid ), 'strict categorical boundary rejects extra or malformed fields' );
}
Atshift_Semantic_Deterrence_Storage::$settings['external_waf_enabled'] = '1';
for ( $i = 0; $i < 200; ++$i ) {
	$cache = array(); // Simulate hosts without a persistent object cache.
	Atshift_Semantic_Deterrence_External_Waf::observe_bbq( array( 'base64_decode(private-marker)' ) );
}
$state = $options[Atshift_Semantic_Deterrence_External_Waf::OPTION_SAMPLES];
waf_assert( 1 === count( $state['rows'] ) && 1 === $writes, 'burst is one sample, not 200 event rows' );
waf_assert( 'code_injection' === $state['rows'][0]['category'], 'BBQ fragment converted to category' );
waf_assert( false === strpos( json_encode( $options ), 'private-marker' ), 'raw match not persisted' );
waf_assert( 60 === max( Atshift_Semantic_Deterrence_Storage::$claims ), 'sample claim lasts 60 seconds' );
$read_writes = $writes;
$reference = Atshift_Semantic_Deterrence_External_Waf::get_reference();
waf_assert( 1 === $reference['samples']['bbq'] && null === $reference['cloudsecure'], 'bounded sample reference' );
waf_assert( $writes === $read_writes && array() === $wpdb->queries, 'dashboard read does not maintain or query provider' );
Atshift_Semantic_Deterrence_External_Waf::refresh_cloudsecure();
waf_assert( array() === $wpdb->queries, 'inactive CloudSecure never queried' );
if ( ! class_exists( 'CloudSecureWP_Waf', false ) ) { class CloudSecureWP_Waf {} }
Atshift_Semantic_Deterrence_External_Waf::refresh_cloudsecure();
Atshift_Semantic_Deterrence_External_Waf::refresh_cloudsecure();
waf_assert( 2 === count( $wpdb->queries ), 'one table check and one bounded count per five minute claim' );
waf_assert( false === $wpdb->suppressed, 'database error display setting restored' );
$query = $wpdb->queries[1][0];
waf_assert( false !== strpos( $query, 'LIMIT 10000' ) && false !== strpos( $query, 'access_at' ), 'provider scan bounded and time filtered' );
waf_assert( ! preg_match( '/\b(ip|url|matched|attack)\b/i', $query ), 'no sensitive source columns selected' );
waf_assert( 42 === Atshift_Semantic_Deterrence_External_Waf::get_reference()['cloudsecure'], 'count snapshot available' );
$html = waf_render( '1' );
waf_assert( false !== strpos( $html, 'CloudSecure' ) && false !== strpos( $html, 'BBQ' ) && false === strpos( $html, '<form' ), 'read-only source reference renders without controls' );
$rows = array_fill( 0, 6002, array_merge( $event, array( 'at' => time() ) ) );
$rows[] = array_merge( $event, array( 'at' => time() - DAY_IN_SECONDS - 1 ) );
$rows[] = array_merge( $event, array( 'at' => time() + 60 ) );
$rows[] = array_merge( $event, array( 'at' => time(), 'payload' => 'private-marker' ) );
$options[Atshift_Semantic_Deterrence_External_Waf::OPTION_SAMPLES]['rows'] = $rows;
$count = Atshift_Semantic_Deterrence_External_Waf::get_reference()['samples']['bbq'];
waf_assert( 5997 === $count, 'row cap, expired/future rows and unbounded fields filtered' );
Atshift_Semantic_Deterrence_Storage::$settings['runtime_epoch'] = 'new-epoch';
waf_assert( array( 'cloudsecure' => null, 'samples' => array() ) === Atshift_Semantic_Deterrence_External_Waf::get_reference(), 'old runtime epoch never reappears after deletion' );
Atshift_Semantic_Deterrence_Storage::$settings['runtime_epoch'] = 'test-epoch';
$options[Atshift_Semantic_Deterrence_External_Waf::OPTION_CLOUDSECURE]['at'] = time() - 901;
waf_assert( null === Atshift_Semantic_Deterrence_External_Waf::get_reference()['cloudsecure'], 'stale snapshots not presented as current' );
waf_reset_claims(); $wpdb->fail = true;
Atshift_Semantic_Deterrence_External_Waf::refresh_cloudsecure();
waf_assert( false === $wpdb->suppressed && false === strpos( json_encode( $options ), 'raw-provider-error-secret' ), 'provider errors do not leak or overwrite source' );
$wpdb->fail = false; $wpdb->last_error = '';
waf_reset_claims(); $fail_write = true;
waf_assert( false === Atshift_Semantic_Deterrence_External_Waf::observe( $event ), 'failed persistence not reported as recorded' );
$fail_write = false;
Atshift_Semantic_Deterrence_Storage::$fail_claim = true;
Atshift_Semantic_Deterrence_External_Waf::observe_bbq( array( 'select private-marker' ) );
Atshift_Semantic_Deterrence_External_Waf::maintain();
Atshift_Semantic_Deterrence_Storage::$fail_claim = false;
waf_reset_claims(); Atshift_Semantic_Deterrence_Storage::$fail_release = true;
Atshift_Semantic_Deterrence_External_Waf::observe_bbq( array( 'select private-marker' ) );
Atshift_Semantic_Deterrence_External_Waf::maintain();
Atshift_Semantic_Deterrence_Storage::$fail_release = false;
waf_assert( false === $wpdb->suppressed, 'error-display setting restored after claim and release failures' );
$options['cloudsecure-provider-owned'] = 'untouched';
Atshift_Semantic_Deterrence_External_Waf::clear();
waf_assert( array( 'cloudsecure-provider-owned' => 'untouched' ) === $options, 'clear affects only owned reference options' );
echo 'PASS external WAF opt-in, bounded sampling, privacy, snapshots, fail-soft and read-only UI checks' . PHP_EOL;
