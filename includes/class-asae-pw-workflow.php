<?php
/**
 * Workflow state machine — submit, approve, reject, shadow draft system.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Workflow {

    public function __construct() {
        add_action('wp_ajax_asae_pw_submit_for_review', array($this, 'ajax_submit_for_review'));
        add_action('wp_ajax_asae_pw_approve_submission', array($this, 'ajax_approve_submission'));
        add_action('wp_ajax_asae_pw_reject_submission', array($this, 'ajax_reject_submission'));
        add_action('wp_ajax_asae_pw_cancel_submission', array($this, 'ajax_cancel_submission'));
    }

    /**
     * AJAX: Editor submits a post for review.
     */
    public function ajax_submit_for_review() {
        check_ajax_referer('asae_pw_workflow', 'nonce');

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $note    = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('Post not found.', 'asae-publishing-workflow')));
        }

        // Check for existing pending submission.
        $existing = self::get_pending_submission($post_id);
        if ($existing) {
            wp_send_json_error(array('message' => __('This post already has a pending submission.', 'asae-publishing-workflow')));
        }

        // Create submission record.
        $submission_id = self::create_submission($post_id, get_current_user_id(), $note);
        if (!$submission_id) {
            wp_send_json_error(array('message' => __('Failed to create submission.', 'asae-publishing-workflow')));
        }

        // Set post status to pending.
        wp_update_post(array(
            'ID'          => $post_id,
            'post_status' => 'pending',
        ));

        ASAE_PW_Activity_Log::log($post_id, get_current_user_id(), 'submitted',
            $note ? sprintf(__('Submitted for review. Note: %s', 'asae-publishing-workflow'), $note) : __('Submitted for review.', 'asae-publishing-workflow')
        );

        // Send notifications.
        ASAE_PW_Notifications::on_submission($post_id, get_current_user_id(), $note);

        wp_send_json_success(array('message' => __('Post submitted for review.', 'asae-publishing-workflow')));
    }

    /**
     * AJAX: Publisher approves a submission.
     */
    public function ajax_approve_submission() {
        check_ajax_referer('asae_pw_workflow', 'nonce');

        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        if (!$submission_id) {
            wp_send_json_error(array('message' => __('Invalid submission.', 'asae-publishing-workflow')));
        }

        $submission = self::get_submission($submission_id);
        if (!$submission) {
            wp_send_json_error(array('message' => __('Submission not found.', 'asae-publishing-workflow')));
        }

        // Concurrent approval safeguard.
        if ('pending' !== $submission->status) {
            wp_send_json_error(array('message' => __('This submission has already been reviewed.', 'asae-publishing-workflow')));
        }

        $post = get_post($submission->post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('Post not found.', 'asae-publishing-workflow')));
        }

        // Permission check — must be Publisher with area access, or Admin.
        if (!current_user_can('manage_options')) {
            $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post->ID);
            if (!ASAE_PW_Roles::is_publisher(wp_get_current_user()) || !ASAE_PW_Assignments::user_has_term(get_current_user_id(), $post_terms)) {
                wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
            }
        }

        $shadow_of = get_post_meta($post->ID, '_asae_pw_shadow_of', true);

        if ($shadow_of) {
            // This is a shadow draft — merge into original.
            $this->merge_shadow_draft($post, (int) $shadow_of);
        } else {
            // Regular submission — publish the post.
            wp_update_post(array(
                'ID'          => $post->ID,
                'post_status' => 'publish',
            ));
        }

        // Apply proposed Content Area changes if any.
        $proposed_terms = get_post_meta($post->ID, '_asae_pw_proposed_content_area', true);
        $target_post_id = $shadow_of ? (int) $shadow_of : $post->ID;
        if ($proposed_terms) {
            wp_set_object_terms($target_post_id, array_map('intval', $proposed_terms), ASAE_PW_Taxonomy::TAXONOMY);
            delete_post_meta($target_post_id, '_asae_pw_proposed_content_area');
            ASAE_PW_Activity_Log::log($target_post_id, get_current_user_id(), 'taxonomy_change_approved',
                __('Proposed Content Area change applied on approval.', 'asae-publishing-workflow')
            );
        }

        // Update submission record.
        self::update_submission($submission_id, array(
            'status'      => 'approved',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
        ));

        ASAE_PW_Activity_Log::log($target_post_id, get_current_user_id(), 'approved',
            __('Submission approved and published.', 'asae-publishing-workflow')
        );

        // Notify the submitting editor.
        ASAE_PW_Notifications::on_approval($target_post_id, $submission->submitted_by, get_current_user_id());

        wp_send_json_success(array('message' => __('Submission approved and published.', 'asae-publishing-workflow')));
    }

    /**
     * AJAX: Publisher rejects a submission.
     */
    public function ajax_reject_submission() {
        check_ajax_referer('asae_pw_workflow', 'nonce');

        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        $review_note   = isset($_POST['review_note']) ? sanitize_textarea_field(wp_unslash($_POST['review_note'])) : '';

        if (!$submission_id) {
            wp_send_json_error(array('message' => __('Invalid submission.', 'asae-publishing-workflow')));
        }

        if (empty($review_note)) {
            wp_send_json_error(array('message' => __('A comment explaining what needs to change is required when rejecting.', 'asae-publishing-workflow')));
        }

        $submission = self::get_submission($submission_id);
        if (!$submission || 'pending' !== $submission->status) {
            wp_send_json_error(array('message' => __('This submission has already been reviewed.', 'asae-publishing-workflow')));
        }

        $post = get_post($submission->post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('Post not found.', 'asae-publishing-workflow')));
        }

        // Permission check.
        if (!current_user_can('manage_options')) {
            $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post->ID);
            if (!ASAE_PW_Roles::is_publisher(wp_get_current_user()) || !ASAE_PW_Assignments::user_has_term(get_current_user_id(), $post_terms)) {
                wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
            }
        }

        // Revert to draft.
        wp_update_post(array(
            'ID'          => $post->ID,
            'post_status' => 'draft',
        ));

        // Update submission.
        self::update_submission($submission_id, array(
            'status'      => 'rejected',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'review_note' => $review_note,
        ));

        ASAE_PW_Activity_Log::log($post->ID, get_current_user_id(), 'rejected',
            sprintf(__('Submission rejected. Comment: %s', 'asae-publishing-workflow'), $review_note)
        );

        // Notify the submitting editor.
        ASAE_PW_Notifications::on_rejection($post->ID, $submission->submitted_by, get_current_user_id(), $review_note);

        wp_send_json_success(array('message' => __('Submission rejected.', 'asae-publishing-workflow')));
    }

    /**
     * AJAX: Editor cancels their own pending submission.
     */
    public function ajax_cancel_submission() {
        check_ajax_referer('asae_pw_workflow', 'nonce');

        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        if (!$submission_id) {
            wp_send_json_error(array('message' => __('Invalid submission.', 'asae-publishing-workflow')));
        }

        $submission = self::get_submission($submission_id);
        if (!$submission || 'pending' !== $submission->status) {
            wp_send_json_error(array('message' => __('This submission cannot be cancelled.', 'asae-publishing-workflow')));
        }

        // Only the submitter or an Admin can cancel.
        if ((int) $submission->submitted_by !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        // Revert to draft.
        wp_update_post(array(
            'ID'          => $submission->post_id,
            'post_status' => 'draft',
        ));

        self::update_submission($submission_id, array(
            'status'      => 'cancelled',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
        ));

        ASAE_PW_Activity_Log::log($submission->post_id, get_current_user_id(), 'status_changed',
            __('Submission cancelled — post reverted to draft.', 'asae-publishing-workflow')
        );

        wp_send_json_success(array('message' => __('Submission cancelled.', 'asae-publishing-workflow')));
    }

    /**
     * Merge a shadow draft's content into the original published post.
     *
     * @param WP_Post $shadow     The shadow draft post.
     * @param int     $original_id The published post ID.
     */
    private function merge_shadow_draft($shadow, $original_id) {
        $original = get_post($original_id);
        if (!$original) {
            return;
        }

        // Copy content fields.
        wp_update_post(array(
            'ID'           => $original_id,
            'post_title'   => $shadow->post_title,
            'post_content' => $shadow->post_content,
            'post_excerpt' => $shadow->post_excerpt,
            'post_status'  => 'publish',
        ));

        // Copy featured image.
        $shadow_thumb = get_post_thumbnail_id($shadow->ID);
        if ($shadow_thumb) {
            set_post_thumbnail($original_id, $shadow_thumb);
        } else {
            delete_post_thumbnail($original_id);
        }

        // Copy custom fields (excluding internal meta).
        $shadow_meta = get_post_meta($shadow->ID);
        $skip_keys   = array('_asae_pw_shadow_of', '_asae_pw_has_shadow', '_asae_pw_proposed_content_area', '_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id');
        foreach ($shadow_meta as $key => $values) {
            if (in_array($key, $skip_keys, true) || strpos($key, '_asae_pw_') === 0) {
                continue;
            }
            delete_post_meta($original_id, $key);
            foreach ($values as $value) {
                add_post_meta($original_id, $key, maybe_unserialize($value));
            }
        }

        // Clean up shadow.
        delete_post_meta($original_id, '_asae_pw_has_shadow');
        wp_delete_post($shadow->ID, true);

        ASAE_PW_Activity_Log::log($original_id, get_current_user_id(), 'shadow_merged',
            __('Shadow draft content merged into published post.', 'asae-publishing-workflow')
        );
    }

    /**
     * Create a shadow draft for editing a published post.
     *
     * @param int $post_id  The published post ID.
     * @param int $user_id  The Editor creating the shadow.
     * @return int|WP_Error Shadow draft post ID.
     */
    public static function create_shadow_draft($post_id, $user_id) {
        $original = get_post($post_id);
        if (!$original) {
            return new WP_Error('not_found', __('Original post not found.', 'asae-publishing-workflow'));
        }

        // Check if shadow already exists.
        $existing_shadow = get_post_meta($post_id, '_asae_pw_has_shadow', true);
        if ($existing_shadow && get_post($existing_shadow)) {
            return (int) $existing_shadow;
        }

        $shadow_id = wp_insert_post(array(
            'post_title'   => $original->post_title,
            'post_content' => $original->post_content,
            'post_excerpt' => $original->post_excerpt,
            'post_status'  => 'draft',
            'post_type'    => $original->post_type,
            'post_author'  => $user_id,
        ));

        if (is_wp_error($shadow_id)) {
            return $shadow_id;
        }

        // Link shadow and original.
        update_post_meta($shadow_id, '_asae_pw_shadow_of', $post_id);
        update_post_meta($post_id, '_asae_pw_has_shadow', $shadow_id);

        // Copy featured image.
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            set_post_thumbnail($shadow_id, $thumb_id);
        }

        // Copy custom fields.
        $meta = get_post_meta($post_id);
        $skip = array('_asae_pw_shadow_of', '_asae_pw_has_shadow', '_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id');
        foreach ($meta as $key => $values) {
            if (in_array($key, $skip, true) || strpos($key, '_asae_pw_') === 0) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($shadow_id, $key, maybe_unserialize($value));
            }
        }

        // Copy Content Area terms to shadow (for display).
        $terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
        if ($terms) {
            wp_set_object_terms($shadow_id, $terms, ASAE_PW_Taxonomy::TAXONOMY);
        }

        ASAE_PW_Activity_Log::log($post_id, $user_id, 'shadow_created',
            sprintf(__('Shadow draft #%d created for editing published content.', 'asae-publishing-workflow'), $shadow_id)
        );

        return $shadow_id;
    }

    /*
    |----------------------------------------------------------------------
    | Database helpers
    |----------------------------------------------------------------------
    */

    /**
     * Create a submission record.
     *
     * @param int    $post_id
     * @param int    $submitted_by
     * @param string $note
     * @return int|false
     */
    public static function create_submission($post_id, $submitted_by, $note = '') {
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'asae_pw_submissions',
            array(
                'post_id'      => $post_id,
                'submitted_by' => $submitted_by,
                'status'       => 'pending',
                'submitted_at' => current_time('mysql'),
                'submit_note'  => $note,
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get a submission by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function get_submission($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asae_pw_submissions WHERE id = %d",
            $id
        ));
    }

    /**
     * Get the pending submission for a post.
     *
     * @param int $post_id
     * @return object|null
     */
    public static function get_pending_submission($post_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asae_pw_submissions WHERE post_id = %d AND status = 'pending' ORDER BY submitted_at DESC LIMIT 1",
            $post_id
        ));
    }

    /**
     * Update a submission record.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public static function update_submission($id, array $data) {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'asae_pw_submissions',
            $data,
            array('id' => $id)
        );
    }

    /**
     * Get submissions with optional filters.
     *
     * @param array $args {
     *     @type string $status
     *     @type int    $post_id
     *     @type int    $submitted_by
     *     @type int[]  $term_ids  Filter by posts in these Content Areas.
     *     @type int    $per_page
     *     @type int    $offset
     * }
     * @return array
     */
    public static function get_submissions($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'asae_pw_submissions';
        $where = array('1=1');
        $values = array();

        if (!empty($args['status'])) {
            $where[]  = 's.status = %s';
            $values[] = $args['status'];
        }
        if (!empty($args['post_id'])) {
            $where[]  = 's.post_id = %d';
            $values[] = $args['post_id'];
        }
        if (!empty($args['submitted_by'])) {
            $where[]  = 's.submitted_by = %d';
            $values[] = $args['submitted_by'];
        }

        $join = '';
        if (!empty($args['term_ids'])) {
            $placeholders = implode(',', array_fill(0, count($args['term_ids']), '%d'));
            $join = " INNER JOIN {$wpdb->term_relationships} tr ON s.post_id = tr.object_id
                      INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[] = "tt.taxonomy = %s AND tt.term_id IN ({$placeholders})";
            $values[] = ASAE_PW_Taxonomy::TAXONOMY;
            $values = array_merge($values, $args['term_ids']);
        }

        $per_page = $args['per_page'] ?? 25;
        $offset   = $args['offset'] ?? 0;

        $sql = "SELECT DISTINCT s.* FROM {$table} s {$join} WHERE " . implode(' AND ', $where) . " ORDER BY s.submitted_at DESC LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$values));
    }

    /**
     * Count submissions with optional filters.
     *
     * @param array $args Same as get_submissions.
     * @return int
     */
    public static function count_submissions($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'asae_pw_submissions';
        $where = array('1=1');
        $values = array();

        if (!empty($args['status'])) {
            $where[]  = 's.status = %s';
            $values[] = $args['status'];
        }
        if (!empty($args['post_id'])) {
            $where[]  = 's.post_id = %d';
            $values[] = $args['post_id'];
        }

        $join = '';
        if (!empty($args['term_ids'])) {
            $placeholders = implode(',', array_fill(0, count($args['term_ids']), '%d'));
            $join = " INNER JOIN {$wpdb->term_relationships} tr ON s.post_id = tr.object_id
                      INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[] = "tt.taxonomy = %s AND tt.term_id IN ({$placeholders})";
            $values[] = ASAE_PW_Taxonomy::TAXONOMY;
            $values = array_merge($values, $args['term_ids']);
        }

        $sql = "SELECT COUNT(DISTINCT s.id) FROM {$table} s {$join} WHERE " . implode(' AND ', $where);

        if (!empty($values)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, ...$values));
        }
        return (int) $wpdb->get_var($sql);
    }
}
