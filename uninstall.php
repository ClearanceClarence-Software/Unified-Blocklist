<?php
/**
 * Removes every trace of the plugin.
 *
 * @package UnifiedBlocklist
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options, stored lists, log table and scheduled events for the current site.
 */
function unified_blocklist_uninstall_site() {
	global $wpdb;

	// Every option the plugin owns starts with this prefix, including the list chunks.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall.
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'unified_blocklist' ) . '%' ) );
	foreach ( (array) $names as $name ) {
		delete_option( $name );
	}

	delete_metadata( 'user', 0, 'unified_blocklist_theme', '', true );

	wp_clear_scheduled_hook( 'unified_blocklist_sync' );
	wp_clear_scheduled_hook( 'unified_blocklist_prune' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}unified_blocklist_log" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}unified_blocklist_lists" );
	// phpcs:enable
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $unified_blocklist_site_id ) {
		switch_to_blog( $unified_blocklist_site_id );
		unified_blocklist_uninstall_site();
		restore_current_blog();
	}
} else {
	unified_blocklist_uninstall_site();
}
