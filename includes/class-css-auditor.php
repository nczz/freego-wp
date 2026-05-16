<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_CSS_Auditor
{
    private Freego_WP_Rules $rules;

    public function __construct(Freego_WP_Rules $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<int,array<string,mixed>>
     */
    public function analyze_html_styles(string $html, array $source): array
    {
        $issues = [];

        if (preg_match_all('/style\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            foreach ($matches[2] as $style) {
                $issues = array_merge($issues, $this->analyze_css_block(html_entity_decode((string) $style), $source, 'inline style'));
            }
        }

        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            foreach ($matches[1] as $style) {
                $issues = array_merge($issues, $this->analyze_css_block((string) $style, $source, 'style element'));
            }
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<int,array<string,mixed>>
     */
    public function analyze_css_block(string $css, array $source, string $selector = 'css'): array
    {
        $issues = [];
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;

        if (preg_match_all('/font-size\s*:\s*([^;}{]+)/i', $css, $matches)) {
            foreach ($matches[1] as $value) {
                $value = trim((string) $value);
                if ($this->uses_absolute_length($value)) {
                    $issues[] = $this->issue('CS2140401C', 'review', sprintf(__('Font size uses an absolute unit: %s', 'freego-wp'), $value), $source, $selector, 'font-size:' . $value);
                    $issues[] = $this->issue('CS2140402C', 'review', sprintf(__('Text font size should be reviewed for em-relative sizing: %s', 'freego-wp'), $value), $source, $selector, 'font-size:' . $value);
                }
            }
        }

        if (preg_match_all('/(?:width|max-width)\s*:\s*([^;}{]+)/i', $css, $matches)) {
            foreach ($matches[1] as $value) {
                $value = trim((string) $value);
                if ($this->uses_absolute_length($value)) {
                    $issues[] = $this->issue('CS3140801C', 'review', sprintf(__('Column or content width uses an absolute unit: %s', 'freego-wp'), $value), $source, $selector, 'width:' . $value);
                }
                if (preg_match('/([0-9.]+)\s*(?:em|rem|ch)/i', $value, $size) && (float) $size[1] > 80) {
                    $issues[] = $this->issue('CS3140801C', 'review', sprintf(__('Column width may exceed 80 characters: %s', 'freego-wp'), $value), $source, $selector, 'width:' . $value);
                }
            }
        }

        if (preg_match_all('/line-height\s*:\s*([^;}{]+)/i', $css, $matches)) {
            foreach ($matches[1] as $value) {
                $value = trim((string) $value);
                if ($this->uses_absolute_length($value)) {
                    $issues[] = $this->issue('CS3140802C', 'review', sprintf(__('Line height uses an absolute unit: %s', 'freego-wp'), $value), $source, $selector, 'line-height:' . $value);
                }
            }
        } elseif (preg_match('/font-size\s*:/i', $css)) {
            $issues[] = $this->issue('CS3140802C', 'review', __('Text styling was found without an explicit line-height declaration.', 'freego-wp'), $source, $selector, 'line-height missing');
        }

        return $issues;
    }

    private function uses_absolute_length(string $value): bool
    {
        if (preg_match('/\b0(?:px|pt|pc|cm|mm|in|q)\b/i', $value)) {
            return false;
        }

        return (bool) preg_match('/\b[0-9.]+\s*(px|pt|pc|cm|mm|in|q)\b/i', $value);
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function issue(string $code, string $severity, string $message, array $source, string $selector, string $snippet): array
    {
        $rule = $this->rules->get($code) ?? [];

        return [
            'code' => $code,
            'guideline' => (string) ($rule['guideline'] ?? ''),
            'automation' => (string) ($rule['automation'] ?? 'report_only'),
            'severity' => $severity,
            'message' => $message,
            'selector' => $selector,
            'snippet' => substr($snippet, 0, 500),
            'source_type' => (string) ($source['source_type'] ?? ''),
            'source_id' => (int) ($source['source_id'] ?? 0),
            'source_url' => (string) ($source['source_url'] ?? ''),
            'context' => ['rule' => $rule],
        ];
    }
}
