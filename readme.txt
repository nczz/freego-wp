=== Freego WP Accessibility Assistant ===
Contributors: nczz
Tags: accessibility, wcag, freego, taiwan, audit
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Freego-oriented accessibility repair, authoring guardrails, and audit workflow for WordPress.

== Description ==

Freego WP Accessibility Assistant helps WordPress sites move toward Freego/WCAG conformance through scoped repair, issue tracking, and human semantic review workflow.

It includes a Freego Dec 19 2025 v3 rule matrix, A/AA/AAA target levels, output-buffer repair, runtime DOM repair, issue workflow, media guardrails, CSS heuristics, and a GitHub Releases updater.

This plugin does not claim one-click compliance. Human review is still required for semantic quality, link purpose, media alternatives, document alternatives, and complex interactions.

== Installation ==

1. Upload or clone the plugin into `wp-content/plugins/freego-wp`.
2. Activate it from the WordPress plugins screen.
3. Open Tools -> Freego Accessibility.
4. Choose the target level and repair mode.
5. Run content or URL scans and review open issues.

== Frequently Asked Questions ==

= Does this guarantee AAA compliance? =

No. It supports AAA-oriented workflow and machine-check repair, but AAA conformance still requires human semantic review.

= What is aggressive fake-value repair? =

It is an optional mode that fills missing required values such as `alt="image"` or `title="frame"` for elements that match known failing conditions. Review markers remain so teams can replace fake values with meaningful content.

= How do updates work? =

The plugin checks GitHub Releases at https://github.com/nczz/freego-wp and shows updates in the WordPress admin when a newer release tag is published.

== Changelog ==

= 0.1.0 =

Initial public release.
