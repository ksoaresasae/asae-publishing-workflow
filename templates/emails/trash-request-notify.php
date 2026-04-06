<?php
/**
 * Email template: Trash request notification (for admins) and trash review notification (for requester).
 *
 * When used for admin notification: $requester, $reason, $review_url are set.
 * When used for review notification: $action ('approved' or 'denied'), $reviewer, $note are set.
 *
 * Available variables: $post, $requester, $reason, $review_url, $site_name,
 *                      $reviewer, $action, $note, $recipient
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

$is_review = !empty($action);
$post_title = $post ? $post->post_title : sprintf(__('Post #%d', 'asae-publishing-workflow'), 0);
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d2327; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;">

<?php if ($is_review) : ?>

    <?php if ('approved' === $action) : ?>
        <h2 style="color: #00a32a; margin-top: 0;"><?php echo esc_html($site_name); ?> — Trash Request Approved</h2>
        <p><?php printf(
            esc_html__('Your trash request for "%1$s" has been approved by %2$s. The post has been moved to trash.', 'asae-publishing-workflow'),
            '<strong>' . esc_html($post_title) . '</strong>',
            '<strong>' . esc_html($reviewer->display_name) . '</strong>'
        ); ?></p>
    <?php else : ?>
        <h2 style="color: #d63638; margin-top: 0;"><?php echo esc_html($site_name); ?> — Trash Request Denied</h2>
        <p><?php printf(
            esc_html__('Your trash request for "%1$s" has been denied by %2$s.', 'asae-publishing-workflow'),
            '<strong>' . esc_html($post_title) . '</strong>',
            '<strong>' . esc_html($reviewer->display_name) . '</strong>'
        ); ?></p>
    <?php endif; ?>

    <?php if (!empty($note)) : ?>
    <div style="background: #f0f6fc; border-left: 4px solid #72aee6; padding: 12px 16px; margin: 16px 0;">
        <strong><?php esc_html_e('Administrator note:', 'asae-publishing-workflow'); ?></strong><br>
        <?php echo esc_html($note); ?>
    </div>
    <?php endif; ?>

<?php else : ?>

    <h2 style="color: #dba617; margin-top: 0;"><?php echo esc_html($site_name); ?> — Trash Request</h2>

    <p><?php printf(
        esc_html__('%1$s has requested that "%2$s" be moved to trash.', 'asae-publishing-workflow'),
        '<strong>' . esc_html($requester->display_name) . '</strong>',
        '<strong>' . esc_html($post_title) . '</strong>'
    ); ?></p>

    <div style="background: #fcf9e8; border-left: 4px solid #dba617; padding: 12px 16px; margin: 16px 0;">
        <strong><?php esc_html_e('Reason:', 'asae-publishing-workflow'); ?></strong><br>
        <?php echo esc_html($reason); ?>
    </div>

    <?php if (!empty($review_url)) : ?>
    <p>
        <a href="<?php echo esc_url($review_url); ?>" style="display: inline-block; background: #2271b1; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 3px;"><?php esc_html_e('Review Request', 'asae-publishing-workflow'); ?></a>
    </p>
    <?php endif; ?>

<?php endif; ?>

<hr style="border: none; border-top: 1px solid #c3c4c7; margin: 24px 0;">
<p style="font-size: 12px; color: #787c82;"><?php printf(
    esc_html__('This is an automated notification from %s.', 'asae-publishing-workflow'),
    esc_html($site_name)
); ?></p>

</body>
</html>
