<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Plugin
{
    private static ?Freego_WP_Plugin $instance = null;

    private Freego_WP_Rules $rules;
    private Freego_WP_Issue_Store $store;
    private Freego_WP_CSS_Auditor $css_auditor;
    private Freego_WP_Scanner $scanner;
    private Freego_WP_Output_Repair $repair;
    private Freego_WP_Guardrails $guardrails;
    private Freego_WP_GitHub_Updater $updater;
    private Freego_WP_Admin $admin;

    public static function instance(): Freego_WP_Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->rules = new Freego_WP_Rules();
        $this->store = new Freego_WP_Issue_Store();
        $this->css_auditor = new Freego_WP_CSS_Auditor($this->rules);
        $this->scanner = new Freego_WP_Scanner($this->rules, $this->store, $this->css_auditor);
        $this->repair = new Freego_WP_Output_Repair($this->rules);
        $this->guardrails = new Freego_WP_Guardrails($this->scanner, $this->store);
        $this->updater = new Freego_WP_GitHub_Updater();
        $this->admin = new Freego_WP_Admin($this->rules, $this->store, $this->scanner);
    }

    public function boot(): void
    {
        $this->repair->boot();
        $this->guardrails->boot();
        $this->updater->boot();
        $this->admin->boot();

        add_action('wp_enqueue_scripts', [$this, 'enqueue_runtime_assets']);
    }

    public function enqueue_runtime_assets(): void
    {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style(
            'freego-wp-runtime',
            FREEGO_WP_URL . 'assets/css/runtime.css',
            [],
            FREEGO_WP_VERSION
        );

        wp_enqueue_script(
            'freego-wp-runtime',
            FREEGO_WP_URL . 'assets/js/runtime.js',
            [],
            FREEGO_WP_VERSION,
            true
        );

        wp_localize_script('freego-wp-runtime', 'FreegoWP', [
            'aggressiveRepair' => (bool) get_option(FREEGO_WP_OPTION_AGGRESSIVE_REPAIR, false),
            'fallbacks' => [
                'image' => __('image', 'freego-wp'),
                'link' => __('link', 'freego-wp'),
                'frame' => __('frame', 'freego-wp'),
                'embed' => __('embedded content', 'freego-wp'),
                'submit' => __('submit', 'freego-wp'),
                'field' => __('field', 'freego-wp'),
                'options' => __('options', 'freego-wp'),
                'button' => __('button', 'freego-wp'),
            ],
        ]);
    }
}
