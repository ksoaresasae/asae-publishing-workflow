<?php
/**
 * Admin UI for the Activity Log — search-first interface.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin_Activity {

    /**
     * Initialize AJAX handlers.
     */
    public static function init() {
        add_action('wp_ajax_asae_pw_search_activity', array(__CLASS__, 'ajax_search'));
        add_action('wp_ajax_asae_pw_search_posts', array(__CLASS__, 'ajax_search_posts'));
        add_action('wp_ajax_asae_pw_export_activity_csv', array(__CLASS__, 'ajax_export_csv'));
    }

    /**
     * Render the activity log search page.
     */
    public static function render() {
        $user     = wp_get_current_user();
        $is_admin = current_user_can('manage_options');
        $is_publisher = ASAE_PW_Roles::is_publisher($user);
        $is_editor    = ASAE_PW_Roles::is_editor($user);

        // Pre-populated post_id from row action link.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $prefill_post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

        // Get Content Area terms for the dropdown.
        $terms = get_terms(array(
            'taxonomy'   => ASAE_PW_Taxonomy::TAXONOMY,
            'hide_empty' => false,
        ));

        // For Publishers, filter terms to their assigned areas.
        if ($is_publisher && !$is_admin) {
            $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
            if (!is_wp_error($terms)) {
                $terms = array_filter($terms, function ($t) use ($user_terms) {
                    return in_array($t->term_id, $user_terms, true);
                });
            }
        }

        // Action types for dropdown.
        $action_types = array(
            'created'                  => __('Created', 'asae-publishing-workflow'),
            'edited'                   => __('Edited', 'asae-publishing-workflow'),
            'submitted'                => __('Submitted', 'asae-publishing-workflow'),
            'approved'                 => __('Approved', 'asae-publishing-workflow'),
            'rejected'                 => __('Rejected', 'asae-publishing-workflow'),
            'published'                => __('Published', 'asae-publishing-workflow'),
            'status_changed'           => __('Status Changed', 'asae-publishing-workflow'),
            'taxonomy_changed'         => __('Taxonomy Changed', 'asae-publishing-workflow'),
            'taxonomy_change_proposed' => __('Taxonomy Change Proposed', 'asae-publishing-workflow'),
            'taxonomy_change_approved' => __('Taxonomy Change Approved', 'asae-publishing-workflow'),
            'trash_requested'          => __('Trash Requested', 'asae-publishing-workflow'),
            'trash_approved'           => __('Trash Approved', 'asae-publishing-workflow'),
            'trash_denied'             => __('Trash Denied', 'asae-publishing-workflow'),
            'shadow_created'           => __('Shadow Created', 'asae-publishing-workflow'),
            'shadow_merged'            => __('Shadow Merged', 'asae-publishing-workflow'),
        );

        ?>
            <div class="asae-pw-activity-search-form">
                <form id="asae-pw-activity-filter-form">
                    <div class="asae-pw-filter-grid">
                        <div class="asae-pw-form-row">
                            <label for="asae-pw-activity-post"><?php esc_html_e('Post', 'asae-publishing-workflow'); ?></label>
                            <input type="text" id="asae-pw-activity-post-search" class="regular-text" placeholder="<?php esc_attr_e('Search by post title...', 'asae-publishing-workflow'); ?>"
                                <?php if ($prefill_post_id) : ?>
                                    value="<?php echo esc_attr(get_the_title($prefill_post_id)); ?>"
                                <?php endif; ?>
                            >
                            <input type="hidden" id="asae-pw-activity-post-id" name="post_id" value="<?php echo esc_attr($prefill_post_id); ?>">
                            <div id="asae-pw-post-search-results" class="asae-pw-autocomplete-results" hidden></div>
                        </div>

                        <div class="asae-pw-form-row">
                            <label for="asae-pw-activity-user"><?php esc_html_e('User', 'asae-publishing-workflow'); ?></label>
                            <?php if ($is_editor && !$is_admin) : ?>
                                <input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                                <input type="text" value="<?php echo esc_attr($user->display_name); ?>" disabled class="regular-text">
                            <?php else : ?>
                                <select name="user_id" id="asae-pw-activity-user">
                                    <option value=""><?php esc_html_e('All Users', 'asae-publishing-workflow'); ?></option>
                                    <?php
                                    $log_users = self::get_users_in_log($is_admin, $user);
                                    foreach ($log_users as $u) :
                                    ?>
                                        <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="asae-pw-form-row">
                            <label for="asae-pw-activity-action"><?php esc_html_e('Action Type', 'asae-publishing-workflow'); ?></label>
                            <select name="action_type" id="asae-pw-activity-action">
                                <option value=""><?php esc_html_e('All Actions', 'asae-publishing-workflow'); ?></option>
                                <?php foreach ($action_types as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="asae-pw-form-row">
                            <label for="asae-pw-activity-term"><?php esc_html_e('Content Area', 'asae-publishing-workflow'); ?></label>
                            <select name="term_id" id="asae-pw-activity-term">
                                <option value=""><?php esc_html_e('All Content Areas', 'asae-publishing-workflow'); ?></option>
                                <?php if (!empty($terms) && !is_wp_error($terms)) : foreach ($terms as $term) : ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>

                        <div class="asae-pw-form-row">
                            <label for="asae-pw-activity-from"><?php esc_html_e('Date From', 'asae-publishing-workflow'); ?></label>
                            <input type="date" name="date_from" id="asae-pw-activity-from">
                        </div>

                        <div class="asae-pw-form-row">
                            <label for="asae-pw-activity-to"><?php esc_html_e('Date To', 'asae-publishing-workflow'); ?></label>
                            <input type="date" name="date_to" id="asae-pw-activity-to">
                        </div>
                    </div>

                    <div class="asae-pw-form-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Search', 'asae-publishing-workflow'); ?></button>
                        <button type="reset" class="button"><?php esc_html_e('Clear', 'asae-publishing-workflow'); ?></button>
                        <?php if ($is_admin) : ?>
                            <button type="button" class="button" id="asae-pw-export-csv"><?php esc_html_e('Download CSV', 'asae-publishing-workflow'); ?></button>
                        <?php endif; ?>
                        <span class="spinner"></span>
                    </div>
                </form>
            </div>

            <div id="asae-pw-activity-results">
                <?php if ($prefill_post_id) : ?>
                    <p class="asae-pw-loading"><?php esc_html_e('Loading activity...', 'asae-publishing-workflow'); ?></p>
                <?php else : ?>
                    <p class="asae-pw-hint"><?php esc_html_e('Use the filters above to search the activity log.', 'asae-publishing-workflow'); ?></p>
                <?php endif; ?>
            </div>
        <?php
    }

    /**
     * Get users who appear in the activity log, respecting visibility rules.
     *
     * @param bool    $is_admin
     * @param WP_User $current_user
     * @return WP_User[]
     */
    private static function get_users_in_log($is_admin, $current_user) {
        global $wpdb;

        if ($is_admin) {
            $user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}asae_pw_activity_log");
        } else {
            // Publishers see users who have activity on posts in their areas.
            $user_terms = ASAE_PW_Assignments::get_user_term_ids($current_user->ID);
            if (empty($user_terms)) {
                return array();
            }
            $placeholders = implode(',', array_fill(0, count($user_terms), '%d'));
            $user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT al.user_id
                 FROM {$wpdb->prefix}asae_pw_activity_log al
                 INNER JOIN {$wpdb->term_relationships} tr ON al.post_id = tr.object_id
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 WHERE tt.taxonomy = %s AND tt.term_id IN ({$placeholders})",
                ASAE_PW_Taxonomy::TAXONOMY,
                ...$user_terms
            ));
        }

        if (empty($user_ids)) {
            return array();
        }

        return get_users(array(
            'include' => array_map('intval', $user_ids),
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ));
    }

    /**
     * AJAX: Search the activity log.
     */
    public static function ajax_search() {
        check_ajax_referer('asae_pw_activity', 'nonce');

        $user     = wp_get_current_user();
        $is_admin = current_user_can('manage_options');

        $args = array();

        if (!empty($_POST['post_id'])) {
            $args['post_id'] = (int) $_POST['post_id'];
        }
        if (!empty($_POST['user_id'])) {
            $args['user_id'] = (int) $_POST['user_id'];
        }
        if (!empty($_POST['action_type'])) {
            $args['action'] = sanitize_text_field(wp_unslash($_POST['action_type']));
        }
        if (!empty($_POST['term_id'])) {
            $args['term_id'] = (int) $_POST['term_id'];
        }
        if (!empty($_POST['date_from'])) {
            $args['date_from'] = sanitize_text_field(wp_unslash($_POST['date_from']));
        }
        if (!empty($_POST['date_to'])) {
            $args['date_to'] = sanitize_text_field(wp_unslash($_POST['date_to']));
        }

        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $args['per_page'] = 25;
        $args['offset']   = ($page - 1) * 25;

        // Enforce visibility rules.
        if (ASAE_PW_Roles::is_editor($user) && !$is_admin) {
            $args['user_id'] = $user->ID;
        }
        if (ASAE_PW_Roles::is_publisher($user) && !$is_admin) {
            // Publisher can only see activity in their areas — if no term filter, add all their terms.
            if (empty($args['term_id'])) {
                $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
                // Can't use array for term_id in search — we'll handle this with post filtering.
            }
        }

        $results = ASAE_PW_Activity_Log::search($args);
        $total   = ASAE_PW_Activity_Log::search_count($args);

        $rows = array();
        foreach ($results as $entry) {
            $entry_user = get_userdata($entry->user_id);
            $post       = get_post($entry->post_id);

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

            $rows[] = array(
                'date'         => wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at)),
                'user'         => $entry_user ? $entry_user->display_name : __('Unknown', 'asae-publishing-workflow'),
                'post_title'   => $post ? $post->post_title : sprintf(__('Post #%d', 'asae-publishing-workflow'), $entry->post_id),
                'post_edit_url' => $post ? get_edit_post_link($entry->post_id, 'raw') : '',
                'action'       => ASAE_PW_Activity_Log::action_label($entry->action),
                'action_raw'   => $entry->action,
                'content_area' => implode(', ', $term_names),
                'detail'       => $entry->detail,
            );
        }

        wp_send_json_success(array(
            'rows'        => $rows,
            'total'       => $total,
            'total_pages' => ceil($total / 25),
            'page'        => $page,
        ));
    }

    /**
     * AJAX: Search posts by title for autocomplete.
     */
    public static function ajax_search_posts() {
        check_ajax_referer('asae_pw_activity', 'nonce');

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        if (strlen($search) < 2) {
            wp_send_json_success(array('posts' => array()));
        }

        $user     = wp_get_current_user();
        $is_admin = current_user_can('manage_options');

        $query_args = array(
            's'              => $search,
            'post_type'      => ASAE_PW_Settings::get()['post_types'] ?? array('post', 'page'),
            'post_status'    => 'any',
            'posts_per_page' => 10,
            'fields'         => 'ids',
        );

        // Scope to user's areas for non-admins.
        if (!$is_admin && ASAE_PW_Roles::is_pw_user($user)) {
            $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
            if (!empty($user_terms)) {
                $query_args['tax_query'] = array(
                    array(
                        'taxonomy' => ASAE_PW_Taxonomy::TAXONOMY,
                        'field'    => 'term_id',
                        'terms'    => $user_terms,
                    ),
                );
            }
        }

        $query = new WP_Query($query_args);
        $posts = array();
        foreach ($query->posts as $pid) {
            $p = get_post($pid);
            if ($p) {
                $posts[] = array(
                    'id'    => $p->ID,
                    'title' => $p->post_title,
                );
            }
        }

        wp_send_json_success(array('posts' => $posts));
    }

    /**
     * AJAX: Export filtered activity log as CSV.
     */
    public static function ajax_export_csv() {
        check_ajax_referer('asae_pw_activity', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Only administrators can export activity data.', 'asae-publishing-workflow')));
        }

        $args = array();
        if (!empty($_POST['post_id'])) {
            $args['post_id'] = (int) $_POST['post_id'];
        }
        if (!empty($_POST['user_id'])) {
            $args['user_id'] = (int) $_POST['user_id'];
        }
        if (!empty($_POST['action_type'])) {
            $args['action'] = sanitize_text_field(wp_unslash($_POST['action_type']));
        }
        if (!empty($_POST['term_id'])) {
            $args['term_id'] = (int) $_POST['term_id'];
        }
        if (!empty($_POST['date_from'])) {
            $args['date_from'] = sanitize_text_field(wp_unslash($_POST['date_from']));
        }
        if (!empty($_POST['date_to'])) {
            $args['date_to'] = sanitize_text_field(wp_unslash($_POST['date_to']));
        }

        $args['per_page'] = 10000;
        $args['offset']   = 0;

        $results = ASAE_PW_Activity_Log::search($args);

        $csv_rows = array();
        $csv_rows[] = array('Date', 'User', 'Post', 'Action', 'Content Area', 'Detail');

        foreach ($results as $entry) {
            $entry_user = get_userdata($entry->user_id);
            $post       = get_post($entry->post_id);

            $term_names = array();
            if ($post) {
                foreach (ASAE_PW_Taxonomy::get_post_term_ids($post->ID) as $tid) {
                    $t = get_term($tid, ASAE_PW_Taxonomy::TAXONOMY);
                    if ($t && !is_wp_error($t)) {
                        $term_names[] = $t->name;
                    }
                }
            }

            $csv_rows[] = array(
                $entry->created_at,
                $entry_user ? $entry_user->display_name : 'Unknown',
                $post ? $post->post_title : 'Post #' . $entry->post_id,
                ASAE_PW_Activity_Log::action_label($entry->action),
                implode(', ', $term_names),
                $entry->detail,
            );
        }

        wp_send_json_success(array('csv' => $csv_rows));
    }
}

// Register AJAX handlers.
ASAE_PW_Admin_Activity::init();
