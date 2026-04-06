<?php
/**
 * Role creation and capability management.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Roles {

    /**
     * Base capabilities for the Editor role.
     *
     * @return array
     */
    public static function editor_caps() {
        return array(
            'read'         => true,
            'upload_files' => true,
            'edit_posts'   => true,
        );
    }

    /**
     * Base capabilities for the Publisher role.
     *
     * @return array
     */
    public static function publisher_caps() {
        return array(
            'read'         => true,
            'upload_files' => true,
            'edit_posts'   => true,
        );
    }

    /**
     * Create or update the two custom roles.
     */
    public static function create_roles() {
        // Remove first to ensure caps are current on reactivation.
        remove_role('asae_pw_editor');
        remove_role('asae_pw_publisher');

        add_role(
            'asae_pw_editor',
            __('ASAE PW Editor', 'asae-publishing-workflow'),
            self::editor_caps()
        );

        add_role(
            'asae_pw_publisher',
            __('ASAE PW Publisher', 'asae-publishing-workflow'),
            self::publisher_caps()
        );
    }

    /**
     * Remove the two custom roles (used on uninstall).
     */
    public static function remove_roles() {
        remove_role('asae_pw_editor');
        remove_role('asae_pw_publisher');
    }

    /**
     * Check whether a user has one of the plugin's custom roles.
     *
     * @param int|WP_User $user User ID or object.
     * @return bool
     */
    public static function is_pw_user($user = null) {
        if (!$user instanceof WP_User) {
            $user = get_userdata($user ?: get_current_user_id());
        }
        if (!$user) {
            return false;
        }
        return in_array('asae_pw_editor', (array) $user->roles, true)
            || in_array('asae_pw_publisher', (array) $user->roles, true);
    }

    /**
     * Check whether a user is specifically an ASAE PW Editor.
     *
     * @param int|WP_User $user User ID or object.
     * @return bool
     */
    public static function is_editor($user = null) {
        if (!$user instanceof WP_User) {
            $user = get_userdata($user ?: get_current_user_id());
        }
        if (!$user) {
            return false;
        }
        return in_array('asae_pw_editor', (array) $user->roles, true);
    }

    /**
     * Check whether a user is specifically an ASAE PW Publisher.
     *
     * @param int|WP_User $user User ID or object.
     * @return bool
     */
    public static function is_publisher($user = null) {
        if (!$user instanceof WP_User) {
            $user = get_userdata($user ?: get_current_user_id());
        }
        if (!$user) {
            return false;
        }
        return in_array('asae_pw_publisher', (array) $user->roles, true);
    }
}
