<?php
/**
 * ASAE Publishing Workflow - GitHub Self-Hosted Updater
 *
 * Checks the plugin's GitHub repository for new releases and integrates
 * with WordPress's built-in update system.
 *
 * @package ASAE_Publishing_Workflow
 * @author  Keith M. Soares
 * @since   0.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_PW_Updater {

    /** @var string GitHub owner/repo */
    private $repo = 'ksoaresasae/asae-publishing-workflow';

    /** @var string Plugin basename */
    private $basename;

    /** @var string Plugin slug */
    private $slug;

    /** @var string Current plugin version */
    private $version;

    /** @var object|null Cached GitHub release data */
    private $github_release = null;

    public function __construct() {
        $this->basename = ASAE_PW_PLUGIN_BASENAME;
        $this->slug     = dirname($this->basename);
        $this->version  = ASAE_PW_VERSION;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);

        // AJAX handler for "Check for Updates Now".
        add_action('wp_ajax_asae_pw_check_updates', array($this, 'ajax_check_updates'));
    }

    /**
     * Check GitHub for a newer release and inject into WordPress update transient.
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = ltrim($release->tag_name, 'vV');
        if (version_compare($remote_version, $this->version, '>')) {
            $download_url = $this->get_release_zip_url($release);
            if ($download_url) {
                $transient->response[$this->basename] = (object) array(
                    'slug'         => $this->slug,
                    'plugin'       => $this->basename,
                    'new_version'  => $remote_version,
                    'url'          => 'https://github.com/' . $this->repo,
                    'package'      => $download_url,
                    'icons'        => array(),
                    'banners'      => array(),
                    'tested'       => '',
                    'requires'     => '6.0',
                    'requires_php' => '8.0',
                );
            }
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View details" modal.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $result;
        }

        $remote_version = ltrim($release->tag_name, 'vV');
        $download_url   = $this->get_release_zip_url($release);

        return (object) array(
            'name'          => 'ASAE Publishing Workflow',
            'slug'          => $this->slug,
            'version'       => $remote_version,
            'author'        => '<a href="https://www.asaecenter.org">Keith M. Soares</a>',
            'homepage'      => 'https://github.com/' . $this->repo,
            'download_link' => $download_url,
            'requires'      => '6.0',
            'requires_php'  => '8.0',
            'tested'        => '',
            'sections'      => array(
                'description' => 'Content ownership and editorial workflow system for WordPress.',
                'changelog'   => nl2br(esc_html($release->body)),
            ),
            'last_updated'  => $release->published_at,
        );
    }

    /**
     * Ensure installed folder matches plugin slug after update.
     *
     * @param bool  $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function post_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $result;
        }

        global $wp_filesystem;

        $install_dir = $result['destination'];
        $proper_dir  = WP_PLUGIN_DIR . '/' . $this->slug;

        if ($install_dir !== $proper_dir) {
            $wp_filesystem->move($install_dir, $proper_dir);
            $result['destination']      = $proper_dir;
            $result['destination_name'] = $this->slug;
        }

        activate_plugin($this->basename);

        return $result;
    }

    /**
     * AJAX: Force check for updates.
     */
    public function ajax_check_updates() {
        check_ajax_referer('asae_pw_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'asae-publishing-workflow')));
        }

        delete_transient('asae_pw_github_release');
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_send_json_success(array('message' => __('Update check complete.', 'asae-publishing-workflow')));
    }

    /**
     * Fetch the latest release from GitHub API. Cached for 6 hours.
     *
     * @return object|null
     */
    private function get_latest_release() {
        if (null !== $this->github_release) {
            return $this->github_release;
        }

        $transient_key = 'asae_pw_github_release';
        $cached = get_transient($transient_key);
        if (false !== $cached) {
            $this->github_release = $cached;
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ASAE-Publishing-Workflow/' . $this->version,
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_transient($transient_key, null, HOUR_IN_SECONDS);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (!$body || !isset($body->tag_name)) {
            return null;
        }

        set_transient($transient_key, $body, 6 * HOUR_IN_SECONDS);
        $this->github_release = $body;

        return $body;
    }

    /**
     * Extract the zip download URL from a GitHub release.
     *
     * @param object $release
     * @return string|null
     */
    private function get_release_zip_url($release) {
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        if (!empty($release->zipball_url)) {
            return $release->zipball_url;
        }

        return null;
    }
}
