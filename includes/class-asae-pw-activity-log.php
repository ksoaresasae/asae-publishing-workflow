<?php
/**
 * Audit trail logging.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Activity_Log {

    public function __construct() {
        // Log post creation.
        add_action('wp_insert_post', array($this, 'on_post_created'), 10, 3);

        // Log status transitions.
        add_action('transition_post_status', array($this, 'on_status_transition'), 10, 3);

        // Log taxonomy changes (by Publishers and Admins — Editors are intercepted earlier).
        add_action('set_object_terms', array($this, 'on_terms_set'), 10, 6);
    }

    /**
     * Log new post creation.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update
     */
    public function on_post_created($post_id, $post, $update) {
        if ($update) {
            return;
        }
        if (!ASAE_PW_Permissions::is_managed_post_type($post->post_type)) {
            return;
        }
        if ('attachment' === $post->post_type) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        // Skip shadow drafts — they're logged separately.
        if (get_post_meta($post_id, '_asae_pw_shadow_of', true)) {
            return;
        }

        self::log($post_id, get_current_user_id(), 'created', sprintf(
            __('New %s created.', 'asae-publishing-workflow'),
            get_post_type_object($post->post_type)->labels->singular_name ?? $post->post_type
        ));
    }

    /**
     * Log post status transitions.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public function on_status_transition($new_status, $old_status, $post) {
        if ($new_status === $old_status) {
            return;
        }
        if (!ASAE_PW_Permissions::is_managed_post_type($post->post_type)) {
            return;
        }
        if ('attachment' === $post->post_type) {
            return;
        }
        if (in_array($new_status, array('auto-draft', 'inherit'), true)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $action = 'status_changed';
        if ('publish' === $new_status && 'publish' !== $old_status) {
            $action = 'published';
        }

        self::log($post->ID, get_current_user_id(), $action, sprintf(
            __('Status changed from "%1$s" to "%2$s".', 'asae-publishing-workflow'),
            $old_status,
            $new_status
        ));
    }

    /**
     * Log taxonomy changes by Publishers/Admins.
     *
     * @param int    $object_id
     * @param array  $terms
     * @param array  $tt_ids
     * @param string $taxonomy
     * @param bool   $append
     * @param array  $old_tt_ids
     */
    public function on_terms_set($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if ($taxonomy !== ASAE_PW_Taxonomy::TAXONOMY) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user->ID) {
            return;
        }

        // Editors' changes are intercepted by the permissions class — skip logging here for them.
        if (ASAE_PW_Roles::is_editor($user)) {
            return;
        }

        if (sort($tt_ids) !== sort($old_tt_ids)) {
            self::log($object_id, $user->ID, 'taxonomy_changed',
                __('Content Area assignment changed.', 'asae-publishing-workflow')
            );
        }
    }

    /*
    |----------------------------------------------------------------------
    | Core logging / query methods
    |----------------------------------------------------------------------
    */

    /**
     * Insert an activity log entry.
     *
     * @param int    $post_id
     * @param int    $user_id
     * @param string $action
     * @param string $detail
     * @return int|false Insert ID.
     */
    public static function log($post_id, $user_id, $action, $detail = '') {
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'asae_pw_activity_log',
            array(
                'post_id'    => $post_id,
                'user_id'    => $user_id,
                'action'     => $action,
                'detail'     => $detail,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get log entries for a specific post.
     *
     * @param int $post_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function get_post_log($post_id, $limit = 20, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asae_pw_activity_log WHERE post_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $post_id,
            $limit,
            $offset
        ));
    }

    /**
     * Count log entries for a specific post.
     *
     * @param int $post_id
     * @return int
     */
    public static function count_post_log($post_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}asae_pw_activity_log WHERE post_id = %d",
            $post_id
        ));
    }

    /**
     * Search the activity log with filters.
     *
     * @param array $args {
     *     @type int    $post_id
     *     @type int    $user_id
     *     @type string $action
     *     @type int    $term_id   Content Area term ID.
     *     @type string $date_from Y-m-d
     *     @type string $date_to   Y-m-d
     *     @type int    $per_page
     *     @type int    $offset
     * }
     * @return array
     */
    public static function search($args = array()) {
        global $wpdb;
        $table  = $wpdb->prefix . 'asae_pw_activity_log';
        $where  = array('1=1');
        $values = array();
        $join   = '';

        if (!empty($args['post_id'])) {
            $where[]  = 'al.post_id = %d';
            $values[] = $args['post_id'];
        }
        if (!empty($args['user_id'])) {
            $where[]  = 'al.user_id = %d';
            $values[] = $args['user_id'];
        }
        if (!empty($args['action'])) {
            $where[]  = 'al.action = %s';
            $values[] = $args['action'];
        }
        if (!empty($args['date_from'])) {
            $where[]  = 'al.created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[]  = 'al.created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }
        if (!empty($args['term_id'])) {
            $join = " INNER JOIN {$wpdb->term_relationships} tr ON al.post_id = tr.object_id
                      INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[]  = 'tt.taxonomy = %s AND tt.term_id = %d';
            $values[] = ASAE_PW_Taxonomy::TAXONOMY;
            $values[] = $args['term_id'];
        }

        $per_page = $args['per_page'] ?? 25;
        $offset   = $args['offset'] ?? 0;

        $sql = "SELECT DISTINCT al.* FROM {$table} al {$join} WHERE " . implode(' AND ', $where) . " ORDER BY al.created_at DESC LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$values));
    }

    /**
     * Count search results.
     *
     * @param array $args Same as search().
     * @return int
     */
    public static function search_count($args = array()) {
        global $wpdb;
        $table  = $wpdb->prefix . 'asae_pw_activity_log';
        $where  = array('1=1');
        $values = array();
        $join   = '';

        if (!empty($args['post_id'])) {
            $where[]  = 'al.post_id = %d';
            $values[] = $args['post_id'];
        }
        if (!empty($args['user_id'])) {
            $where[]  = 'al.user_id = %d';
            $values[] = $args['user_id'];
        }
        if (!empty($args['action'])) {
            $where[]  = 'al.action = %s';
            $values[] = $args['action'];
        }
        if (!empty($args['date_from'])) {
            $where[]  = 'al.created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[]  = 'al.created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }
        if (!empty($args['term_id'])) {
            $join = " INNER JOIN {$wpdb->term_relationships} tr ON al.post_id = tr.object_id
                      INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[]  = 'tt.taxonomy = %s AND tt.term_id = %d';
            $values[] = ASAE_PW_Taxonomy::TAXONOMY;
            $values[] = $args['term_id'];
        }

        $sql = "SELECT COUNT(DISTINCT al.id) FROM {$table} al {$join} WHERE " . implode(' AND ', $where);

        if (!empty($values)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, ...$values));
        }
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get a human-readable label for an action.
     *
     * @param string $action
     * @return string
     */
    public static function action_label($action) {
        $labels = array(
            'created'                  => __('Created', 'asae-publishing-workflow'),
            'edited'                   => __('Edited', 'asae-publishing-workflow'),
            'submitted'                => __('Submitted for review', 'asae-publishing-workflow'),
            'approved'                 => __('Approved', 'asae-publishing-workflow'),
            'rejected'                 => __('Rejected', 'asae-publishing-workflow'),
            'published'                => __('Published', 'asae-publishing-workflow'),
            'status_changed'           => __('Status changed', 'asae-publishing-workflow'),
            'taxonomy_changed'         => __('Content Area changed', 'asae-publishing-workflow'),
            'taxonomy_change_proposed' => __('Content Area change proposed', 'asae-publishing-workflow'),
            'taxonomy_change_approved' => __('Content Area change approved', 'asae-publishing-workflow'),
            'trash_requested'          => __('Trash requested', 'asae-publishing-workflow'),
            'trash_approved'           => __('Trash approved', 'asae-publishing-workflow'),
            'trash_denied'             => __('Trash denied', 'asae-publishing-workflow'),
            'shadow_created'           => __('Shadow draft created', 'asae-publishing-workflow'),
            'shadow_merged'            => __('Shadow draft merged', 'asae-publishing-workflow'),
            'notification_sent'        => __('Notification sent', 'asae-publishing-workflow'),
        );
        return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Get all distinct actions in the log (for dropdowns).
     *
     * @return string[]
     */
    public static function get_distinct_actions() {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT action FROM {$wpdb->prefix}asae_pw_activity_log ORDER BY action ASC");
    }
}
