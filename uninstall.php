<?php
/**
 * Uninstall handler for AIMF Secure Contact Form.
 *
 * Runs when the plugin is deleted via the WordPress admin. Removes the custom
 * database table and plugin options. This file is NOT called on deactivation —
 * only on permanent deletion.
 *
 * @package AIMF_Secure_Contact_Form
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove the custom submissions table.
global $wpdb;
$table_name = $wpdb->prefix . 'aimf_secure_contact_submissions';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Remove plugin options.
delete_option( 'aimf_scf_settings' );
delete_option( 'ascf_forms' );

// Clean up transients (best-effort — per-IP transients expire on their own).
delete_transient( 'aimf_scf_activated' );
delete_transient( 'ascf_global_rl' );

// Unschedule the data retention cron job.
$timestamp = wp_next_scheduled( 'ascf_data_retention_cleanup' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'ascf_data_retention_cleanup' );
}
