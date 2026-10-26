<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes every option the plugin created and its key file.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$easy_indexnow_settings = get_option( 'easy_indexnow_settings' );
if ( is_array( $easy_indexnow_settings ) && ! empty( $easy_indexnow_settings['key'] ) && preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $easy_indexnow_settings['key'] ) ) {
	$easy_indexnow_file = trailingslashit( ABSPATH ) . $easy_indexnow_settings['key'] . '.txt';
	// Only delete the file if it is ours (contains exactly the key).
	if ( file_exists( $easy_indexnow_file ) && trim( (string) file_get_contents( $easy_indexnow_file ) ) === $easy_indexnow_settings['key'] ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $easy_indexnow_file );
	}
}

delete_option( 'easy_indexnow_settings' );
delete_option( 'easy_indexnow_log' );
delete_option( 'easy_indexnow_queue' );
delete_option( 'easy_indexnow_retry' );
delete_transient( 'easy_indexnow_types_added' );
wp_clear_scheduled_hook( 'easy_indexnow_send_queue' );
wp_clear_scheduled_hook( 'easy_indexnow_send_retry' );
