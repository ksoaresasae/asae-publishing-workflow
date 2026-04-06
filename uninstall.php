<?php
/**
 * ASAE Publishing Workflow — Uninstall
 *
 * Fired when the plugin is deleted from the Plugins screen.
 * Removes roles, tables, options, and post meta.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Remove custom roles.
remove_role('asae_pw_editor');
remove_role('asae_pw_publisher');

// 2. Drop plugin database tables.
$tables = array(
    $wpdb->prefix . 'asae_pw_assignments',
    $wpdb->prefix . 'asae_pw_submissions',
    $wpdb->prefix . 'asae_pw_trash_requests',
    $wpdb->prefix . 'asae_pw_activity_log',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// 3. Delete plugin options.
delete_option('asae_pw_settings');
delete_option('asae_pw_version');

// 4. Delete all post meta keys prefixed with _asae_pw_.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_asae_pw_%'");

// 5. Delete transients.
delete_transient('asae_pw_github_release');

// 6. Optionally delete Content Area terms.
$settings = get_option('asae_pw_settings', array());
if (!empty($settings['delete_terms_on_uninstall'])) {
    $terms = get_terms(array(
        'taxonomy'   => 'asae_content_area',
        'hide_empty' => false,
        'fields'     => 'ids',
    ));

    if (!is_wp_error($terms)) {
        foreach ($terms as $term_id) {
            wp_delete_term($term_id, 'asae_content_area');
        }
    }
}
