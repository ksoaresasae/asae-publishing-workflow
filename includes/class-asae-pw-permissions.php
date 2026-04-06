<?php
/**
 * Permission enforcement — the most critical file in the plugin.
 *
 * Every permission check across every WordPress entry point flows through here.
 * Hooks: user_has_cap, map_meta_cap, wp_insert_post_data, rest_pre_insert_post,
 * pre_get_posts, inline-save, bulk-edit, admin lockdown.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Permissions {

    public function __construct() {
        // Capability filters.
        add_filter('user_has_cap', array($this, 'filter_user_has_cap'), 10, 4);
        add_filter('map_meta_cap', array($this, 'filter_map_meta_cap'), 10, 4);

        // Pre-save safeguards.
        add_filter('wp_insert_post_data', array($this, 'filter_insert_post_data'), 10, 2);
        add_filter('rest_pre_insert_post', array($this, 'filter_rest_pre_insert'), 10, 2);
        add_filter('rest_pre_insert_page', array($this, 'filter_rest_pre_insert'), 10, 2);

        // Post list filtering.
        add_action('pre_get_posts', array($this, 'filter_post_queries'));

        // Notice on filtered post lists explaining the scope.
        add_action('admin_notices', array($this, 'show_filtering_notice'));

        // Block direct URL access.
        add_action('load-post.php', array($this, 'block_unauthorized_post_access'));
        add_action('load-post-new.php', array($this, 'block_unauthorized_new_post'));

        // Quick Edit and Bulk Edit interception.
        add_action('wp_ajax_inline-save', array($this, 'intercept_inline_save'), 1);
        add_action('wp_ajax_bulk-edit-posts', array($this, 'intercept_bulk_edit'), 1);

        // Admin UI lockdown.
        add_action('admin_menu', array($this, 'lockdown_admin_menu'), 999);
        add_action('admin_init', array($this, 'lockdown_admin_pages'));

        // Media library restrictions.
        add_filter('ajax_query_attachments_args', array($this, 'allow_full_media_library'));
    }

    /**
     * Dynamically grant capabilities based on content area assignments.
     *
     * @param bool[]   $allcaps All capabilities for the user.
     * @param string[] $caps    Required primitive capabilities.
     * @param array    $args    [0] = requested cap, [1] = user ID, [2] = post ID (if applicable).
     * @param WP_User  $user    The user object.
     * @return bool[]
     */
    public function filter_user_has_cap($allcaps, $caps, $args, $user) {
        // Only modify our custom roles.
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return $allcaps;
        }

        $requested_cap = $args[0] ?? '';
        $post_id       = $args[2] ?? 0;

        // Non-post-specific caps — grant if user has any assignments at all.
        $general_caps = array(
            'edit_posts', 'edit_pages', 'edit_others_posts', 'edit_others_pages',
            'edit_published_posts', 'edit_published_pages',
            'edit_private_posts', 'edit_private_pages',
            'publish_posts', 'publish_pages',
            'read_private_posts', 'read_private_pages',
        );

        if (in_array($requested_cap, $general_caps, true) && !$post_id) {
            $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
            if (!empty($user_terms)) {
                // Editors never get publish.
                if (ASAE_PW_Roles::is_editor($user) && in_array($requested_cap, array('publish_posts', 'publish_pages'), true)) {
                    return $allcaps;
                }
                $allcaps[$requested_cap] = true;
            }
            return $allcaps;
        }

        // Post-specific capability checks.
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post) {
                return $allcaps;
            }

            // Check if this post type is covered by the plugin.
            if (!self::is_managed_post_type($post->post_type)) {
                return $allcaps;
            }

            // Attachment-specific rules.
            if ('attachment' === $post->post_type) {
                return $this->handle_attachment_caps($allcaps, $requested_cap, $post, $user);
            }

            $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);

            // Orphaned content — no Content Area assigned.
            if (empty($post_terms)) {
                $settings = get_option('asae_pw_settings', array());
                $orphaned = $settings['orphaned_content'] ?? 'admin_only';
                if ('admin_only' === $orphaned) {
                    return $allcaps;
                }
                // 'all_users' — let the general caps through.
                $post_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
                if (empty($post_terms)) {
                    return $allcaps;
                }
            }

            $has_area = ASAE_PW_Assignments::user_has_term($user->ID, $post_terms);
            if (!$has_area) {
                return $allcaps;
            }

            // User has assignment — grant appropriate caps.
            $edit_caps = array(
                'edit_post', 'edit_posts', 'edit_pages',
                'edit_others_posts', 'edit_others_pages',
                'edit_published_posts', 'edit_published_pages',
                'edit_private_posts', 'edit_private_pages',
                'read_post', 'read_private_posts', 'read_private_pages',
            );

            foreach ($caps as $cap) {
                if (in_array($cap, $edit_caps, true)) {
                    $allcaps[$cap] = true;
                }
            }

            // Publishers also get publish.
            if (ASAE_PW_Roles::is_publisher($user)) {
                $publish_caps = array('publish_posts', 'publish_pages');
                foreach ($caps as $cap) {
                    if (in_array($cap, $publish_caps, true)) {
                        $allcaps[$cap] = true;
                    }
                }
            }
        }

        return $allcaps;
    }

    /**
     * Handle attachment-specific capabilities.
     *
     * @param bool[]  $allcaps
     * @param string  $requested_cap
     * @param WP_Post $post
     * @param WP_User $user
     * @return bool[]
     */
    private function handle_attachment_caps($allcaps, $requested_cap, $post, $user) {
        // Allow browsing/reading all media.
        if (in_array($requested_cap, array('read_post', 'read'), true)) {
            $allcaps[$requested_cap] = true;
            return $allcaps;
        }

        // Allow editing own uploads only.
        if (in_array($requested_cap, array('edit_post', 'edit_posts'), true)) {
            if ((int) $post->post_author === $user->ID) {
                $allcaps['edit_post']  = true;
                $allcaps['edit_posts'] = true;
            }
            return $allcaps;
        }

        // Deny delete for all attachments.
        // Handled in map_meta_cap.
        return $allcaps;
    }

    /**
     * Map meta capabilities — lower-level enforcement.
     *
     * @param string[] $required_caps Required primitive capabilities.
     * @param string   $cap           Capability being checked.
     * @param int      $user_id       User ID.
     * @param array    $args          Additional arguments (post ID, etc.).
     * @return string[]
     */
    public function filter_map_meta_cap($required_caps, $cap, $user_id, $args) {
        $user = get_userdata($user_id);
        if (!$user || !ASAE_PW_Roles::is_pw_user($user)) {
            return $required_caps;
        }

        $post_id = $args[0] ?? 0;

        // Delete post — always deny for both roles.
        if (in_array($cap, array('delete_post', 'delete_page'), true)) {
            if ($post_id) {
                $post = get_post($post_id);
                if ($post && self::is_managed_post_type($post->post_type)) {
                    return array('do_not_allow');
                }
            }
        }

        // Delete attachment — always deny for both roles.
        if ('delete_post' === $cap && $post_id) {
            $post = get_post($post_id);
            if ($post && 'attachment' === $post->post_type) {
                return array('do_not_allow');
            }
        }

        // Edit attachment by others — deny.
        if ('edit_post' === $cap && $post_id) {
            $post = get_post($post_id);
            if ($post && 'attachment' === $post->post_type && (int) $post->post_author !== $user_id) {
                return array('do_not_allow');
            }
        }

        // Publish — always deny for Editors.
        if (in_array($cap, array('publish_posts', 'publish_pages'), true)) {
            if (ASAE_PW_Roles::is_editor($user)) {
                return array('do_not_allow');
            }
        }

        // Publish specific post — check Publisher area.
        if ('publish_post' === $cap && $post_id) {
            if (ASAE_PW_Roles::is_editor($user)) {
                return array('do_not_allow');
            }
            if (ASAE_PW_Roles::is_publisher($user)) {
                $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
                if (!empty($post_terms) && !ASAE_PW_Assignments::user_has_term($user_id, $post_terms)) {
                    return array('do_not_allow');
                }
            }
        }

        // Edit post — check area assignment.
        if (in_array($cap, array('edit_post', 'edit_page'), true) && $post_id) {
            $post = get_post($post_id);
            if ($post && self::is_managed_post_type($post->post_type) && 'attachment' !== $post->post_type) {
                $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
                if (!empty($post_terms) && !ASAE_PW_Assignments::user_has_term($user_id, $post_terms)) {
                    return array('do_not_allow');
                }
                if (empty($post_terms)) {
                    $settings = get_option('asae_pw_settings', array());
                    if (($settings['orphaned_content'] ?? 'admin_only') === 'admin_only') {
                        return array('do_not_allow');
                    }
                }
            }
        }

        return $required_caps;
    }

    /**
     * Prevent unauthorized publishes and intercept taxonomy changes BEFORE database write.
     *
     * @param array $data    Slashed post data.
     * @param array $postarr Raw post array including ID.
     * @return array
     */
    public function filter_insert_post_data($data, $postarr) {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return $data;
        }

        if (!self::is_managed_post_type($data['post_type'])) {
            return $data;
        }

        $post_id = $postarr['ID'] ?? 0;

        // Editors cannot publish or schedule.
        if (ASAE_PW_Roles::is_editor($user)) {
            if (in_array($data['post_status'], array('publish', 'future'), true)) {
                $data['post_status'] = 'pending';
                if ($post_id) {
                    ASAE_PW_Activity_Log::log($post_id, $user->ID, 'status_changed', sprintf(
                        __('Attempted to set status to "%s" — forced to "pending" (Editor cannot publish).', 'asae-publishing-workflow'),
                        $postarr['post_status'] ?? 'publish'
                    ));
                }
            }
        }

        // Publishers can only publish within their areas.
        if (ASAE_PW_Roles::is_publisher($user) && $post_id) {
            if (in_array($data['post_status'], array('publish', 'future'), true)) {
                $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
                if (!empty($post_terms) && !ASAE_PW_Assignments::user_has_term($user->ID, $post_terms)) {
                    $old_status = get_post_field('post_status', $post_id);
                    $data['post_status'] = $old_status ?: 'draft';
                    ASAE_PW_Activity_Log::log($post_id, $user->ID, 'status_changed', sprintf(
                        __('Attempted to publish outside assigned Content Area — status reverted to "%s".', 'asae-publishing-workflow'),
                        $data['post_status']
                    ));
                }
            }
        }

        // Intercept Content Area taxonomy changes by Editors.
        if (ASAE_PW_Roles::is_editor($user) && $post_id) {
            $this->intercept_taxonomy_change($post_id, $user->ID);
        }

        return $data;
    }

    /**
     * If an Editor is attempting to change Content Area terms, store as proposed change instead.
     *
     * @param int $post_id
     * @param int $user_id
     */
    private function intercept_taxonomy_change($post_id, $user_id) {
        $taxonomy = ASAE_PW_Taxonomy::TAXONOMY;

        // Check if taxonomy data is being submitted.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verified upstream.
        $new_terms = isset($_POST['tax_input'][$taxonomy])
            ? array_map('intval', (array) $_POST['tax_input'][$taxonomy])
            : null;

        if (null === $new_terms) {
            return;
        }

        $new_terms = array_filter($new_terms);
        $current_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);

        sort($new_terms);
        sort($current_terms);

        if ($new_terms !== $current_terms) {
            // Store proposed change, don't actually change taxonomy.
            update_post_meta($post_id, '_asae_pw_proposed_content_area', $new_terms);
            // Restore original terms via a late-firing hook.
            add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy_slug) use ($post_id, $current_terms, $taxonomy) {
                if ((int) $object_id === $post_id && $taxonomy_slug === $taxonomy) {
                    wp_set_object_terms($post_id, $current_terms, $taxonomy);
                    // Prevent infinite loop.
                    remove_all_actions('set_object_terms');
                }
            }, 10, 4);

            ASAE_PW_Activity_Log::log($post_id, $user_id, 'taxonomy_change_proposed',
                __('Editor proposed a Content Area change (pending approval).', 'asae-publishing-workflow')
            );
        }
    }

    /**
     * REST API safeguard — identical logic to wp_insert_post_data for Gutenberg.
     *
     * @param stdClass        $prepared_post
     * @param WP_REST_Request $request
     * @return stdClass
     */
    public function filter_rest_pre_insert($prepared_post, $request) {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return $prepared_post;
        }

        // Editors cannot publish or schedule via REST.
        if (ASAE_PW_Roles::is_editor($user)) {
            if (isset($prepared_post->post_status) && in_array($prepared_post->post_status, array('publish', 'future'), true)) {
                $prepared_post->post_status = 'pending';
            }
        }

        // Publishers outside their area cannot publish via REST.
        if (ASAE_PW_Roles::is_publisher($user) && !empty($prepared_post->ID)) {
            if (isset($prepared_post->post_status) && in_array($prepared_post->post_status, array('publish', 'future'), true)) {
                $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($prepared_post->ID);
                if (!empty($post_terms) && !ASAE_PW_Assignments::user_has_term($user->ID, $post_terms)) {
                    $prepared_post->post_status = get_post_field('post_status', $prepared_post->ID) ?: 'draft';
                }
            }
        }

        return $prepared_post;
    }

    /**
     * Filter post queries so Editors/Publishers only see posts in their Content Areas.
     *
     * @param WP_Query $query
     */
    public function filter_post_queries($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        $post_type = $query->get('post_type');
        if (!$post_type) {
            $post_type = 'post';
        }
        if (is_array($post_type)) {
            $post_type = reset($post_type);
        }

        // Don't filter media library — users get full read access.
        if ('attachment' === $post_type) {
            return;
        }

        if (!self::is_managed_post_type($post_type)) {
            return;
        }

        $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
        if (empty($user_terms)) {
            // No assignments — show nothing.
            $query->set('post__in', array(0));
            return;
        }

        // Include posts in assigned content areas, plus user's own shadow drafts.
        $tax_query = array(
            array(
                'taxonomy' => ASAE_PW_Taxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => $user_terms,
            ),
        );

        $settings = get_option('asae_pw_settings', array());
        if (($settings['orphaned_content'] ?? 'admin_only') !== 'admin_only') {
            // Include orphaned posts too.
            $tax_query['relation'] = 'OR';
            $tax_query[] = array(
                'taxonomy' => ASAE_PW_Taxonomy::TAXONOMY,
                'operator' => 'NOT EXISTS',
            );
        }

        $query->set('tax_query', $tax_query);
    }

    /**
     * Show an admin notice on filtered post list screens explaining the scope.
     */
    public function show_filtering_notice() {
        global $pagenow;
        if ('edit.php' !== $pagenow) {
            return;
        }

        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        if (!self::is_managed_post_type($post_type) || 'attachment' === $post_type) {
            return;
        }

        $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
        if (empty($user_terms)) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('You have no Content Area assignments yet. Ask an administrator to assign you to a Content Area.', 'asae-publishing-workflow')
            );
            return;
        }

        $term_names = array();
        foreach ($user_terms as $tid) {
            $t = get_term($tid, ASAE_PW_Taxonomy::TAXONOMY);
            if ($t && !is_wp_error($t)) {
                $term_names[] = $t->name;
            }
        }

        printf(
            '<div class="notice notice-info"><p>%s</p></div>',
            sprintf(
                /* translators: %s: comma-separated list of Content Area names */
                esc_html__('This list is filtered to your assigned Content Areas: %s. Existing content not yet tagged with one of these areas is hidden. Ask an administrator to tag content if you need access to it.', 'asae-publishing-workflow'),
                '<strong>' . esc_html(implode(', ', $term_names)) . '</strong>'
            )
        );
    }

    /**
     * Block direct URL access to posts outside the user's Content Areas.
     */
    public function block_unauthorized_post_access() {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$post_id) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || !self::is_managed_post_type($post->post_type)) {
            return;
        }

        if ('attachment' === $post->post_type) {
            return;
        }

        $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);

        if (empty($post_terms)) {
            $settings = get_option('asae_pw_settings', array());
            if (($settings['orphaned_content'] ?? 'admin_only') === 'admin_only') {
                wp_safe_redirect(admin_url());
                exit;
            }
            return;
        }

        if (!ASAE_PW_Assignments::user_has_term($user->ID, $post_terms)) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Block new post creation if user has no assignments at all.
     */
    public function block_unauthorized_new_post() {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        $user_terms = ASAE_PW_Assignments::get_user_term_ids($user->ID);
        if (empty($user_terms)) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Intercept Quick Edit inline save.
     */
    public function intercept_inline_save() {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post_id = isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0;
        if (!$post_id) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $new_status = isset($_POST['_status']) ? sanitize_text_field(wp_unslash($_POST['_status'])) : '';

        // Editors cannot publish via Quick Edit.
        if (ASAE_PW_Roles::is_editor($user) && in_array($new_status, array('publish', 'future'), true)) {
            wp_die(
                esc_html__('You do not have permission to publish this content.', 'asae-publishing-workflow'),
                esc_html__('Permission Denied', 'asae-publishing-workflow'),
                array('response' => 403)
            );
        }

        // Check area for Publishers.
        if (ASAE_PW_Roles::is_publisher($user)) {
            $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($post_id);
            if (!empty($post_terms) && !ASAE_PW_Assignments::user_has_term($user->ID, $post_terms)) {
                wp_die(
                    esc_html__('You do not have permission to edit content outside your assigned Content Areas.', 'asae-publishing-workflow'),
                    esc_html__('Permission Denied', 'asae-publishing-workflow'),
                    array('response' => 403)
                );
            }
        }
    }

    /**
     * Intercept Bulk Edit.
     */
    public function intercept_bulk_edit() {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post_ids = isset($_POST['post_IDs']) ? array_map('intval', (array) $_POST['post_IDs']) : array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $new_status = isset($_POST['_status']) ? sanitize_text_field(wp_unslash($_POST['_status'])) : '';

        foreach ($post_ids as $pid) {
            $post_terms = ASAE_PW_Taxonomy::get_post_term_ids($pid);

            // Block if any post is outside user's area.
            if (!empty($post_terms) && !ASAE_PW_Assignments::user_has_term($user->ID, $post_terms)) {
                wp_die(
                    esc_html__('One or more selected posts are outside your assigned Content Areas. The entire bulk operation has been cancelled.', 'asae-publishing-workflow'),
                    esc_html__('Permission Denied', 'asae-publishing-workflow'),
                    array('response' => 403)
                );
            }

            // Editors cannot bulk-publish.
            if (ASAE_PW_Roles::is_editor($user) && in_array($new_status, array('publish', 'future'), true)) {
                wp_die(
                    esc_html__('You do not have permission to publish content.', 'asae-publishing-workflow'),
                    esc_html__('Permission Denied', 'asae-publishing-workflow'),
                    array('response' => 403)
                );
            }
        }
    }

    /**
     * Remove unauthorized admin menu items for Editors and Publishers.
     */
    public function lockdown_admin_menu() {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        // Menus to remove.
        $remove = array(
            'themes.php',       // Appearance.
            'plugins.php',      // Plugins.
            'options-general.php', // Settings.
            'tools.php',        // Tools.
            'users.php',        // Users.
            'edit-comments.php', // Comments.
        );

        foreach ($remove as $menu_slug) {
            remove_menu_page($menu_slug);
        }
    }

    /**
     * Block direct access to unauthorized admin pages.
     */
    public function lockdown_admin_pages() {
        $user = wp_get_current_user();
        if (!ASAE_PW_Roles::is_pw_user($user)) {
            return;
        }

        global $pagenow;

        $blocked = array(
            'themes.php', 'customize.php', 'widgets.php', 'nav-menus.php',
            'plugins.php', 'plugin-install.php', 'plugin-editor.php',
            'options-general.php', 'options-writing.php', 'options-reading.php',
            'options-discussion.php', 'options-media.php', 'options-permalink.php',
            'options-privacy.php',
            'tools.php', 'import.php', 'export.php', 'site-health.php',
            'users.php', 'user-new.php',
            'edit-comments.php',
            'theme-editor.php',
        );

        if (in_array($pagenow, $blocked, true)) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }

    /**
     * Ensure Editors/Publishers can browse the full Media Library.
     *
     * @param array $query Query args for AJAX attachment queries.
     * @return array
     */
    public function allow_full_media_library($query) {
        $user = wp_get_current_user();
        if (ASAE_PW_Roles::is_pw_user($user)) {
            // Don't filter by author — let them see all media.
            unset($query['author']);
        }
        return $query;
    }

    /**
     * Check if a post type is managed by this plugin.
     *
     * @param string $post_type
     * @return bool
     */
    public static function is_managed_post_type($post_type) {
        if ('attachment' === $post_type) {
            return true; // Always manage attachment caps.
        }
        $settings   = get_option('asae_pw_settings', array());
        $post_types = $settings['post_types'] ?? array('post', 'page');
        return in_array($post_type, $post_types, true);
    }
}
