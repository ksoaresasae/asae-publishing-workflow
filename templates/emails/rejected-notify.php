<?php
/**
 * Email template: Submission rejected notification for editor.
 *
 * Available variables: $post, $editor, $rejector, $review_note, $edit_url, $site_name
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d2327; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;">

<h2 style="color: #d63638; margin-top: 0;"><?php echo esc_html($site_name); ?> — Changes Requested</h2>

<p><?php printf(
    /* translators: 1: rejector name, 2: post title */
    esc_html__('%1$s has reviewed your submission "%2$s" and requested changes.', 'asae-publishing-workflow'),
    '<strong>' . esc_html($rejector->display_name) . '</strong>',
    '<strong>' . esc_html($post->post_title) . '</strong>'
); ?></p>

<div style="background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 16px; margin: 16px 0;">
    <strong><?php esc_html_e('Reviewer comments:', 'asae-publishing-workflow'); ?></strong><br>
    <?php echo esc_html($review_note); ?>
</div>

<p><?php esc_html_e('The post has been reverted to draft status. Please review the comments above, make the requested changes, and resubmit for review.', 'asae-publishing-workflow'); ?></p>

<p>
    <a href="<?php echo esc_url($edit_url); ?>" style="display: inline-block; background: #2271b1; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 3px;"><?php esc_html_e('Edit Post', 'asae-publishing-workflow'); ?></a>
</p>

<hr style="border: none; border-top: 1px solid #c3c4c7; margin: 24px 0;">
<p style="font-size: 12px; color: #787c82;"><?php printf(
    esc_html__('This is an automated notification from %s.', 'asae-publishing-workflow'),
    esc_html($site_name)
); ?></p>

</body>
</html>
