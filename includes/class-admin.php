<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Admin
{
    private Freego_WP_Rules $rules;
    private Freego_WP_Issue_Store $store;
    private Freego_WP_Scanner $scanner;

    public function __construct(Freego_WP_Rules $rules, Freego_WP_Issue_Store $store, Freego_WP_Scanner $scanner)
    {
        $this->rules = $rules;
        $this->store = $store;
        $this->scanner = $scanner;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_freego_wp_scan_site', [$this, 'handle_scan_site']);
        add_action('admin_post_freego_wp_scan_url', [$this, 'handle_scan_url']);
        add_action('admin_post_freego_wp_issue_status', [$this, 'handle_issue_status']);
        add_action('admin_post_freego_wp_save_settings', [$this, 'handle_save_settings']);
        add_filter('plugin_action_links_' . plugin_basename(FREEGO_WP_FILE), [$this, 'action_links']);
    }

    public function register_menu(): void
    {
        add_management_page(
            __('Freego Accessibility', 'freego-wp'),
            __('Freego Accessibility', 'freego-wp'),
            'manage_options',
            'freego-wp',
            [$this, 'render_page']
        );
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public function action_links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('tools.php?page=freego-wp')) . '">' . esc_html__('Dashboard', 'freego-wp') . '</a>');

        return $links;
    }

    public function handle_scan_site(): void
    {
        $this->require_admin('freego_wp_scan_site');
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 50;
        $result = $this->scanner->scan_site($limit);
        $this->redirect(['freego_notice' => sprintf('Scanned %d sources and found %d issues.', $result['scanned'], $result['issues'])]);
    }

    public function handle_scan_url(): void
    {
        $this->require_admin('freego_wp_scan_url');
        $url = isset($_POST['freego_wp_scan_url']) ? esc_url_raw(wp_unslash((string) $_POST['freego_wp_scan_url'])) : home_url('/');
        $result = $this->scanner->scan_url($url, true);

        if (!empty($result['error'])) {
            $this->redirect(['freego_error' => (string) $result['error']]);
        }

        $this->redirect(['freego_notice' => sprintf('Scanned URL and found %d issues.', count($result['issues'] ?? []))]);
    }

    public function handle_issue_status(): void
    {
        $this->require_admin('freego_wp_issue_status');
        $id = isset($_POST['issue_id']) ? (int) $_POST['issue_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_key((string) $_POST['status']) : Freego_WP_Issue_Store::STATUS_OPEN;

        if ($id > 0 && $this->store->update_status($id, $status)) {
            $this->redirect(['freego_notice' => __('Issue status updated.', 'freego-wp')]);
        }

        $this->redirect(['freego_error' => __('Unable to update issue status.', 'freego-wp')]);
    }

    public function handle_save_settings(): void
    {
        $this->require_admin('freego_wp_save_settings');
        update_option(FREEGO_WP_OPTION_AGGRESSIVE_REPAIR, !empty($_POST['aggressive_repair']) ? '1' : '0');
        $target = isset($_POST['target_level']) ? strtoupper(sanitize_key((string) $_POST['target_level'])) : 'AAA';
        if (!in_array($target, ['A', 'AA', 'AAA'], true)) {
            $target = 'AAA';
        }
        update_option(FREEGO_WP_OPTION_TARGET_LEVEL, $target);
        $this->redirect(['freego_notice' => __('Settings saved.', 'freego-wp')]);
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'freego-wp'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'dashboard';
        $allowed = ['dashboard', 'issues', 'rules'];
        if (!in_array($tab, $allowed, true)) {
            $tab = 'dashboard';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Freego Accessibility Assistant', 'freego-wp') . '</h1>';
        $this->render_notices();
        $this->render_tabs($tab);

        if ($tab === 'issues') {
            $this->render_issues();
        } elseif ($tab === 'rules') {
            $this->render_rules();
        } else {
            $this->render_dashboard();
        }

        echo '</div>';
    }

    private function render_dashboard(): void
    {
        $counts = $this->store->counts();
        $rule_counts = $this->counts_by_automation($this->rules->all());
        $level_counts = $this->rules->counts_by_level();
        $target_level = (string) get_option(FREEGO_WP_OPTION_TARGET_LEVEL, 'AAA');

        echo '<p>' . esc_html__('This dashboard connects automatic repair, authoring guardrails, semantic review, and Freego-oriented rule tracking.', 'freego-wp') . '</p>';

        echo '<div class="freego-wp-cards" style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
        foreach ($counts as $status => $count) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 14px;min-width:140px;">';
            echo '<strong>' . esc_html(ucfirst($status)) . '</strong><br><span style="font-size:24px;">' . esc_html((string) $count) . '</span>';
            echo '</div>';
        }
        echo '</div>';

        echo '<h2>' . esc_html__('Scan', 'freego-wp') . '</h2>';
        echo '<div style="display:grid;grid-template-columns:minmax(280px,1fr) minmax(280px,1fr);gap:16px;max-width:960px;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #c3c4c7;padding:14px;">';
        wp_nonce_field('freego_wp_scan_site');
        echo '<input type="hidden" name="action" value="freego_wp_scan_site">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Scan WordPress Content', 'freego-wp') . '</h3>';
        echo '<p>' . esc_html__('Scans public posts/pages and attachments, then stores review tasks.', 'freego-wp') . '</p>';
        echo '<label>' . esc_html__('Limit', 'freego-wp') . ' <input type="number" name="limit" value="50" min="1" max="200"></label> ';
        submit_button(__('Run content scan', 'freego-wp'), 'primary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #c3c4c7;padding:14px;">';
        wp_nonce_field('freego_wp_scan_url');
        echo '<input type="hidden" name="action" value="freego_wp_scan_url">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Scan Rendered URL', 'freego-wp') . '</h3>';
        echo '<p>' . esc_html__('Fetches one rendered HTML response and stores findings. Use Freego for browser-authoritative validation.', 'freego-wp') . '</p>';
        echo '<input name="freego_wp_scan_url" type="url" class="regular-text" value="' . esc_attr(home_url('/')) . '"> ';
        submit_button(__('Scan URL', 'freego-wp'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<h2>' . esc_html__('Repair Mode', 'freego-wp') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #c3c4c7;padding:14px;max-width:960px;">';
        wp_nonce_field('freego_wp_save_settings');
        echo '<input type="hidden" name="action" value="freego_wp_save_settings">';
        echo '<p><label>' . esc_html__('Target level', 'freego-wp') . ' <select name="target_level">';
        foreach (['A', 'AA', 'AAA'] as $level) {
            echo '<option value="' . esc_attr($level) . '" ' . selected($target_level, $level, false) . '>' . esc_html($level) . '</option>';
        }
        echo '</select></label></p>';
        echo '<label><input type="checkbox" name="aggressive_repair" value="1" ' . checked((bool) get_option(FREEGO_WP_OPTION_AGGRESSIVE_REPAIR, false), true, false) . '> ';
        echo esc_html__('Aggressive fake-value repair', 'freego-wp') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, missing required attributes receive fallback values across Freego-covered elements. Existing valid values are not overwritten. Review markers are still added so semantic cleanup remains traceable.', 'freego-wp') . '</p>';
        submit_button(__('Save repair mode', 'freego-wp'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<h2>' . esc_html__('Rule Automation Split', 'freego-wp') . '</h2>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
        foreach ($level_counts as $level => $count) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 14px;min-width:110px;"><strong>' . esc_html($level) . '</strong><br>' . esc_html((string) $count) . '</div>';
        }
        foreach ($rule_counts as $type => $count) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 14px;min-width:150px;"><strong>' . esc_html($this->automation_label($type)) . '</strong><br>' . esc_html((string) $count) . '</div>';
        }
        echo '</div>';
    }

    private function render_issues(): void
    {
        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = sanitize_key((string) $_GET['status']);
        }
        if (!empty($_GET['source_type'])) {
            $filters['source_type'] = sanitize_key((string) $_GET['source_type']);
        }
        if (isset($_GET['source_id'])) {
            $filters['source_id'] = (int) $_GET['source_id'];
        }

        $issues = $this->store->query($filters);

        echo '<h2>' . esc_html__('Issue Workflow', 'freego-wp') . '</h2>';
        echo '<p>' . esc_html__('Open items are not automatically claimed as compliant. Mark them reviewed only after semantic or engineering confirmation.', 'freego-wp') . '</p>';

        if (!$issues) {
            echo '<p>' . esc_html__('No issues match the current filter.', 'freego-wp') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Code', 'freego-wp') . '</th>';
        echo '<th>' . esc_html__('Status', 'freego-wp') . '</th>';
        echo '<th>' . esc_html__('Source', 'freego-wp') . '</th>';
        echo '<th>' . esc_html__('Finding', 'freego-wp') . '</th>';
        echo '<th>' . esc_html__('Actions', 'freego-wp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($issues as $issue) {
            echo '<tr>';
            $rule = $this->rules->get((string) $issue['rule_code']);
            echo '<td><code>' . esc_html((string) $issue['rule_code']) . '</code><br><small>' . esc_html((string) $issue['guideline']) . ' / ' . esc_html((string) ($rule['level'] ?? '')) . '</small></td>';
            echo '<td>' . esc_html((string) $issue['status']) . '<br><small>' . esc_html((string) $issue['severity']) . '</small></td>';
            echo '<td>' . esc_html((string) $issue['source_type']) . ' #' . esc_html((string) $issue['source_id']);
            if (!empty($issue['source_url'])) {
                echo '<br><a href="' . esc_url((string) $issue['source_url']) . '" target="_blank" rel="noreferrer">' . esc_html__('View', 'freego-wp') . '</a>';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) $issue['message']);
            if (!empty($issue['snippet'])) {
                echo '<details><summary>' . esc_html__('Snippet', 'freego-wp') . '</summary><pre style="white-space:pre-wrap;max-width:560px;">' . esc_html((string) $issue['snippet']) . '</pre></details>';
            }
            echo '</td><td>';
            $this->status_button((int) $issue['id'], Freego_WP_Issue_Store::STATUS_REVIEWED, __('Reviewed', 'freego-wp'));
            $this->status_button((int) $issue['id'], Freego_WP_Issue_Store::STATUS_FIXED, __('Fixed', 'freego-wp'));
            $this->status_button((int) $issue['id'], Freego_WP_Issue_Store::STATUS_IGNORED, __('Ignore', 'freego-wp'));
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function render_rules(): void
    {
        echo '<h2>' . esc_html__('Freego Dec 19 2025 Rule Matrix', 'freego-wp') . '</h2>';
        echo '<p>' . esc_html__('The matrix drives which findings can be repaired, which must enter semantic review, and which are report-only diagnostics.', 'freego-wp') . '</p>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Code', 'freego-wp') . '</th><th>' . esc_html__('Level', 'freego-wp') . '</th><th>' . esc_html__('Guideline', 'freego-wp') . '</th><th>' . esc_html__('Automation', 'freego-wp') . '</th><th>' . esc_html__('Description', 'freego-wp') . '</th><th>' . esc_html__('Surfaces', 'freego-wp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->rules->all() as $code => $rule) {
            echo '<tr><td><code>' . esc_html($code) . '</code></td>';
            echo '<td>' . esc_html((string) ($rule['level'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($rule['guideline'] ?? '')) . '</td>';
            echo '<td>' . esc_html($this->automation_label((string) ($rule['automation'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string) ($rule['description'] ?? ''));
            if (!empty($rule['notes'])) {
                echo '<p style="margin:4px 0 0;color:#646970;">' . esc_html((string) $rule['notes']) . '</p>';
            }
            echo '</td><td>' . esc_html(implode(', ', (array) ($rule['surface'] ?? []))) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function render_tabs(string $active): void
    {
        $tabs = [
            'dashboard' => __('Dashboard', 'freego-wp'),
            'issues' => __('Issues', 'freego-wp'),
            'rules' => __('Rule Matrix', 'freego-wp'),
        ];

        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ($tabs as $tab => $label) {
            $class = $tab === $active ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(admin_url('tools.php?page=freego-wp&tab=' . $tab)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function render_notices(): void
    {
        if (!empty($_GET['freego_notice'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash((string) $_GET['freego_notice'])) . '</p></div>';
        }
        if (!empty($_GET['freego_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash((string) $_GET['freego_error'])) . '</p></div>';
        }
    }

    private function status_button(int $id, string $status, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0;">';
        wp_nonce_field('freego_wp_issue_status');
        echo '<input type="hidden" name="action" value="freego_wp_issue_status">';
        echo '<input type="hidden" name="issue_id" value="' . esc_attr((string) $id) . '">';
        echo '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
        submit_button($label, 'small', 'submit', false);
        echo '</form>';
    }

    /**
     * @param array<string,array<string,mixed>> $rules
     * @return array<string,int>
     */
    private function counts_by_automation(array $rules): array
    {
        $counts = [
            'auto_repair' => 0,
            'repair_then_review' => 0,
            'review_required' => 0,
            'report_only' => 0,
        ];

        foreach ($rules as $rule) {
            $type = (string) ($rule['automation'] ?? 'report_only');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    private function automation_label(string $type): string
    {
        $labels = [
            'auto_repair' => __('Auto repair', 'freego-wp'),
            'repair_then_review' => __('Repair then review', 'freego-wp'),
            'review_required' => __('Human review', 'freego-wp'),
            'report_only' => __('Report only', 'freego-wp'),
        ];

        return $labels[$type] ?? $type;
    }

    private function require_admin(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'freego-wp'));
        }

        check_admin_referer($nonce_action);
    }

    /**
     * @param array<string,string> $args
     */
    private function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php?page=freego-wp')));
        exit;
    }
}
