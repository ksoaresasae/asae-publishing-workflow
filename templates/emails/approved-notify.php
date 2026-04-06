<?php
/**
 * Email template: Submission approved notification for editor.
 *
 * Available variables: $post, $editor, $approver, $view_url, $edit_url, $site_name
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

<h2 style="color: #00a32a; margin-top: 0;"><?php echo esc_html($site_name); ?> — Submission Approved</h2>

<p><?php printf(
    /* translators: 1: approver name, 2: post title */
    esc_html__('Your submission "%2$s" has been approved by %1$s and is now published.', 'asae-publishing-workflow'),
    '<strong>' . esc_html($approver->display_name) . '</strong>',
    '<strong>' . esc_html($post->post_title) . '</strong>'
); ?></p>

<p>
    <a href="<?php echo esc_url($view_url); ?>" style="display: inline-block; background: #00a32a; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 3px; margin-right: 8px;"><?php esc_html_e('View Published Post', 'asae-publishing-workflow'); ?></a>
    <a href="<?php echo esc_url($edit_url); ?>" style="color: #2271b1;"><?php esc_html_e('Edit Post', 'asae-publishing-workflow'); ?></a>
</p>

<hr style="border: none; border-top: 1px solid #c3c4c7; margin: 24px 0;">
<p style="font-size: 12px; color: #787c82;"><?php printf(
    esc_html__('This is an automated notification from %s.', 'asae-publishing-workflow'),
    esc_html($site_name)
); ?></p>

</body>
</html>
