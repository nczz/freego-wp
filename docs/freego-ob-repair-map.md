# Freego OB Repair Map

This document maps Freego Dec 19 2025 checker behavior to the WordPress output-buffer repairs implemented by this plugin.

The checker behavior was inspected from `/Applications/Freego.app/Contents/app/freego.jar` with `javap -p -c -constants`. The original extractor in `tools/extract-freego-v3-rules.sh` only captures checker metadata; it does not capture predicate logic. This file is the working layer for predicate-to-repair alignment.

## Current OB Coverage

| Code | Freego checker predicate observed from bytecode | OB repair |
|---|---|---|
| HM1110100C | `img` must have `alt`; non-hidden images with missing or invalid text are reported. | Add missing `alt`; infer linked image alt from link title/text/href or article heading, otherwise fallback only in aggressive mode. |
| HM1110101C | `map area` needs non-empty `alt`; empty `alt` can pass only when ARIA naming is present. | Add `area alt` from `title`, href-derived label, or review fallback; mark for alt review. |
| HM1110104C | `input[type=image]` needs non-empty `alt` or accessible name. | Add fallback `alt` in aggressive mode and mark review. |
| HM1110105C | `applet` needs non-empty `alt`; `object` needs accessible name or fallback content beyond `param`; hidden/template/slot elements are skipped. | Add applet `alt` and object/embed title in aggressive mode; otherwise mark embed review. Existing fallback content is preserved. |
| HM1110106C | `img alt=""` must not also have `title`. | Move title to alt in aggressive mode; otherwise remove title and mark review. |
| HM1130100C | Heading structure and empty heading conditions are checked. | Remove empty headings; inject visually hidden `h1` from document title when no heading exists; still mark hierarchy jumps for review. |
| HM1130103C | `fieldset` groups need `legend`; select grouping is handled separately by `HM1130103C_1`. | Add visually hidden `legend` for fieldsets without one, with special label for CF7 hidden fields. |
| HM1130103C_1 | Long `select` option lists may need `optgroup`. | Wrap long ungrouped select options in an `optgroup` in aggressive mode; mark review. |
| HM1130104C | Visible form controls need label, title, or accessible name. | Add visually hidden labels plus `title`/`aria-label` from placeholder, first select option, name, or fallback. |
| HM1240102C | Visible `nav` elements fail when they have no child elements. | Remove empty visible `nav` elements because they do not expose any navigable content. |
| HM1240200C | The document head must contain a non-empty direct `title` element. | Create or fill head `title` from `wp_get_document_title()` or site name. |
| HM1240400C | For `a[href]` that has both text and `img`, image `alt` must not duplicate the same link text. | When a linked image alt exactly equals the link text, clear the image `alt` and mark alt review. |
| HM1240401C | `a[href]` must have link text; hidden/template/slot elements are skipped; image links require non-empty `img alt`; SVG role img needs accessible name. | Mirror visible link text to `title`; copy `title` to `aria-label` for icon-only links; infer generic share/save labels from URL purpose; aggressive fallback from href; linked images get inferred alt. |
| HM1310100C | Root `html` needs non-empty `lang`. | Fill root `html lang` from WordPress locale. |
| HM1410200C | Form/link controls must follow role/name requirements; `input[type=button]` needs non-empty `value`; `button` needs text or ARIA name; `a`/`area` with href are checked. | Add value for empty `input[type=button]`; add `aria-label` to icon-only buttons using class/context/fallback; link repair covers icon-only anchors. |
| HM1410201C | `iframe`/`frame` need non-empty `title`. | Add fallback iframe title in aggressive mode and mark review. |
| HM2310200C | Body descendants with `lang` are checked; empty `lang` fails; same value as root `html lang` fails; template/slot/hidden elements are skipped. | Remove empty descendant `lang`; remove descendant `lang` when it normalizes to the root language. |
| CS2140401C | `font-size` declarations using absolute units fail; Freego also scans external stylesheets. | Convert absolute `font-size` units (`px`, `pt`, `pc`, `in`, `cm`, `mm`) to `rem` in inline styles and same-origin local external CSS by replacing stylesheet/preload links with repaired inline style blocks and expanding same-origin `@import`. |
| CS3140801C | `max-width` in external CSS should use a relative unit. | Convert absolute `max-width` units (`px`, `pt`, `pc`, `in`, `cm`, `mm`) to `rem` in inline styles and same-origin local external CSS. |
| CS3140802C | `line-height` in external CSS should be unitless or use a relative unit. | Convert absolute `line-height` units (`px`, `pt`, `pc`, `in`, `cm`, `mm`) to `rem` in inline styles and same-origin local external CSS. |
| HM3330500C | Form controls can need contextual `title` help. | Fill missing form control `title` from the same label inference used for `HM1130104C`. |

