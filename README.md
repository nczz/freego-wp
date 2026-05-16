# Freego WP Accessibility Assistant

Freego WP Accessibility Assistant 是一套開源 WordPress 外掛，目標是協助網站以 Freego 檢測與人工語意稽核為核心，建立完整的無障礙修補、提示、追蹤與更新流程。

> English summary: An open-source WordPress plugin for Freego-oriented accessibility repair, authoring guardrails, issue workflow, A/AA/AAA targets, and GitHub Releases updates.

本專案目前對齊本機觀察到的 **Freego Dec 19 2025** 檢測器，特別針對台灣網站無障礙流程中「Freego 機器檢測」與「人工語意判斷」需要並行的情境設計。

## 目標

這個外掛不是宣稱「安裝後一鍵完全合規」，而是提供一個可落地的合規輔助系統：

- 修補可由程式判斷的 HTML/DOM 問題
- 對需要語意判斷的項目留下 review marker
- 在內容編輯與媒體管理階段提示作者
- 在 WordPress 後台追蹤尚未完成的無障礙工作
- 支援 A、AA、AAA 目標等級
- 透過 GitHub Releases 讓外掛可直接在後台更新

完整無障礙合規仍需要人工確認圖片替代文字、連結目的、媒體替代資訊、文件替代格式、互動操作與內容語意。

## 功能

- Freego Dec 19 2025 v3 規則矩陣，已抽出 32 個 checker class
- 支援 A、AA、AAA 目標等級，AAA 會包含 A 與 AA
- OB output-buffer 修補既有主題與第三方外掛輸出
- 前端 runtime 修補 JavaScript 後載入的 DOM
- MutationObserver 二次修補：動態插入元素與無障礙相關屬性/文字變更會重新評估
- 可選的 aggressive fake-value repair，用於機器檢測導向的 fallback 值補強
- Issue workflow：`open`、`reviewed`、`ignored`、`fixed`
- 文章與附件儲存時自動掃描
- 後台單一 URL 掃描
- Freego CSS 相關規則的 heuristic auditor
- 媒體欄位：字幕、逐字稿、開放格式替代檔
- GitHub Releases updater，可在 WordPress 後台看到更新
- 刪除外掛時可選擇清除外掛資料；停用外掛不會刪資料
- WordPress i18n ready：固定 `freego-wp` text domain、`/languages` domain path、POT 翻譯模板、內建繁中語系檔

## 方法

核心公式是：

```text
Freego rule -> failing selector/condition -> scoped repair or marker -> review workflow
```

也就是只針對「符合失敗條件」的元素做修補或標記，不會無差別覆寫所有同類元素，也不會覆蓋已經存在且有效的屬性。

修補分成兩層：

- PHP OB：處理伺服器輸出的初始 HTML。
- 前端 runtime：處理瀏覽器渲染後由 JavaScript 新增、替換或改寫的 DOM。

前端 runtime 使用 `MutationObserver` 監看 `childList`、文字變更，以及 `alt`、`title`、`aria-label`、`aria-labelledby`、`href`、`src`、`role`、`scope` 等無障礙相關屬性。每次只重新評估受影響的元素或子樹，不做全頁無差別覆寫。

巢狀 DOM 會依子樹範圍處理。iframe 則分為兩種：

- 同源 iframe：外層 runtime 可進入 `contentDocument`，對 iframe 內部文件套用同一套掃描、修補與 MutationObserver。
- 跨來源 iframe：瀏覽器安全模型禁止父頁讀寫內部 DOM，外掛只能修補外層 `<iframe>` 的 `title` 等屬性，並標記 `data-freego-wp-cross-origin-frame="1"` 作為人工或來源端處理邊界。

例如：

```html
<img src="photo.jpg">
```

保守模式：

```html
<img src="photo.jpg" alt="" data-freego-wp-needs-alt-review="1">
```

Aggressive 模式：

```html
<img src="photo.jpg" alt="image" data-freego-wp-needs-alt-review="1">
```

Aggressive 模式適合希望先補足 Freego 形式檢測 fallback 值的情境，但外掛仍會留下 review marker，因為假值不是語意合格的證明。

## 後台流程

啟用後進入：

```text
工具 -> Freego Accessibility
```

後台提供：

- 目標等級：A、AA、AAA
- Aggressive fake-value repair 開關
- WordPress 內容掃描
- Rendered URL 掃描
- Issue workflow
- Freego rule matrix

