<?php

define('ABSPATH', __DIR__ . '/fixtures/');
define('FREEGO_WP_OPTION_AGGRESSIVE_REPAIR', 'freego_wp_aggressive_repair');

function __($text, $domain = null)
{
    return $text;
}

function add_action($hook, $callback, $priority = 10)
{
}

function apply_filters($hook, $value)
{
    return $value;
}

function esc_url($url)
{
    return $url;
}

function esc_url_raw($url)
{
    return $url;
}

function get_bloginfo($show = '')
{
    return $show === 'charset' ? 'UTF-8' : 'Fixture Site';
}

function get_locale()
{
    return 'zh_TW';
}

function home_url($path = '')
{
    return 'https://www.mxp.tw' . $path;
}

function is_ssl()
{
    return true;
}

function trailingslashit($value)
{
    return rtrim($value, '/') . '/';
}

function wp_get_document_title()
{
    return 'Fixture Page';
}

function wp_make_link_relative($url)
{
    return preg_replace('#^https?://[^/]+#', '', $url);
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

require dirname(__DIR__) . '/includes/class-output-repair.php';

$repair = (new ReflectionClass('Freego_WP_Output_Repair'))->newInstanceWithoutConstructor();

function assert_contains($needle, $haystack, $label)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL {$label}: missing {$needle}\n");
        exit(1);
    }
}

function assert_not_contains($needle, $haystack, $label)
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL {$label}: unexpected {$needle}\n");
        exit(1);
    }
}

$method = new ReflectionMethod('Freego_WP_Output_Repair', 'repair_css_accessibility_units');
$method->setAccessible(true);
$css = $method->invoke($repair, 'a{font-size:22px;max-width:1220px;line-height:10px;font-size:24pt}');
assert_contains('font-size:1.375rem', $css, 'CS2140401C px');
assert_contains('max-width:76.25rem', $css, 'CS3140801C max-width');
assert_contains('line-height:0.625rem', $css, 'CS3140802C line-height');
assert_contains('font-size:2rem', $css, 'CS2140401C pt');

$html = '<html><head><title>Fixture</title>'
    . '<link rel="stylesheet" href="https://www.mxp.tw/wp-content/themes/twentynineteen/style.css">'
    . '<link rel="stylesheet" href="/wp-content/themes/mxp_tw/style.css">'
    . '<link rel="stylesheet" href="/wp-content/themes/mxp_tw/github-markdown.css">'
    . '</head><body>'
    . '<select name="swifts" id="mxp-swifts"><option value="">Choose SWIFT/BIC region</option></select>'
    . '<a href="#" class="cmswt-InstantSearchPopup--closeIcon" title="close"><svg role="img"></svg></a>'
    . '<button class="submenu-expand" tabindex="-1"><svg aria-hidden="true"></svg></button>'
    . '<bdo lang="">text</bdo>'
    . '</body></html>';

$output = $repair->repair_html($html);

assert_contains('data-freego-wp-inlined-css', $output, 'external CSS inlined');
assert_contains('font-size: 1.375rem', $output, 'theme font-size repaired');
assert_contains('font-size: 2rem', $output, 'print font-size repaired');
assert_contains('max-width: 76.25rem', $output, 'theme max-width repaired');
assert_contains('line-height: 0.625rem', $output, 'theme line-height repaired');
assert_contains('for="mxp-swifts"', $output, 'select label');
assert_contains('title="Choose SWIFT/BIC region"', $output, 'select title');
assert_contains('aria-label="Choose SWIFT/BIC region"', $output, 'select aria-label');
assert_contains('aria-label="close"', $output, 'icon link aria-label');
assert_contains('aria-label="menu"', $output, 'icon button aria-label');
assert_contains('<bdo lang="zh-TW"', $output, 'empty lang repaired');
assert_not_contains('<link rel="stylesheet"', $output, 'stylesheet links replaced');

echo "Output repair fixtures passed\n";
