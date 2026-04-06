<?php
/**
 * Admin UI for plugin settings.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin_Settings {

    /**
     * Initialize AJAX handlers.
     */
    public static function init() {
        add_action('wp_ajax_asae_pw_save_settings', array(__CLASS__, 'ajax_save'));
    }

    /**
     * Render the settings page.
     */
    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'asae-publishing-workflow'));
        }

        $settings = ASAE_PW_Settings::get();

        // Get all public post types.
        $all_post_types = get_post_types(array('public' => true), 'objects');
        unset($all_post_types['attachment']);

        ?>
            <form id="asae-pw-settings-form">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="asae-pw-disable-xmlrpc"><?php esc_html_e('Disable XML-RPC', 'asae-publishing-workflow'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="disable_xmlrpc" id="asae-pw-disable-xmlrpc" value="1" <?php checked($settings['disable_xmlrpc']); ?>>
                                <?php esc_html_e('Disable all XML-RPC functionality site-wide', 'asae-publishing-workflow'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('This disables all XML-RPC functionality, including the WordPress mobile app\'s legacy connection method and pingbacks.', 'asae-publishing-workflow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="asae-pw-sender-name"><?php esc_html_e('Notification Sender Name', 'asae-publishing-workflow'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="notification_sender_name" id="asae-pw-sender-name" class="regular-text" value="<?php echo esc_attr($settings['notification_sender_name']); ?>">
                            <p class="description"><?php esc_html_e('The "From" name on notification emails.', 'asae-publishing-workflow'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="asae-pw-sender-email"><?php esc_html_e('Notification Sender Email', 'asae-publishing-workflow'); ?></label>
                        </th>
                        <td>
                            <input type="email" name="notification_sender_email" id="asae-pw-sender-email" class="regular-text" value="<?php echo esc_attr($settings['notification_sender_email']); ?>">
                            <p class="description"><?php esc_html_e('The "From" address on notification emails.', 'asae-publishing-workflow'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Post Types', 'asae-publishing-workflow'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('Post Types', 'asae-publishing-workflow'); ?></legend>
                                <?php foreach ($all_post_types as $pt) : ?>
                                    <label>
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $settings['post_types'], true)); ?>>
                                        <?php echo esc_html($pt->labels->name); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Which post types are covered by the workflow.', 'asae-publishing-workflow'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Orphaned Content', 'asae-publishing-workflow'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('Orphaned Content Behavior', 'asae-publishing-workflow'); ?></legend>
                                <label>
                                    <input type="radio" name="orphaned_content" value="admin_only" <?php checked($settings['orphaned_content'], 'admin_only'); ?>>
                                    <?php esc_html_e('Only Admins can edit content with no Content Area assigned (recommended)', 'asae-publishing-workflow'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="orphaned_content" value="all_users" <?php checked($settings['orphaned_content'], 'all_users'); ?>>
                                    <?php esc_html_e('All Editors and Publishers can edit content with no Content Area assigned', 'asae-publishing-workflow'); ?>
                                </label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('How to handle content that has no Content Area taxonomy assigned. The "all users" option is less safe but useful during initial setup or migration.', 'asae-publishing-workflow'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Uninstall Behavior', 'asae-publishing-workflow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="delete_terms_on_uninstall" value="1" <?php checked($settings['delete_terms_on_uninstall']); ?>>
                                <?php esc_html_e('Delete Content Area terms and their assignments when the plugin is uninstalled', 'asae-publishing-workflow'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Warning: This will delete all Content Area taxonomy terms, which affects content organization. Leave unchecked to preserve terms after uninstall.', 'asae-publishing-workflow'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'asae-publishing-workflow'); ?></button>
                    <span class="spinner"></span>
                </p>
            </form>

            <hr>

            <h2><?php esc_html_e('Plugin Updates', 'asae-publishing-workflow'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: version number */
                    esc_html__('Current version: %s', 'asae-publishing-workflow'),
                    '<strong>' . esc_html(ASAE_PW_VERSION) . '</strong>'
                );
                ?>
            </p>
            <button type="button" class="button" id="asae-pw-check-updates"><?php esc_html_e('Check for Updates Now', 'asae-publishing-workflow'); ?></button>
            <span class="spinner" id="asae-pw-update-spinner"></span>
            <span id="asae-pw-update-result"></span>
        <?php
    }

    /**
     * AJAX: Save settings.
     */
    public static function ajax_save() {
        check_ajax_referer('asae_pw_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        $new = array(
            'disable_xmlrpc'            => !empty($_POST['disable_xmlrpc']),
            'notification_sender_name'  => isset($_POST['notification_sender_name']) ? sanitize_text_field(wp_unslash($_POST['notification_sender_name'])) : '',
            'notification_sender_email' => isset($_POST['notification_sender_email']) ? sanitize_email(wp_unslash($_POST['notification_sender_email'])) : '',
            'post_types'                => isset($_POST['post_types']) ? array_map('sanitize_text_field', (array) $_POST['post_types']) : array('post', 'page'),
            'orphaned_content'          => isset($_POST['orphaned_content']) && 'all_users' === $_POST['orphaned_content'] ? 'all_users' : 'admin_only',
            'delete_terms_on_uninstall' => !empty($_POST['delete_terms_on_uninstall']),
        );

        ASAE_PW_Settings::update($new);

        wp_send_json_success(array('message' => __('Settings saved.', 'asae-publishing-workflow')));
    }
}

// Register AJAX handlers.
ASAE_PW_Admin_Settings::init();
