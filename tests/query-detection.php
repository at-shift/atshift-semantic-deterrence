<?php
/** Inert request fixtures: never fetch URLs or evaluate the supplied PHP strings. */
define( 'ABSPATH', __DIR__ );
$context = array();
$filter_calls = array();
function is_admin() { global $context; return ! empty( $context['admin'] ); }
function wp_doing_cron() { global $context; return ! empty( $context['cron'] ); }
function is_user_logged_in() { global $context; return ! empty( $context['manager'] ); }
function current_user_can( $cap ) { global $context; return ! empty( $context['manager'] ); }
function is_404() { return false; }
function is_search() { return false; }
function is_archive() { return false; }
function is_singular() { return false; }
function is_front_page() { return true; }
function wp_unslash( $value ) { return stripslashes( $value ); }
function wp_parse_url( $value, $component ) { return parse_url( $value, $component ); }
function wp_check_invalid_utf8( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
function current_time( $format ) { return '20261022'; }
function get_option( $key, $default = '' ) { return 'fixture-daily-salt'; }
function apply_filters( $name, $value, ...$arguments ) {
	global $filter_calls;
	$filter_calls[] = array( $name, $value, $arguments );
	return $value;
}
class Query_Response_Sent extends RuntimeException {}
function status_header( $status ) { throw new Query_Response_Sent( 'response', $status ); }
class Atshift_Semantic_Deterrence_Storage {
	public static $settings;
	public static $claims = array();
	public static $budget_available = true;
	public static $follow_ups = 0;
	public $events = array();
	public static function ensure_secret() { return 'fixture-local-secret'; }
	public static function get_settings() { return self::$settings; }
	public static function acquire_request_claim( $scope, $key, $seconds ) {
		$key = $scope . '|' . $key;
		if ( isset( self::$claims[$key] ) ) { return false; }
		self::$claims[$key] = 'fixture-claim';
		return 'fixture-claim';
	}
	public static function release_request_claim( $scope, $key, $claim ) { unset( self::$claims[$scope . '|' . $key] ); }
	public static function consume_request_budget( $scope, $epoch, $limit ) { return self::$budget_available; }
	public function mark_follow_up( $source, $category, $level ) { return self::$follow_ups; }
	public function has_recent_continuation( $source ) { return false; }
	public function record_event( $event ) { $this->events[] = $event; }
}
require_once dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-detector.php';
require_once dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-plugin.php';
function query_assert( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$settings = array(
	'sensitivity' => 'standard', 'excluded_paths' => '', 'excluded_ips' => '',
	'custom_high_confidence_paths' => '', 'runtime_epoch' => 'fixture',
	'policy_version' => 'fixture-policy', 'mode' => 'observe', 'experiment_enabled' => '0',
	'preferred_variant' => 'combined_notice', 'country_header_source' => 'disabled',
);
function query_request( $query, $path = '/', $fallback = false, $method = 'GET' ) {
	$_SERVER = array(
		'REQUEST_URI' => $path . '?' . $query,
		'REMOTE_ADDR' => '192.0.2.10', 'REQUEST_METHOD' => $method,
		'HTTP_USER_AGENT' => 'inert-fixture', 'HTTP_ACCEPT_LANGUAGE' => 'en',
	);
	if ( ! $fallback ) { $_SERVER['QUERY_STRING'] = $query; }
}
function query_classify( $query, $settings, $path = '/', $fallback = false, $method = 'GET' ) {
	query_request( $query, $path, $fallback, $method );
	return ( new Atshift_Semantic_Deterrence_Detector( $settings ) )->classify_current_request();
}

// User-supplied attack structure with site/marker identifiers replaced by inert fixtures.
$probe = '+config-create+/?=passthru(chr(101).chr(99).chr(104).chr(111))?+/tmp/wp-pear-rce-fixture.php';
$template_probe = "a=fetch&templateFile=public/index&prefix=''&content=phpfile_put_contents('fixture.txt',base64_decode('RklYVFVSRQ=='));/php";
$route_probe = 's=/index/index/name/$%7B@phpinfo()%7D';
$archive_probe = 's=?=/**/file_put_contents(runtime/archive/fixture.php,base64_decode(RklYVFVSRQ==));?';
$tag_probe = 'tag/index&tag=fixture/Index:if(1)(usort/*%3e*/(post/*%3e*/(1),create_function/*%3e*/(post/*%3e*/(2),post/*%3e*/(3))))://)(123)/fixture/Index:if&tagstpl=news.html';
$drupal_probe = 'element_parents=account/mail/%23value&ajax_form=1&_wrapper_format=drupal_ajax';
$positive = array(
	'original structure' => $probe,
	'URL encoded' => rawurlencode( $probe ),
	'double encoded' => rawurlencode( rawurlencode( $probe ) ),
	'upper case' => strtoupper( $probe ),
	'space delimiters' => str_replace( '+', '%20', $probe ),
	'additional argument' => 'a=1&' . $probe,
	'PEAR ampersand arguments' => '+config-create+/&<?=system(chr(105).chr(100))?>+/tmp/fixture.php',
	'exec call' => '+config-create+/?=exec(chr(105))?+/tmp/fixture.php',
	'shell_exec call' => '+config-create+/?=shell_exec(chr(105))?+/tmp/fixture.php',
	'template write' => $template_probe,
	'route expression' => $route_probe,
	'archive write' => $archive_probe,
	'archive write with root parameter' => 'p=/&' . $archive_probe,
	'legacy template callback' => $tag_probe,
);
foreach ( $positive as $label => $query ) {
	foreach ( array( 'cautious', 'standard', 'strong' ) as $sensitivity ) {
		$current = $settings;
		$current['sensitivity'] = $sensitivity;
		$result = query_classify( $query, $current );
		query_assert( 2 === $result['level'] && 'other_high_confidence' === $result['category'], $label . ' / ' . $sensitivity );
		query_assert( array( 'category', 'level', 'series_hmac', 'source_hmac', 'experiment_hmac', 'method' ) === array_keys( $result ), 'only bounded classification fields escape' );
	}
}
$negative = array(
	'empty query' => '',
	'ordinary parameters' => 's=example&page=2&nonce=private-fixture',
	'command name alone' => '+config-create+/+file.conf',
	'execution string alone' => 's=passthru(chr(101))',
	'PHP destination alone' => 'download=/tmp/fixture.php',
	'no execution call' => '+config-create+/+chr(101)+/tmp/fixture.php',
	'no PHP destination' => '+config-create+/+passthru(chr(101))+/tmp/fixture.txt',
	'destination extension suffix' => $probe . '.txt',
	'lookalike function' => str_replace( 'passthru', 'my_passthru', $probe ),
	'ordinary config form' => 'command=config-create&root=/&code=passthru(chr(101))&file=/tmp/fixture.php',
	'properly encoded search example' => 's=' . rawurlencode( '/?' . $probe ),
	'encoded full attack URL in a form' => 'url=' . rawurlencode( 'https://example.test/?' . $positive['PEAR ampersand arguments'] ),
	'encoded ampersand inside search value' => 's=' . rawurlencode( 'text&' . $probe ),
	'third encoding layer outside supported scope' => rawurlencode( rawurlencode( rawurlencode( $probe ) ) ),
	'oversized query outside scan prefix' => 'a=' . str_repeat( 'x', 8192 ) . '&' . $probe,
	'truncated destination' => str_repeat( '+', 8192 - strlen( $probe ) + 1 ) . $probe,
	'malformed percent encoding' => 's=%ZZ%2%00%FF&command=config-create',
	'legitimate batch route by itself' => 'rest_route=/batch/v1',
	'normal template fetch' => "a=fetch&templateFile=public/index&prefix=''&content=welcome",
	'named search containing template example' => 's=' . rawurlencode( $template_probe ),
	'normal article search for phpinfo' => 's=' . rawurlencode( 'documentation: /index/index/name/${@phpinfo()}' ),
	'named search containing route example' => 's=' . rawurlencode( $route_probe ),
	'archive wrong extension' => str_replace( 'fixture.php', 'fixture.txt', $archive_probe ),
	'archive missing wrapper' => str_replace( '?=/**/', '', $archive_probe ),
	'archive missing decoder' => str_replace( 'base64_decode', 'fixture_decoder', $archive_probe ),
	'tag missing callback' => str_replace( 'create_function', 'fixture_callback', $tag_probe ),
	'tag missing template' => str_replace( '&tagstpl=news.html', '', $tag_probe ),
	'normal tag value' => 'tag=wordpress&tagstpl=news.html',
	'Drupal query on normal WordPress page' => $drupal_probe,
);
foreach ( $negative as $label => $query ) {
	$result = query_classify( $query, $settings );
	query_assert( 0 === $result['level'], $label . ' remains unclassified' );
}
query_assert( 2 === query_classify( $probe, $settings, '/', true )['level'], 'REQUEST_URI fallback' );
query_request( '', '/' );
$_SERVER['QUERY_STRING'] = $probe;
query_assert( 2 === ( new Atshift_Semantic_Deterrence_Detector( $settings ) )->classify_current_request()['level'], 'server query is authoritative when request URI omits query' );
query_assert( 2 === query_classify( str_repeat( '+', 8192 - strlen( $probe ) ) . $probe, $settings )['level'], 'complete fixture at exact scan limit' );
query_assert( 2 === query_classify( $probe, $settings, '/', false, 'POST' )['level'], 'query classification on POST' );
query_assert( 2 === query_classify( $probe, $settings, '/', false, 'TRACE' )['level'], 'high-confidence query outranks weak method anomaly' );
query_assert( 'secret_config' === query_classify( $probe, $settings, '/.env' )['category'], 'specific path classification takes precedence' );
query_assert( 'secret_config' === query_classify( '', $settings, '/.env' )['category'], 'existing path detection unchanged' );
query_assert( 0 === query_classify( $probe, $settings, '/.well-known/security.txt' )['level'], 'security.txt exclusion' );
query_assert( 0 === query_classify( '', $settings, '/api/v1/validate/code' )['level'], 'API route name alone is not an attack' );
foreach ( array( 'cautious' => 1, 'standard' => 2, 'strong' => 2 ) as $sensitivity => $level ) {
	$current = $settings;
	$current['sensitivity'] = $sensitivity;
	$result = query_classify( $drupal_probe, $current, '/user/register' );
	query_assert( 'unrelated_admin_surface' === $result['category'] && $level === $result['level'], 'Drupal registration-probe combination respects sensitivity' );
}
query_assert( 0 === query_classify( 'ajax_form=1&_wrapper_format=drupal_ajax', $settings, '/user/register' )['level'], 'AJAX flags alone do not classify registration' );
foreach ( array( 'admin', 'cron', 'manager' ) as $exclusion ) {
	$context = array( $exclusion => true );
	query_assert( 0 === query_classify( $probe, $settings )['level'], $exclusion . ' exclusion' );
}
$context = array();
$excluded = $settings;
$excluded['excluded_paths'] = '/payment/';
query_assert( 0 === query_classify( $probe, $excluded, '/payment/callback' )['level'], 'configured path exclusion' );
$excluded = $settings;
$excluded['excluded_ips'] = '192.0.2.10';
query_assert( 0 === query_classify( $probe, $excluded )['level'], 'configured IP exclusion' );

// Runtime integration without emitting headers, terminating PHP, or making network calls.
$runtime = ( new ReflectionClass( 'Atshift_Semantic_Deterrence_Plugin' ) )->newInstanceWithoutConstructor();
$storage_property = new ReflectionProperty( $runtime, 'storage' );
$storage_property->setAccessible( true );
foreach ( array( 'observe', 'deter', 'deter_limit', 'experiment' ) as $mode ) {
	foreach ( array( '0', '1' ) as $opt_in ) {
		Atshift_Semantic_Deterrence_Storage::$settings = array_merge( $settings, array( 'mode' => $mode, 'experiment_enabled' => $opt_in ) );
		Atshift_Semantic_Deterrence_Storage::$claims = array();
		$storage = new Atshift_Semantic_Deterrence_Storage();
		$storage_property->setValue( $runtime, $storage );
		query_request( $probe . '&token=private-fixture' );
		$status = null;
		try { $runtime->observe_request(); } catch ( Query_Response_Sent $sent ) { $status = $sent->getCode(); }
		$responds = 'observe' !== $mode && ( 'experiment' !== $mode || '1' === $opt_in );
		query_assert( ( $responds ? 403 : null ) === $status, $mode . ' respects response opt-in' );
		query_assert( 1 === count( $storage->events ), $mode . ' records local observation' );
		$event = $storage->events[0];
		query_assert( 'other_high_confidence' === $event['category'] && 2 === $event['level'], 'runtime receives new classification' );
		query_assert( ( $responds ? 1 : 0 ) === $event['responded'], 'response fact matches mode' );
		query_assert( ( $responds ? 403 : 200 ) === $event['http_status'], 'observed or returned HTTP status' );
		query_assert( $responds || 'observe_only' === $event['variant'], 'nonparticipants remain observation-only' );
		query_assert( array( 'series_hmac', 'source_hmac', 'country_code', 'category', 'level', 'variant', 'experiment_arm', 'http_status', 'policy_version', 'responded', 'response_fingerprint', 'response_label' ) === array_keys( $event ), 'event payload has no new sensitive fields' );
		$serialized = json_encode( array( $event, $filter_calls, Atshift_Semantic_Deterrence_Storage::$claims ) );
		query_assert( false === strpos( $serialized, 'private-fixture' ) && false === strpos( $serialized, 'passthru' ) && false === strpos( $serialized, 'wp-pear-rce-fixture' ), 'query never reaches records, filters or throttle keys' );
	}
}
Atshift_Semantic_Deterrence_Storage::$settings = array_merge( $settings, array( 'mode' => 'deter_limit' ) );
Atshift_Semantic_Deterrence_Storage::$claims = array();
Atshift_Semantic_Deterrence_Storage::$follow_ups = 1;
$storage = new Atshift_Semantic_Deterrence_Storage();
$storage_property->setValue( $runtime, $storage );
query_request( $probe );
$status = null;
try { $runtime->observe_request(); } catch ( Query_Response_Sent $sent ) { $status = $sent->getCode(); }
query_assert( 429 === $status && 429 === $storage->events[0]['http_status'], 'existing continuation-based temporary limit still applies' );
Atshift_Semantic_Deterrence_Storage::$follow_ups = 0;
Atshift_Semantic_Deterrence_Storage::$settings = array_merge( $settings, array( 'mode' => 'deter' ) );
$storage = new Atshift_Semantic_Deterrence_Storage();
$storage_property->setValue( $runtime, $storage );
query_request( 's=' . rawurlencode( '/?' . $probe ) );
$runtime->observe_request();
query_assert( array() === $storage->events, 'ordinary encoded search does not record or send a response in deterrence mode' );
Atshift_Semantic_Deterrence_Storage::$settings = $settings;
Atshift_Semantic_Deterrence_Storage::$claims = array();
$storage = new Atshift_Semantic_Deterrence_Storage();
$storage_property->setValue( $runtime, $storage );
for ( $request = 0; $request < 200; ++$request ) {
	query_request( $probe . '&nonce=' . $request );
	$runtime->observe_request();
}
query_assert( 1 === count( $storage->events ), '200-request burst retains per-source duplicate suppression despite changing query values' );
Atshift_Semantic_Deterrence_Storage::$claims = array();
Atshift_Semantic_Deterrence_Storage::$budget_available = false;
$runtime->observe_request();
query_assert( 1 === count( $storage->events ), 'site-wide write budget still applies' );
define( 'REST_REQUEST', true );
query_assert( 0 === query_classify( $probe, $settings )['level'], 'REST exclusion remains intact' );
echo 'PASS query detection, exclusions, privacy and runtime-mode regression checks' . PHP_EOL;
