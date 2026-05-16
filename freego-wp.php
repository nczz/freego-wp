<?php
/**
 * Plugin Name: Freego WP Accessibility Assistant
 * Description: Freego-oriented accessibility repair, authoring guardrails, and audit workflow for WordPress.
 * Version: 0.1.9
 * Author: MXP
 * Text Domain: freego-wp
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/nczz/freego-wp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FREEGO_WP_VERSION', '0.1.9');
define('FREEGO_WP_FILE', __FILE__);
define('FREEGO_WP_DIR', plugin_dir_path(__FILE__));
define('FREEGO_WP_URL', plugin_dir_url(__FILE__));
define('FREEGO_WP_OPTION_AGGRESSIVE_REPAIR', 'freego_wp_aggressive_repair');
define('FREEGO_WP_OPTION_TARGET_LEVEL', 'freego_wp_target_level');
define('FREEGO_WP_OPTION_DELETE_DATA_ON_UNINSTALL', 'freego_wp_delete_data_on_uninstall');
define('FREEGO_WP_GITHUB_OWNER', 'nczz');
define('FREEGO_WP_GITHUB_REPO', 'freego-wp');
define('FREEGO_WP_GITHUB_REPO_URL', 'https://github.com/' . FREEGO_WP_GITHUB_OWNER . '/' . FREEGO_WP_GITHUB_REPO);

require_once FREEGO_WP_DIR . 'includes/class-rules.php';
require_once FREEGO_WP_DIR . 'includes/class-issue-store.php';
require_once FREEGO_WP_DIR . 'includes/class-css-auditor.php';
require_once FREEGO_WP_DIR . 'includes/class-scanner.php';
require_once FREEGO_WP_DIR . 'includes/class-output-repair.php';
require_once FREEGO_WP_DIR . 'includes/class-guardrails.php';
require_once FREEGO_WP_DIR . 'includes/class-github-updater.php';
require_once FREEGO_WP_DIR . 'includes/class-admin.php';
require_once FREEGO_WP_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, static function (): void {
    Freego_WP_Issue_Store::activate();
});

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('freego-wp', false, dirname(plugin_basename(__FILE__)) . '/languages');

    Freego_WP_Plugin::instance()->boot();
});
