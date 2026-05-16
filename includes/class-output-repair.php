<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Output_Repair
{
    private Freego_WP_Rules $rules;
    private bool $aggressive_repair = false;
    /** @var array<string,string> */
    private static array $css_inline_cache = [];

    public function __construct(Freego_WP_Rules $rules)
    {
        $this->rules = $rules;
    }

    public function boot(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
            return;
        }

        add_action('template_redirect', [$this, 'start_buffer'], 0);
    }

    public function start_buffer(): void
    {
        if (is_feed() || is_robots() || is_trackback()) {
            return;
        }

        $this->aggressive_repair = (bool) get_option(FREEGO_WP_OPTION_AGGRESSIVE_REPAIR, false);
        ob_start([$this, 'repair_html']);
    }

    public function repair_html(string $html): string
    {
        if (!$this->should_repair($html)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', get_bloginfo('charset') ?: 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($document);

        $this->repair_html_lang($document);
        $this->repair_language_sections($document, $xpath);
        $this->repair_document_title($document);
        $this->repair_images($xpath);
        $this->repair_image_maps($xpath);
        $this->repair_image_inputs($xpath);
        $this->repair_links($xpath);
        $this->repair_buttons($xpath);
        $this->repair_iframes($xpath);
        $this->repair_forms($document, $xpath);
        $this->repair_navigation_landmarks($xpath);
        $this->repair_tables($document, $xpath);
        $this->repair_embeds($document, $xpath);
        $this->repair_headings($document, $xpath);
        $this->mark_heading_review($xpath);
        $this->ensure_skip_link($document, $xpath);
        $this->repair_inline_font_sizes($xpath);
        $this->inline_repaired_stylesheets($document, $xpath);
        $this->mark_report($document);

        $output = $document->saveHTML();

        return is_string($output) ? $this->strip_xml_encoding_marker($output) : $html;
    }

    private function should_repair(string $html): bool
    {
        if (stripos($html, '<html') === false && stripos($html, '<body') === false) {
            return false;
        }

        return stripos($html, '</html>') !== false || stripos($html, '</body>') !== false;
    }

    private function repair_html_lang(DOMDocument $document): void
    {
        $html = $document->getElementsByTagName('html')->item(0);
        if (!$html instanceof DOMElement) {
            return;
        }

        $lang = trim($html->getAttribute('lang'));
        if ($lang !== '') {
            return;
        }

        $html->setAttribute('lang', str_replace('_', '-', get_locale() ?: 'zh-TW'));
        $html->setAttribute('data-freego-wp-repaired', trim($html->getAttribute('data-freego-wp-repaired') . ' HM2310200C'));
    }

    private function repair_language_sections(DOMDocument $document, DOMXPath $xpath): void
    {
        $html = $document->getElementsByTagName('html')->item(0);
        $page_lang = $html instanceof DOMElement ? trim($html->getAttribute('lang')) : '';
        if ($page_lang === '') {
            $page_lang = str_replace('_', '-', get_locale() ?: 'zh-TW');
        }

        $normalized_page_lang = $this->normalize_lang($page_lang);

        foreach ($xpath->query('//body//*[@lang]') ?: [] as $element) {
            if (!$element instanceof DOMElement || $this->is_hidden($element)) {
                continue;
            }

            $lang = trim($element->getAttribute('lang'));
            if ($lang === '') {
                $element->removeAttribute('lang');
                $element->setAttribute('data-freego-wp-repaired', trim($element->getAttribute('data-freego-wp-repaired') . ' HM2310200C'));
                continue;
            }

            if ($this->normalize_lang($lang) === $normalized_page_lang) {
                $element->removeAttribute('lang');
                $element->setAttribute('data-freego-wp-repaired', trim($element->getAttribute('data-freego-wp-repaired') . ' HM2310200C'));
            }
        }
    }

    private function repair_document_title(DOMDocument $document): void
    {
        $head = $document->getElementsByTagName('head')->item(0);
        if (!$head instanceof DOMElement) {
            return;
        }

        $title = null;
        foreach ($head->getElementsByTagName('title') as $candidate) {
            if (
                $candidate instanceof DOMElement &&
                $candidate->parentNode instanceof DOMNode &&
                strtolower($candidate->parentNode->nodeName) === 'head'
            ) {
                $title = $candidate;
                break;
            }
        }

        if ($title instanceof DOMElement && trim($title->textContent) !== '') {
            return;
        }

        $text = trim(wp_get_document_title());
        if ($text === '') {
            $text = get_bloginfo('name') ?: __('Page', 'freego-wp');
        }

        if (!$title instanceof DOMElement) {
            $title = $document->createElement('title');
            $head->insertBefore($title, $head->firstChild);
        }

        $title->nodeValue = '';
        $title->appendChild($document->createTextNode($text));
        $title->setAttribute('data-freego-wp-repaired', 'HM1240200C');
    }

    private function inline_repaired_stylesheets(DOMDocument $document, DOMXPath $xpath): void
    {
        $enabled = (bool) apply_filters('freego_wp_inline_css_repair_enabled', true);
        if (!$enabled) {
            return;
        }

        foreach ($xpath->query('//link[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement || !$this->is_stylesheet_link($link)) {
                continue;
            }

            $href = html_entity_decode(trim($link->getAttribute('href')), ENT_QUOTES);
            $css = $this->load_repaired_stylesheet($href);
            if ($css === null) {
                continue;
            }

            $style = $document->createElement('style');
            $style->setAttribute('data-freego-wp-inlined-css', esc_url($href));
            $style->setAttribute('data-freego-wp-repaired', 'CS2140401C CS3140801C CS3140802C');

            $media = trim($link->getAttribute('media'));
            if ($media !== '') {
                $style->setAttribute('media', $media);
            }

            $style->appendChild($document->createTextNode($css));

            if ($link->parentNode) {
                $link->parentNode->replaceChild($style, $link);
            }
        }
    }

    private function is_stylesheet_link(DOMElement $link): bool
    {
        if (trim($link->getAttribute('disabled')) !== '' || trim($link->getAttribute('integrity')) !== '') {
            return false;
        }

        $rel = strtolower(trim($link->getAttribute('rel')));
        $as = strtolower(trim($link->getAttribute('as')));
        if (
            $rel === '' ||
            strpos($rel, 'alternate') !== false ||
            (strpos($rel, 'stylesheet') === false && !(strpos($rel, 'preload') !== false && $as === 'style'))
        ) {
            return false;
        }

        $href = trim($link->getAttribute('href'));
        return $href !== '' && !preg_match('/^(data|javascript):/i', $href);
    }

    private function load_repaired_stylesheet(string $href, array $seen = []): ?string
    {
        $resolved = $this->resolve_local_stylesheet($href);
        if (!$resolved) {
            return null;
        }

        [$path, $url] = $resolved;
        if (isset($seen[$path])) {
            return '';
        }

        if (isset(self::$css_inline_cache[$path])) {
            return self::$css_inline_cache[$path];
        }

        $css = file_get_contents($path);
        if (!is_string($css) || $css === '') {
            return null;
        }

        $seen[$path] = true;
        $repaired = $this->inline_css_imports($css, $url, $seen);
        $repaired = $this->repair_css_accessibility_units($repaired);
        if ($repaired === $css) {
            return null;
        }

        $repaired = $this->rewrite_css_urls($repaired, $url);
        self::$css_inline_cache[$path] = $repaired;

        return $repaired;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function resolve_local_stylesheet(string $href): ?array
    {
        $absolute_url = esc_url_raw(wp_make_link_relative($href));
        if (strpos($href, '//') === 0) {
            $scheme = is_ssl() ? 'https:' : 'http:';
            $absolute_url = $scheme . $href;
        } elseif (preg_match('/^https?:\/\//i', $href)) {
            $absolute_url = $href;
        } elseif (strpos($href, '/') === 0) {
            $absolute_url = home_url($href);
        } else {
            $absolute_url = home_url('/' . ltrim($href, '/'));
        }

        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $css_host = wp_parse_url($absolute_url, PHP_URL_HOST);
        if ($site_host && $css_host && strtolower($site_host) !== strtolower($css_host)) {
            return null;
        }

        $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
        if ($path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'css') {
            return null;
        }

        $allowed_prefixes = (array) apply_filters('freego_wp_inline_css_repair_allowed_paths', ['*']);

        $relative = ltrim($path, '/');
        $allowed = false;
        foreach ($allowed_prefixes as $prefix) {
            $prefix = ltrim((string) $prefix, '/');
            if ($prefix === '*' || ($prefix !== '' && strpos($relative, $prefix) === 0)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return null;
        }

        $file = realpath(ABSPATH . $relative);
        $root = realpath(ABSPATH);
        if (!$file || !$root || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_readable($file)) {
            return null;
        }

        return [$file, home_url('/' . $relative)];
    }

    private function repair_css_accessibility_units(string $css): string
    {
        $css = preg_replace_callback(
            '/font-size(\s*:\s*)([0-9]+(?:\.[0-9]+)?)(\s*)(px|pt|pc|in|cm|mm)\b/i',
            function (array $matches): string {
                return 'font-size' . $matches[1] . $this->absolute_font_size_to_rem((float) $matches[2], strtolower($matches[4]));
            },
            $css
        ) ?? $css;

        $css = preg_replace_callback(
            '/max-width(\s*:\s*)([0-9]+(?:\.[0-9]+)?)(\s*)(px|pt|pc|in|cm|mm)\b/i',
            function (array $matches): string {
                return 'max-width' . $matches[1] . $this->absolute_font_size_to_rem((float) $matches[2], strtolower($matches[4]));
            },
            $css
        ) ?? $css;

        return preg_replace_callback(
            '/line-height(\s*:\s*)([0-9]+(?:\.[0-9]+)?)(\s*)(px|pt|pc|in|cm|mm)\b/i',
            function (array $matches): string {
                return 'line-height' . $matches[1] . $this->absolute_font_size_to_rem((float) $matches[2], strtolower($matches[4]));
            },
            $css
        ) ?? $css;
    }

    private function inline_css_imports(string $css, string $stylesheet_url, array $seen): string
    {
        return preg_replace_callback(
            '/@import\s+(?:url\(\s*)?[\'"]?([^\'"\);]+)[\'"]?\s*\)?([^;]*);/i',
            function (array $matches) use ($stylesheet_url, $seen): string {
                $import_url = trim($matches[1]);
                if ($import_url === '' || preg_match('/^(data:|javascript:|#)/i', $import_url)) {
                    return $matches[0];
                }

                $resolved_url = $this->resolve_css_url($import_url, $stylesheet_url);
                $imported = $this->load_stylesheet_content_for_inline($resolved_url, $seen);
                if ($imported === null) {
                    return $matches[0];
                }

                $media = trim($matches[2] ?? '');
                if ($media !== '') {
                    return "@media {$media} {\n{$imported}\n}";
                }

                return $imported;
            },
            $css
        ) ?? $css;
    }

    private function load_stylesheet_content_for_inline(string $href, array $seen): ?string
    {
        $resolved = $this->resolve_local_stylesheet($href);
        if (!$resolved) {
            return null;
        }

        [$path, $url] = $resolved;
        if (isset($seen[$path])) {
            return '';
        }

        if (isset(self::$css_inline_cache[$path])) {
            return self::$css_inline_cache[$path];
        }

        $css = file_get_contents($path);
        if (!is_string($css) || $css === '') {
            return null;
        }

        $seen[$path] = true;
        $repaired = $this->inline_css_imports($css, $url, $seen);
        $repaired = $this->repair_css_accessibility_units($repaired);
        $repaired = $this->rewrite_css_urls($repaired, $url);
        self::$css_inline_cache[$path] = $repaired;

        return $repaired;
    }

    private function repair_inline_font_sizes(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//*[@style]') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $style = $element->getAttribute('style');
            $repaired = $this->repair_css_accessibility_units($style);
            if ($repaired === $style) {
                continue;
            }

            $element->setAttribute('style', $repaired);
            $element->setAttribute('data-freego-wp-repaired', trim($element->getAttribute('data-freego-wp-repaired') . ' CS2140401C CS3140801C CS3140802C'));
        }

        foreach ($xpath->query('//style') ?: [] as $style_element) {
            if (!$style_element instanceof DOMElement) {
                continue;
            }

            $css = $style_element->textContent;
            $repaired = $this->repair_css_accessibility_units($css);
            if ($repaired === $css) {
                continue;
            }

            $style_element->nodeValue = '';
            $style_element->appendChild($style_element->ownerDocument->createTextNode($repaired));
            $style_element->setAttribute('data-freego-wp-repaired', trim($style_element->getAttribute('data-freego-wp-repaired') . ' CS2140401C CS3140801C CS3140802C'));
        }
    }

    private function absolute_font_size_to_rem(float $value, string $unit): string
    {
        switch ($unit) {
            case 'pt':
                $px = $value * (4 / 3);
                break;
            case 'pc':
                $px = $value * 16;
                break;
            case 'in':
                $px = $value * 96;
                break;
            case 'cm':
                $px = $value * (96 / 2.54);
                break;
            case 'mm':
                $px = $value * (96 / 25.4);
                break;
            default:
                $px = $value;
                break;
        }

        return $this->px_to_rem($px);
    }

    private function px_to_rem(float $px): string
    {
        if ($px == 0.0) {
            return '0';
        }

        $rem = round($px / 16, 4);
        return rtrim(rtrim((string) $rem, '0'), '.') . 'rem';
    }

    private function rewrite_css_urls(string $css, string $stylesheet_url): string
    {
        $base = trailingslashit(dirname($stylesheet_url));

        return preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $matches) use ($base): string {
                $url = trim($matches[2]);
                if ($url === '' || preg_match('/^(data:|https?:|\/\/|\/|#)/i', $url)) {
                    return $matches[0];
                }

                return 'url("' . esc_url_raw($base . $url) . '")';
            },
            $css
        ) ?? $css;
    }

    private function resolve_css_url(string $url, string $stylesheet_url): string
    {
        if (preg_match('/^(https?:)?\/\//i', $url) || strpos($url, '/') === 0) {
            return $url;
        }

        return trailingslashit(dirname($stylesheet_url)) . $url;
    }

    private function repair_images(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//img[not(ancestor::template) and not(ancestor::slot)]') ?: [] as $image) {
            if (!$image instanceof DOMElement || $this->is_hidden($image)) {
                continue;
            }

            if (!$image->hasAttribute('alt')) {
                $inferred = $this->infer_image_alt($image);
                $image->setAttribute('alt', $inferred !== '' ? $inferred : ($this->aggressive_repair ? __('image', 'freego-wp') : ''));
                $image->setAttribute('data-freego-wp-needs-alt-review', '1');
                $image->setAttribute('data-freego-wp-repaired', trim($image->getAttribute('data-freego-wp-repaired') . ' HM1110100C'));
                continue;
            }

            if (trim($image->getAttribute('alt')) === '' && !$image->hasAttribute('title')) {
                $inferred = $this->infer_image_alt($image);
                if ($inferred === '' && !$this->aggressive_repair) {
                    continue;
                }

                $image->setAttribute('alt', $inferred !== '' ? $inferred : __('image', 'freego-wp'));
                $image->setAttribute('data-freego-wp-needs-alt-review', '1');
                $image->setAttribute('data-freego-wp-repaired', trim($image->getAttribute('data-freego-wp-repaired') . ' HM1110100C'));
            }

            if (trim($image->getAttribute('alt')) === '' && $image->hasAttribute('title')) {
                if ($this->aggressive_repair) {
                    $image->setAttribute('alt', trim($image->getAttribute('title')) ?: __('image', 'freego-wp'));
                } else {
                    $image->removeAttribute('title');
                }
                $image->setAttribute('data-freego-wp-needs-alt-review', '1');
                $image->setAttribute('data-freego-wp-repaired', trim($image->getAttribute('data-freego-wp-repaired') . ' HM1110106C'));
            }

            if ($image->hasAttribute('src') && trim($image->getAttribute('src')) === trim($image->getAttribute('alt'))) {
                $image->setAttribute('data-freego-wp-needs-alt-review', '1');
            }
        }

        foreach ($xpath->query('//*[@role="img" and not(self::img) and not(self::svg)]') ?: [] as $node) {
            if (!$node instanceof DOMElement || $this->is_hidden($node)) {
                continue;
            }

            if (!$this->has_accessible_name($node, $xpath)) {
                if ($this->aggressive_repair) {
                    $node->setAttribute('aria-label', __('image', 'freego-wp'));
                    $node->setAttribute('data-freego-wp-repaired', trim($node->getAttribute('data-freego-wp-repaired') . ' HM1110100C'));
                }
                $node->setAttribute('data-freego-wp-needs-name-review', '1');
            }
        }
    }

    private function repair_image_maps(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//map[not(ancestor::template) and not(ancestor::slot)]//area') ?: [] as $area) {
            if (!$area instanceof DOMElement || $this->is_hidden($area)) {
                continue;
            }

            $alt = trim($area->getAttribute('alt'));
            if ($alt !== '' || $this->has_accessible_name($area, $xpath)) {
                continue;
            }

            $label = trim($area->getAttribute('title'));
            if ($label === '') {
                $label = $this->label_from_href($area->getAttribute('href'), __('Map area pending accessibility review', 'freego-wp'));
            }

            $area->setAttribute('alt', $label);
            $area->setAttribute('data-freego-wp-needs-alt-review', '1');
            $area->setAttribute('data-freego-wp-repaired', trim($area->getAttribute('data-freego-wp-repaired') . ' HM1110101C'));
        }
    }

    private function repair_image_inputs(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//input[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "image"]') ?: [] as $input) {
            if (!$input instanceof DOMElement || $this->is_hidden($input)) {
                continue;
            }

            if (trim($input->getAttribute('alt')) === '' && !$this->has_accessible_name($input, $xpath)) {
                if ($this->aggressive_repair) {
                    $input->setAttribute('alt', __('submit', 'freego-wp'));
                    $input->setAttribute('data-freego-wp-repaired', trim($input->getAttribute('data-freego-wp-repaired') . ' HM1110104C'));
                }
                $input->setAttribute('data-freego-wp-needs-name-review', '1');
            }
        }
    }

    private function infer_image_alt(DOMElement $image): string
    {
        $parent = $image->parentNode;
        while ($parent instanceof DOMElement && strtolower($parent->tagName) !== 'a') {
            $parent = $parent->parentNode;
        }

        if ($parent instanceof DOMElement) {
            foreach (['aria-label', 'title'] as $attribute) {
                $value = trim($parent->getAttribute($attribute));
                if ($value !== '') {
                    return $value;
                }
            }

            $text = trim(preg_replace('/\s+/', ' ', $parent->textContent) ?? '');
            if ($text !== '') {
                return $text;
            }

            $label = $this->label_from_href($parent->getAttribute('href'));
            if ($label !== '') {
                return $label;
            }
        }

        $article = $this->closest_ancestor($image, 'article');
        if ($article instanceof DOMElement) {
            foreach ($article->getElementsByTagName('*') as $candidate) {
                if (!$candidate instanceof DOMElement || !preg_match('/^h[1-6]$/i', $candidate->tagName)) {
                    continue;
                }

                $text = trim(preg_replace('/\s+/', ' ', $candidate->textContent) ?? '');
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function repair_links(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement || $this->is_hidden($link)) {
                continue;
            }

            $visible_text = trim(preg_replace('/\s+/', ' ', $link->textContent) ?? '');
            $text = $visible_text;
            $title = trim($link->getAttribute('title'));

            if ($text === '') {
                $image = $link->getElementsByTagName('img')->item(0);
                if ($image instanceof DOMElement && trim($image->getAttribute('alt')) !== '') {
                    $text = trim($image->getAttribute('alt'));
                }
            }

            if ($text === '' && $title !== '') {
                $link->setAttribute('aria-label', $title);
                $link->setAttribute('data-freego-wp-repaired', trim($link->getAttribute('data-freego-wp-repaired') . ' HM1240401C'));
                continue;
            }

            if ($title === '' && $text !== '') {
                $link->setAttribute('title', $text);
                $link->setAttribute('data-freego-wp-repaired', trim($link->getAttribute('data-freego-wp-repaired') . ' HM3240900C'));
            }

            if ($visible_text !== '') {
                foreach ($link->getElementsByTagName('img') as $image) {
                    if (!$image instanceof DOMElement || $this->is_hidden($image)) {
                        continue;
                    }

                    if (trim($image->getAttribute('alt')) === $visible_text) {
                        $image->setAttribute('alt', '');
                        $image->setAttribute('data-freego-wp-needs-alt-review', '1');
                        $image->setAttribute('data-freego-wp-repaired', trim($image->getAttribute('data-freego-wp-repaired') . ' HM1240400C'));
                    }
                }
            }

            if ($text === '' && $title === '') {
                $label = $this->label_from_link_context($link);
                if ($label !== '') {
                    $link->setAttribute('aria-label', $label);
                    $link->setAttribute('title', $label);
                    $link->setAttribute('data-freego-wp-repaired', trim($link->getAttribute('data-freego-wp-repaired') . ' HM1240401C'));
                    continue;
                }

                if ($this->aggressive_repair) {
                    $label = $this->label_from_href($link->getAttribute('href'), __('link', 'freego-wp'));
                    $link->setAttribute('aria-label', $label);
                    $link->setAttribute('title', $link->getAttribute('aria-label'));
                    $link->setAttribute('data-freego-wp-repaired', trim($link->getAttribute('data-freego-wp-repaired') . ' HM1240401C'));
                }
                $link->setAttribute('data-freego-wp-needs-link-review', '1');
            }
        }
    }

    private function repair_buttons(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//button') ?: [] as $button) {
            if (
                !$button instanceof DOMElement ||
                $this->is_hidden($button) ||
                trim(preg_replace('/\s+/', ' ', $button->textContent) ?? '') !== '' ||
                $this->has_accessible_name($button, $xpath)
            ) {
                continue;
            }

            $label = $this->label_from_classes($button->getAttribute('class'));
            if ($label === '') {
                $label = $this->button_label_from_context($button);
            }

            if ($label === '') {
                if (!$this->aggressive_repair) {
                    $button->setAttribute('data-freego-wp-needs-name-review', '1');
                    continue;
                }
                $label = __('button', 'freego-wp');
            }

            $button->setAttribute('aria-label', $label);
            $button->setAttribute('data-freego-wp-needs-name-review', '1');
            $button->setAttribute('data-freego-wp-repaired', trim($button->getAttribute('data-freego-wp-repaired') . ' HM1410200C'));
        }
    }

    private function repair_navigation_landmarks(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//nav[not(ancestor::template) and not(ancestor::slot)]') ?: [] as $nav) {
            if (!$nav instanceof DOMElement || $this->is_hidden($nav) || $this->has_element_children($nav)) {
                continue;
            }

            if ($nav->parentNode) {
                $nav->parentNode->removeChild($nav);
            }
        }
    }

    private function repair_iframes(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//iframe') ?: [] as $iframe) {
            if (!$iframe instanceof DOMElement || trim($iframe->getAttribute('title')) !== '') {
                continue;
            }

            if ($this->aggressive_repair) {
                $iframe->setAttribute('title', $this->label_from_href($iframe->getAttribute('src'), __('frame', 'freego-wp')));
                $iframe->setAttribute('data-freego-wp-repaired', trim($iframe->getAttribute('data-freego-wp-repaired') . ' HM1410201C'));
            }
            $iframe->setAttribute('data-freego-wp-needs-title-review', '1');
        }
    }

    private function repair_forms(DOMDocument $document, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//input[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "button"]') ?: [] as $button_input) {
            if (!$button_input instanceof DOMElement || $this->is_hidden($button_input)) {
                continue;
            }

            if (trim($button_input->getAttribute('value')) === '') {
                $button_input->setAttribute('value', $this->field_label($button_input));
                $button_input->setAttribute('data-freego-wp-needs-name-review', '1');
                $button_input->setAttribute('data-freego-wp-repaired', trim($button_input->getAttribute('data-freego-wp-repaired') . ' HM1410200C'));
            }
        }

        foreach ($xpath->query('//input[not(@type) or not(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "hidden" or translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "submit" or translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "button" or translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "image")] | //textarea | //select') ?: [] as $control) {
            if (!$control instanceof DOMElement || $this->is_hidden($control)) {
                continue;
            }

            $label_text = $this->field_label($control);
            if ($this->has_accessible_name($control, $xpath)) {
                if (trim($control->getAttribute('title')) === '') {
                    $control->setAttribute('title', $label_text);
                    $control->setAttribute('data-freego-wp-repaired', trim($control->getAttribute('data-freego-wp-repaired') . ' HM3330500C'));
                }
                continue;
            }

            $id = trim($control->getAttribute('id'));
            if ($id === '') {
                $id = 'freego-wp-field-' . substr(md5(spl_object_hash($control)), 0, 10);
                $control->setAttribute('id', $id);
            }

            $control->setAttribute('title', $label_text);
            $control->setAttribute('aria-label', $label_text);
            $control->setAttribute('data-freego-wp-repaired', trim($control->getAttribute('data-freego-wp-repaired') . ' HM1130104C HM3330500C'));

            $label = $document->createElement('label', $label_text);
            $label->setAttribute('for', $id);
            $label->setAttribute('class', 'freego-wp-sr-only');
            $label->setAttribute('data-freego-wp-needs-label-review', '1');

            if ($control->parentNode) {
                $control->parentNode->insertBefore($label, $control);
            }
        }

        foreach ($xpath->query('//fieldset[not(legend)]') ?: [] as $fieldset) {
            if (!$fieldset instanceof DOMElement || $this->is_hidden($fieldset)) {
                continue;
            }

            $legend = $document->createElement('legend', $this->legend_label($fieldset));
            $legend->setAttribute('class', 'freego-wp-sr-only');
            $legend->setAttribute('data-freego-wp-needs-legend-review', '1');
            $fieldset->insertBefore($legend, $fieldset->firstChild);
            $fieldset->setAttribute('data-freego-wp-repaired', trim($fieldset->getAttribute('data-freego-wp-repaired') . ' HM1130103C'));
        }
    }

    private function repair_tables(DOMDocument $document, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//table[not(caption)]') ?: [] as $table) {
            if (!$table instanceof DOMElement || $this->is_hidden($table)) {
                continue;
            }

            $table->setAttribute('data-freego-wp-needs-caption-review', '1');
        }

        foreach ($xpath->query('//th[not(@scope)]') ?: [] as $header) {
            if (!$header instanceof DOMElement) {
                continue;
            }

            if ($this->aggressive_repair) {
                $header->setAttribute('scope', $this->infer_th_scope($header));
                $header->setAttribute('data-freego-wp-repaired', trim($header->getAttribute('data-freego-wp-repaired') . ' HM1130101C'));
            }
            $header->setAttribute('data-freego-wp-needs-table-review', '1');
        }

        if ($this->aggressive_repair) {
            foreach ($xpath->query('//table[.//th and .//td]') ?: [] as $table) {
                if ($table instanceof DOMElement) {
                    $this->repair_table_headers($table, $xpath);
                }
            }
        }
    }

    private function repair_embeds(DOMDocument $document, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//applet') ?: [] as $applet) {
            if (!$applet instanceof DOMElement || $this->is_hidden($applet) || trim($applet->getAttribute('alt')) !== '') {
                continue;
            }

            if ($this->aggressive_repair) {
                $applet->setAttribute('alt', __('embedded content', 'freego-wp'));
                $applet->setAttribute('data-freego-wp-repaired', trim($applet->getAttribute('data-freego-wp-repaired') . ' HM1110105C'));
            }
            $applet->setAttribute('data-freego-wp-needs-embed-review', '1');
        }

        foreach ($xpath->query('//object | //embed') ?: [] as $embed) {
            if (!$embed instanceof DOMElement || $this->is_hidden($embed)) {
                continue;
            }

            if ($this->has_object_fallback_content($embed) || $this->has_accessible_name($embed, $xpath)) {
                continue;
            }

            if ($this->aggressive_repair) {
                $embed->setAttribute('title', __('embedded content', 'freego-wp'));
                $embed->setAttribute('data-freego-wp-repaired', trim($embed->getAttribute('data-freego-wp-repaired') . ' HM1110105C'));
            }
            $embed->setAttribute('data-freego-wp-needs-embed-review', '1');
        }

        foreach ($xpath->query('//select[count(option) > 8 and not(optgroup)]') ?: [] as $select) {
            if (!$select instanceof DOMElement) {
                continue;
            }

            if ($this->aggressive_repair) {
                $this->wrap_options_in_optgroup($document, $select);
            }
            $select->setAttribute('data-freego-wp-needs-optgroup-review', '1');
            $select->setAttribute('data-freego-wp-repaired', trim($select->getAttribute('data-freego-wp-repaired') . ' HM1130103C_1'));
        }
    }

    private function mark_heading_review(DOMXPath $xpath): void
    {
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        if (!$headings || $headings->length === 0) {
            return;
        }

        $previous = 0;
        foreach ($headings as $heading) {
            if (!$heading instanceof DOMElement) {
                continue;
            }

            $level = (int) substr($heading->tagName, 1);
            if ($previous > 0 && $level > $previous + 1) {
                $heading->setAttribute('data-freego-wp-needs-heading-review', '1');
            }
            $previous = $level;
        }
    }

    private function repair_headings(DOMDocument $document, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//h1[not(normalize-space())] | //h2[not(normalize-space())] | //h3[not(normalize-space())] | //h4[not(normalize-space())] | //h5[not(normalize-space())] | //h6[not(normalize-space())]') ?: [] as $heading) {
            if ($heading instanceof DOMElement && $heading->parentNode) {
                $heading->parentNode->removeChild($heading);
            }
        }

        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        if ($headings && $headings->length > 0) {
            return;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return;
        }

        $title = $document->getElementsByTagName('title')->item(0);
        $text = $title instanceof DOMElement ? trim($title->textContent) : '';
        if ($text === '') {
            $text = get_bloginfo('name') ?: __('Page', 'freego-wp');
        }

        $h1 = $document->createElement('h1', $text);
        $h1->setAttribute('class', 'freego-wp-sr-only');
        $h1->setAttribute('data-freego-wp-repaired', 'HM1130100C HM3241000C');
        $body->insertBefore($h1, $body->firstChild);
    }

    private function ensure_skip_link(DOMDocument $document, DOMXPath $xpath): void
    {
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return;
        }

        $existing = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " freego-wp-skip-link ")]');
        if ($existing && $existing->length > 0) {
            return;
        }

        $main = $xpath->query('//main | //*[@role="main"]')->item(0);
        if ($main instanceof DOMElement && trim($main->getAttribute('id')) === '') {
            $main->setAttribute('id', 'freego-wp-main');
        }

        $target = $main instanceof DOMElement ? $main->getAttribute('id') : 'content';
        $link = $document->createElement('a', __('Skip to main content', 'freego-wp'));
        $link->setAttribute('href', '#' . $target);
        $link->setAttribute('class', 'freego-wp-skip-link');

        $body->insertBefore($link, $body->firstChild);
    }

    private function mark_report(DOMDocument $document): void
    {
        $comment = $document->createComment(' Freego WP Accessibility Assistant active: repairs are marked with data-freego-wp-* attributes. ');
        $document->appendChild($comment);
    }

    private function is_hidden(DOMElement $element): bool
    {
        if (strtolower(trim($element->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }

        $style = strtolower(str_replace(' ', '', $element->getAttribute('style')));

        return strpos($style, 'display:none') !== false || strpos($style, 'visibility:hidden') !== false;
    }

    private function has_accessible_name(DOMElement $element, DOMXPath $xpath): bool
    {
        foreach (['aria-label', 'title', 'alt'] as $attribute) {
            if (trim($element->getAttribute($attribute)) !== '') {
                return true;
            }
        }

        $labelledby = trim($element->getAttribute('aria-labelledby'));
        if ($labelledby !== '') {
            foreach (preg_split('/\s+/', $labelledby) ?: [] as $id) {
                $query = '//*[@id=' . $this->xpath_literal($id) . ']';
                $label = $xpath->query($query)->item(0);
                if ($label instanceof DOMElement && trim($label->textContent) !== '') {
                    return true;
                }
            }
        }

        $id = trim($element->getAttribute('id'));
        if ($id !== '') {
            $query = '//label[@for=' . $this->xpath_literal($id) . ']';
            $label = $xpath->query($query)->item(0);
            if ($label instanceof DOMElement && trim($label->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    private function has_object_fallback_content(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if ($tag === 'embed') {
            return trim($element->textContent) !== '';
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->wholeText) !== '') {
                return true;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            if (strtolower($child->tagName) !== 'param') {
                return true;
            }
        }

        return false;
    }

    private function has_element_children(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    private function field_label(DOMElement $control): string
    {
        $placeholder = trim($control->getAttribute('placeholder'));
        if ($placeholder !== '') {
            return $placeholder;
        }

        if (strtolower($control->tagName) === 'select') {
            foreach ($control->getElementsByTagName('option') as $option) {
                if (!$option instanceof DOMElement) {
                    continue;
                }

                $text = trim(preg_replace('/\s+/', ' ', $option->textContent) ?? '');
                if ($text !== '') {
                    return $text;
                }
            }
        }

        $name = trim($control->getAttribute('name'));
        if ($name !== '') {
            return ucwords(str_replace(['_', '-'], ' ', $name));
        }

        return __('Field pending accessibility review', 'freego-wp');
    }

    private function legend_label(DOMElement $fieldset): string
    {
        $class = strtolower($fieldset->getAttribute('class'));
        if (strpos($class, 'hidden-fields-container') !== false) {
            return __('Hidden form fields', 'freego-wp');
        }

        $form = $this->closest_ancestor($fieldset, 'form');
        if ($form instanceof DOMElement) {
            foreach (['aria-label', 'title', 'name', 'id'] as $attribute) {
                $value = trim($form->getAttribute($attribute));
                if ($value !== '') {
                    return ucwords(str_replace(['_', '-'], ' ', $value));
                }
            }
        }

        return __('Form fields', 'freego-wp');
    }

    private function label_from_href(string $href, string $fallback = ''): string
    {
        $path = trim((string) wp_parse_url($href, PHP_URL_PATH), '/');
        if ($path !== '') {
            $last = basename($path);
            return ucwords(str_replace(['-', '_', '%20'], ' ', rawurldecode($last)));
        }

        return $fallback !== '' ? $fallback : __('Link pending accessibility review', 'freego-wp');
    }

    private function label_from_classes(string $class): string
    {
        $class = strtolower($class);

        if (preg_match('/\b(close|dismiss)\b|closeicon/', $class)) {
            return __('close', 'freego-wp');
        }

        if (preg_match('/\b(menu|submenu|toggle|expand)\b/', $class)) {
            return __('menu', 'freego-wp');
        }

        if (preg_match('/\b(search)\b/', $class)) {
            return __('search', 'freego-wp');
        }

        return '';
    }

    private function label_from_link_context(DOMElement $link): string
    {
        $href = strtolower(html_entity_decode($link->getAttribute('href'), ENT_QUOTES));

        $service = $this->service_label_from_href($href);
        if ($service !== '') {
            if (preg_match('/(?:^|[\/?&=._-])(share|sharer|sharearticle|submit|pin|send)(?:$|[\/?&=._-])/', $href)) {
                /* translators: %s: social network or sharing service name. */
                return sprintf(__('Share on %s', 'freego-wp'), $service);
            }

            if (preg_match('/(?:^|[\/?&=._-])(save|bookmark)(?:$|[\/?&=._-])/', $href)) {
                /* translators: %s: saving or bookmarking service name. */
                return sprintf(__('Save to %s', 'freego-wp'), $service);
            }
        }

        $token = $this->semantic_token_from_classes($link);
        if ($token !== '') {
            return $token;
        }

        return '';
    }

    private function service_label_from_href(string $href): string
    {
        $host = (string) wp_parse_url($href, PHP_URL_HOST);
        if (strpos($href, 'whatsapp:') === 0) {
            $host = 'whatsapp';
        }

        $host = preg_replace('/^www\./', '', strtolower($host)) ?? '';
        if ($host === '') {
            return '';
        }

        $labels = [
            'x.com' => 'X',
            'twitter.com' => 'Twitter',
            'facebook.com' => 'Facebook',
            'pinterest.com' => 'Pinterest',
            'linkedin.com' => 'LinkedIn',
            'tumblr.com' => 'Tumblr',
            'reddit.com' => 'Reddit',
            'getpocket.com' => 'Pocket',
            'vk.com' => 'VKontakte',
            'ok.ru' => 'OK',
            'connect.ok.ru' => 'OK',
            'whatsapp' => 'WhatsApp',
        ];

        foreach ($labels as $domain => $label) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return $label;
            }
        }

        $parts = explode('.', $host);
        $base = $parts[0] ?? '';

        return $base !== '' ? ucwords(str_replace(['-', '_'], ' ', $base)) : '';
    }

    private function semantic_token_from_classes(DOMElement $element): string
    {
        $tokens = [];
        $current = $element;

        while ($current instanceof DOMElement) {
            foreach (preg_split('/\s+/', strtolower($current->getAttribute('class'))) ?: [] as $class) {
                foreach (preg_split('/[-_]+/', $class) ?: [] as $part) {
                    $part = trim($part);
                    if ($part === '' || in_array($part, ['a', 'link', 'links', 'icon', 'icons', 'social', 'share', 'sharing', 'button', 'btn', 'tfm', 'cmswt'], true)) {
                        continue;
                    }

                    if (preg_match('/^[a-z][a-z0-9]{1,24}$/', $part)) {
                        $tokens[] = $part;
                    }
                }
            }

            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        if (!$tokens) {
            return '';
        }

        return ucwords(str_replace(['-', '_'], ' ', $tokens[0]));
    }

    private function closest_ancestor(DOMElement $element, string $tag): ?DOMElement
    {
        $tag = strtolower($tag);
        $parent = $element->parentNode;
        while ($parent instanceof DOMElement) {
            if (strtolower($parent->tagName) === $tag) {
                return $parent;
            }
            $parent = $parent->parentNode;
        }

        return null;
    }

    private function normalize_lang(string $lang): string
    {
        return strtolower(str_replace('_', '-', trim($lang)));
    }

    private function button_label_from_context(DOMElement $button): string
    {
        foreach (['title', 'aria-label'] as $attribute) {
            $value = trim($button->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        $parent = $button->parentNode;
        if (!$parent instanceof DOMElement) {
            return '';
        }

        $text = trim(preg_replace('/\s+/', ' ', $parent->textContent) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        return $text !== '' && $length <= 80 ? $text : '';
    }

    private function infer_th_scope(DOMElement $header): string
    {
        $parent = $header->parentNode;
        if (!$parent instanceof DOMElement) {
            return 'col';
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['th', 'td'], true)) {
                return $child->isSameNode($header) ? 'row' : 'col';
            }
        }

        return 'col';
    }

    private function repair_table_headers(DOMElement $table, DOMXPath $xpath): void
    {
        $headers = [];
        foreach ($xpath->query('.//th', $table) ?: [] as $index => $header) {
            if (!$header instanceof DOMElement) {
                continue;
            }

            if (trim($header->getAttribute('id')) === '') {
                $header->setAttribute('id', 'freego-wp-th-' . substr(md5(spl_object_hash($header) . (string) $index), 0, 10));
            }
            $headers[] = $header->getAttribute('id');
        }

        if (!$headers) {
            return;
        }

        $header_string = implode(' ', array_slice($headers, 0, 6));
        foreach ($xpath->query('.//td[not(@headers)]', $table) ?: [] as $cell) {
            if ($cell instanceof DOMElement) {
                $cell->setAttribute('headers', $header_string);
                $cell->setAttribute('data-freego-wp-needs-table-review', '1');
                $cell->setAttribute('data-freego-wp-repaired', trim($cell->getAttribute('data-freego-wp-repaired') . ' HM1130102C'));
            }
        }
    }

    private function wrap_options_in_optgroup(DOMDocument $document, DOMElement $select): void
    {
        if ($select->getElementsByTagName('optgroup')->length > 0) {
            return;
        }

        $options = [];
        foreach ($select->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'option') {
                $options[] = $child;
            }
        }

        if (!$options) {
            return;
        }

        $group = $document->createElement('optgroup');
        $group->setAttribute('label', __('options', 'freego-wp'));
        foreach ($options as $option) {
            $group->appendChild($option);
        }
        $select->appendChild($group);
    }

    private function xpath_literal(string $value): string
    {
        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }

        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }

        $parts = array_map(static function (string $part): string {
            return '"' . $part . '"';
        }, explode('"', $value));

        return 'concat(' . implode(', \'"\', ', $parts) . ')';
    }

    private function strip_xml_encoding_marker(string $html): string
    {
        return preg_replace('/^<\?xml encoding="utf-8" \?>/i', '', $html) ?? $html;
    }
}
