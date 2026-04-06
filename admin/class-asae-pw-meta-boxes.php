<?php
/**
 * Post editor meta boxes — submission controls, status display, activity history.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Meta_Boxes {

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('wp_ajax_asae_pw_load_more_activity', array($this, 'ajax_load_more_activity'));
        add_action('wp_ajax_asae_pw_create_shadow_draft', array($this, 'ajax_create_shadow_draft'));
    }

    /**
     * Register meta boxes on post/page edit screens.
     */
    public function register_meta_boxes() {
        $settings   = ASAE_PW_Settings::get();
        $post_types = $settings['post_types'] ?? array('post', 'page');

        foreach ($post_types as $pt) {
            // Workflow status and actions meta box.
            add_meta_box(
                'asae-pw-workflow-status',
                __('Publishing Workflow', 'asae-publishing-workflow'),
                array($this, 'render_workflow_meta_box'),
                $pt,
                'side',
                'high'
            );

            // Activity history meta box at the bottom.
            add_meta_box(
                'asae-pw-activity-history',
                __('Publishing Activity', 'asae-publishing-workflow'),
                array($this, 'render_activity_meta_box'),
                $pt,
                'normal',
                'low'
            );
        }
    }

    /**
     * Render the workflow status and actions meta box.
     *
     * @param WP_Post $post
     */
    public function render_workflow_meta_box($post) {
        $user = wp_get_current_user();

        // Show shadow draft info.
        $shadow_of = get_post_meta($post->ID, '_asae_pw_shadow_of', true);
        if ($shadow_of) {
            $original = get_post($shadow_of);
            printf(
                '<div class="asae-pw-notice asae-pw-notice-info"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: link to original post */
                    esc_html__('This is a shadow draft of the published post: %s. Changes here will be applied when approved.', 'asae-publishing-workflow'),
                    $original ? '<a href="' . esc_url(get_edit_post_link($shadow_of)) . '">' . esc_html($original->post_title) . '</a>' : '#' . esc_html($shadow_of)
                )
            );
        }

        // Show if original has pending shadow.
        $has_shadow = get_post_meta($post->ID, '_asae_pw_has_shadow', true);
        if ($has_shadow) {
            $shadow_post = get_post($has_shadow);
            if ($shadow_post) {
                printf(
                    '<div class="asae-pw-notice asae-pw-notice-warning"><p>%s</p></div>',
                    sprintf(
                        /* translators: %s: link to shadow draft */
                        esc_html__('This post has a pending shadow draft with proposed changes: %s', 'asae-publishing-workflow'),
                        '<a href="' . esc_url(get_edit_post_link($has_shadow)) . '">' . esc_html__('View shadow draft', 'asae-publishing-workflow') . '</a>'
                    )
                );
            }
        }

        // Pending submission status.
        $pending = ASAE_PW_Workflow::get_pending_submission($post->ID);
        if ($pending) {
            $submitter = get_userdata($pending->submitted_by);
            printf(
                '<div class="asae-pw-notice asae-pw-notice-pending"><p><strong>%s</strong><br>%s: %s<br>%s: %s</p></div>',
                esc_html__('Pending Review', 'asae-publishing-workflow'),
                esc_html__('Submitted by', 'asae-publishing-workflow'),
                $submitter ? esc_html($submitter->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'),
                esc_html__('Date', 'asae-publishing-workflow'),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($pending->submitted_at)))
            );

            if ($pending->submit_note) {
                printf('<p><em>%s</em></p>', esc_html($pending->submit_note));
            }
        }

        // Proposed taxonomy change.
        $proposed = get_post_meta($post->ID, '_asae_pw_proposed_content_area', true);
        if ($proposed) {
            $names = array();
            foreach ((array) $proposed as $tid) {
                $t = get_term($tid, ASAE_PW_Taxonomy::TAXONOMY);
                if ($t && !is_wp_error($t)) {
                    $names[] = $t->name;
                }
            }
            if ($names) {
                printf(
                    '<div class="asae-pw-notice asae-pw-notice-info"><p><strong>%s</strong> %s</p></div>',
                    esc_html__('Proposed Content Area change:', 'asae-publishing-workflow'),
                    esc_html(implode(', ', $names))
                );
            }
        }

        // Action buttons.
        echo '<div class="asae-pw-actions">';

        // Submit for Review button (for Editors, or for pending-status posts).
        if (ASAE_PW_Roles::is_editor($user) && !$pending && 'publish' !== $post->post_status && !$shadow_of) {
            printf(
                '<button type="button" class="button button-primary asae-pw-submit-review-btn" data-post-id="%d">%s</button>',
                esc_attr($post->ID),
                esc_html__('Submit for Review', 'asae-publishing-workflow')
            );
        }

        // Create Shadow Draft button (for Editors editing published content).
        if (ASAE_PW_Roles::is_editor($user) && 'publish' === $post->post_status && !$has_shadow) {
            printf(
                '<button type="button" class="button asae-pw-create-shadow-btn" data-post-id="%d">%s</button>',
                esc_attr($post->ID),
                esc_html__('Edit as Shadow Draft', 'asae-publishing-workflow')
            );
        }

        // Approve/Reject buttons (for Publishers and Admins on pending submissions).
        if ($pending && (current_user_can('manage_options') || ASAE_PW_Roles::is_publisher($user))) {
            printf(
                '<button type="button" class="button button-primary asae-pw-approve-btn" data-submission-id="%d">%s</button> ',
                esc_attr($pending->id),
                esc_html__('Approve', 'asae-publishing-workflow')
            );
            printf(
                '<button type="button" class="button asae-pw-reject-btn" data-submission-id="%d">%s</button>',
                esc_attr($pending->id),
                esc_html__('Reject', 'asae-publishing-workflow')
            );
        }

        // Request Trash button.
        if (ASAE_PW_Roles::is_pw_user($user) && $post->ID) {
            printf(
                '<button type="button" class="button button-link-delete asae-pw-request-trash" data-post-id="%d">%s</button>',
                esc_attr($post->ID),
                esc_html__('Request Trash', 'asae-publishing-workflow')
            );
        }

        echo '</div>';

        // Submit for Review modal.
        ?>
        <div id="asae-pw-submit-modal" class="asae-pw-modal" role="dialog" aria-modal="true" aria-labelledby="asae-pw-submit-modal-title" hidden>
            <div class="asae-pw-modal-overlay"></div>
            <div class="asae-pw-modal-content">
                <h2 id="asae-pw-submit-modal-title"><?php esc_html_e('Submit for Review', 'asae-publishing-workflow'); ?></h2>
                <label for="asae-pw-submit-note"><?php esc_html_e('Note (optional)', 'asae-publishing-workflow'); ?></label>
                <textarea id="asae-pw-submit-note" rows="3" class="large-text"></textarea>
                <div class="asae-pw-modal-actions">
                    <button type="button" class="button button-primary" id="asae-pw-submit-confirm"><?php esc_html_e('Submit', 'asae-publishing-workflow'); ?></button>
                    <button type="button" class="button asae-pw-modal-close"><?php esc_html_e('Cancel', 'asae-publishing-workflow'); ?></button>
                </div>
            </div>
        </div>

        <!-- Trash Request modal -->
        <div id="asae-pw-trash-modal" class="asae-pw-modal" role="dialog" aria-modal="true" aria-labelledby="asae-pw-trash-modal-title" hidden>
            <div class="asae-pw-modal-overlay"></div>
            <div class="asae-pw-modal-content">
                <h2 id="asae-pw-trash-modal-title"><?php esc_html_e('Request Trash', 'asae-publishing-workflow'); ?></h2>
                <p><?php esc_html_e('Please provide a reason for requesting this content be trashed.', 'asae-publishing-workflow'); ?></p>
                <label for="asae-pw-trash-reason"><?php esc_html_e('Reason', 'asae-publishing-workflow'); ?></label>
                <textarea id="asae-pw-trash-reason" rows="3" class="large-text" required></textarea>
                <div class="asae-pw-modal-actions">
                    <button type="button" class="button button-primary" id="asae-pw-trash-confirm"><?php esc_html_e('Submit Request', 'asae-publishing-workflow'); ?></button>
                    <button type="button" class="button asae-pw-modal-close"><?php esc_html_e('Cancel', 'asae-publishing-workflow'); ?></button>
                </div>
                <input type="hidden" id="asae-pw-trash-post-id" value="">
            </div>
        </div>

        <!-- Reject modal (for inline use in post editor) -->
        <div id="asae-pw-reject-modal" class="asae-pw-modal" role="dialog" aria-modal="true" aria-labelledby="asae-pw-reject-modal-title" hidden>
            <div class="asae-pw-modal-overlay"></div>
            <div class="asae-pw-modal-content">
                <h2 id="asae-pw-reject-modal-title"><?php esc_html_e('Reject Submission', 'asae-publishing-workflow'); ?></h2>
                <p><?php esc_html_e('Please explain what needs to change.', 'asae-publishing-workflow'); ?></p>
                <label for="asae-pw-reject-note"><?php esc_html_e('Comment', 'asae-publishing-workflow'); ?></label>
                <textarea id="asae-pw-reject-note" rows="4" class="large-text" required></textarea>
                <div class="asae-pw-modal-actions">
                    <button type="button" class="button button-primary" id="asae-pw-reject-confirm"><?php esc_html_e('Reject', 'asae-publishing-workflow'); ?></button>
                    <button type="button" class="button asae-pw-modal-close"><?php esc_html_e('Cancel', 'asae-publishing-workflow'); ?></button>
                </div>
                <input type="hidden" id="asae-pw-reject-submission-id" value="">
            </div>
        </div>
        <?php
    }

    /**
     * Render the activity history meta box.
     *
     * @param WP_Post $post
     */
    public function render_activity_meta_box($post) {
        $entries = ASAE_PW_Activity_Log::get_post_log($post->ID, 20, 0);
        $total   = ASAE_PW_Activity_Log::count_post_log($post->ID);

        if (empty($entries)) {
            echo '<p>' . esc_html__('No publishing activity recorded for this item.', 'asae-publishing-workflow') . '</p>';
            return;
        }

        echo '<div class="asae-pw-activity-timeline" id="asae-pw-post-activity">';
        foreach ($entries as $entry) {
            $entry_user = get_userdata($entry->user_id);
            printf(
                '<div class="asae-pw-activity-entry asae-pw-activity-%s">
                    <span class="asae-pw-activity-date">%s</span>
                    <span class="asae-pw-activity-user">%s</span>
                    <span class="asae-pw-activity-action">%s</span>
                    %s
                </div>',
                esc_attr($entry->action),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))),
                $entry_user ? esc_html($entry_user->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'),
                esc_html(ASAE_PW_Activity_Log::action_label($entry->action)),
                $entry->detail ? '<span class="asae-pw-activity-detail">' . esc_html($entry->detail) . '</span>' : ''
            );
        }
        echo '</div>';

        if ($total > 20) {
            printf(
                '<button type="button" class="button asae-pw-load-more-activity" data-post-id="%d" data-offset="20" data-total="%d">%s</button>',
                esc_attr($post->ID),
                esc_attr($total),
                esc_html__('Load More', 'asae-publishing-workflow')
            );
        }
    }

    /**
     * AJAX: Load more activity entries.
     */
    public function ajax_load_more_activity() {
        check_ajax_referer('asae_pw_activity', 'nonce');

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $offset  = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

        if (!$post_id) {
            wp_send_json_error();
        }

        $entries = ASAE_PW_Activity_Log::get_post_log($post_id, 20, $offset);
        $html    = '';

        foreach ($entries as $entry) {
            $entry_user = get_userdata($entry->user_id);
            $html .= sprintf(
                '<div class="asae-pw-activity-entry asae-pw-activity-%s">
                    <span class="asae-pw-activity-date">%s</span>
                    <span class="asae-pw-activity-user">%s</span>
                    <span class="asae-pw-activity-action">%s</span>
                    %s
                </div>',
                esc_attr($entry->action),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))),
                $entry_user ? esc_html($entry_user->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'),
                esc_html(ASAE_PW_Activity_Log::action_label($entry->action)),
                $entry->detail ? '<span class="asae-pw-activity-detail">' . esc_html($entry->detail) . '</span>' : ''
            );
        }

        wp_send_json_success(array(
            'html'  => $html,
            'count' => count($entries),
        ));
    }

    /**
     * AJAX: Create a shadow draft.
     */
    public function ajax_create_shadow_draft() {
        check_ajax_referer('asae_pw_workflow', 'nonce');

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        $shadow_id = ASAE_PW_Workflow::create_shadow_draft($post_id, get_current_user_id());

        if (is_wp_error($shadow_id)) {
            wp_send_json_error(array('message' => $shadow_id->get_error_message()));
        }

        wp_send_json_success(array(
            'message'  => __('Shadow draft created. You will be redirected to edit it.', 'asae-publishing-workflow'),
            'edit_url' => get_edit_post_link($shadow_id, 'raw'),
        ));
    }
}
