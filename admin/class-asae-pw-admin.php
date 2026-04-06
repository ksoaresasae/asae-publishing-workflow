<?php
/**
 * Admin menu registration and page routing.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'register_menus'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register submenu pages under the ASAE top-level menu.
     */
    public function register_menus() {
        // Fallback: create ASAE top-level menu if asae-explore is not active.
        global $menu;
        $asae_menu_exists = false;
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (isset($item[2]) && 'asae-explore' === $item[2]) {
                    $asae_menu_exists = true;
                    break;
                }
            }
        }

        $parent_slug = 'asae-explore';

        if (!$asae_menu_exists) {
            add_menu_page(
                'ASAE',
                'ASAE',
                'read',
                'asae-explore',
                array($this, 'render_fallback_page'),
                'dashicons-building',
                30
            );
            // Remove duplicate first submenu item.
            add_submenu_page(
                'asae-explore',
                __('ASAE', 'asae-publishing-workflow'),
                __('ASAE Home', 'asae-publishing-workflow'),
                'read',
                'asae-explore',
                array($this, 'render_fallback_page')
            );
        }

        // Dashboard — visible to all PW users and Admins.
        add_submenu_page(
            $parent_slug,
            __('Publishing Workflow', 'asae-publishing-workflow'),
            __('Publishing Workflow', 'asae-publishing-workflow'),
            'read',
            'asae-pw-dashboard',
            array($this, 'route_page')
        );

        // Submissions — visible to Publishers and Admins.
        add_submenu_page(
            $parent_slug,
            __('Submissions', 'asae-publishing-workflow'),
            __('PW Submissions', 'asae-publishing-workflow'),
            'read',
            'asae-pw-submissions',
            array($this, 'route_page')
        );

        // Activity Log — visible to all PW users and Admins.
        add_submenu_page(
            $parent_slug,
            __('Activity Log', 'asae-publishing-workflow'),
            __('PW Activity Log', 'asae-publishing-workflow'),
            'read',
            'asae-pw-activity-log',
            array($this, 'route_page')
        );

        // Assignments — Admin only.
        add_submenu_page(
            $parent_slug,
            __('Assignments', 'asae-publishing-workflow'),
            __('PW Assignments', 'asae-publishing-workflow'),
            'manage_options',
            'asae-pw-assignments',
            array($this, 'route_page')
        );

        // Settings — Admin only.
        add_submenu_page(
            $parent_slug,
            __('PW Settings', 'asae-publishing-workflow'),
            __('PW Settings', 'asae-publishing-workflow'),
            'manage_options',
            'asae-pw-settings',
            array($this, 'route_page')
        );
    }

    /**
     * Route to the correct admin page renderer.
     */
    public function route_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        // Access control for PW users.
        $user = wp_get_current_user();
        if (ASAE_PW_Roles::is_pw_user($user)) {
            // Editors cannot access submissions page.
            if ('asae-pw-submissions' === $page && ASAE_PW_Roles::is_editor($user)) {
                wp_die(esc_html__('You do not have permission to access this page.', 'asae-publishing-workflow'));
            }
        }

        switch ($page) {
            case 'asae-pw-dashboard':
                ASAE_PW_Admin_Dashboard::render();
                break;
            case 'asae-pw-submissions':
                ASAE_PW_Admin_Submissions::render();
                break;
            case 'asae-pw-activity-log':
                ASAE_PW_Admin_Activity::render();
                break;
            case 'asae-pw-assignments':
                ASAE_PW_Admin_Assignments::render();
                break;
            case 'asae-pw-settings':
                ASAE_PW_Admin_Settings::render();
                break;
        }
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
     * Enqueue admin CSS and JS on our plugin pages.
     *
     * @param string $hook_suffix
     */
    public function enqueue_assets($hook_suffix) {
        $our_pages = array(
            'asae_page_asae-pw-dashboard',
            'asae_page_asae-pw-submissions',
            'asae_page_asae-pw-activity-log',
            'asae_page_asae-pw-assignments',
            'asae_page_asae-pw-settings',
            'asae-explore_page_asae-pw-dashboard',
            'asae-explore_page_asae-pw-submissions',
            'asae-explore_page_asae-pw-activity-log',
            'asae-explore_page_asae-pw-assignments',
            'asae-explore_page_asae-pw-settings',
        );

        // Also load on post edit screens for meta boxes.
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
                'workflow' => wp_create_nonce('asae_pw_workflow'),
                'trash'    => wp_create_nonce('asae_pw_trash'),
                'settings' => wp_create_nonce('asae_pw_settings'),
                'assignments' => wp_create_nonce('asae_pw_assignments'),
                'activity'    => wp_create_nonce('asae_pw_activity'),
            ),
            'i18n' => array(
                'confirm_approve'    => __('Are you sure you want to approve this submission?', 'asae-publishing-workflow'),
                'confirm_reject'     => __('Are you sure you want to reject this submission?', 'asae-publishing-workflow'),
                'reject_note_required' => __('A comment is required when rejecting a submission.', 'asae-publishing-workflow'),
                'trash_reason_required' => __('A reason is required for trash requests.', 'asae-publishing-workflow'),
                'loading'            => __('Loading...', 'asae-publishing-workflow'),
                'error'              => __('An error occurred. Please try again.', 'asae-publishing-workflow'),
            ),
        ));
    }
}
