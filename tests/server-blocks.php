<?php
/** Deterministic local measurement-fence, storage and race fixtures. No site data. */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ATSHIFT_SEMANTIC_DETERRENCE_VERSION', 'fixture' );
$options = array(); $capable = true; $multisite = false; $now = 2000000000; $checks = 0;
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap ) { global $capable; return $capable && 'manage_options' === $cap; }
function is_multisite() { global $multisite; return $multisite; }
function current_time( $format ) { global $now; return 'timestamp' === $format ? $now : gmdate( 'mysql' === $format ? 'Y-m-d H:i:s' : $format, $now ); }
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { global $options; if ( isset( $options[$key] ) ) { return false; } $options[$key] = $value; return true; }
function wp_cache_delete() {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_parse_args( $values, $defaults ) { return array_merge( $defaults, $values ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
class Fence_DB {
	public $prefix = 'wp_'; public $options = 'wp_options'; public $last_error = ''; public $insert_id = 0;
	public $rows = array(); public $queries = array(); public $fail_read = false; public $fail_censor = false; public $fail_cas = false; public $before_insert; public $before_follow;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var( $query ) { global $options; $this->last_error = $this->fail_read ? 'fixture' : ''; return $this->fail_read ? null : ( $options[$query[1][0]] ?? null ); }
	public function get_results( $query, $format = null ) {
		$this->queries[] = $query; $rows = array();
		foreach ( $this->rows as $id => $row ) {
			if ( $row['source_hmac'] === $query[1][0] && $row['responded'] && $row['window_ends_at'] >= $query[1][1] && in_array( $row['outcome'], array( 'unknown', 'observed_ceased' ), true ) ) { $rows[] = (object) array_merge( $row, array( 'id' => $id ) ); }
		}
		return $rows;
	}
	public function insert( $table, $row, $formats ) { if ( $this->before_insert ) { $callback = $this->before_insert; $this->before_insert = null; $callback(); } $this->rows[++$this->insert_id] = $row; return 1; }
	public function query( $query ) {
		global $options; $this->queries[] = $query; list( $sql, $args ) = $query;
		if ( false !== strpos( $sql, 'UPDATE wp_options' ) ) {
			if ( $this->fail_cas || ( $options[$args[1]] ?? null ) !== $args[2] ) { return 0; }
			$options[$args[1]] = $args[0]; return 1;
		}
		if ( false !== strpos( $sql, "SET outcome = 'rate_limited'" ) && $this->fail_censor ) { return false; }
		if ( false !== strpos( $sql, 'follow_up_count = follow_up_count' ) && $this->before_follow ) { $callback = $this->before_follow; $this->before_follow = null; $callback(); }
		$count = 0;
		foreach ( $this->rows as $id => &$row ) {
			if ( ! $row['responded'] || ! in_array( $row['outcome'], array( 'unknown', 'observed_ceased' ), true ) ) { continue; }
			if ( false !== strpos( $sql, 'SET outcome = CASE' ) ) {
				if ( 'unknown' !== $row['outcome'] ) { continue; }
				$interrupted = $row['server_block_revision'] !== $args[0] || 1 === $args[1];
				if ( ! $interrupted && $row['window_ends_at'] >= $args[2] ) { continue; }
				$row['outcome'] = $interrupted ? 'rate_limited' : 'observed_ceased';
			} elseif ( false !== strpos( $sql, 'follow_up_count = follow_up_count' ) ) {
				if ( $id !== $args[2] ) { continue; } $row['outcome'] = $args[1]; ++$row['follow_up_count'];
			} elseif ( false !== strpos( $sql, "SET outcome = 'observed_ceased'" ) ) {
				if ( 'unknown' !== $row['outcome'] || $row['window_ends_at'] >= $args[0] ) { continue; } $row['outcome'] = 'observed_ceased';
			} else {
				if ( false !== strpos( $sql, 'WHERE id = %d' ) ? $id !== $args[0] : $row['window_ends_at'] < $args[0] ) { continue; } $row['outcome'] = 'rate_limited';
			}
			++$count;
		}
		return $count;
	}
}
$wpdb = new Fence_DB();
require dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-storage.php';
require dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-server-blocks.php';
$storage = new Atshift_Semantic_Deterrence_Storage();
function check_fence( $ok, $message ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function response_event( $extra = array() ) { global $storage; return $storage->record_event( array_merge( array( 'responded' => 1, 'source_hmac' => 'fixture-source', 'category' => 'secret_config', 'level' => 2, 'variant' => 'policy_notice' ), $extra ) ); }
$clean = Atshift_Semantic_Deterrence_Server_Blocks::snapshot();
check_fence( false === $clean['active'] && '' === $clean['revision'], 'initial state idle' );
$open = response_event();
$closed = response_event( array( 'window_ends_at' => gmdate( 'Y-m-d H:i:s', $now - 1 ), 'outcome' => 'observed_ceased' ) );
$continued = response_event( array( 'outcome' => 'continued_same' ) );
$observe = $storage->record_event( array() );
$token = Atshift_Semantic_Deterrence_Server_Blocks::begin_change();
check_fence( is_string( $token ) && 32 === strlen( $token ), 'begin returns opaque token' );
check_fence( 'rate_limited' === $wpdb->rows[$open]['outcome'], 'open undecided window censored' );
check_fence( 'observed_ceased' === $wpdb->rows[$closed]['outcome'], 'completed window preserved' );
check_fence( 'continued_same' === $wpdb->rows[$continued]['outcome'], 'observed continuation preserved' );
check_fence( 'unknown' === $wpdb->rows[$observe]['outcome'], 'observe-only event unchanged' );
check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::begin_change() ), 'overlap refused' );
$during = response_event();
check_fence( 'rate_limited' === $wpdb->rows[$during]['outcome'], 'response during change censored' );
check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::finish_change( 'wrong' ) ), 'foreign completion refused' );
check_fence( true === Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $token ), 'completion succeeds' );
check_fence( true === Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $token ), 'completion idempotent' );
check_fence( ! Atshift_Semantic_Deterrence_Server_Blocks::snapshot()['active'], 'completion clears active state' );
$late = response_event( array( 'server_block_snapshot' => $clean ) );
check_fence( 'rate_limited' === $wpdb->rows[$late]['outcome'], 'request spanning completed change censored' );
$after = response_event();
check_fence( 'unknown' === $wpdb->rows[$after]['outcome'], 'new responses measure normally' );
$now += 601; $storage->finalize_windows();
check_fence( 'rate_limited' === $wpdb->rows[$open]['outcome'] && 'rate_limited' === $wpdb->rows[$during]['outcome'], 'censored windows never become successes' );
check_fence( 'observed_ceased' === $wpdb->rows[$after]['outcome'], 'ordinary expired response finalizes' );
$wpdb->before_insert = static function () { $t = Atshift_Semantic_Deterrence_Server_Blocks::begin_change(); Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $t ); };
$race = response_event();
check_fence( 'rate_limited' === $wpdb->rows[$race]['outcome'], 'insert racing with whole change is censored' );
$wpdb->before_insert = static function () { global $wpdb; $t = Atshift_Semantic_Deterrence_Server_Blocks::begin_change(); Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $t ); $wpdb->fail_censor = true; };
$failed_race = response_event(); $wpdb->fail_censor = false;
check_fence( 'unknown' === $wpdb->rows[$failed_race]['outcome'], 'post-insert failure fixture reached' );
$storage->finalize_windows();
check_fence( 'rate_limited' === $wpdb->rows[$failed_race]['outcome'], 'persisted revision repairs failed race censor before success classification' );
$follow = response_event(); $race_token = null;
$wpdb->before_follow = static function () use ( &$race_token ) { $race_token = Atshift_Semantic_Deterrence_Server_Blocks::begin_change(); };
check_fence( 0 === $storage->mark_follow_up( 'fixture-source', 'secret_config', 2 ), 'follow-up racing with fence not counted' );
check_fence( 'rate_limited' === $wpdb->rows[$follow]['outcome'], 'follow-up cannot revive censored result' );
Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $race_token );
$wpdb->fail_censor = true; $failed = Atshift_Semantic_Deterrence_Server_Blocks::begin_change();
check_fence( is_wp_error( $failed ) && Atshift_Semantic_Deterrence_Server_Blocks::snapshot()['active'], 'failed censor leaves fence active' );
$stuck = Atshift_Semantic_Deterrence_Server_Blocks::snapshot();
check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $stuck['revision'] ) ), 'failed recovery stays pending' );
$wpdb->fail_censor = false; check_fence( true === Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $stuck['revision'] ), 'explicit recovery completes' );
$wpdb->fail_cas = true; check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::begin_change() ), 'lost CAS never starts change' ); $wpdb->fail_cas = false;
$capable = false; check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::begin_change() ) && is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::finish_change( $token ) ), 'administrator required' ); $capable = true;
$multisite = true; check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::begin_change() ), 'multisite refused' ); $multisite = false;
$wpdb->fail_read = true;
check_fence( Atshift_Semantic_Deterrence_Server_Blocks::interrupted( $clean ) && is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::begin_change() ), 'read failure fails closed' );
$wpdb->fail_read = false; $options[Atshift_Semantic_Deterrence_Server_Blocks::OPTION] = '{invalid';
check_fence( is_wp_error( Atshift_Semantic_Deterrence_Server_Blocks::snapshot() ), 'malformed state fails closed' );
check_fence( 1 === count( $options ) && ! isset( $options[Atshift_Semantic_Deterrence_Storage::OPTION_SETTINGS] ), 'mode and assignment never changed' );
foreach ( $wpdb->rows as $row ) { check_fence( ! isset( $row['server_block_snapshot'] ) && ! isset( $row['ip'] ), 'no fence identifiers or raw IP stored in events' ); }
echo 'PASS ' . $checks . ' server-block measurement, concurrency and failure checks' . PHP_EOL;
