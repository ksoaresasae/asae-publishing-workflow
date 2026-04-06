<?php
/**
 * User-to-Content-Area assignment logic.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Assignments {

    /**
     * Get all assignments for a user.
     *
     * @param int    $user_id
     * @param string $role Optional. Filter by 'editor' or 'publisher'.
     * @return array Array of assignment rows.
     */
    public static function get_user_assignments($user_id, $role = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'asae_pw_assignments';

        if ($role) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND role = %s",
                $user_id,
                $role
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get assigned Content Area term IDs for a user.
     *
     * @param int    $user_id
     * @param string $role Optional filter.
     * @return int[]
     */
    public static function get_user_term_ids($user_id, $role = '') {
        $assignments = self::get_user_assignments($user_id, $role);
        return array_unique(array_map(function ($a) {
            return (int) $a->term_id;
        }, $assignments));
    }

    /**
     * Check if a user has an assignment for any of the given term IDs.
     *
     * @param int   $user_id
     * @param int[] $term_ids
     * @return bool
     */
    public static function user_has_term($user_id, array $term_ids) {
        if (empty($term_ids)) {
            return false;
        }
        $user_terms = self::get_user_term_ids($user_id);
        return !empty(array_intersect($user_terms, $term_ids));
    }

    /**
     * Get all assignments (optionally filtered).
     *
     * @param array $args {
     *     @type int    $user_id
     *     @type string $role
     *     @type int    $term_id
     * }
     * @return array
     */
    public static function get_all($args = array()) {
        global $wpdb;
        $table  = $wpdb->prefix . 'asae_pw_assignments';
        $where  = array('1=1');
        $values = array();

        if (!empty($args['user_id'])) {
            $where[]  = 'user_id = %d';
            $values[] = $args['user_id'];
        }
        if (!empty($args['role'])) {
            $where[]  = 'role = %s';
            $values[] = $args['role'];
        }
        if (!empty($args['term_id'])) {
            $where[]  = 'term_id = %d';
            $values[] = $args['term_id'];
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY assigned_at DESC";

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Create a new assignment.
     *
     * @param int    $user_id
     * @param string $role     'editor' or 'publisher'.
     * @param int    $term_id
     * @param int    $assigned_by
     * @return int|false Insert ID or false on failure.
     */
    public static function create($user_id, $role, $term_id, $assigned_by) {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'asae_pw_assignments',
            array(
                'user_id'     => $user_id,
                'role'        => $role,
                'term_id'     => $term_id,
                'assigned_by' => $assigned_by,
                'assigned_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%d', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Delete an assignment by ID.
     *
     * @param int $assignment_id
     * @return bool
     */
    public static function delete($assignment_id) {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'asae_pw_assignments',
            array('id' => $assignment_id),
            array('%d')
        );
    }

    /**
     * Delete all assignments for a user.
     *
     * @param int $user_id
     * @return int|false Number of rows deleted or false.
     */
    public static function delete_user_assignments($user_id) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'asae_pw_assignments',
            array('user_id' => $user_id),
            array('%d')
        );
    }

    /**
     * Synchronize a user's WP roles with their content area assignments.
     *
     * Adds asae_pw_editor / asae_pw_publisher roles when the user has at least
     * one assignment of that type, and removes them when they have none.
     * Other WP roles are left untouched.
     *
     * @param int $user_id
     */
    public static function sync_user_roles($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $assignments = self::get_user_assignments($user_id);
        $has_editor    = false;
        $has_publisher = false;
        foreach ($assignments as $a) {
            if ('editor' === $a->role) {
                $has_editor = true;
            }
            if ('publisher' === $a->role) {
                $has_publisher = true;
            }
        }

        $current_roles = (array) $user->roles;

        if ($has_editor && !in_array('asae_pw_editor', $current_roles, true)) {
            $user->add_role('asae_pw_editor');
        } elseif (!$has_editor && in_array('asae_pw_editor', $current_roles, true)) {
            $user->remove_role('asae_pw_editor');
        }

        if ($has_publisher && !in_array('asae_pw_publisher', $current_roles, true)) {
            $user->add_role('asae_pw_publisher');
        } elseif (!$has_publisher && in_array('asae_pw_publisher', $current_roles, true)) {
            $user->remove_role('asae_pw_publisher');
        }
    }

    /**
     * Synchronize all users' roles based on their current assignments.
     *
     * Used during upgrade to backfill role assignments for installs created
     * before automatic role syncing existed.
     */
    public static function sync_all_user_roles() {
        global $wpdb;
        $user_ids = array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}asae_pw_assignments"
        ));

        foreach ($user_ids as $user_id) {
            self::sync_user_roles($user_id);
        }
    }

    /**
     * Get all users assigned as publishers for given term IDs.
     *
     * @param int[] $term_ids
     * @return int[] User IDs.
     */
    public static function get_publishers_for_terms(array $term_ids) {
        global $wpdb;
        if (empty($term_ids)) {
            return array();
        }
        $table        = $wpdb->prefix . 'asae_pw_assignments';
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $values       = array_merge(array('publisher'), $term_ids);

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table} WHERE role = %s AND term_id IN ({$placeholders})",
            ...$values
        )));
    }

    /**
     * Get all admin user IDs.
     *
     * @return int[]
     */
    public static function get_admin_user_ids() {
        $admins = get_users(array(
            'role'   => 'administrator',
            'fields' => 'ID',
        ));
        return array_map('intval', $admins);
    }
}
