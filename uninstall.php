<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes every option the plugin created.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'easy_indexnow_settings' );
delete_option( 'easy_indexnow_log' );
delete_option( 'easy_indexnow_queue' );
wp_clear_scheduled_hook( 'easy_indexnow_send_queue' );
