<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Guardrails
{
    private Freego_WP_Scanner $scanner;
    private Freego_WP_Issue_Store $store;

    public function __construct(Freego_WP_Scanner $scanner, Freego_WP_Issue_Store $store)
    {
        $this->scanner = $scanner;
        $this->store = $store;
    }

    public function boot(): void
    {
        add_action('save_post', [$this, 'scan_post_on_save'], 20, 2);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'save_attachment_fields'], 10, 2);
        add_action('edit_attachment', [$this, 'scan_attachment_on_save']);
        add_action('add_attachment', [$this, 'scan_attachment_on_save']);
    }

    public function scan_post_on_save(int $post_id, WP_Post $post): void
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!in_array($post->post_type, get_post_types(['public' => true], 'names'), true)) {
            return;
        }

        $this->scanner->scan_post($post_id);
    }

    public function scan_attachment_on_save(int $attachment_id): void
    {
        $this->scanner->scan_attachment($attachment_id);
    }

    public function add_meta_boxes(): void
    {
        foreach (get_post_types(['public' => true], 'names') as $post_type) {
            add_meta_box(
                'freego-wp-issues',
                __('Freego Accessibility', 'freego-wp'),
                [$this, 'render_post_box'],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_post_box(WP_Post $post): void
    {
        $issues = $this->store->query([
            'source_type' => $post->post_type,
            'source_id' => (int) $post->ID,
            'status' => Freego_WP_Issue_Store::STATUS_OPEN,
        ]);

        if (!$issues) {
            echo '<p>' . esc_html__('No open Freego issues for this content.', 'freego-wp') . '</p>';
            return;
        }

        echo '<ul style="margin-left:16px;list-style:disc;">';
        foreach ($issues as $issue) {
            echo '<li><code>' . esc_html((string) $issue['rule_code']) . '</code> ' . esc_html((string) $issue['message']) . '</li>';
        }
        echo '</ul>';
        echo '<p><a href="' . esc_url(admin_url('tools.php?page=freego-wp&source_type=' . rawurlencode($post->post_type) . '&source_id=' . (int) $post->ID)) . '">' . esc_html__('Open issue workflow', 'freego-wp') . '</a></p>';
    }

    /**
     * @param array<string,mixed> $form_fields
     * @return array<string,mixed>
     */
    public function attachment_fields(array $form_fields, WP_Post $post): array
    {
        $mime = (string) get_post_mime_type($post);

        if (strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0) {
            $form_fields['freego_wp_captions_url'] = [
                'label' => __('Captions URL', 'freego-wp'),
                'input' => 'text',
                'value' => (string) get_post_meta($post->ID, '_freego_wp_captions_url', true),
                'helps' => __('Used for Freego media caption/transcript review.', 'freego-wp'),
            ];
            $form_fields['freego_wp_transcript'] = [
                'label' => __('Transcript', 'freego-wp'),
                'input' => 'textarea',
                'value' => (string) get_post_meta($post->ID, '_freego_wp_transcript', true),
                'helps' => __('Provide equivalent text content when captions are not enough.', 'freego-wp'),
            ];
        }

        if (preg_match('/(msword|officedocument|powerpoint|excel)/i', $mime)) {
            $form_fields['freego_wp_open_format_url'] = [
                'label' => __('Open-format alternative', 'freego-wp'),
                'input' => 'text',
                'value' => (string) get_post_meta($post->ID, '_freego_wp_open_format_url', true),
                'helps' => __('ODF, PDF, or HTML alternative URL for Freego review.', 'freego-wp'),
            ];
        }

        return $form_fields;
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $attachment
     * @return array<string,mixed>
     */
    public function save_attachment_fields(array $post, array $attachment): array
    {
        $id = (int) ($post['ID'] ?? 0);
        foreach (['_freego_wp_captions_url' => 'freego_wp_captions_url', '_freego_wp_transcript' => 'freego_wp_transcript', '_freego_wp_open_format_url' => 'freego_wp_open_format_url'] as $meta_key => $field) {
            if (isset($attachment[$field])) {
                update_post_meta($id, $meta_key, sanitize_textarea_field((string) $attachment[$field]));
            }
        }

        return $post;
    }
}
