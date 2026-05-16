# Changelog

## 0.1.11

- Improve icon-only social/share link naming in output-buffer and runtime repairs.
- Remove invalid empty descendant `lang` attributes instead of copying the root language.
- Localize share/save fallback label templates for frontend runtime repairs.

## 0.1.10

- Add Freego v3 bytecode predicate matrix documentation.
- Add output repair fixture coverage for CSS unit repair, select labeling, icon controls, and language repair.
- Keep fixture tests out of the installable release package.

## 0.1.9

- Expand CSS accessibility unit repair to `max-width` and `line-height`.
- Mark CS3140801C and CS3140802C as output-buffer repair then review targets.
- Add contextual form control `title` inference for HM3330500C.

## 0.1.8

- Improve CSS repair handling for same-origin local stylesheets.
- Expand same-origin `@import` rules before inlining repaired CSS.
- Convert additional absolute `font-size` units to rem.
- Keep the output repair implementation compatible with PHP 7.4.

## 0.1.7

- Expand Freego output-buffer repair coverage for additional checker predicates.
- Add scoped CSS font-size px-to-rem repair for allowlisted same-origin stylesheets and inline styles.
- Add repair coverage for image maps, buttons, fieldsets, document headings, language sections, empty navigation landmarks, and duplicate linked image alt text.
- Document the output-buffer repair map under `docs/freego-ob-repair-map.md`.

## 0.1.6

- Add same-origin iframe content repair from the frontend runtime.
- Observe iframe load events so nested same-origin documents loaded after initial render are scanned and repaired.
- Mark cross-origin frames as externally bounded because browser security prevents parent-page DOM repair inside them.

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
