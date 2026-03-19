[English](README.md) | 繁體中文

# GA4 Pageviews Sync

**用 Google Analytics 4 的真實數據取代 Post Views Counter。**

一個單檔 WordPress 外掛，每天透過 WP Cron 從 GA4 同步真實瀏覽量到 `post_meta`。零前端 JavaScript、零頁面載入 DB 寫入、零 composer 依賴。

---

## 為什麼做這個？

[Post Views Counter](https://wordpress.org/plugins/post-views-counter/) 是 WordPress 最受歡迎的瀏覽量外掛（100 萬+ 安裝量），但它有根本性的問題：

|  | Post Views Counter | **GA4 Pageviews Sync** |
|:--|:--|:--|
| **計數方式** | 每次未命中快取的頁面載入都寫一次 DB | 每天從 GA4 讀一次，頁面載入零負擔 |
| **前端 JS** | 每頁載入 `frontend.js`（3 KB） | 無 |
| **Bot 流量** | 被計入（數字偏高） | GA4 自動過濾 |
| **有快取時** | 漏算（快取頁面不觸發計數器） | 不受快取影響，永遠準確 |
| **DB 寫入** | 每次瀏覽寫 1 次 | 每次瀏覽寫 0 次 |
| **數據來源** | 自己計數（不準確） | GA4（跟你在 Analytics 看到的一樣） |
| **依賴** | jQuery（JS 計數器需要） | 零（使用 PHP 內建 `openssl_sign()`） |

### 準確性問題

Post Views Counter 面臨一個**快取 catch-22**：

- **快取開啟**（WP Rocket、Kinsta、Cloudflare 等）→ 大部分訪客拿到快取的 HTML → PHP 不執行 → 計數器不觸發 → 瀏覽量**被低估**
- **快取關閉** → 計數器正常，但網站速度變慢
- **JS 回退模式** → 每頁多一個 HTTP 請求，違背快取的意義

GA4 沒有這個問題——它透過客戶端的分析標籤計數，跟伺服器端快取完全無關。

---

## 運作原理

```
WP Cron（每天一次）
  → PHP 用 Service Account 建立 JWT
  → 換取 Google access token
  → 呼叫 GA4 Data API（screenPageViews × pagePath，全部歷史數據）
  → pagePath → 文章 slug → post ID
  → 寫入 post_meta（'ga4_pageviews'）
```

**總執行時間**：約 3-5 秒，每天一次。

---

## 安裝

### 1. 下載

下載 `ga4-pageviews-sync.php` 上傳到 `wp-content/plugins/`。

或用 WP-CLI：
```bash
wp plugin install https://github.com/Raymondhou0917/ga4-pageviews-sync/archive/refs/heads/main.zip --activate
```

### 2. 建立 Google Service Account

1. 到 [Google Cloud Console](https://console.cloud.google.com/)
2. 建立專案（或用現有的）
3. 啟用 **Google Analytics Data API**
4. 到 **IAM 與管理 → 服務帳戶** → 建立服務帳戶
5. 下載 JSON 金鑰檔案
6. 到 **Google Analytics → 管理 → 資源存取管理**，把服務帳戶 email 加為**檢視者**

### 3. 設定

在 `wp-config.php` 加入兩行：

```php
define('GA4_CREDENTIALS_PATH', '/web-root-之外的路徑/service-account.json');
define('GA4_PROPERTY_ID', '123456789');  // 你的 GA4 Property ID
```

> **安全提示**：把 JSON 檔案放在 web root **之外**（例如 `public_html` 的上一層目錄），避免被直接 HTTP 存取。

GA4 Property ID 在哪裡找：Google Analytics → 管理 → 資源設定 → 資源 ID（一串數字，如 `311674390`）。

### 4. 啟用 & 同步

啟用外掛後，到 **設定 → GA4 Pageviews** 點 **Sync Now** 執行第一次同步。

之後每天凌晨 4:00（伺服器時間）自動執行。

---

## 功能

- **可排序的後台欄位** — 文章列表新增「GA4 Views」欄位，點擊可按瀏覽量排序
- **所有公開文章類型** — 支援文章、頁面和任何自訂文章類型
- **Slug 聚合** — 同一篇文章如果在 GA4 有多條路徑（例如有無結尾斜線），瀏覽量會自動合併
- **設定頁面** — 在 **設定 → GA4 Pageviews** 查看同步狀態、上次同步時間，並手動觸發同步
- **標準 post_meta** — 數據存在 `ga4_pageviews` meta key，可透過 `get_post_meta()` 在佈景主題或其他外掛中使用

---

## 在佈景主題中使用

```php
// 取得特定文章的瀏覽量
$views = get_post_meta(get_the_ID(), 'ga4_pageviews', true);
echo number_format_i18n($views) . ' 次瀏覽';

// 查詢最熱門文章
$popular = new WP_Query([
    'meta_key' => 'ga4_pageviews',
    'orderby'  => 'meta_value_num',
    'order'    => 'DESC',
    'posts_per_page' => 10,
]);
```

---

## 系統需求

- PHP 8.0+（使用 `openssl_sign()` 簽署 JWT，不需要 composer）
- WordPress 6.0+
- 已有數據的 Google Analytics 4 資源
- 有 GA4 讀取權限的 Google Cloud Service Account

---

## 常見問題

**Q：多久同步一次？**
每天凌晨 4:00（伺服器時間）自動同步一次。也可以在設定頁面手動觸發。

**Q：抓取的日期範圍？**
全部歷史數據——從你的 GA4 資源開始收集數據的那天算起。每篇文章顯示的是上線至今的累積瀏覽量。

**Q：會拖慢網站嗎？**
不會。跟 Post Views Counter 不同，這個外掛在前端完全不做任何事。零 JS、零 CSS、零 DB 寫入。同步是背景 cron 任務。

**Q：跟 WP Rocket / 快取外掛相容嗎？**
完全相容。因為數據來自 GA4（不是來自頁面載入），快取對準確性零影響。

**Q：怎麼從 Post Views Counter 遷移？**
直接啟用這個外掛、停用 Post Views Counter 就好。兩者數據來源完全獨立——GA4 Pageviews Sync 從 GA4 讀取，不碰舊的計數器數據。你的 GA4 數據本來就在那裡。

**Q：同步失敗會怎樣？**
不會壞掉。上次成功的數據會留在 `post_meta`。到 **設定 → GA4 Pageviews** 查看錯誤訊息。

---

## 授權

GPLv2 或更高版本。見 [LICENSE](LICENSE)。

---

使用 [Claude Code](https://claude.ai/claude-code) 開發，by [Raymond Hou](https://raymondhouch.com)。
