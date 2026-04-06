<?php
/**
 * Email notifications for workflow events.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Notifications {

    public function __construct() {
        // Hooks for Content Area change proposals.
        add_action('asae_pw_taxonomy_change_proposed', array(__CLASS__, 'on_taxonomy_change_proposed'), 10, 3);
    }

    /**
     * Get "From" headers based on settings.
     *
     * @return string[]
     */
    private static function get_headers() {
        $settings   = get_option('asae_pw_settings', array());
        $from_name  = $settings['notification_sender_name'] ?? get_bloginfo('name');
        $from_email = $settings['notification_sender_email'] ?? get_option('admin_email');

        return array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email),
        );
    }

    /**
     * Send an email using a template.
     *
     * @param string $to        Recipient email.
     * @param string $subject   Email subject.
     * @param string $template  Template file name (without path).
     * @param array  $vars      Variables to extract into the template.
     * @param int    $post_id   For activity logging.
     * @return bool
     */
    private static function send($to, $subject, $template, $vars = array(), $post_id = 0) {
        $template_path = ASAE_PW_PLUGIN_DIR . 'templates/emails/' . $template;
        if (!file_exists($template_path)) {
            return false;
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        include $template_path;
        $body = ob_get_clean();

        $sent = wp_mail($to, $subject, $body, self::get_headers());

        if ($post_id) {
            ASAE_PW_Activity_Log::log($post_id, get_current_user_id(), 'notification_sent',
                sprintf(__('Email sent to %s. Subject: %s', 'asae-publishing-workflow'), $to, $subject)
            );
        }

        return $sent;
    }

    /**
     * Notify publishers and admins when an editor submits for review.
     *
     * @param int    $post_id
     * @param int    $submitted_by
     * @param string $note
     */
    public static function on_submission($post_id, $submitted_by, $note = '') {
        $post      = get_post($post_id);
        $submitter = get_userdata($submitted_by);
        if (!$post || !$submitter) {
            return;
        }

        $post_terms   = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
        $publisher_ids = ASAE_PW_Assignments::get_publishers_for_terms($post_terms);
        $admin_ids     = ASAE_PW_Assignments::get_admin_user_ids();
        $recipient_ids = array_unique(array_merge($publisher_ids, $admin_ids));

        $subject = sprintf(
            /* translators: %s: post title */
            __('[%1$s] New submission for review: %2$s', 'asae-publishing-workflow'),
            get_bloginfo('name'),
            $post->post_title
        );

        $edit_url = get_edit_post_link($post_id, 'raw');
        $review_url = admin_url('admin.php?page=asae-pw-submissions');

        $term_names = array();
        foreach ($post_terms as $tid) {
            $t = get_term($tid, ASAE_PW_Taxonomy::TAXONOMY);
            if ($t && !is_wp_error($t)) {
                $term_names[] = $t->name;
            }
        }

        $vars = array(
            'post'          => $post,
            'submitter'     => $submitter,
            'note'          => $note,
            'edit_url'      => $edit_url,
            'review_url'    => $review_url,
            'content_areas' => implode(', ', $term_names),
            'site_name'     => get_bloginfo('name'),
        );

        foreach ($recipient_ids as $uid) {
            $user = get_userdata($uid);
            if ($user && $user->user_email) {
                $vars['recipient'] = $user;
                self::send($user->user_email, $subject, 'submission-notify.php', $vars, $post_id);
            }
        }
    }

    /**
     * Notify editor when their submission is approved.
     *
     * @param int $post_id
     * @param int $editor_id
     * @param int $approved_by
     */
    public static function on_approval($post_id, $editor_id, $approved_by) {
        $post     = get_post($post_id);
        $editor   = get_userdata($editor_id);
        $approver = get_userdata($approved_by);
        if (!$post || !$editor || !$approver) {
            return;
        }

        $subject = sprintf(
            __('[%1$s] Your submission has been approved: %2$s', 'asae-publishing-workflow'),
            get_bloginfo('name'),
            $post->post_title
        );

        $vars = array(
            'post'      => $post,
            'editor'    => $editor,
            'approver'  => $approver,
            'view_url'  => get_permalink($post_id),
            'edit_url'  => get_edit_post_link($post_id, 'raw'),
            'site_name' => get_bloginfo('name'),
        );

        self::send($editor->user_email, $subject, 'approved-notify.php', $vars, $post_id);
    }

    /**
     * Notify editor when their submission is rejected.
     *
     * @param int    $post_id
     * @param int    $editor_id
     * @param int    $rejected_by
     * @param string $review_note
     */
    public static function on_rejection($post_id, $editor_id, $rejected_by, $review_note) {
        $post     = get_post($post_id);
        $editor   = get_userdata($editor_id);
        $rejector = get_userdata($rejected_by);
        if (!$post || !$editor || !$rejector) {
            return;
        }

        $subject = sprintf(
            __('[%1$s] Your submission needs changes: %2$s', 'asae-publishing-workflow'),
            get_bloginfo('name'),
            $post->post_title
        );

        $vars = array(
            'post'        => $post,
            'editor'      => $editor,
            'rejector'    => $rejector,
            'review_note' => $review_note,
            'edit_url'    => get_edit_post_link($post_id, 'raw'),
            'site_name'   => get_bloginfo('name'),
        );

        self::send($editor->user_email, $subject, 'rejected-notify.php', $vars, $post_id);
    }

    /**
     * Notify admins when a trash request is created.
     *
     * @param int    $post_id
     * @param int    $requested_by
     * @param string $reason
     */
    public static function on_trash_request($post_id, $requested_by, $reason) {
        $post      = get_post($post_id);
        $requester = get_userdata($requested_by);
        if (!$post || !$requester) {
            return;
        }

        $admin_ids = ASAE_PW_Assignments::get_admin_user_ids();

        $subject = sprintf(
            __('[%1$s] Trash request: %2$s', 'asae-publishing-workflow'),
            get_bloginfo('name'),
            $post->post_title
        );

        $vars = array(
            'post'       => $post,
            'requester'  => $requester,
            'reason'     => $reason,
            'review_url' => admin_url('admin.php?page=asae-pw-dashboard'),
            'site_name'  => get_bloginfo('name'),
        );

        foreach ($admin_ids as $uid) {
            $user = get_userdata($uid);
            if ($user && $user->user_email) {
                $vars['recipient'] = $user;
                self::send($user->user_email, $subject, 'trash-request-notify.php', $vars, $post_id);
            }
        }
    }

    /**
     * Notify requester when trash request is approved or denied.
     *
     * @param int    $post_id
     * @param int    $requester_id
     * @param int    $reviewed_by
     * @param string $action       'approved' or 'denied'.
     * @param string $note
     */
    public static function on_trash_review($post_id, $requester_id, $reviewed_by, $action, $note = '') {
        $post      = get_post($post_id);
        $requester = get_userdata($requester_id);
        $reviewer  = get_userdata($reviewed_by);
        if (!$requester || !$reviewer) {
            return;
        }

        $subject = sprintf(
            __('[%1$s] Trash request %2$s: %3$s', 'asae-publishing-workflow'),
            get_bloginfo('name'),
            $action,
            $post ? $post->post_title : sprintf(__('Post #%d', 'asae-publishing-workflow'), $post_id)
        );

        $vars = array(
            'post'      => $post,
            'requester' => $requester,
            'reviewer'  => $reviewer,
            'action'    => $action,
            'note'      => $note,
            'site_name' => get_bloginfo('name'),
        );

        // Reuse trash-request-notify template with action context.
        self::send($requester->user_email, $subject, 'trash-request-notify.php', $vars, $post_id);
    }

    /**
     * Notify publishers and admins when an Editor proposes a Content Area change.
     *
     * @param int   $post_id
     * @param int   $user_id
     * @param int[] $proposed_term_ids
     */
    public static function on_taxonomy_change_proposed($post_id, $user_id, $proposed_term_ids) {
        $post = get_post($post_id);
        $user = get_userdata($user_id);
        if (!$post || !$user) {
            return;
        }

        $current_terms  = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
        $all_terms      = array_unique(array_merge($current_terms, $proposed_term_ids));
        $publisher_ids  = ASAE_PW_Assignments::get_publishers_for_terms($all_terms);
        $admin_ids      = ASAE_PW_Assignments::get_admin_user_ids();
        $recipient_ids  = array_unique(array_merge($publisher_ids, $admin_ids));

        $subject = sprintf(
            __('[%1$s] Content Area change proposed: %2$s', 'asae-publishing-workflow'),
            get_bloginfo('name'),
            $post->post_title
        );

        foreach ($recipient_ids as $uid) {
            $recipient = get_userdata($uid);
            if ($recipient && $recipient->user_email) {
                self::send($recipient->user_email, $subject, 'submission-notify.php', array(
                    'post'          => $post,
                    'submitter'     => $user,
                    'note'          => __('Content Area change has been proposed for this post.', 'asae-publishing-workflow'),
                    'edit_url'      => get_edit_post_link($post_id, 'raw'),
                    'review_url'    => admin_url('admin.php?page=asae-pw-submissions'),
                    'content_areas' => '',
                    'site_name'     => get_bloginfo('name'),
                    'recipient'     => $recipient,
                ), $post_id);
            }
        }
    }
}
