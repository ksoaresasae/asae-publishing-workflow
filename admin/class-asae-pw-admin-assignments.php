<?php
/**
 * Admin UI for managing user-to-content-area assignments.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin_Assignments {

    /**
     * Initialize AJAX handlers.
     */
    public static function init() {
        add_action('wp_ajax_asae_pw_add_assignment', array(__CLASS__, 'ajax_add_assignment'));
        add_action('wp_ajax_asae_pw_delete_assignment', array(__CLASS__, 'ajax_delete_assignment'));
        add_action('wp_ajax_asae_pw_bulk_delete_assignments', array(__CLASS__, 'ajax_bulk_delete'));
    }

    /**
     * Render the assignments page.
     */
    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'asae-publishing-workflow'));
        }

        // Get filter values.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_user = isset($_GET['filter_user']) ? (int) $_GET['filter_user'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_role = isset($_GET['filter_role']) ? sanitize_text_field(wp_unslash($_GET['filter_role'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_term = isset($_GET['filter_term']) ? (int) $_GET['filter_term'] : 0;

        $args = array();
        if ($filter_user) {
            $args['user_id'] = $filter_user;
        }
        if ($filter_role) {
            $args['role'] = $filter_role;
        }
        if ($filter_term) {
            $args['term_id'] = $filter_term;
        }

        $assignments = ASAE_PW_Assignments::get_all($args);
        $terms = get_terms(array(
            'taxonomy'   => ASAE_PW_Taxonomy::TAXONOMY,
            'hide_empty' => false,
        ));

        // Get users who could be assigned (PW roles + all users for the dropdown).
        $assignable_users = get_users(array(
            'role__in' => array('asae_pw_editor', 'asae_pw_publisher'),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ));

        ?>
            <div class="asae-pw-add-assignment-form">
                <h2><?php esc_html_e('Add Assignment', 'asae-publishing-workflow'); ?></h2>
                <form id="asae-pw-add-assignment-form" class="asae-pw-inline-form">
                    <div class="asae-pw-form-row">
                        <label for="asae-pw-assign-user"><?php esc_html_e('User', 'asae-publishing-workflow'); ?></label>
                        <select id="asae-pw-assign-user" name="user_id" required>
                            <option value=""><?php esc_html_e('Select a user...', 'asae-publishing-workflow'); ?></option>
                            <?php foreach ($assignable_users as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>">
                                    <?php echo esc_html($u->display_name . ' (' . implode(', ', $u->roles) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="asae-pw-form-row">
                        <label for="asae-pw-assign-role"><?php esc_html_e('Role', 'asae-publishing-workflow'); ?></label>
                        <select id="asae-pw-assign-role" name="role" required>
                            <option value=""><?php esc_html_e('Select a role...', 'asae-publishing-workflow'); ?></option>
                            <option value="editor"><?php esc_html_e('Editor', 'asae-publishing-workflow'); ?></option>
                            <option value="publisher"><?php esc_html_e('Publisher', 'asae-publishing-workflow'); ?></option>
                        </select>
                    </div>

                    <div class="asae-pw-form-row">
                        <fieldset>
                            <legend><?php esc_html_e('Content Areas', 'asae-publishing-workflow'); ?></legend>
                            <?php if (!empty($terms) && !is_wp_error($terms)) : ?>
                                <?php foreach ($terms as $term) : ?>
                                    <label class="asae-pw-checkbox-label">
                                        <input type="checkbox" name="term_ids[]" value="<?php echo esc_attr($term->term_id); ?>">
                                        <?php echo esc_html($term->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <p><?php esc_html_e('No Content Areas defined yet. Create them in the taxonomy editor.', 'asae-publishing-workflow'); ?></p>
                            <?php endif; ?>
                        </fieldset>
                    </div>

                    <button type="submit" class="button button-primary"><?php esc_html_e('Add Assignment', 'asae-publishing-workflow'); ?></button>
                    <span class="spinner"></span>
                </form>
            </div>

            <h2><?php esc_html_e('Current Assignments', 'asae-publishing-workflow'); ?></h2>

            <div class="asae-pw-filter-bar">
                <form method="get" action="">
                    <input type="hidden" name="page" value="asae-pw">
                    <input type="hidden" name="tab" value="assignments">

                    <label for="filter-user" class="screen-reader-text"><?php esc_html_e('Filter by user', 'asae-publishing-workflow'); ?></label>
                    <select name="filter_user" id="filter-user">
                        <option value=""><?php esc_html_e('All Users', 'asae-publishing-workflow'); ?></option>
                        <?php foreach ($assignable_users as $u) : ?>
                            <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($filter_user, $u->ID); ?>>
                                <?php echo esc_html($u->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="filter-role" class="screen-reader-text"><?php esc_html_e('Filter by role', 'asae-publishing-workflow'); ?></label>
                    <select name="filter_role" id="filter-role">
                        <option value=""><?php esc_html_e('All Roles', 'asae-publishing-workflow'); ?></option>
                        <option value="editor" <?php selected($filter_role, 'editor'); ?>><?php esc_html_e('Editor', 'asae-publishing-workflow'); ?></option>
                        <option value="publisher" <?php selected($filter_role, 'publisher'); ?>><?php esc_html_e('Publisher', 'asae-publishing-workflow'); ?></option>
                    </select>

                    <label for="filter-term" class="screen-reader-text"><?php esc_html_e('Filter by Content Area', 'asae-publishing-workflow'); ?></label>
                    <select name="filter_term" id="filter-term">
                        <option value=""><?php esc_html_e('All Content Areas', 'asae-publishing-workflow'); ?></option>
                        <?php if (!empty($terms) && !is_wp_error($terms)) : foreach ($terms as $term) : ?>
                            <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected($filter_term, $term->term_id); ?>>
                                <?php echo esc_html($term->name); ?>
                            </option>
                        <?php endforeach; endif; ?>
                    </select>

                    <button type="submit" class="button"><?php esc_html_e('Filter', 'asae-publishing-workflow'); ?></button>
                </form>
            </div>

            <?php if (empty($assignments)) : ?>
                <p><?php esc_html_e('No assignments found.', 'asae-publishing-workflow'); ?></p>
            <?php else : ?>
            <form id="asae-pw-assignments-table-form">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all"><?php esc_html_e('Select All', 'asae-publishing-workflow'); ?></label>
                                <input id="cb-select-all" type="checkbox">
                            </td>
                            <th scope="col"><?php esc_html_e('User', 'asae-publishing-workflow'); ?></th>
                            <th scope="col"><?php esc_html_e('Role', 'asae-publishing-workflow'); ?></th>
                            <th scope="col"><?php esc_html_e('Content Area', 'asae-publishing-workflow'); ?></th>
                            <th scope="col"><?php esc_html_e('Assigned By', 'asae-publishing-workflow'); ?></th>
                            <th scope="col"><?php esc_html_e('Date', 'asae-publishing-workflow'); ?></th>
                            <th scope="col"><?php esc_html_e('Actions', 'asae-publishing-workflow'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a) :
                            $user = get_userdata($a->user_id);
                            $assigned_by = get_userdata($a->assigned_by);
                            $term = get_term($a->term_id, ASAE_PW_Taxonomy::TAXONOMY);
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($a->id); ?>">
                                    <?php esc_html_e('Select', 'asae-publishing-workflow'); ?>
                                </label>
                                <input type="checkbox" id="cb-select-<?php echo esc_attr($a->id); ?>" name="assignment_ids[]" value="<?php echo esc_attr($a->id); ?>">
                            </th>
                            <td><?php echo $user ? esc_html($user->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'); ?></td>
                            <td><?php echo esc_html(ucfirst($a->role)); ?></td>
                            <td><?php echo ($term && !is_wp_error($term)) ? esc_html($term->name) : esc_html__('Deleted term', 'asae-publishing-workflow'); ?></td>
                            <td><?php echo $assigned_by ? esc_html($assigned_by->display_name) : esc_html__('Unknown', 'asae-publishing-workflow'); ?></td>
                            <td><?php echo esc_html(wp_date(get_option('date_format'), strtotime($a->assigned_at))); ?></td>
                            <td>
                                <button type="button" class="button button-link-delete asae-pw-delete-assignment" data-id="<?php echo esc_attr($a->id); ?>">
                                    <?php esc_html_e('Remove', 'asae-publishing-workflow'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="asae-pw-bulk-actions">
                    <button type="button" class="button asae-pw-bulk-delete-assignments"><?php esc_html_e('Remove Selected', 'asae-publishing-workflow'); ?></button>
                </div>
            </form>
            <?php endif; ?>
        <?php
    }

    /**
     * AJAX: Add assignment(s).
     */
    public static function ajax_add_assignment() {
        check_ajax_referer('asae_pw_assignments', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        $user_id  = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $role     = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : '';
        $term_ids = isset($_POST['term_ids']) ? array_map('intval', (array) $_POST['term_ids']) : array();

        if (!$user_id || !$role || empty($term_ids)) {
            wp_send_json_error(array('message' => __('User, role, and at least one Content Area are required.', 'asae-publishing-workflow')));
        }

        if (!in_array($role, array('editor', 'publisher'), true)) {
            wp_send_json_error(array('message' => __('Invalid role.', 'asae-publishing-workflow')));
        }

        $created = 0;
        $skipped = 0;
        foreach ($term_ids as $tid) {
            $result = ASAE_PW_Assignments::create($user_id, $role, $tid, get_current_user_id());
            if ($result) {
                $created++;
            } else {
                $skipped++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('%d assignment(s) created, %d skipped (duplicates).', 'asae-publishing-workflow'),
                $created,
                $skipped
            ),
        ));
    }

    /**
     * AJAX: Delete a single assignment.
     */
    public static function ajax_delete_assignment() {
        check_ajax_referer('asae_pw_assignments', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        $id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
        if (!$id) {
            wp_send_json_error(array('message' => __('Invalid assignment.', 'asae-publishing-workflow')));
        }

        ASAE_PW_Assignments::delete($id);
        wp_send_json_success(array('message' => __('Assignment removed.', 'asae-publishing-workflow')));
    }

    /**
     * AJAX: Bulk delete assignments.
     */
    public static function ajax_bulk_delete() {
        check_ajax_referer('asae_pw_assignments', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        $ids = isset($_POST['assignment_ids']) ? array_map('intval', (array) $_POST['assignment_ids']) : array();
        if (empty($ids)) {
            wp_send_json_error(array('message' => __('No assignments selected.', 'asae-publishing-workflow')));
        }

        foreach ($ids as $id) {
            ASAE_PW_Assignments::delete($id);
        }

        wp_send_json_success(array(
            'message' => sprintf(__('%d assignment(s) removed.', 'asae-publishing-workflow'), count($ids)),
        ));
    }
}

// Register AJAX handlers.
ASAE_PW_Admin_Assignments::init();