## Intentional Boundaries

- Static CSS repair is allowlist based. By default it touches same-origin local CSS files under the WordPress root; the allowlist can be narrowed with `freego_wp_inline_css_repair_allowed_paths`.
- CSS repair only changes the targeted `font-size`, `max-width`, and `line-height` declarations needed by the mapped Freego rules.
- Cross-origin stylesheets and imports are not fetched or inlined.
- Semantic rules that require author intent still leave review markers.
- Runtime JavaScript covers post-load link/button naming, but this file focuses on the server-side OB layer because Freego commonly evaluates initial rendered HTML and linked CSS.

## Review Or Report Only

| Code | Boundary |
|---|---|
| CS2140400C | Reports CSS-dependent presentation. OB cannot remove the site's CSS without changing layout and meaning. |
| CS2140402C | Related text sizing review remains scanner/report oriented; `CS2140401C` px-to-rem repair covers the current AA px unit failure path. |
| HM1110102C | `longdesc` URI plus backlink validation needs crawling and author-maintained long-description content. |
| HM1110103C | Character art and emoji alternatives require author intent and language understanding. |
| HM1130101C | Simple `th scope` inference is available in aggressive mode, but complex tables need author review. |
| HM1130102C | Simple `td headers` inference is available in aggressive mode, but complex tables need author review. |
| HM1130200C | Bidirectional text direction requires language/content intent. |
| HM1410100C | W3C validation is a document-wide validator concern; OB should avoid creating invalid markup. |
| ME1320200C | Office-document links require real open-format alternatives such as PDF/HTML/ODF, not a synthetic OB value. |
| HM3240900C | AAA link title repair mirrors visible link text; empty/icon-only links still follow the A-level link-name rules. |
| HM3241000C | AAA heading organization can be assisted by injecting a missing first heading, but content hierarchy remains review. |

## Reverse Engineering Status

The Freego app currently has 32 checker classes under `checker/v3/`. All 32 are classified here as either current OB coverage or review/report-only boundaries. The high-impact production predicates currently implemented in OB are: `HM1110100C`, `HM1110101C`, `HM1110104C`, `HM1110105C`, `HM1110106C`, `HM1130100C`, `HM1130103C`, `HM1130103C_1`, `HM1130104C`, `HM1240102C`, `HM1240200C`, `HM1240400C`, `HM1240401C`, `HM1310100C`, `HM1410200C`, `HM1410201C`, `HM2310200C`, `CS2140401C`, `CS3140801C`, `CS3140802C`, and `HM3330500C`.

When Freego ships a new checker version, process updates the same way before claiming parity with that version:

1. Run `javap -classpath /Applications/Freego.app/Contents/app/freego.jar -p -c -constants checker.v3.<CODE>`.
2. Extract selectors, skip conditions, required attributes, and error branches.
3. Add the predicate summary to this map.
4. Implement OB repair only when the fix is deterministic and reversible.
5. Keep semantic or destructive fixes as review markers.
