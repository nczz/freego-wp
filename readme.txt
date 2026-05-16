=== Freego WP Accessibility Assistant ===
Contributors: nczz
Tags: accessibility, wcag, freego, taiwan, audit
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

以 Freego 檢測為核心的 WordPress 無障礙修補、提示與稽核流程外掛。

== Description ==

Freego WP Accessibility Assistant 協助 WordPress 網站朝 Freego/WCAG 合規前進。它不是「一鍵宣稱合規」工具，而是把機器可判斷的修補、人工語意稽核、內容提示與 issue 追蹤整合在 WordPress 後台。

主要功能包含：

* Freego Dec 19 2025 v3 規則矩陣
* A、AA、AAA 目標等級
* output-buffer HTML 修補
* 前端 runtime DOM 修補
* 可選的 aggressive fake-value repair
* issue workflow
* 媒體 guardrails
* CSS heuristic auditor
* GitHub Releases updater

此外掛仍需要人工確認圖片替代文字、連結目的、媒體替代資訊、文件替代格式與複雜互動品質。

English: A Freego-oriented WordPress accessibility assistant for scoped repair, authoring guardrails, issue workflow, A/AA/AAA targets, and GitHub-based updates.

== Installation ==

1. 將外掛上傳或 clone 到 `wp-content/plugins/freego-wp`。
2. 到 WordPress 後台啟用外掛。
3. 開啟 Tools -> Freego Accessibility。
4. 選擇目標等級與修補模式。
5. 執行內容或 URL 掃描，並處理 open issues。

== Frequently Asked Questions ==

= 這能保證 AAA 合規嗎？ =

不能。外掛支援 AAA 導向流程與機器檢測修補，但 AAA 合規仍需要人工語意稽核。

= Aggressive fake-value repair 是什麼？ =

這是可選模式。開啟後，外掛會針對符合失敗條件的元素補上 fallback 值，例如 `alt="image"` 或 `title="frame"`。外掛仍會留下 review marker，讓團隊後續替換成真正有意義的內容。

= 如何更新？ =

外掛會檢查 GitHub Releases：https://github.com/nczz/freego-wp 。當有較新的 release tag 時，WordPress 後台會顯示可更新。

== Changelog ==

= 0.1.12 =

調整前端 runtime 圖片修補順序，缺少 `alt` 的圖片會先補屬性，再進入 hidden-state 後續語意檢查。

= 0.1.11 =

改善 icon-only social/share link 命名，空白 descendant `lang` 改為移除，並補前端 runtime share/save fallback label 的本地化。

= 0.1.10 =

新增 Freego v3 bytecode predicate matrix 文件與 output repair fixture 測試覆蓋，並排除 fixture tests 於正式安裝包之外。

= 0.1.9 =

擴充 CSS accessibility unit 修補到 `max-width` 與 `line-height`，並新增 HM3330500C 表單控制項 title 推論。

= 0.1.8 =

改善同源 local CSS 修補演算法，支援展開同源 `@import`、轉換更多 `font-size` 絕對單位為 rem，並維持 PHP 7.4 相容。

= 0.1.7 =

擴充 Freego OB 修補覆蓋，新增 allowlist CSS font-size px-to-rem 修補、影像地圖、按鈕、fieldset、標題、語言區段、空 nav 與連結圖片重複替代文字處理，並補上 OB repair map 文件。

= 0.1.6 =

新增前端 runtime 對同源 iframe 內容文件的巢狀掃描與修補；跨來源 iframe 會標記邊界，但受瀏覽器安全限制無法由父頁直接修補其內部 DOM。

= 0.1.5 =

強化前端 runtime 對瀏覽器渲染後 DOM 變動的二次修補，包含動態插入元素本身與無障礙相關屬性/文字變更後的重新評估。

= 0.1.4 =

新增內建繁體中文語系檔 `freego-wp-zh_TW.po` 與 `freego-wp-zh_TW.mo`，讓繁中後台可直接顯示中文介面。

= 0.1.3 =

補齊 WordPress i18n metadata、textdomain 載入、POT 翻譯模板與 placeholder translators comments。

= 0.1.2 =

新增刪除外掛時可選的資料自清理程序。停用外掛不會刪除資料。

= 0.1.1 =

新增正式 release package 與 GitHub updater asset 優先下載機制。

= 0.1.0 =

Initial public release.
