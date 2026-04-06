<?php
/**
 * Admin menu registration and tab-based page routing.
 *
 * Single submenu entry "Publishing Workflow" under the ASAE menu,
 * with internal tabs for Dashboard, Submissions, Activity Log,
 * Assignments, and Settings.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin {

    /** @var string The single page slug. */
    const PAGE_SLUG = 'asae-pw';

    public function __construct() {
        add_action('admin_menu', array($this, 'register_menus'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register a single submenu page under the ASAE top-level menu.
     */
    public function register_menus() {
        // If the ASAE top-level menu doesn't exist yet (Explore not active),
        // create a fallback so our submenu page has a parent.
        global $admin_page_hooks;
        if (empty($admin_page_hooks['asae'])) {
            add_menu_page(
                __('ASAE', 'asae-publishing-workflow'),
                __('ASAE', 'asae-publishing-workflow'),
                'read',
                'asae',
                array($this, 'render_fallback_page'),
                'dashicons-building',
                30
            );
        }

        // Single submenu entry under the ASAE menu.
        add_submenu_page(
            'asae',
            __('Publishing Workflow', 'asae-publishing-workflow'),
            __('Publishing Workflow', 'asae-publishing-workflow'),
            'read',
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    /**
     * Get the current active tab.
     *
     * @return string
     */
    public static function get_current_tab() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        $valid = array('dashboard', 'submissions', 'activity-log', 'assignments', 'settings');
        return in_array($tab, $valid, true) ? $tab : 'dashboard';
    }

    /**
     * Build a URL for a specific tab.
     *
     * @param string $tab   Tab slug.
     * @param array  $extra Extra query args.
     * @return string
     */
    public static function tab_url($tab = 'dashboard', $extra = array()) {
        $args = array_merge(array('page' => self::PAGE_SLUG, 'tab' => $tab), $extra);
        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Render the page with tab navigation and routed content.
     */
    public function render_page() {
        $user       = wp_get_current_user();
        $is_admin   = current_user_can('manage_options');
        $is_publisher = ASAE_PW_Roles::is_publisher($user);
        $is_editor  = ASAE_PW_Roles::is_editor($user);
        $active_tab = self::get_current_tab();

        // Access control.
        if ('submissions' === $active_tab && $is_editor && !$is_admin) {
            wp_die(esc_html__('You do not have permission to access this tab.', 'asae-publishing-workflow'));
        }
        if (in_array($active_tab, array('assignments', 'settings'), true) && !$is_admin) {
            wp_die(esc_html__('You do not have permission to access this tab.', 'asae-publishing-workflow'));
        }

        // Define available tabs with visibility rules.
        $tabs = array();
        $tabs['dashboard'] = __('Dashboard', 'asae-publishing-workflow');

        if ($is_admin || $is_publisher) {
            $tabs['submissions'] = __('Submissions', 'asae-publishing-workflow');
        }

        $tabs['activity-log'] = __('Activity Log', 'asae-publishing-workflow');

        if ($is_admin) {
            $tabs['assignments'] = __('Assignments', 'asae-publishing-workflow');
            $tabs['settings']    = __('Settings', 'asae-publishing-workflow');
        }

        ?>
        <div class="wrap asae-pw-wrap">
            <h1><?php esc_html_e('Publishing Workflow', 'asae-publishing-workflow'); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <a href="<?php echo esc_url(self::tab_url($slug)); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"
                       <?php echo $active_tab === $slug ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="asae-pw-tab-content">
                <?php
                switch ($active_tab) {
                    case 'dashboard':
                        ASAE_PW_Admin_Dashboard::render();
                        break;
                    case 'submissions':
                        ASAE_PW_Admin_Submissions::render();
                        break;
                    case 'activity-log':
                        ASAE_PW_Admin_Activity::render();
                        break;
                    case 'assignments':
                        ASAE_PW_Admin_Assignments::render();
                        break;
                    case 'settings':
                        ASAE_PW_Admin_Settings::render();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render fallback page when asae-explore is not active.
     */
    public function render_fallback_page() {
        echo '<div class="wrap"><h1>ASAE</h1>';
        echo '<p>' . esc_html__('Welcome to the ASAE plugin suite. Use the submenu items to navigate to specific tools.', 'asae-publishing-workflow') . '</p>';
        echo '</div>';
    }

    /**
     * Enqueue admin CSS and JS on our plugin page and post screens.
     *
     * @param string $hook_suffix
     */
    public function enqueue_assets($hook_suffix) {
        $our_pages = array(
            'asae_page_' . self::PAGE_SLUG,
        );

        $is_our_page = in_array($hook_suffix, $our_pages, true)
            || 'post.php' === $hook_suffix
            || 'post-new.php' === $hook_suffix
            || 'edit.php' === $hook_suffix;

        if (!$is_our_page) {
            return;
        }

        wp_enqueue_style(
            'asae-pw-admin',
            ASAE_PW_PLUGIN_URL . 'assets/css/asae-pw-admin.css',
            array(),
            ASAE_PW_VERSION
        );

        wp_enqueue_script(
            'asae-pw-admin',
            ASAE_PW_PLUGIN_URL . 'assets/js/asae-pw-admin.js',
            array('jquery'),
            ASAE_PW_VERSION,
            true
        );

        wp_localize_script('asae-pw-admin', 'asaePW', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonces'   => array(
                'workflow'    => wp_create_nonce('asae_pw_workflow'),
                'trash'       => wp_create_nonce('asae_pw_trash'),
                'settings'    => wp_create_nonce('asae_pw_settings'),
                'assignments' => wp_create_nonce('asae_pw_assignments'),
                'activity'    => wp_create_nonce('asae_pw_activity'),
            ),
            'i18n' => array(
                'confirm_approve'      => __('Are you sure you want to approve this submission?', 'asae-publishing-workflow'),
                'confirm_reject'       => __('Are you sure you want to reject this submission?', 'asae-publishing-workflow'),
                'reject_note_required' => __('A comment is required when rejecting a submission.', 'asae-publishing-workflow'),
                'trash_reason_required' => __('A reason is required for trash requests.', 'asae-publishing-workflow'),
                'loading'              => __('Loading...', 'asae-publishing-workflow'),
                'error'                => __('An error occurred. Please try again.', 'asae-publishing-workflow'),
            ),
        ));
    }
}
