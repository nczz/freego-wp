<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Rules
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $rules = require FREEGO_WP_DIR . 'data/freego-v3-rules.php';

        return is_array($rules) ? $rules : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function by_automation(string $automation): array
    {
        return array_filter($this->all(), static function (array $rule) use ($automation): bool {
            return ($rule['automation'] ?? '') === $automation;
        });
    }

    public function get(string $code): ?array
    {
        $rules = $this->all();

        return $rules[$code] ?? null;
    }

    /**
     * @return string[]
     */
    public function target_levels(string $target): array
    {
        $target = strtoupper($target);
        if ($target === 'A') {
            return ['A'];
        }
        if ($target === 'AA') {
            return ['A', 'AA'];
        }
        if ($target === 'AAA') {
            return ['A', 'AA', 'AAA'];
        }

        return ['A', 'AA', 'AAA'];
    }

    public function code_in_target(string $code, string $target): bool
    {
        $rule = $this->get($code);
        if (!$rule) {
            return true;
        }

        return in_array((string) ($rule['level'] ?? 'A'), $this->target_levels($target), true);
    }

    /**
     * @return array<string,int>
     */
    public function counts_by_level(): array
    {
        $counts = ['A' => 0, 'AA' => 0, 'AAA' => 0];
        foreach ($this->all() as $rule) {
            $level = (string) ($rule['level'] ?? 'A');
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        return $counts;
    }
}
