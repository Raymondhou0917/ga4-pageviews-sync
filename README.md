[繁體中文](README.zh-TW.md) | English

# GA4 Pageviews Sync

**Replace Post Views Counter with real Google Analytics 4 data.**

A single-file WordPress plugin that syncs actual pageview counts from GA4 into `post_meta` — once a day, via WP Cron. No frontend JavaScript, no database writes on every page load, no composer dependencies.

---

## Why?

[Post Views Counter](https://wordpress.org/plugins/post-views-counter/) is the most popular pageview plugin for WordPress (1M+ installs). But it has fundamental problems:

|  | Post Views Counter | **GA4 Pageviews Sync** |
|:--|:--|:--|
| **How it counts** | Writes to DB on every uncached page load | Reads from GA4 once/day — zero page-load overhead |
| **Frontend JS** | Loads `frontend.js` on every page (3 KB) | None |
| **Bot traffic** | Counted as real views | Filtered by GA4 automatically |
| **With page cache** | Undercounts (cached pages skip the counter) | Accurate regardless of caching |
| **DB writes** | 1 write per uncached pageview | 0 writes per pageview |
| **Data source** | Self-counted (inaccurate) | GA4 (the same data you see in Analytics) |
| **Dependencies** | jQuery (for JS counter) | Zero (uses PHP's built-in `openssl_sign()`) |

### The accuracy problem, explained

Post Views Counter faces a **catch-22 with page caching**:

- **Cache ON** (WP Rocket, Kinsta, Cloudflare, etc.) → Most visitors get a cached HTML page → PHP never runs → Counter never fires → Views are **undercounted**
- **Cache OFF** → Counter fires, but you sacrifice site speed
- **JS fallback mode** → Adds another HTTP request per page, defeats the purpose of caching

GA4 doesn't have this problem — it counts via a client-side analytics tag that works independently of server-side caching.

---

## How it works

```
WP Cron (daily)
  → PHP creates JWT from Service Account credentials
  → Exchanges JWT for Google access token
  → Calls GA4 Data API (screenPageViews by pagePath, all time)
  → Maps pagePath → post slug → post ID
  → Writes to post_meta ('ga4_pageviews')
```

**Total execution time**: ~3-5 seconds, once per day.

---

## Installation

### 1. Download

Download `ga4-pageviews-sync.php` and upload it to `wp-content/plugins/`.

Or via WP-CLI:
```bash
wp plugin install https://github.com/Raymondhou0917/ga4-pageviews-sync/archive/refs/heads/main.zip --activate
```

### 2. Create a Google Service Account

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or use an existing one)
3. Enable the **Google Analytics Data API**
4. Go to **IAM & Admin → Service Accounts** → Create a service account
5. Download the JSON key file
6. In **Google Analytics → Admin → Property Access Management**, add the service account email with **Viewer** role

### 3. Configure

Add two lines to your `wp-config.php`:

```php
define('GA4_CREDENTIALS_PATH', '/path/outside/webroot/service-account.json');
define('GA4_PROPERTY_ID', '123456789');  // Your GA4 Property ID
```

> **Security tip**: Store the JSON file **outside** your web root (e.g., one directory above `public_html`). This prevents direct HTTP access to your credentials.

To find your GA4 Property ID: Google Analytics → Admin → Property Settings → Property ID (a number like `311674390`).

### 4. Activate & sync

Activate the plugin, then go to **Settings → GA4 Pageviews** and click **Sync Now** to run the first sync.

After that, it runs automatically once per day at 4:00 AM (server time).

---

## Features

- **Sortable admin column** — "GA4 Views" column in the post list, click to sort by most viewed
- **All public post types** — Works with posts, pages, and any custom post type
- **Slug aggregation** — If GA4 reports multiple paths for the same post (e.g., with/without trailing slash), views are combined
- **Settings page** — View sync status, last sync time, and trigger manual sync at **Settings → GA4 Pageviews**
- **Standard post_meta** — Data is stored as `ga4_pageviews` in `post_meta`, accessible via `get_post_meta()` for themes and other plugins

---

## Using the data in your theme

```php
// Get pageviews for a specific post
$views = get_post_meta(get_the_ID(), 'ga4_pageviews', true);
echo number_format_i18n($views) . ' views';

// Query posts ordered by views
$popular = new WP_Query([
    'meta_key' => 'ga4_pageviews',
    'orderby'  => 'meta_value_num',
    'order'    => 'DESC',
    'posts_per_page' => 10,
]);
```

---

## Requirements

- PHP 8.0+ (uses `openssl_sign()` for JWT — no composer needed)
- WordPress 6.0+
- A Google Analytics 4 property with data
- A Google Cloud Service Account with GA4 read access

---

## FAQ

**Q: How often does it sync?**
Once per day at 4:00 AM server time. You can also trigger a manual sync from the settings page.

**Q: What date range does it use?**
All time — from when your GA4 property started collecting data. This gives you cumulative lifetime pageviews for each post.

**Q: Will it slow down my site?**
No. Unlike Post Views Counter, this plugin does absolutely nothing on the frontend. No JS, no CSS, no DB writes. The sync runs as a background cron task.

**Q: Does it work with WP Rocket / caching plugins?**
Yes. Since it reads from GA4 (not from page loads), caching has zero impact on accuracy.

**Q: Can I migrate from Post Views Counter?**
Just activate this plugin and deactivate Post Views Counter. The data sources are independent — GA4 Pageviews Sync reads from GA4, not from the old counter. Your GA4 data is already there.

**Q: What happens if the sync fails?**
Nothing breaks. The last successful data stays in `post_meta`. Check **Settings → GA4 Pageviews** for error details.

---

## License

GPLv2 or later. See [LICENSE](LICENSE).

---

Built with [Claude Code](https://claude.ai/claude-code) by [Raymond Hou](https://raymondhouch.com).
