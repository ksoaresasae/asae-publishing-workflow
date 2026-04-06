<?php
/**
 * Plugin Name: ASAE Publishing Workflow
 * Plugin URI:  https://github.com/ksoaresasae/asae-publishing-workflow
 * Description: Content ownership and editorial workflow system — assigns users to content areas and enforces a two-step Editor/Publisher approval workflow.
 * Version:     0.2.4
 * Author:      Keith M. Soares
 * Author URI:  https://www.asaecenter.org
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: asae-publishing-workflow
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ASAE_PW_VERSION', '0.2.4');
define('ASAE_PW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASAE_PW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASAE_PW_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('ASAE_PW_PREFIX', 'asae_pw');

/**
 * Main plugin class — singleton bootstrap.
 */
class ASAE_Publishing_Workflow {

    /** @var self|null */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — load dependencies and hook into WordPress.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Require all class files.
     */
    private function load_dependencies() {
        $includes = array(
            'includes/class-asae-pw-roles.php',
            'includes/class-asae-pw-taxonomy.php',
            'includes/class-asae-pw-assignments.php',
            'includes/class-asae-pw-permissions.php',
            'includes/class-asae-pw-workflow.php',
            'includes/class-asae-pw-notifications.php',
            'includes/class-asae-pw-activity-log.php',
            'includes/class-asae-pw-settings.php',
            'includes/class-asae-pw-trash.php',
            'includes/class-asae-pw-cache.php',
            'includes/class-asae-pw-updater.php',
        );

        foreach ($includes as $file) {
            require_once ASAE_PW_PLUGIN_DIR . $file;
        }

        if (is_admin()) {
            $admin_files = array(
                'admin/class-asae-pw-admin.php',
                'admin/class-asae-pw-admin-dashboard.php',
                'admin/class-asae-pw-admin-assignments.php',
                'admin/class-asae-pw-admin-submissions.php',
                'admin/class-asae-pw-admin-activity.php',
                'admin/class-asae-pw-admin-settings.php',
                'admin/class-asae-pw-meta-boxes.php',
            );

            foreach ($admin_files as $file) {
                require_once ASAE_PW_PLUGIN_DIR . $file;
            }
        }
    }

    /**
     * Register all WordPress hooks.
     */
    private function init_hooks() {
        // Run upgrade routine immediately, before any user/cap caching.
        $this->maybe_upgrade();

        // Core components — always loaded.
        new ASAE_PW_Taxonomy();
        new ASAE_PW_Permissions();
        new ASAE_PW_Workflow();
        new ASAE_PW_Notifications();
        new ASAE_PW_Activity_Log();
        new ASAE_PW_Settings();
        new ASAE_PW_Trash();
        new ASAE_PW_Cache();
        new ASAE_PW_Updater();

        // Admin-only components.
        if (is_admin()) {
            new ASAE_PW_Admin();
            new ASAE_PW_Meta_Boxes();
        }
    }

    /**
     * Run upgrade routines when the plugin version changes.
     *
     * Re-creates roles so capability changes propagate to existing installs
     * without requiring deactivate/reactivate.
     */
    public function maybe_upgrade() {
        $stored_version = get_option('asae_pw_version');
        $version_changed = $stored_version !== ASAE_PW_VERSION;

        // Safety check: ensure the role exists and has the expected base caps.
        // If the role is missing or has stale caps, recreate it even if the
        // version option hasn't changed.
        $role = get_role('asae_pw_editor');
        $caps_ok = $role && !empty($role->capabilities['edit_pages']);

        if (!$version_changed && $caps_ok) {
            return;
        }

        // Re-create roles with current cap definitions.
        ASAE_PW_Roles::create_roles();

        update_option('asae_pw_version', ASAE_PW_VERSION);

        // If a user has already been loaded for this request, refresh their
        // in-memory caps so the new role definitions take effect immediately.
        if (function_exists('wp_get_current_user')) {
            $current = wp_get_current_user();
            if ($current && $current->ID) {
                $current->get_role_caps();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Activation / Deactivation
|--------------------------------------------------------------------------
*/

register_activation_hook(__FILE__, 'asae_pw_activate');
register_deactivation_hook(__FILE__, 'asae_pw_deactivate');

/**
 * Plugin activation: create tables, roles, taxonomy, defaults.
 */
function asae_pw_activate() {
    // Load required classes — activation fires before plugins_loaded.
    require_once ASAE_PW_PLUGIN_DIR . 'includes/class-asae-pw-roles.php';
    require_once ASAE_PW_PLUGIN_DIR . 'includes/class-asae-pw-taxonomy.php';
    require_once ASAE_PW_PLUGIN_DIR . 'includes/class-asae-pw-settings.php';

    // 1. Create database tables.
    asae_pw_create_tables();

    // 2. Register taxonomy early so terms are available.
    ASAE_PW_Taxonomy::register_taxonomy();

    // 3. Create / update custom roles.
    ASAE_PW_Roles::create_roles();

    // 4. Flush rewrite rules.
    flush_rewrite_rules();

    // 5. Set default options.
    $defaults = array(
        'disable_xmlrpc'          => false,
        'notification_sender_name'  => get_bloginfo('name'),
        'notification_sender_email' => get_option('admin_email'),
        'post_types'              => array('post', 'page'),
        'orphaned_content'        => 'admin_only',
        'delete_terms_on_uninstall' => false,
    );

    if (!get_option('asae_pw_settings')) {
        add_option('asae_pw_settings', $defaults);
    }

    // Store version for future upgrade routines.
    update_option('asae_pw_version', ASAE_PW_VERSION);
}

/**
 * Create the four plugin database tables.
 */
function asae_pw_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $tables = array();

    $tables[] = "CREATE TABLE {$wpdb->prefix}asae_pw_assignments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        role VARCHAR(20) NOT NULL,
        term_id BIGINT UNSIGNED NOT NULL,
        assigned_by BIGINT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_role_term (user_id, role, term_id),
        KEY term_id (term_id)
    ) {$charset};";

    $tables[] = "CREATE TABLE {$wpdb->prefix}asae_pw_submissions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        submitted_by BIGINT UNSIGNED NOT NULL,
        reviewed_by BIGINT UNSIGNED DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME DEFAULT NULL,
        submit_note TEXT DEFAULT NULL,
        review_note TEXT DEFAULT NULL,
        KEY post_status (post_id, status),
        KEY submitted_by (submitted_by)
    ) {$charset};";

    $tables[] = "CREATE TABLE {$wpdb->prefix}asae_pw_trash_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        requested_by BIGINT UNSIGNED NOT NULL,
        reviewed_by BIGINT UNSIGNED DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        reason TEXT NOT NULL,
        review_note TEXT DEFAULT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME DEFAULT NULL,
        KEY post_id (post_id),
        KEY status (status)
    ) {$charset};";

    $tables[] = "CREATE TABLE {$wpdb->prefix}asae_pw_activity_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL,
        detail TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY post_id (post_id),
        KEY user_created (user_id, created_at),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ($tables as $sql) {
        dbDelta($sql);
    }
}

/**
 * Plugin deactivation: flush rewrite rules only.
 */
function asae_pw_deactivate() {
    flush_rewrite_rules();
}

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
*/

add_action('plugins_loaded', function () {
    ASAE_Publishing_Workflow::get_instance();
});
