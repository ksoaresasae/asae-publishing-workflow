<?php
/**
 * Plugin settings — stored in wp_options as 'asae_pw_settings'.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Settings {

    public function __construct() {
        add_action('init', array($this, 'maybe_disable_xmlrpc'));
    }

    /**
     * Disable XML-RPC if setting is enabled.
     */
    public function maybe_disable_xmlrpc() {
        $settings = self::get();
        if (!empty($settings['disable_xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array');
        }
    }

    /**
     * Get all settings with defaults.
     *
     * @return array
     */
    public static function get() {
        $defaults = array(
            'disable_xmlrpc'            => false,
            'notification_sender_name'  => get_bloginfo('name'),
            'notification_sender_email' => get_option('admin_email'),
            'post_types'                => array('post', 'page'),
            'orphaned_content'          => 'admin_only',
            'delete_terms_on_uninstall' => false,
        );

        $stored = get_option('asae_pw_settings', array());
        return wp_parse_args($stored, $defaults);
    }

    /**
     * Update settings.
     *
     * @param array $new_settings
     * @return bool
     */
    public static function update($new_settings) {
        $current = self::get();
        $merged  = wp_parse_args($new_settings, $current);
        return update_option('asae_pw_settings', $merged);
    }
}
