<?php
/**
 * Custom taxonomy registration: Content Area.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Taxonomy {

    const TAXONOMY = 'asae_content_area';

    public function __construct() {
        add_action('init', array(__CLASS__, 'register_taxonomy'));
    }

    /**
     * Register the Content Area taxonomy.
     */
    public static function register_taxonomy() {
        $labels = array(
            'name'              => __('Content Areas', 'asae-publishing-workflow'),
            'singular_name'     => __('Content Area', 'asae-publishing-workflow'),
            'search_items'      => __('Search Content Areas', 'asae-publishing-workflow'),
            'all_items'         => __('All Content Areas', 'asae-publishing-workflow'),
            'parent_item'       => __('Parent Content Area', 'asae-publishing-workflow'),
            'parent_item_colon' => __('Parent Content Area:', 'asae-publishing-workflow'),
            'edit_item'         => __('Edit Content Area', 'asae-publishing-workflow'),
            'update_item'       => __('Update Content Area', 'asae-publishing-workflow'),
            'add_new_item'      => __('Add New Content Area', 'asae-publishing-workflow'),
            'new_item_name'     => __('New Content Area Name', 'asae-publishing-workflow'),
            'menu_name'         => __('Content Areas', 'asae-publishing-workflow'),
        );

        $settings   = get_option('asae_pw_settings', array());
        $post_types = !empty($settings['post_types']) ? $settings['post_types'] : array('post', 'page');

        register_taxonomy(self::TAXONOMY, $post_types, array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'show_in_menu'      => true,
            'meta_box_cb'       => array(__CLASS__, 'meta_box_callback'),
            'capabilities'      => array(
                'manage_terms' => 'manage_options',
                'edit_terms'   => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'edit_posts',
            ),
        ));
    }

    /**
     * Custom meta box callback — read-only for Editors/Publishers, normal for Admins.
     *
     * @param WP_Post $post
     * @param array   $box
     */
    public static function meta_box_callback($post, $box) {
        if (current_user_can('manage_options')) {
            // Gutenberg's block-editor compat layer can call meta_box_cb with
            // a WP_Block_Editor_Context object instead of the expected
            // meta-box args array. Normalize before delegating to core.
            if (!is_array($box) || empty($box['args']['taxonomy'])) {
                $box = array(
                    'args' => array('taxonomy' => self::TAXONOMY),
                );
            }
            post_categories_meta_box($post, $box);
            return;
        }

        // Editors and Publishers see a read-only display.
        $terms = wp_get_object_terms($post->ID, self::TAXONOMY);
        if (empty($terms) || is_wp_error($terms)) {
            echo '<p>' . esc_html__('No Content Area assigned.', 'asae-publishing-workflow') . '</p>';
            return;
        }

        echo '<ul class="asae-pw-content-areas-readonly">';
        foreach ($terms as $term) {
            echo '<li>' . esc_html($term->name) . '</li>';
        }
        echo '</ul>';

        // Show proposed change if one exists.
        $proposed = get_post_meta($post->ID, '_asae_pw_proposed_content_area', true);
        if ($proposed) {
            $proposed_terms = array();
            foreach ((array) $proposed as $tid) {
                $t = get_term($tid, self::TAXONOMY);
                if ($t && !is_wp_error($t)) {
                    $proposed_terms[] = $t->name;
                }
            }
            if ($proposed_terms) {
                echo '<p class="asae-pw-proposed-change"><strong>'
                    . esc_html__('Proposed change:', 'asae-publishing-workflow')
                    . '</strong> '
                    . esc_html(implode(', ', $proposed_terms))
                    . '</p>';
            }
        }
    }

    /**
     * Get Content Area term IDs for a given post.
     *
     * @param int $post_id
     * @return int[]
     */
    public static function get_post_term_ids($post_id) {
        $terms = wp_get_object_terms($post_id, self::TAXONOMY, array('fields' => 'ids'));
        if (is_wp_error($terms)) {
            return array();
        }
        return array_map('intval', $terms);
    }
}
