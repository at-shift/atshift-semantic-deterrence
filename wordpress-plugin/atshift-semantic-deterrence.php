<?php
/**
 * Plugin Name: atshift Semantic Deterrence
 * Plugin URI: https://github.com/at-shift/atshift-semantic-deterrence
 * Description: 不審な自動探索を観測し、必要に応じて機械可読な状態と自然言語の撤退勧告を返します。
 * Version: 0.1.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: @shift
 * Author URI: https://cfs.at-shift.net/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atshift-semantic-deterrence
 * Domain Path: /languages
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATSHIFT_SEMANTIC_DETERRENCE_VERSION', '0.1.1' );
define( 'ATSHIFT_SEMANTIC_DETERRENCE_FILE', __FILE__ );
define( 'ATSHIFT_SEMANTIC_DETERRENCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATSHIFT_SEMANTIC_DETERRENCE_URL', plugin_dir_url( __FILE__ ) );

require_once ATSHIFT_SEMANTIC_DETERRENCE_DIR . 'includes/class-atshift-semantic-deterrence-storage.php';
require_once ATSHIFT_SEMANTIC_DETERRENCE_DIR . 'includes/class-atshift-semantic-deterrence-detector.php';
require_once ATSHIFT_SEMANTIC_DETERRENCE_DIR . 'includes/class-atshift-semantic-deterrence-admin.php';
require_once ATSHIFT_SEMANTIC_DETERRENCE_DIR . 'includes/class-atshift-semantic-deterrence-plugin.php';

register_activation_hook( __FILE__, array( 'Atshift_Semantic_Deterrence_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Atshift_Semantic_Deterrence_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'atshift-semantic-deterrence',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		Atshift_Semantic_Deterrence_Plugin::instance();
	}
);
