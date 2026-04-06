<?php
/**
 * Cache purge on post status transitions.
 *
 * @package ASAE_Publishing_Workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Cache {

    public function __construct() {
        add_action('transition_post_status', array($this, 'on_status_transition'), 10, 3);
    }

    /**
     * Purge caches when a post status changes.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public function on_status_transition($new_status, $old_status, $post) {
        if ($new_status === $old_status) {
            return;
        }

        $post_id = $post->ID;

        // 1. Clear WordPress internal object cache.
        clean_post_cache($post_id);

        // 2. Fire custom action for external caching plugins to hook into.
        do_action('asae_pw_post_status_changed', $post_id, $new_status, $old_status);

        // 3. Flush object cache group if available.
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('posts');
        }

        // 4. Purge known caching plugins.
        $this->purge_caching_plugins($post_id);
    }

    /**
     * Purge post from known caching plugins.
     *
     * @param int $post_id
     */
    private function purge_caching_plugins($post_id) {
        // WP Super Cache.
        if (function_exists('wpsc_delete_post_cache')) {
            wpsc_delete_post_cache($post_id);
        }

        // W3 Total Cache.
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }

        // WP Rocket.
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }

        // LiteSpeed Cache.
        if (class_exists('LiteSpeed_Cache_Purge') && method_exists('LiteSpeed_Cache_Purge', 'purge_post')) {
            LiteSpeed_Cache_Purge::purge_post($post_id);
        }
    }
}
