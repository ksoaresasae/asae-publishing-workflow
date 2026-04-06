<?php
/**
 * Email template: New submission notification for publishers/admins.
 *
 * Available variables: $post, $submitter, $note, $edit_url, $review_url,
 *                      $content_areas, $site_name, $recipient
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

<h2 style="color: #2271b1; margin-top: 0;"><?php echo esc_html($site_name); ?> — New Submission for Review</h2>

<p><?php printf(
    /* translators: 1: submitter name, 2: post title */
    esc_html__('%1$s has submitted "%2$s" for review.', 'asae-publishing-workflow'),
    '<strong>' . esc_html($submitter->display_name) . '</strong>',
    '<strong>' . esc_html($post->post_title) . '</strong>'
); ?></p>

<?php if ($content_areas) : ?>
<p><strong><?php esc_html_e('Content Area:', 'asae-publishing-workflow'); ?></strong> <?php echo esc_html($content_areas); ?></p>
<?php endif; ?>

<?php if ($note) : ?>
<div style="background: #f0f6fc; border-left: 4px solid #72aee6; padding: 12px 16px; margin: 16px 0;">
    <strong><?php esc_html_e('Note from submitter:', 'asae-publishing-workflow'); ?></strong><br>
    <?php echo esc_html($note); ?>
</div>
<?php endif; ?>

<p>
    <a href="<?php echo esc_url($edit_url); ?>" style="display: inline-block; background: #2271b1; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 3px; margin-right: 8px;"><?php esc_html_e('Review Post', 'asae-publishing-workflow'); ?></a>
    <a href="<?php echo esc_url($review_url); ?>" style="color: #2271b1;"><?php esc_html_e('View All Submissions', 'asae-publishing-workflow'); ?></a>
</p>

<hr style="border: none; border-top: 1px solid #c3c4c7; margin: 24px 0;">
<p style="font-size: 12px; color: #787c82;"><?php printf(
    esc_html__('This is an automated notification from %s.', 'asae-publishing-workflow'),
    esc_html($site_name)
); ?></p>

</body>
</html>