## 安裝

把 repo clone 到 WordPress 外掛目錄：

```sh
cd wp-content/plugins
git clone https://github.com/nczz/freego-wp.git
```

然後在 WordPress 後台啟用 **Freego WP Accessibility Assistant**。

## GitHub 更新機制

此外掛內建輕量 GitHub Releases updater。

更新來源：

```text
https://github.com/nczz/freego-wp
```

當 GitHub release 發布新的 semver tag，例如 `v0.2.0`，WordPress 會透過 GitHub API 檢查 latest release。若 release 版本大於外掛內的 `FREEGO_WP_VERSION`，後台 Plugins 頁面就會顯示可更新。

正式 release 會附上 `freego-wp.zip` asset，這是給 WordPress 直接安裝與更新使用的 package。GitHub 自動產生的 Source code zip 只作為 fallback。

## i18n 與翻譯

外掛以 `freego-wp` 作為 text domain，並宣告 `Domain Path: /languages`。翻譯模板位於：

```text
languages/freego-wp.pot
```

目前 release package 內建繁體中文語系檔：

```text
languages/freego-wp-zh_TW.po
languages/freego-wp-zh_TW.mo
```

更新翻譯模板：

```bash
docker run --rm -v "$PWD":/app -w /app wordpress:cli wp i18n make-pot . languages/freego-wp.pot --domain=freego-wp --slug=freego-wp --exclude=dist,tools,.git --allow-root
```

English: The plugin is WordPress i18n ready. UI strings use the `freego-wp` text domain, translation files are loaded from `/languages`, and the POT template is included for community translation workflows.
Traditional Chinese is bundled as `freego-wp-zh_TW.mo` so `zh_TW` WordPress sites can show localized plugin UI immediately.

## 停用與刪除

停用外掛是非破壞性的：

- 停止前台 OB 修補
- 停止前端 runtime 修補
- 停止掃描與後台功能
- 不刪除 issue table、設定或附件 metadata

如果要在「刪除外掛」時一併清理資料，可以在後台 `Tools -> Freego Accessibility` 勾選：

```text
Delete plugin data when uninstalling
```

啟用此選項後，刪除外掛會清除：

- `wp_freego_wp_issues` 資料表
- 外掛 options
- GitHub release cache
- `_freego_wp_captions_url`
- `_freego_wp_transcript`
- `_freego_wp_open_format_url`

## 發版流程

1. 修改 `freego-wp.php` 內 plugin header 的 `Version` 與 `FREEGO_WP_VERSION`
2. commit 並 push
3. 建立安裝包：

```sh
scripts/build-release.sh
```

4. 建立 GitHub release 並上傳 `dist/freego-wp.zip`，例如：

```sh
gh release create v0.2.0 dist/freego-wp.zip --title "Freego WP Accessibility Assistant 0.2.0" --notes-file CHANGELOG.md
```

5. 已安裝此外掛的 WordPress 站台會在後台看到更新

## Freego 規則抽取

Freego 更新時，可以用本專案工具重新抽取規則：

```sh
tools/extract-freego-v3-rules.sh /Applications/Freego.app/Contents/app/freego.jar
```

輸出欄位：

```text
code    level    guideline    web_id    description
```

## 目前限制

- CSS auditor 目前是靜態 heuristic，尚未等同於瀏覽器 computed style 檢查
- 完整 AAA 仍需要人工語意稽核
- 尚未支援匯入 Freego `.cat` 檢測報告
- Gutenberg sidebar 深度整合尚未完成
- 尚未加入 Playwright/Selenium 的 rendered DOM parity 測試

## 開發

建議檢查：

```sh
docker run --rm -v "$PWD:/app:ro" -w /app php:8.2-cli sh -lc 'for f in $(find . -name "*.php" -print); do php -l "$f" || exit 1; done'
node --check assets/js/runtime.js
tools/extract-freego-v3-rules.sh /Applications/Freego.app/Contents/app/freego.jar
scripts/build-release.sh
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## English Summary

Freego WP Accessibility Assistant helps WordPress sites move toward Freego/WCAG conformance through scoped DOM repair, authoring guardrails, persistent issue tracking, A/AA/AAA targets, CSS heuristics, and GitHub Releases updates. It does not replace human semantic review.
