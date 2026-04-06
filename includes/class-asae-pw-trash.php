<?php
/**
 * Trash request/approval workflow.
 *
 * Editors and Publishers cannot delete posts — they submit trash requests
 * which Admins review.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Trash {

    public function __construct() {
        // AJAX handlers.
        add_action('wp_ajax_asae_pw_request_trash', array($this, 'ajax_request_trash'));
        add_action('wp_ajax_asae_pw_approve_trash', array($this, 'ajax_approve_trash'));
        add_action('wp_ajax_asae_pw_deny_trash', array($this, 'ajax_deny_trash'));

        // Remove "Move to Trash" for PW users and add "Request Trash".
        add_filter('post_row_actions', array($this, 'modify_row_actions'), 10, 2);
        add_filter('page_row_actions', array($this, 'modify_row_actions'), 10, 2);

        // Remove Trash bulk action for PW users.
        add_filter('bulk_actions-edit-post', array($this, 'remove_trash_bulk_action'));
        add_filter('bulk_actions-edit-page', array($this, 'remove_trash_bulk_action'));
    }

    /**
     * AJAX: Create a trash request.
     */
    public function ajax_request_trash() {
        check_ajax_referer('asae_pw_trash', 'nonce');

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $reason  = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if (!$post_id || empty($reason)) {
            wp_send_json_error(array('message' => __('Post ID and reason are required.', 'asae-publishing-workflow')));
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        // Check for existing pending request.
        $existing = self::get_pending_request($post_id);
        if ($existing) {
            wp_send_json_error(array('message' => __('A trash request for this post is already pending.', 'asae-publishing-workflow')));
        }

        $request_id = self::create_request($post_id, get_current_user_id(), $reason);
        if (!$request_id) {
            wp_send_json_error(array('message' => __('Failed to create trash request.', 'asae-publishing-workflow')));
        }

        ASAE_PW_Activity_Log::log($post_id, get_current_user_id(), 'trash_requested',
            sprintf(__('Trash requested. Reason: %s', 'asae-publishing-workflow'), $reason)
        );

        ASAE_PW_Notifications::on_trash_request($post_id, get_current_user_id(), $reason);

        wp_send_json_success(array('message' => __('Trash request submitted. An administrator will review it.', 'asae-publishing-workflow')));
    }

    /**
     * AJAX: Admin approves a trash request.
     */
    public function ajax_approve_trash() {
        check_ajax_referer('asae_pw_trash', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Only administrators can approve trash requests.', 'asae-publishing-workflow')));
        }

        $request_id = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
        $note       = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        $request = self::get_request($request_id);
        if (!$request || 'pending' !== $request->status) {
            wp_send_json_error(array('message' => __('This request has already been reviewed.', 'asae-publishing-workflow')));
        }

        // Actually trash the post.
        wp_trash_post($request->post_id);

        self::update_request($request_id, array(
            'status'      => 'approved',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'review_note' => $note,
        ));

        ASAE_PW_Activity_Log::log($request->post_id, get_current_user_id(), 'trash_approved',
            $note ? sprintf(__('Trash request approved. Note: %s', 'asae-publishing-workflow'), $note) : __('Trash request approved.', 'asae-publishing-workflow')
        );

        ASAE_PW_Notifications::on_trash_review($request->post_id, (int) $request->requested_by, get_current_user_id(), 'approved', $note);

        wp_send_json_success(array('message' => __('Trash request approved. Post has been trashed.', 'asae-publishing-workflow')));
    }

    /**
     * AJAX: Admin denies a trash request.
     */
    public function ajax_deny_trash() {
        check_ajax_referer('asae_pw_trash', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Only administrators can review trash requests.', 'asae-publishing-workflow')));
        }

        $request_id = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
        $note       = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if (empty($note)) {
            wp_send_json_error(array('message' => __('A note is required when denying a trash request.', 'asae-publishing-workflow')));
        }

        $request = self::get_request($request_id);
        if (!$request || 'pending' !== $request->status) {
            wp_send_json_error(array('message' => __('This request has already been reviewed.', 'asae-publishing-workflow')));
        }

        self::update_request($request_id, array(
            'status'      => 'denied',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'review_note' => $note,
        ));

        ASAE_PW_Activity_Log::log($request->post_id, get_current_user_id(), 'trash_denied',
            sprintf(__('Trash request denied. Note: %s', 'asae-publishing-workflow'), $note)
        );

        ASAE_PW_Notifications::on_trash_review($request->post_id, (int) $request->requested_by, get_current_user_id(), 'denied', $note);

        wp_send_json_success(array('message' => __('Trash request denied.', 'asae-publishing-workflow')));
    }

    /**
     * Replace "Trash" with "Request Trash" in post row actions.
     *
     * @param array   $actions
     * @param WP_Post $post
     * @return array
     */
    public function modify_row_actions($actions, $post) {
        if (!ASAE_PW_Roles::is_pw_user()) {
            return $actions;
        }

        if (!ASAE_PW_Permissions::is_managed_post_type($post->post_type)) {
            return $actions;
        }

        // Remove Trash link.
        unset($actions['trash']);

        // Add Request Trash link.
        $actions['request_trash'] = sprintf(
            '<a href="#" class="asae-pw-request-trash" data-post-id="%d" aria-label="%s">%s</a>',
            $post->ID,
            /* translators: %s: post title */
            esc_attr(sprintf(__('Request trash for &#8220;%s&#8221;', 'asae-publishing-workflow'), $post->post_title)),
            esc_html__('Request Trash', 'asae-publishing-workflow')
        );

        // Add Activity link.
        $actions['activity'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url(admin_url('admin.php?page=asae-pw-activity-log&post_id=' . $post->ID)),
            esc_attr(sprintf(__('View activity for &#8220;%s&#8221;', 'asae-publishing-workflow'), $post->post_title)),
            esc_html__('Activity', 'asae-publishing-workflow')
        );

        return $actions;
    }

    /**
     * Remove "Move to Trash" from bulk actions for PW users.
     *
     * @param array $actions
     * @return array
     */
    public function remove_trash_bulk_action($actions) {
        if (ASAE_PW_Roles::is_pw_user()) {
            unset($actions['trash']);
        }
        return $actions;
    }

    /*
    |----------------------------------------------------------------------
    | Database helpers
    |----------------------------------------------------------------------
    */

    /**
     * Create a trash request.
     *
     * @param int    $post_id
     * @param int    $requested_by
     * @param string $reason
     * @return int|false
     */
    public static function create_request($post_id, $requested_by, $reason) {
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'asae_pw_trash_requests',
            array(
                'post_id'      => $post_id,
                'requested_by' => $requested_by,
                'status'       => 'pending',
                'reason'       => $reason,
                'requested_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get a trash request by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function get_request($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asae_pw_trash_requests WHERE id = %d",
            $id
        ));
    }

    /**
     * Get pending trash request for a post.
     *
     * @param int $post_id
     * @return object|null
     */
    public static function get_pending_request($post_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asae_pw_trash_requests WHERE post_id = %d AND status = 'pending' ORDER BY requested_at DESC LIMIT 1",
            $post_id
        ));
    }

    /**
     * Update a trash request.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public static function update_request($id, array $data) {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'asae_pw_trash_requests',
            $data,
            array('id' => $id)
        );
    }

    /**
     * Get trash requests with optional filters.
     *
     * @param array $args
     * @return array
     */
    public static function get_requests($args = array()) {
        global $wpdb;
        $table  = $wpdb->prefix . 'asae_pw_trash_requests';
        $where  = array('1=1');
        $values = array();

        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }
        if (!empty($args['post_id'])) {
            $where[]  = 'post_id = %d';
            $values[] = $args['post_id'];
        }

        $per_page = $args['per_page'] ?? 25;
        $offset   = $args['offset'] ?? 0;

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY requested_at DESC LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$values));
    }

    /**
     * Count pending trash requests.
     *
     * @return int
     */
    public static function count_pending() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asae_pw_trash_requests WHERE status = 'pending'");
    }
}
