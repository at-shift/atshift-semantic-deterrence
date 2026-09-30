<?php
/** Verify read-only screens and direct action authorization. */
define( 'ABSPATH', __DIR__ );
$capabilities = array();
$menus = array();
function current_user_can( $cap ) { global $capabilities; return ! empty( $capabilities[$cap] ); }
function add_action() {}
function add_menu_page( $title, $label, $cap, $slug, $callback ) { global $menus; $menus[$slug] = $cap; }
function add_submenu_page( $parent, $title, $label, $cap, $slug, $callback ) { global $menus; $menus[$slug] = $cap; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html__( $text, $domain ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_html__( $text, $domain ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function wp_die( $message ) { throw new RuntimeException( 'access-denied' ); }
function check_admin_referer() { throw new RuntimeException( 'unauthorized-action-reached-nonce' ); }
class Atshift_Semantic_Deterrence_Storage {
	public $finalizations = 0;
	public static function get_settings() { return array( 'onboarding_completed' => '0' ); }
	public function finalize_windows() { ++$this->finalizations; }
	public function get_summary( $days ) { throw new RuntimeException( 'dashboard-data-read' ); }
}
require_once dirname( __DIR__ ) . '/wordpress-plugin/includes/class-atshift-semantic-deterrence-admin.php';
function permissions_assert( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$storage = new Atshift_Semantic_Deterrence_Storage();
$admin = new Atshift_Semantic_Deterrence_Admin( $storage );
foreach ( array( 'subscriber', 'contributor', 'author', 'editor' ) as $role ) {
	$capabilities = array( 'read' => true );
	$menus = array();
	$admin->add_menu();
	permissions_assert( 'read' === $menus['atshift-semantic-deterrence'], $role . ' can open overview' );
	permissions_assert( 'read' === $menus['atshift-semantic-deterrence-dashboard'], $role . ' can open dashboard' );
	permissions_assert( 'manage_options' === $menus['atshift-semantic-deterrence-settings'], 'settings requires management capability' );
	ob_start();
	$admin->render_readme_page();
	$html = ob_get_clean();
	permissions_assert( false !== strpos( $html, 'page=atshift-semantic-deterrence-dashboard' ), 'overview links to dashboard' );
	permissions_assert( false === strpos( $html, 'page=atshift-semantic-deterrence-settings' ), 'reader navigation omits settings' );
	permissions_assert( false === strpos( $html, 'data-atsdn-onboarding' ), 'reader never gets onboarding form' );
	try { $admin->render_dashboard_page(); } catch ( RuntimeException $error ) {
		permissions_assert( 'dashboard-data-read' === $error->getMessage(), 'reader reaches dashboard data' );
	}
	permissions_assert( 0 === $storage->finalizations, 'reader does not finalize database windows' );
	foreach ( array( 'render_settings_page', 'save_settings', 'complete_onboarding', 'finalize_windows', 'delete_local_data', 'download_anonymous_batch' ) as $method ) {
		try { $admin->$method(); permissions_assert( false, $method . ' must deny reader' ); }
		catch ( RuntimeException $error ) {
			permissions_assert( 'access-denied' === $error->getMessage(), $method . ' rejects before nonce and storage work' );
		}
	}
}
$capabilities = array();
foreach ( array( 'render_readme_page', 'render_dashboard_page' ) as $method ) {
	try { $admin->$method(); permissions_assert( false, 'anonymous access must fail' ); }
	catch ( RuntimeException $error ) { permissions_assert( 'access-denied' === $error->getMessage(), 'anonymous screen access denied' ); }
}
$capabilities = array( 'read' => true, 'manage_options' => true );
$nav = new ReflectionMethod( $admin, 'render_screen_nav' );
$nav->setAccessible( true );
ob_start();
$nav->invoke( $admin, 'dashboard' );
$html = ob_get_clean();
permissions_assert( false !== strpos( $html, 'page=atshift-semantic-deterrence-settings' ), 'administrator retains settings navigation' );
try { $admin->render_dashboard_page(); } catch ( RuntimeException $error ) {
	permissions_assert( 'dashboard-data-read' === $error->getMessage(), 'administrator reaches data' );
}
permissions_assert( 1 === $storage->finalizations, 'administrator retains window finalization' );
echo 'PASS admin read-only permissions regression checks' . PHP_EOL;
