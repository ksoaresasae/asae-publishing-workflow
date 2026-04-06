<?php
/**
 * Admin dashboard / overview screen.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin_Dashboard {

    /**
     * Render the dashboard page.
     */
    public static function render() {
        $user    = wp_get_current_user();
        $is_admin = current_user_can('manage_options');
        $is_publisher = ASAE_PW_Roles::is_publisher($user);

        // Get data scoped to user's areas.
        $user_terms = $is_admin ? array() : ASAE_PW_Assignments::get_user_term_ids($user->ID);

        $pending_args = array('status' => 'pending');
        if (!$is_admin && !empty($user_terms)) {
            $pending_args['term_ids'] = $user_terms;
        }
        $pending_count = ASAE_PW_Workflow::count_submissions($pending_args);
        $pending_submissions = ASAE_PW_Workflow::get_submissions(array_merge($pending_args, array('per_page' => 5)));

        $trash_pending_count = $is_admin ? ASAE_PW_Trash::count_pending() : 0;

        ?>
        <div class="wrap asae-pw-wrap">
            <h1><?php esc_html_e('Publishing Workflow', 'asae-publishing-workflow'); ?></h1>

            <div class="asae-pw-dashboard-cards">
                <?php if ($is_admin || $is_publisher) : ?>
                <div class="asae-pw-card">
                    <h2><?php esc_html_e('Pending Submissions', 'asae-publishing-workflow'); ?></h2>
                    <p class="asae-pw-count"><?php echo esc_html($pending_count); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=asae-pw-submissions')); ?>" class="button">
                        <?php esc_html_e('Review Submissions', 'asae-publishing-workflow'); ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($is_admin) : ?>
                <div class="asae-pw-card">
                    <h2><?php esc_html_e('Pending Trash Requests', 'asae-publishing-workflow'); ?></h2>
                    <p class="asae-pw-count"><?php echo esc_html($trash_pending_count); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=asae-pw-dashboard&view=trash-requests')); ?>" class="button">
                        <?php esc_html_e('Review Trash Requests', 'asae-publishing-workflow'); ?>
                    </a>
                </div>
                <?php endif; ?>

                <div class="asae-pw-card">
                    <h2><?php esc_html_e('My Content Areas', 'asae-publishing-workflow'); ?></h2>
                    <?php
                    if ($is_admin) {
                        echo '<p>' . esc_html__('Administrator — access to all Content Areas.', 'asae-publishing-workflow') . '</p>';
                    } else {
                        $assignments = ASAE_PW_Assignments::get_user_assignments($user->ID);
                        if (empty($assignments)) {
                            echo '<p>' . esc_html__('No Content Areas assigned.', 'asae-publishing-workflow') . '</p>';
                        } else {
                            echo '<ul class="asae-pw-assignment-list">';
                            foreach ($assignments as $a) {
                                $term = get_term($a->term_id, ASAE_PW_Taxonomy::TAXONOMY);
                                if ($term && !is_wp_error($term)) {
                                    printf(
                                        '<li><strong>%s</strong> — %s</li>',
                                        esc_html($term->name),
                                        esc_html(ucfirst($a->role))
                                    );
                                }
                            }
                            echo '</ul>';
                        }
                    }
                    ?>
                </div>
            </div>

            <?php if (($is_admin || $is_publisher) && !empty($pending_submissions)) : ?>
            <h2><?php esc_html_e('Recent Pending Submissions', 'asae-publishing-workflow'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Post', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Submitted By', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Date', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Note', 'asae-publishing-workflow'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'asae-publishing-workflow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_submissions as $sub) :
                        $post = get_post($sub->post_id);
                        $submitter = get_userdata($sub->submitted_by);
                    ?>
                    <tr>
                        <td>
                            <?php if ($post) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a>
                            <?php else : ?>
                                <?php echo esc_html(sprintf(__('Post #%d (deleted)', 'asae-publishing-workflow'), $sub->post_id)); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $submitter ? esc_html($submitter->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'); ?></td>
                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sub->submitted_at))); ?></td>
                        <td><?php echo $sub->submit_note ? esc_html($sub->submit_note) : '&mdash;'; ?></td>
                        <td>
                            <button type="button" class="button button-primary asae-pw-approve-btn" data-submission-id="<?php echo esc_attr($sub->id); ?>">
                                <?php esc_html_e('Approve', 'asae-publishing-workflow'); ?>
                            </button>
                            <button type="button" class="button asae-pw-reject-btn" data-submission-id="<?php echo esc_attr($sub->id); ?>">
                                <?php esc_html_e('Reject', 'asae-publishing-workflow'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($is_admin) : ?>
                <?php self::render_trash_requests(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the pending trash requests section.
     */
    private static function render_trash_requests() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
        if ('trash-requests' !== $view) {
            return;
        }

        $requests = ASAE_PW_Trash::get_requests(array('status' => 'pending'));
        ?>
        <h2><?php esc_html_e('Pending Trash Requests', 'asae-publishing-workflow'); ?></h2>

        <?php if (empty($requests)) : ?>
            <p><?php esc_html_e('No pending trash requests.', 'asae-publishing-workflow'); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Post', 'asae-publishing-workflow'); ?></th>
                    <th scope="col"><?php esc_html_e('Requested By', 'asae-publishing-workflow'); ?></th>
                    <th scope="col"><?php esc_html_e('Reason', 'asae-publishing-workflow'); ?></th>
                    <th scope="col"><?php esc_html_e('Date', 'asae-publishing-workflow'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'asae-publishing-workflow'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req) :
                    $post = get_post($req->post_id);
                    $requester = get_userdata($req->requested_by);
                ?>
                <tr>
                    <td>
                        <?php if ($post) : ?>
                            <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a>
                        <?php else : ?>
                            <?php echo esc_html(sprintf(__('Post #%d', 'asae-publishing-workflow'), $req->post_id)); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $requester ? esc_html($requester->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'); ?></td>
                    <td><?php echo esc_html($req->reason); ?></td>
                    <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($req->requested_at))); ?></td>
                    <td>
                        <button type="button" class="button button-primary asae-pw-approve-trash-btn" data-request-id="<?php echo esc_attr($req->id); ?>">
                            <?php esc_html_e('Approve', 'asae-publishing-workflow'); ?>
                        </button>
                        <button type="button" class="button asae-pw-deny-trash-btn" data-request-id="<?php echo esc_attr($req->id); ?>">
                            <?php esc_html_e('Deny', 'asae-publishing-workflow'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif;
    }
}
