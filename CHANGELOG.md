# Changelog

## 0.1.5

- Strengthen frontend runtime repair for browser-rendered DOM changes.
- Include dynamically inserted elements themselves when scanning MutationObserver additions.
- Re-evaluate affected elements when accessibility-relevant attributes or text content change after initial repair.

## 0.1.4

- Add bundled Traditional Chinese translation files: `freego-wp-zh_TW.po` and `freego-wp-zh_TW.mo`.
- Ensure WordPress sites using `zh_TW` can load localized plugin UI without waiting for a community language pack.

## 0.1.3

- Add WordPress i18n metadata and plugin textdomain loading.
- Add `languages/freego-wp.pot` for community translation workflows.
- Add translator comments for placeholder-based strings.
- Localize remaining updater UI fallback strings.

## 0.1.2

- Add optional uninstall cleanup controlled by an admin setting.
- Keep deactivation non-destructive.
- Cleanup can remove issue tables, plugin options, cached release metadata, and Freego attachment metadata.

## 0.1.1

- Add a packaged release zip workflow for direct WordPress installation.
- Prefer the `freego-wp.zip` GitHub release asset in the WordPress updater.
- Keep GitHub source zipball as a fallback only.

## 0.1.0

- Initial public release.
- Freego Dec 19 2025 v3 rule matrix with A, AA, and AAA levels.
- Output-buffer and runtime repair layers.
- Aggressive fake-value repair mode.
- Persistent issue workflow for semantic review.
- WordPress content, attachment, URL, and CSS heuristic scans.
- GitHub Releases updater for WordPress admin updates.
