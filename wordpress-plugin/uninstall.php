<?php
/**
 * Uninstall cleanup for atshift Semantic Deterrence.
 *
 * @package AtshiftSemanticDeterrence
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-atshift-semantic-deterrence-storage.php';

$settings = Atshift_Semantic_Deterrence_Storage::get_settings();

if ( '1' === (string) $settings['delete_on_uninstall'] ) {
	$storage = new Atshift_Semantic_Deterrence_Storage();
	$storage->drop_tables();

	delete_option( Atshift_Semantic_Deterrence_Storage::OPTION_SETTINGS );
	delete_option( Atshift_Semantic_Deterrence_Storage::OPTION_SECRET );
	delete_option( Atshift_Semantic_Deterrence_Storage::OPTION_HUB_SECRET );
	delete_option( Atshift_Semantic_Deterrence_Storage::OPTION_SCHEMA_VERSION );
	wp_clear_scheduled_hook( 'atshift_semantic_deterrence_maintenance' );

	global $wpdb;
	$daily_salt_like = $wpdb->esc_like( 'atshift_semantic_deterrence_salt_' ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$daily_salt_like
		)
	);
}
