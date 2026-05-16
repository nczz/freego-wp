# Freego WP Accessibility Assistant

Freego WP Accessibility Assistant is an open-source WordPress plugin for Freego-oriented accessibility repair, authoring guardrails, and audit workflow.

The project is aligned with the locally observed Freego Dec 19 2025 checker. It is designed for Taiwan website accessibility workflows where automated Freego checks and human semantic review both matter.

## Goal

The goal is to help WordPress sites move toward Freego/WCAG conformance through a complete workflow:

- repair known machine-detectable markup problems
- preserve review markers for semantic issues
- guide authors while editing content and media
- track unresolved accessibility work in the WordPress admin
- support A, AA, and AAA target levels
- keep the plugin updateable through GitHub Releases

This plugin does not claim that a site becomes fully compliant simply by being installed. Accessibility conformance still requires human review for meaning, intent, media alternatives, document alternatives, and interaction quality.

## Features

- Freego Dec 19 2025 v3 rule matrix with 32 extracted checker classes
- A, AA, and AAA target levels. AAA includes A and AA checks.
- Output-buffer repair for legacy themes and third-party plugin output
- Browser runtime repair for DOM inserted after page load
- Optional aggressive fake-value repair mode for machine-check-oriented fallback values
- Persistent issue workflow with `open`, `reviewed`, `ignored`, and `fixed` states
- WordPress content and attachment scans on save
- Single URL scan from the admin dashboard
- CSS heuristic auditor for Freego CSS-related rules
- Media fields for captions, transcripts, and open-format alternatives
- GitHub Releases updater for WordPress admin updates

## How It Works

The plugin maps each Freego rule to a workflow:

```text
Freego rule -> failing selector/condition -> scoped repair or marker -> review workflow
```

Automatic repair is scoped to elements that match a known failing condition. Existing valid attributes are not overwritten.

Examples:

```html
<img src="photo.jpg">
```

Conservative mode:

```html
<img src="photo.jpg" alt="" data-freego-wp-needs-alt-review="1">
```

Aggressive mode:

```html
<img src="photo.jpg" alt="image" data-freego-wp-needs-alt-review="1">
```

Aggressive mode is useful when you want fallback values for Freego-style machine checks, but review markers remain because fake values are not semantic proof.

## Admin Workflow

After activation, open:

```text
Tools -> Freego Accessibility
```

The dashboard includes:

- target level setting: A, AA, or AAA
- aggressive repair toggle
- content scan
- rendered URL scan
- issue workflow
- rule matrix

## Installation

Clone or download this repository into WordPress:

```sh
cd wp-content/plugins
git clone https://github.com/nczz/freego-wp.git
```

Then activate **Freego WP Accessibility Assistant** from the WordPress plugins screen.

## GitHub Update Mechanism

This plugin includes a lightweight GitHub Releases updater.

The update source is:

```text
https://github.com/nczz/freego-wp
```

When a new GitHub release is published with a semver tag such as `v0.2.0`, WordPress checks the latest release through the GitHub API and shows an update in the Plugins screen when the release version is newer than `FREEGO_WP_VERSION`.

Release zipballs from GitHub are supported. The updater normalizes GitHub's extracted directory name back to `freego-wp` after installation.

## Release Process

1. Update the plugin header version and `FREEGO_WP_VERSION` in `freego-wp.php`.
2. Commit and push the change.
3. Create a GitHub release with a tag like `v0.2.0`.
4. WordPress sites using the plugin will see the update through the admin plugins page.

## Freego Rule Extraction

When Freego updates, regenerate rule metadata from the local app:

```sh
tools/extract-freego-v3-rules.sh /Applications/Freego.app/Contents/app/freego.jar
```

The extractor outputs:

```text
code    level    guideline    web_id    description
```

## Current Limitations

- CSS auditing is heuristic and static. It is not yet equivalent to browser computed-style inspection.
- Full AAA still requires human semantic review.
- Freego `.cat` report import is not implemented yet.
- Deep Gutenberg sidebar integration is planned but not complete.
- Browser-driven parity testing with Playwright/Selenium is a future step.

## Development

Recommended checks:

```sh
docker run --rm -v "$PWD:/app:ro" -w /app php:8.2-cli sh -lc 'for f in $(find . -name "*.php" -print); do php -l "$f" || exit 1; done'
node --check assets/js/runtime.js
tools/extract-freego-v3-rules.sh /Applications/Freego.app/Contents/app/freego.jar
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
