<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_GitHub_Updater
{
    private string $plugin_basename;

    public function __construct()
    {
        $this->plugin_basename = plugin_basename(FREEGO_WP_FILE);
    }

    public function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'normalize_install_directory'], 10, 3);
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->latest_release();
        if (!$release || empty($release['version']) || empty($release['zipball_url'])) {
            return $transient;
        }

        if (!version_compare((string) $release['version'], FREEGO_WP_VERSION, '>')) {
            return $transient;
        }

        $transient->response[$this->plugin_basename] = (object) [
            'slug' => FREEGO_WP_GITHUB_REPO,
            'plugin' => $this->plugin_basename,
            'new_version' => (string) $release['version'],
            'url' => FREEGO_WP_GITHUB_REPO_URL,
            'package' => (string) $release['zipball_url'],
            'tested' => (string) ($release['tested'] ?? ''),
            'requires' => '6.0',
            'requires_php' => '7.4',
        ];

        return $transient;
    }

    /**
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugin_info($result, string $action, object $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== FREEGO_WP_GITHUB_REPO) {
            return $result;
        }

        $release = $this->latest_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Freego WP Accessibility Assistant',
            'slug' => FREEGO_WP_GITHUB_REPO,
            'version' => (string) ($release['version'] ?? FREEGO_WP_VERSION),
            'author' => '<a href="https://github.com/' . esc_attr(FREEGO_WP_GITHUB_OWNER) . '">MXP</a>',
            'homepage' => FREEGO_WP_GITHUB_REPO_URL,
            'download_link' => (string) ($release['zipball_url'] ?? ''),
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => (string) ($release['tested'] ?? ''),
            'sections' => [
                'description' => wp_kses_post($this->markdownish_to_html((string) ($release['body'] ?? 'Freego-oriented accessibility repair and audit workflow for WordPress.'))),
                'changelog' => wp_kses_post($this->markdownish_to_html((string) ($release['body'] ?? ''))),
            ],
        ];
    }

    /**
     * GitHub zipballs extract to owner-repo-sha. Move that directory to the installed plugin folder name.
     *
     * @param bool|WP_Error $response
     * @param array<string,mixed> $hook_extra
     * @param array<string,mixed> $result
     * @return bool|WP_Error
     */
    public function normalize_install_directory($response, array $hook_extra, array $result)
    {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename || empty($result['destination'])) {
            return $response;
        }

        $destination = trailingslashit(WP_PLUGIN_DIR) . FREEGO_WP_GITHUB_REPO;
        $source = untrailingslashit((string) $result['destination']);

        if ($source === untrailingslashit($destination)) {
            return $response;
        }

        if ($wp_filesystem->exists($destination)) {
            $wp_filesystem->delete($destination, true);
        }

        $wp_filesystem->move($source, $destination);
        $result['destination'] = $destination;

        return $response;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latest_release(): ?array
    {
        $cache_key = 'freego_wp_github_latest_release';
        $cached = get_site_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode(FREEGO_WP_GITHUB_OWNER), rawurlencode(FREEGO_WP_GITHUB_REPO));
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Freego-WP-Updater/' . FREEGO_WP_VERSION,
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_site_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $release = [
            'version' => ltrim((string) $body['tag_name'], 'vV'),
            'tag_name' => (string) $body['tag_name'],
            'body' => (string) ($body['body'] ?? ''),
            'zipball_url' => (string) ($body['zipball_url'] ?? ''),
            'html_url' => (string) ($body['html_url'] ?? FREEGO_WP_GITHUB_REPO_URL),
            'tested' => $this->extract_tested((string) ($body['body'] ?? '')),
        ];

        set_site_transient($cache_key, $release, HOUR_IN_SECONDS);

        return $release;
    }

    private function extract_tested(string $body): string
    {
        if (preg_match('/tested(?: up to)?\s*:\s*([0-9.]+)/i', $body, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function markdownish_to_html(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<p>No release notes provided.</p>';
        }

        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $html = '';
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) {
                $html .= '<h3>' . esc_html(ltrim($line, "# \t")) . '</h3>';
            } elseif (strpos($line, '-') === 0 || strpos($line, '*') === 0) {
                $html .= '<p>&bull; ' . esc_html(ltrim($line, "-* \t")) . '</p>';
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }

        return $html;
    }
}
