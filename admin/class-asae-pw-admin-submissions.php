<?php
/**
 * Admin UI for reviewing pending submissions.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin_Submissions {

    /**
     * Render the submissions page.
     */
    public static function render() {
        $user     = wp_get_current_user();
        $is_admin = current_user_can('manage_options');

        // Publishers see only their area's submissions; Admins see all.
        $args = array('status' => 'pending');
        if (!$is_admin) {
            $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
            if (empty($user_terms)) {
                echo '<div class="wrap"><h1>' . esc_html__('Submissions', 'asae-publishing-workflow') . '</h1>';
                echo '<p>' . esc_html__('You have no Content Area assignments.', 'asae-publishing-workflow') . '</p></div>';
                return;
            }
            $args['term_ids'] = $user_terms;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'pending';
        if (in_array($status_filter, array('pending', 'approved', 'rejected', 'cancelled', 'all'), true)) {
            $args['status'] = 'all' === $status_filter ? '' : $status_filter;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged  = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $args['per_page'] = 25;
        $args['offset']   = ($paged - 1) * $args['per_page'];

        $submissions = ASAE_PW_Workflow::get_submissions($args);
        $total_count = ASAE_PW_Workflow::count_submissions($args);
        $total_pages = ceil($total_count / $args['per_page']);

        ?>
        <div class="wrap asae-pw-wrap">
            <h1><?php esc_html_e('Submissions', 'asae-publishing-workflow'); ?></h1>

            <ul class="subsubsub">
                <?php
                $statuses = array(
                    'pending'   => __('Pending', 'asae-publishing-workflow'),
                    'approved'  => __('Approved', 'asae-publishing-workflow'),
                    'rejected'  => __('Rejected', 'asae-publishing-workflow'),
                    'cancelled' => __('Cancelled', 'asae-publishing-workflow'),
                    'all'       => __('All', 'asae-publishing-workflow'),
                );
                $i = 0;
                foreach ($statuses as $key => $label) :
                    $i++;
                    $url = add_query_arg(array('page' => 'asae-pw-submissions', 'status' => $key), admin_url('admin.php'));
                    $current = ($status_filter === $key) ? ' class="current" aria-current="page"' : '';
                ?>
                    <li><a href="<?php echo esc_url($url); ?>"<?php echo $current; ?>><?php echo esc_html($label); ?></a><?php echo $i < count($statuses) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Post', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Content Area', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Submitted By', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Date', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Note', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'asae-publishing-workflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No submissions found.', 'asae-publishing-workflow'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($submissions as $sub) :
                            $post = get_post($sub->post_id);
                            $submitter = get_userdata($sub->submitted_by);
                            $reviewer  = $sub->reviewed_by ? get_userdata($sub->reviewed_by) : null;

                            $term_names = array();
                            if ($post) {
                                $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post->ID);
                                foreach ($post_terms as $tid) {
                                    $t = get_term($tid, ASAE_PW_Taxonomy::TAXONOMY);
                                    if ($t && !is_wp_error($t)) {
                                        $term_names[] = $t->name;
                                    }
                                }
                            }
                        ?>
                        <tr>
                            <td>
                                <?php if ($post) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a>
                                    <?php
                                    $shadow_of = get_post_meta($post->ID, '_asae_pw_shadow_of', true);
                                    if ($shadow_of) {
                                        echo ' <span class="asae-pw-badge asae-pw-badge-info">' . esc_html__('Shadow Draft', 'asae-publishing-workflow') . '</span>';
                                    }
                                    ?>
                                <?php else : ?>
                                    <?php echo esc_html(sprintf(__('Post #%d (deleted)', 'asae-publishing-workflow'), $sub->post_id)); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(implode(', ', $term_names) ?: '—'); ?></td>
                            <td><?php echo $submitter ? esc_html($submitter->display_name) : '—'; ?></td>
                            <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sub->submitted_at))); ?></td>
                            <td>
                                <span class="asae-pw-badge asae-pw-badge-<?php echo esc_attr($sub->status); ?>">
                                    <?php echo esc_html(ucfirst($sub->status)); ?>
                                </span>
                                <?php if ($reviewer) : ?>
                                    <br><small><?php echo esc_html(sprintf(__('by %s', 'asae-publishing-workflow'), $reviewer->display_name)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub->submit_note) : ?>
                                    <em><?php echo esc_html($sub->submit_note); ?></em>
                                <?php endif; ?>
                                <?php if ($sub->review_note) : ?>
                                    <br><strong><?php esc_html_e('Review:', 'asae-publishing-workflow'); ?></strong> <?php echo esc_html($sub->review_note); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ('pending' === $sub->status) : ?>
                                    <button type="button" class="button button-primary button-small asae-pw-approve-btn" data-submission-id="<?php echo esc_attr($sub->id); ?>">
                                        <?php esc_html_e('Approve', 'asae-publishing-workflow'); ?>
                                    </button>
                                    <button type="button" class="button button-small asae-pw-reject-btn" data-submission-id="<?php echo esc_attr($sub->id); ?>">
                                        <?php esc_html_e('Reject', 'asae-publishing-workflow'); ?>
                                    </button>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links(array(
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                        'type'    => 'plain',
                    )));
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Rejection Note Modal -->
        <div id="asae-pw-reject-modal" class="asae-pw-modal" role="dialog" aria-modal="true" aria-labelledby="asae-pw-reject-modal-title" hidden>
            <div class="asae-pw-modal-overlay"></div>
            <div class="asae-pw-modal-content">
                <h2 id="asae-pw-reject-modal-title"><?php esc_html_e('Reject Submission', 'asae-publishing-workflow'); ?></h2>
                <p><?php esc_html_e('Please explain what needs to change. This comment will be sent to the editor.', 'asae-publishing-workflow'); ?></p>
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
}
