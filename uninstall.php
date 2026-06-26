<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'singular_markdown_options' );
delete_option( 'singular_markdown_version' );
delete_option( 'flolive_md_options' );
wp_clear_scheduled_hook( 'singular_markdown_batch_regenerate' );
wp_clear_scheduled_hook( 'singular_markdown_generate_post' );
wp_clear_scheduled_hook( 'singular_markdown_generate_archive' );

global $wpdb;
if ( isset( $wpdb ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'singular_md_generating_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $wpdb->esc_like( 'singular_md_elig_' ) . '%', '_transient_timeout_' . $wpdb->esc_like( 'singular_md_elig_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )", '_singular_markdown_mode', '_singular_markdown_custom' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

$upload = wp_upload_dir();
if ( empty( $upload['error'] ) ) {
	$dirs = array( 'singular-markdown-cache', 'flolive-md-cache' );
	foreach ( $dirs as $subdir ) {
		$dir = trailingslashit( $upload['basedir'] ) . $subdir;
		if ( is_dir( $dir ) ) {
			$files = glob( trailingslashit( $dir ) . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
