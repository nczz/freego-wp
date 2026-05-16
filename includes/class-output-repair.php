<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Output_Repair
{
    private Freego_WP_Rules $rules;
    private bool $aggressive_repair = false;

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
        $this->repair_images($xpath);
        $this->repair_image_inputs($xpath);
        $this->repair_links($xpath);
        $this->repair_iframes($xpath);
        $this->repair_forms($document, $xpath);
        $this->repair_tables($document, $xpath);
        $this->repair_embeds($document, $xpath);
        $this->mark_heading_review($xpath);
        $this->ensure_skip_link($document, $xpath);
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

    private function repair_images(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//img[not(ancestor::template) and not(ancestor::slot)]') ?: [] as $image) {
            if (!$image instanceof DOMElement || $this->is_hidden($image)) {
                continue;
            }

            if (!$image->hasAttribute('alt')) {
                $image->setAttribute('alt', $this->aggressive_repair ? __('image', 'freego-wp') : '');
                $image->setAttribute('data-freego-wp-needs-alt-review', '1');
                $image->setAttribute('data-freego-wp-repaired', trim($image->getAttribute('data-freego-wp-repaired') . ' HM1110100C'));
                continue;
            }

            if ($this->aggressive_repair && trim($image->getAttribute('alt')) === '' && !$image->hasAttribute('title')) {
                $image->setAttribute('alt', __('image', 'freego-wp'));
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

    private function repair_links(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement || $this->is_hidden($link)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', $link->textContent) ?? '');
            $title = trim($link->getAttribute('title'));

            if ($text === '') {
                $image = $link->getElementsByTagName('img')->item(0);
                if ($image instanceof DOMElement && trim($image->getAttribute('alt')) !== '') {
                    $text = trim($image->getAttribute('alt'));
                }
            }

            if ($title === '' && $text !== '') {
                $link->setAttribute('title', $text);
                $link->setAttribute('data-freego-wp-repaired', trim($link->getAttribute('data-freego-wp-repaired') . ' HM3240900C'));
            }

            if ($text === '' && $title === '') {
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
        foreach ($xpath->query('//input[not(@type) or not(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "hidden" or translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "submit" or translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "button" or translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "image")] | //textarea | //select') ?: [] as $control) {
            if (!$control instanceof DOMElement || $this->is_hidden($control)) {
                continue;
            }

            if ($this->has_accessible_name($control, $xpath)) {
                continue;
            }

            $id = trim($control->getAttribute('id'));
            if ($id === '') {
                $id = 'freego-wp-field-' . substr(md5(spl_object_hash($control)), 0, 10);
                $control->setAttribute('id', $id);
            }

            $label = $document->createElement('label', $this->field_label($control));
            $label->setAttribute('for', $id);
            $label->setAttribute('class', 'freego-wp-sr-only');
            $label->setAttribute('data-freego-wp-needs-label-review', '1');

            if ($control->parentNode) {
                $control->parentNode->insertBefore($label, $control);
            }
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
        foreach ($xpath->query('//object | //embed | //applet') ?: [] as $embed) {
            if (!$embed instanceof DOMElement || $this->is_hidden($embed)) {
                continue;
            }

            if (trim($embed->textContent) !== '' || $this->has_accessible_name($embed, $xpath)) {
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

    private function field_label(DOMElement $control): string
    {
        $placeholder = trim($control->getAttribute('placeholder'));
        if ($placeholder !== '') {
            return $placeholder;
        }

        $name = trim($control->getAttribute('name'));
        if ($name !== '') {
            return ucwords(str_replace(['_', '-'], ' ', $name));
        }

        return __('Field pending accessibility review', 'freego-wp');
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
