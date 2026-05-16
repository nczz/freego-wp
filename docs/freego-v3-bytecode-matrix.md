# Freego v3 Bytecode Predicate Matrix

Source: `/Applications/Freego.app/Contents/app/freego.jar`, inspected with `javap -p -c -constants checker.v3.<CODE>`.

This matrix is the guardrail for output-buffer repairs. A rule is marked `repair` only when the Freego predicate can be satisfied without inventing author intent. Rules that need real content, crawling, validation, or semantic alternatives stay `review` or `report`.

Common skip logic seen across DOM rules: descendants of `template` or `slot` are skipped; `aria-hidden="true"` is skipped; inline `display:none`, `display: none`, `visibility:hidden`, or `visibility: hidden` is skipped.

| Code | Freego predicate from bytecode | Preventive handling in plugin |
|---|---|---|
| CS2140400C | Scans external and inline CSS usage for CSS-dependent presentation. | `report`: do not remove CSS; scanner records CSS source. |
| CS2140401C | `font-size` must use named, relative, percentage, viewport, math, or CSS variable values; absolute units fail in style attributes, style blocks, and linked CSS. | `repair`: same-origin local CSS is inlined; `font-size` absolute units are converted to `rem`. |
| CS2140402C | Metadata-only/self-report checker for em-relative text sizing. | `report`: covered by CSS audit notes; no distinct OB predicate was present in bytecode. |
| CS3140801C | `max-width` must use relative/percentage/keyword/math values; absolute units in inline or linked CSS fail. | `repair`: same-origin local CSS is inlined; absolute `max-width` units are converted to `rem`. |
| CS3140802C | `line-height` must be unitless or relative/math/variable; absolute units in inline or linked CSS fail. | `repair`: same-origin local CSS is inlined; absolute `line-height` units are converted to `rem`. |
| HM1110100C | `img` needs `alt`; role image widgets need accessible names; hidden/template/slot skipped. | `repair_then_review`: add/infer `alt`; mark uncertain image names for review. |
| HM1110101C | `map area` needs non-empty `alt`, unless accessible naming is present. | `repair_then_review`: fill `area alt` from title/href/fallback and mark review. |
| HM1110102C | `img[longdesc]` URI must be valid and longdesc target must link back by image labels/descriptions. | `review`: requires crawling and author-maintained long description content. |
| HM1110103C | Character art/emoji-like language forms need meaningful `title`. | `review`: requires content intent and language interpretation. |
| HM1110104C | `input[type=image]` needs non-empty `alt` or accessible name. | `repair_then_review`: aggressive fallback `alt`; mark review. |
| HM1110105C | `applet` needs non-empty `alt`; `object` needs title/ARIA/fallback content beyond `param`. | `repair_then_review`: aggressive fallback for applet/object/embed; preserve real fallback content. |
| HM1110106C | `img alt=""` must not also have `title`. | `repair_then_review`: remove title or move title to alt in aggressive mode. |
| HM1130100C | Heading structure: headings must exist when needed, cannot be empty, role heading needs aria-level, hierarchy/h1 conditions are checked. | `repair_then_review`: remove empty headings, inject missing hidden h1, mark hierarchy review. |
| HM1130101C | Tables with header cells need correct `scope` relationships; complex colspan/rowspan/id cases are examined. | `review/aggressive`: simple `th scope` inference is possible; complex tables remain review. |
| HM1130102C | Tables need `id`/`headers` relationships for data/header associations when scope is not sufficient. | `review/aggressive`: simple headers inference is possible; complex tables remain review. |
| HM1130103C | `fieldset` requires a `legend`. | `repair_then_review`: add hidden legend from form context/fallback. |
| HM1130103C_1 | `select` option groups: options should be grouped by `optgroup` with non-empty labels. | `repair_then_review`: aggressive mode wraps long ungrouped selects and marks review. |
| HM1130104C | Visible `input`, `select`, `textarea` need associated label, title, ARIA name, or valid image-alt label. | `repair_then_review`: add hidden label plus `title`/`aria-label`; select label can come from first option prompt. |
| HM1130200C | Mixed direction content checks `[dir]`, `dir=rtl`, and language direction hints. | `review`: language/direction intent cannot be synthesized safely. |
| HM1240102C | Visible `nav` elements fail when empty. | `repair`: remove empty visible nav elements. |
| HM1240200C | Direct `head > title` must exist and be non-empty; title under svg/iframe is ignored. | `repair`: create/fill head title from WordPress document title/site name. |
| HM1240400C | In a text link containing images, image `alt` must not duplicate the same link text. | `repair_then_review`: clear linked image alt when it exactly equals visible link text. |
| HM1240401C | `a[href]` needs link text, `aria-label`, non-empty linked image alt, or named svg role image. | `repair_then_review`: add aria-label from title, generic share/save URL purpose, class tokens, or href fallback; infer linked image alt; mark uncertain links. |
| HM1310100C | Root `html` needs non-empty `lang`. | `repair`: fill from WordPress locale. |
| HM1410100C | Flags invalid/deprecated elements and W3C conformance concerns. | `report`: requires validator-level proof; OB avoids producing invalid markup. |
| HM1410200C | Controls and links need valid roles/names; button text/ARIA/image alt, input button value, links/areas href/name cases are checked. | `repair_then_review`: name icon buttons, fill input button values, repair links/areas where deterministic. |
| HM1410201C | `frame` and `iframe` need non-empty `title`. | `repair_then_review`: aggressive fallback title; mark review. |
| HM2310200C | Root/body language handling; descendant `lang=""` fails and descendant same as root lang fails. | `repair`: remove empty descendant lang and redundant same-as-root lang so same-language content is not falsely declared as a different-language section. |
| HM3240900C | AAA link purpose: `a[href]` needs link text and non-empty `title`; image links need title/alt handling. | `repair_then_review`: mirror visible link text to title; icon-only follows A-level link-name repair. |
| HM3241000C | AAA headings are expected to organize sections. | `repair_then_review`: missing heading gets hidden h1; hierarchy remains review. |
| HM3330500C | AAA contextual help via `title`; bytecode class has no extra selector logic beyond metadata, but reports overlap form controls. | `repair_then_review`: fill missing form-control title from label inference. |
| ME1320200C | Office-format download links require open-format alternatives: odt/ods/odp/odb/odg/pdf/html. | `review`: cannot fabricate real alternative files in OB. |

## Fixture Coverage

`tests/run-output-repair-fixtures.php` currently covers the recurring production regressions:

- same-origin external CSS link inlining
- `font-size` px/pt conversion
- `max-width` px conversion
- `line-height` px conversion
- `select` label/title/aria-label repair from first option
- icon-only link naming from title
- icon-only button naming from class
- descendant empty `lang` repair
