<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Scanner
{
    private Freego_WP_Rules $rules;
    private Freego_WP_Issue_Store $store;
    private Freego_WP_CSS_Auditor $css_auditor;
    private string $target_level;

    public function __construct(Freego_WP_Rules $rules, Freego_WP_Issue_Store $store, Freego_WP_CSS_Auditor $css_auditor)
    {
        $this->rules = $rules;
        $this->store = $store;
        $this->css_auditor = $css_auditor;
        $this->target_level = (string) get_option(FREEGO_WP_OPTION_TARGET_LEVEL, 'AAA');
    }

    /**
     * @return array{error?:string,issues?:array<int,array<string,mixed>>}
     */
    public function scan_url(string $url, bool $persist = true): array
    {
        if (!$url || !wp_http_validate_url($url)) {
            return ['error' => __('Please enter a valid HTTP or HTTPS URL.', 'freego-wp')];
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 3,
            'user-agent' => 'Freego WP Accessibility Assistant/' . FREEGO_WP_VERSION,
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            return ['error' => sprintf(__('HTTP status %d returned by scanned URL.', 'freego-wp'), $status)];
        }

        if (stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'html') === false && stripos($body, '<html') === false) {
            return ['error' => __('The scanned URL did not return HTML content.', 'freego-wp')];
        }

        $issues = $this->filter_by_target($this->analyze_html($body, [
            'source_type' => 'url',
            'source_id' => 0,
            'source_url' => $url,
        ]));

        if ($persist) {
            $this->persist($issues, 'url', 0);
        }

        return ['issues' => $issues];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function scan_post(int $post_id): array
    {
        $post = get_post($post_id);
        if (!$post || wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') {
            return [];
        }

        $html = apply_filters('the_content', $post->post_content);
        $issues = $this->analyze_html('<html lang="' . esc_attr(str_replace('_', '-', get_locale())) . '"><body>' . $html . '</body></html>', [
            'source_type' => $post->post_type,
            'source_id' => $post_id,
            'source_url' => get_permalink($post_id) ?: '',
        ]);

        $issues = array_merge($issues, $this->analyze_post_metadata($post));
        $issues = $this->filter_by_target($issues);
        $this->persist($issues, $post->post_type, $post_id);

        return $issues;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function scan_attachment(int $attachment_id): array
    {
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return [];
        }

        $issues = [];
        $mime = (string) get_post_mime_type($attachment);

        if (strpos($mime, 'image/') === 0) {
            $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
            if ($alt === '') {
                $issues[] = $this->issue('HM1110100C', 'review', __('Image attachment is missing alt text.', 'freego-wp'), [
                    'source_type' => 'attachment',
                    'source_id' => $attachment_id,
                    'source_url' => wp_get_attachment_url($attachment_id) ?: '',
                ]);
            }
        }

        if (strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0) {
            $transcript = trim((string) get_post_meta($attachment_id, '_freego_wp_transcript', true));
            $captions = trim((string) get_post_meta($attachment_id, '_freego_wp_captions_url', true));
            if ($transcript === '' && $captions === '') {
                $issues[] = $this->issue('HM1240102C', 'review', __('Media attachment needs captions, transcript, or equivalent alternative content.', 'freego-wp'), [
                    'source_type' => 'attachment',
                    'source_id' => $attachment_id,
                    'source_url' => wp_get_attachment_url($attachment_id) ?: '',
                ]);
            }
        }

        if (preg_match('/(msword|officedocument|powerpoint|excel)/i', $mime)) {
            $issues[] = $this->issue('ME1320200C', 'review', __('Office document should have an open-format alternative or manual confirmation.', 'freego-wp'), [
                'source_type' => 'attachment',
                'source_id' => $attachment_id,
                'source_url' => wp_get_attachment_url($attachment_id) ?: '',
            ]);
        }

        $issues = $this->filter_by_target($issues);
        $this->persist($issues, 'attachment', $attachment_id);

        return $issues;
    }

    /**
     * @return array{scanned:int,issues:int}
     */
    public function scan_site(int $limit = 50): array
    {
        $query = new WP_Query([
            'post_type' => get_post_types(['public' => true], 'names'),
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(200, $limit)),
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $scanned = 0;
        $issues = 0;

        foreach ($query->posts as $post_id) {
            $found = $this->scan_post((int) $post_id);
            $scanned++;
            $issues += count($found);
        }

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => max(1, min(200, $limit)),
            'fields' => 'ids',
        ]);

        foreach ($attachments as $attachment_id) {
            $found = $this->scan_attachment((int) $attachment_id);
            $scanned++;
            $issues += count($found);
        }

        return ['scanned' => $scanned, 'issues' => $issues];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<int,array<string,mixed>>
     */
    public function analyze_html(string $html, array $source): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [$this->issue('HTML', 'error', __('Unable to parse HTML for accessibility scan.', 'freego-wp'), $source)];
        }

        $xpath = new DOMXPath($document);
        $issues = [];
        $issues = array_merge($issues, $this->css_auditor->analyze_html_styles($html, $source));

        $html_node = $document->getElementsByTagName('html')->item(0);
        if (!$html_node instanceof DOMElement || trim($html_node->getAttribute('lang')) === '') {
            $issues[] = $this->issue('HM1310100C', 'auto', __('The html element is missing a lang value.', 'freego-wp'), $source, 'html');
        }

        $title_node = $document->getElementsByTagName('title')->item(0);
        if (!$title_node instanceof DOMElement || trim($title_node->textContent) === '') {
            $issues[] = $this->issue('HM1240200C', 'auto', __('The page is missing a non-empty title element.', 'freego-wp'), $source, 'title');
        }

        foreach ($this->nodes($xpath, '//img[not(@alt)]') as $node) {
            $issues[] = $this->issue('HM1110100C', 'review', __('Image is missing alt text.', 'freego-wp'), $source, 'img:not([alt])', $node);
        }

        foreach ($this->nodes($xpath, '//img[@alt and @src and normalize-space(@alt) = normalize-space(@src)]') as $node) {
            $issues[] = $this->issue('HM1110100C', 'review', __('Image alt text matches the src value and needs semantic review.', 'freego-wp'), $source, 'img[alt=src]', $node);
        }

        foreach ($this->nodes($xpath, '//input[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "image" and (not(@alt) or normalize-space(@alt) = "")]') as $node) {
            $issues[] = $this->issue('HM1110104C', 'review', __('Image submit input is missing alt text.', 'freego-wp'), $source, 'input[type=image]', $node);
        }

        foreach ($this->nodes($xpath, '//a[@href and not(normalize-space()) and not(@aria-label) and not(@title)]') as $node) {
            $issues[] = $this->issue('HM1240401C', 'review', __('Link has no text, title, or aria-label.', 'freego-wp'), $source, 'a[href]:empty', $node);
        }

        foreach ($this->nodes($xpath, '//iframe[not(@title) or normalize-space(@title) = ""]') as $node) {
            $issues[] = $this->issue('HM1410201C', 'auto', __('Iframe is missing title.', 'freego-wp'), $source, 'iframe:not([title])', $node);
        }

        foreach ($this->nodes($xpath, '//table[not(caption)]') as $node) {
            $issues[] = $this->issue('TABLE_CAPTION_REVIEW', 'review', __('Table is missing a caption or equivalent summary.', 'freego-wp'), $source, 'table:not(:has(caption))', $node);
        }

        foreach ($this->nodes($xpath, '//th[not(@scope)]') as $node) {
            $issues[] = $this->issue('HM1130101C', 'review', __('Table header cell is missing scope.', 'freego-wp'), $source, 'th:not([scope])', $node);
        }

        foreach ($this->nodes($xpath, '//select[count(option) > 8 and not(optgroup)]') as $node) {
            $issues[] = $this->issue('HM1130103C_1', 'review', __('Long select list should be reviewed for option grouping.', 'freego-wp'), $source, 'select', $node);
        }

        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        if (!$headings || $headings->length === 0) {
            $issues[] = $this->issue('HM3241000C', 'review', __('No heading elements found on the page.', 'freego-wp'), $source, 'h1-h6');
        }

        foreach ($this->nodes($xpath, '//a[contains(translate(@href, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), ".doc") or contains(translate(@href, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), ".ppt") or contains(translate(@href, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), ".xls")]') as $node) {
            $issues[] = $this->issue('ME1320200C', 'review', __('Office-format download link needs open-format review.', 'freego-wp'), $source, 'a[href*=office]', $node);
        }

        return $issues;
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return array<int,array<string,mixed>>
     */
    private function filter_by_target(array $issues): array
    {
        $target = (string) get_option(FREEGO_WP_OPTION_TARGET_LEVEL, $this->target_level ?: 'AAA');

        return array_values(array_filter($issues, function (array $issue) use ($target): bool {
            return $this->rules->code_in_target((string) ($issue['code'] ?? ''), $target);
        }));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function analyze_post_metadata(WP_Post $post): array
    {
        $issues = [];
        $source = [
            'source_type' => $post->post_type,
            'source_id' => (int) $post->ID,
            'source_url' => get_permalink($post) ?: '',
        ];

        if (preg_match('/(?:^|[\s>])更多(?:[\s<]|$)|(?:^|[\s>])more(?:[\s<]|$)/iu', wp_strip_all_tags($post->post_content))) {
            $issues[] = $this->issue('HM3240900C', 'review', __('Content contains generic link text such as "more"; verify link purpose.', 'freego-wp'), $source);
        }

        return $issues;
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     */
    private function persist(array $issues, string $source_type, int $source_id): void
    {
        $hashes = [];
        foreach ($issues as $issue) {
            $hashes[] = $this->store->hash($issue);
            $this->store->upsert($issue);
        }

        if ($source_id > 0) {
            $this->store->mark_stale_fixed($source_type, $source_id, $hashes);
        }
    }

    /**
     * @return array<int,DOMElement>
     */
    private function nodes(DOMXPath $xpath, string $query): array
    {
        $nodes = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function issue(string $code, string $severity, string $message, array $source, string $selector = '', ?DOMElement $node = null): array
    {
        $rule = $this->rules->get($code) ?? [];

        return [
            'code' => $code,
            'guideline' => (string) ($rule['guideline'] ?? ''),
            'automation' => (string) ($rule['automation'] ?? 'report_only'),
            'severity' => $severity,
            'message' => $message,
            'selector' => $selector,
            'snippet' => $node ? substr((string) $node->C14N(), 0, 500) : '',
            'source_type' => (string) ($source['source_type'] ?? ''),
            'source_id' => (int) ($source['source_id'] ?? 0),
            'source_url' => (string) ($source['source_url'] ?? ''),
            'context' => ['rule' => $rule],
        ];
    }
}
