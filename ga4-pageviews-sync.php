<?php
/**
 * Plugin Name: GA4 Pageviews Sync
 * Plugin URI:  https://github.com/Raymondhou0917/ga4-pageviews-sync
 * Description: Sync real pageview data from Google Analytics 4 into post_meta. Zero frontend JS, zero page-load DB writes. A lightweight replacement for Post Views Counter.
 * Version:     1.0.0
 * Author:      Raymond Hou
 * Author URI:  https://raymondhouch.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.0
 *
 * Configuration — add to wp-config.php:
 *   define('GA4_CREDENTIALS_PATH', '/path/to/service-account.json');
 *   define('GA4_PROPERTY_ID', 'YOUR_GA4_PROPERTY_ID');
 */

if (!defined('ABSPATH')) exit;

class GA4_Pageviews_Sync {

    const META_KEY  = 'ga4_pageviews';
    const CRON_HOOK = 'ga4_pageviews_daily_sync';
    const OPT_LAST  = 'ga4_pageviews_last_sync';

    public function __construct() {
        add_action(self::CRON_HOOK, [$this, 'sync']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_filter('manage_posts_columns', [$this, 'add_column']);
        add_action('manage_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('manage_edit-post_sortable_columns', [$this, 'sortable_column']);
        add_action('pre_get_posts', [$this, 'sort_by_views']);

        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_post_ga4_manual_sync', [$this, 'handle_manual_sync']);
        add_action('admin_notices', [$this, 'show_sync_notice']);
    }

    /* ----- Cron ----- */

    public function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 04:00:00'), 'daily', self::CRON_HOOK);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ----- Auth: Service Account → JWT → Access Token ----- */

    private function load_credentials(): ?array {
        if (defined('GA4_CREDENTIALS_PATH') && file_exists(GA4_CREDENTIALS_PATH)) {
            return json_decode(file_get_contents(GA4_CREDENTIALS_PATH), true);
        }
        $fallback = __DIR__ . '/ga4-credentials.json';
        if (file_exists($fallback)) {
            return json_decode(file_get_contents($fallback), true);
        }
        return null;
    }

    private function get_property_id(): string {
        return defined('GA4_PROPERTY_ID') ? GA4_PROPERTY_ID : '';
    }

    private function get_access_token(): ?string {
        $creds = $this->load_credentials();
        if (!$creds || empty($creds['private_key'])) return null;

        $now     = time();
        $header  = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::b64url(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $unsigned = "{$header}.{$payload}";
        if (!openssl_sign($unsigned, $sig, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $unsigned . '.' . self::b64url($sig),
            ],
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) return null;

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return $data['access_token'] ?? null;
    }

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /* ----- Sync ----- */

    public function sync(): array {
        $property = $this->get_property_id();
        if (!$property) {
            $this->log_sync(['error' => 'GA4_PROPERTY_ID not configured']);
            return ['error' => 'GA4_PROPERTY_ID not configured'];
        }

        $token = $this->get_access_token();
        if (!$token) {
            $this->log_sync(['error' => 'Failed to get access token — check credentials']);
            return ['error' => 'Failed to get access token'];
        }

        $all_rows = [];
        $offset   = 0;
        $limit    = 10000;

        do {
            $resp = wp_remote_post(
                "https://analyticsdata.googleapis.com/v1beta/properties/{$property}:runReport",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode([
                        'dateRanges' => [['startDate' => '365daysAgo', 'endDate' => 'today']],
                        'dimensions' => [['name' => 'pagePath']],
                        'metrics'    => [['name' => 'screenPageViews']],
                        'limit'      => $limit,
                        'offset'     => $offset,
                        'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                    ]),
                    'timeout' => 60,
                ]
            );

            if (is_wp_error($resp)) break;

            $data  = json_decode(wp_remote_retrieve_body($resp), true);
            $rows  = $data['rows'] ?? [];
            $total = (int) ($data['rowCount'] ?? 0);

            $all_rows = array_merge($all_rows, $rows);
            $offset  += $limit;
        } while ($offset < $total);

        // Build slug → post_id map
        $slug_map = $this->build_slug_map();

        // Aggregate views by slug (GA4 may have multiple paths for the same post)
        $views_by_slug = [];
        foreach ($all_rows as $row) {
            $path  = $row['dimensionValues'][0]['value'] ?? '';
            $views = (int) ($row['metricValues'][0]['value'] ?? 0);
            $segments = array_filter(explode('/', trim($path, '/')));
            $slug = end($segments);
            if (!$slug) continue;
            $views_by_slug[$slug] = ($views_by_slug[$slug] ?? 0) + $views;
        }

        // Write to post_meta
        $updated = 0;
        foreach ($views_by_slug as $slug => $views) {
            if (isset($slug_map[$slug])) {
                update_post_meta($slug_map[$slug], self::META_KEY, $views);
                $updated++;
            }
        }

        $result = [
            'time'    => current_time('mysql'),
            'rows'    => count($all_rows),
            'slugs'   => count($views_by_slug),
            'updated' => $updated,
        ];
        $this->log_sync($result);
        return $result;
    }

    private function build_slug_map(): array {
        global $wpdb;

        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');
        $types_sql  = implode(',', array_map(function($t) { return "'" . esc_sql($t) . "'"; }, $post_types));

        $rows = $wpdb->get_results(
            "SELECT ID, post_name FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ({$types_sql})",
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $r) {
            if (!isset($map[$r['post_name']])) {
                $map[$r['post_name']] = (int) $r['ID'];
            }
        }
        return $map;
    }

    private function log_sync(array $result): void {
        update_option(self::OPT_LAST, $result, false);
    }

    /* ----- Admin Column ----- */

    public function add_column(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $new[self::META_KEY] = __('GA4 Views', 'ga4-pageviews-sync');
            }
            $new[$key] = $label;
        }
        if (!isset($new[self::META_KEY])) {
            $new[self::META_KEY] = __('GA4 Views', 'ga4-pageviews-sync');
        }
        return $new;
    }

    public function render_column(string $column, int $post_id): void {
        if ($column !== self::META_KEY) return;
        $views = get_post_meta($post_id, self::META_KEY, true);
        echo $views ? number_format_i18n((int) $views) : '<span style="color:#999">&mdash;</span>';
    }

    public function sortable_column(array $columns): array {
        $columns[self::META_KEY] = self::META_KEY;
        return $columns;
    }

    public function sort_by_views(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('orderby') !== self::META_KEY) return;
        $query->set('meta_key', self::META_KEY);
        $query->set('orderby', 'meta_value_num');
    }

    /* ----- Settings Page ----- */

    public function add_admin_page(): void {
        add_options_page(
            'GA4 Pageviews Sync',
            'GA4 Pageviews',
            'manage_options',
            'ga4-pageviews',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        $last  = get_option(self::OPT_LAST, []);
        $next  = wp_next_scheduled(self::CRON_HOOK);
        $creds = $this->load_credentials();
        $pid   = $this->get_property_id();
        ?>
        <div class="wrap">
            <h1>GA4 Pageviews Sync</h1>

            <h2><?php esc_html_e('Status', 'ga4-pageviews-sync'); ?></h2>
            <table class="form-table">
                <tr>
                    <th>Credentials</th>
                    <td><?php echo $creds
                        ? '&#9989; ' . esc_html($creds['client_email'])
                        : '&#10060; Not configured &mdash; define <code>GA4_CREDENTIALS_PATH</code> in <code>wp-config.php</code>'; ?></td>
                </tr>
                <tr>
                    <th>GA4 Property ID</th>
                    <td><?php echo $pid ? esc_html($pid) : '&#10060; Not configured &mdash; define <code>GA4_PROPERTY_ID</code> in <code>wp-config.php</code>'; ?></td>
                </tr>
                <tr>
                    <th>Last sync</th>
                    <td>
                        <?php if (!empty($last['time'])): ?>
                            <?php echo esc_html($last['time']); ?>
                            &mdash; <?php echo esc_html($last['updated'] ?? 0); ?> posts updated
                            (<?php echo esc_html($last['rows'] ?? 0); ?> GA4 rows)
                        <?php elseif (!empty($last['error'])): ?>
                            &#10060; <?php echo esc_html($last['error']); ?>
                        <?php else: ?>
                            Never
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Next scheduled</th>
                    <td><?php echo $next ? esc_html(wp_date('Y-m-d H:i:s', $next)) : 'Not scheduled (deactivate and reactivate the plugin)'; ?></td>
                </tr>
            </table>

            <h2>Manual Sync</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ga4_manual_sync">
                <?php wp_nonce_field('ga4_manual_sync'); ?>
                <?php submit_button('Sync Now', 'primary'); ?>
            </form>

            <h2>Setup</h2>
            <p>Add two lines to your <code>wp-config.php</code>:</p>
            <pre style="background:#f5f5f5;padding:16px;border-radius:4px;overflow-x:auto">define('GA4_CREDENTIALS_PATH', '/absolute/path/to/service-account.json');
define('GA4_PROPERTY_ID', '123456789');</pre>
            <p>
                The JSON file is your
                <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank">Google Cloud Service Account</a>
                key. Store it <strong>outside</strong> the web root for security.
            </p>
        </div>
        <?php
    }

    public function handle_manual_sync(): void {
        check_admin_referer('ga4_manual_sync');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $result = $this->sync();
        $msg = isset($result['error'])
            ? 'error|Sync failed: ' . $result['error']
            : "success|Sync complete: {$result['updated']} posts updated ({$result['rows']} GA4 rows)";

        set_transient('ga4_sync_notice', $msg, 60);
        wp_redirect(admin_url('options-general.php?page=ga4-pageviews'));
        exit;
    }

    public function show_sync_notice(): void {
        $notice = get_transient('ga4_sync_notice');
        if (!$notice) return;
        delete_transient('ga4_sync_notice');

        [$type, $message] = explode('|', $notice, 2);
        $class = ($type === 'success') ? 'notice-success' : 'notice-error';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
    }
}

new GA4_Pageviews_Sync();
