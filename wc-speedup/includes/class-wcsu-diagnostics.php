<?php
/**
 * Diagnostics Module - מזהה בעיות ביצועים
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Diagnostics {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wcsu_daily_cleanup', array($this, 'run_daily_diagnostics'));
    }

    /**
     * Run full diagnostics
     */
    public function run_full_diagnostics() {
        $results = array(
            'server' => $this->check_server(),
            'wordpress' => $this->check_wordpress(),
            'woocommerce' => $this->check_woocommerce(),
            'database' => $this->check_database(),
            'plugins' => $this->check_plugins(),
            'theme' => $this->check_theme(),
            'caching' => $this->check_caching(),
            'overall_score' => 0
        );

        $results['overall_score'] = $this->calculate_score($results);

        return $results;
    }

    /**
     * Check server configuration
     */
    public function check_server() {
        $checks = array();

        // PHP Version
        $php_version = phpversion();
        $checks['php_version'] = array(
            'label' => __('PHP Version', 'wc-speedup'),
            'value' => $php_version,
            'status' => version_compare($php_version, '8.0', '>=') ? 'good' : (version_compare($php_version, '7.4', '>=') ? 'warning' : 'bad'),
            'message' => version_compare($php_version, '8.0', '>=')
                ? __('PHP version is optimal', 'wc-speedup')
                : __('Consider upgrading to PHP 8.0+', 'wc-speedup')
        );

        // Memory Limit
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->convert_to_bytes($memory_limit);
        $checks['memory_limit'] = array(
            'label' => __('Memory Limit', 'wc-speedup'),
            'value' => $memory_limit,
            'status' => $memory_bytes >= 256 * 1024 * 1024 ? 'good' : ($memory_bytes >= 128 * 1024 * 1024 ? 'warning' : 'bad'),
            'message' => $memory_bytes >= 256 * 1024 * 1024
                ? __('Memory limit is sufficient', 'wc-speedup')
                : __('Increase memory_limit to at least 256M', 'wc-speedup')
        );

        // Max Execution Time
        $max_execution = ini_get('max_execution_time');
        $checks['max_execution'] = array(
            'label' => __('Max Execution Time', 'wc-speedup'),
            'value' => $max_execution . 's',
            'status' => $max_execution >= 60 || $max_execution == 0 ? 'good' : ($max_execution >= 30 ? 'warning' : 'bad'),
            'message' => $max_execution >= 60 || $max_execution == 0
                ? __('Execution time is sufficient', 'wc-speedup')
                : __('Increase max_execution_time to 60+', 'wc-speedup')
        );

        // OPcache
        $opcache_enabled = function_exists('opcache_get_status') && opcache_get_status();
        $checks['opcache'] = array(
            'label' => __('OPcache', 'wc-speedup'),
            'value' => $opcache_enabled ? __('Enabled', 'wc-speedup') : __('Disabled', 'wc-speedup'),
            'status' => $opcache_enabled ? 'good' : 'bad',
            'message' => $opcache_enabled
                ? __('OPcache is active', 'wc-speedup')
                : __('Enable OPcache for better performance', 'wc-speedup')
        );

        // MySQL Version
        global $wpdb;
        $mysql_version = $wpdb->db_version();
        $checks['mysql_version'] = array(
            'label' => __('MySQL Version', 'wc-speedup'),
            'value' => $mysql_version,
            'status' => version_compare($mysql_version, '5.7', '>=') ? 'good' : 'warning',
            'message' => version_compare($mysql_version, '5.7', '>=')
                ? __('MySQL version is good', 'wc-speedup')
                : __('Consider upgrading MySQL', 'wc-speedup')
        );

        // GZIP Compression
        $gzip_enabled = extension_loaded('zlib');
        $checks['gzip'] = array(
            'label' => __('GZIP Compression', 'wc-speedup'),
            'value' => $gzip_enabled ? __('Available', 'wc-speedup') : __('Not Available', 'wc-speedup'),
            'status' => $gzip_enabled ? 'good' : 'warning',
            'message' => $gzip_enabled
                ? __('GZIP compression available', 'wc-speedup')
                : __('Enable zlib extension for GZIP', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Check WordPress configuration
     */
    public function check_wordpress() {
        $checks = array();

        // WordPress Version
        global $wp_version;
        $checks['wp_version'] = array(
            'label' => __('WordPress Version', 'wc-speedup'),
            'value' => $wp_version,
            'status' => version_compare($wp_version, '6.0', '>=') ? 'good' : 'warning',
            'message' => version_compare($wp_version, '6.0', '>=')
                ? __('WordPress is up to date', 'wc-speedup')
                : __('Update WordPress to latest version', 'wc-speedup')
        );

        // Debug Mode
        $debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        $checks['debug_mode'] = array(
            'label' => __('Debug Mode', 'wc-speedup'),
            'value' => $debug_mode ? __('Enabled', 'wc-speedup') : __('Disabled', 'wc-speedup'),
            'status' => !$debug_mode ? 'good' : 'warning',
            'message' => !$debug_mode
                ? __('Debug mode is off (good for production)', 'wc-speedup')
                : __('Disable WP_DEBUG in production', 'wc-speedup')
        );

        // SCRIPT_DEBUG
        $script_debug = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG;
        $checks['script_debug'] = array(
            'label' => __('Script Debug', 'wc-speedup'),
            'value' => $script_debug ? __('Enabled', 'wc-speedup') : __('Disabled', 'wc-speedup'),
            'status' => !$script_debug ? 'good' : 'warning',
            'message' => !$script_debug
                ? __('Using minified scripts', 'wc-speedup')
                : __('Disable SCRIPT_DEBUG in production', 'wc-speedup')
        );

        // Cron
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $checks['wp_cron'] = array(
            'label' => __('WP Cron', 'wc-speedup'),
            'value' => $cron_disabled ? __('Disabled (System Cron)', 'wc-speedup') : __('Enabled', 'wc-speedup'),
            'status' => $cron_disabled ? 'good' : 'warning',
            'message' => $cron_disabled
                ? __('Using system cron (recommended)', 'wc-speedup')
                : __('Consider using system cron instead', 'wc-speedup')
        );

        // Post Revisions
        if (defined('WP_POST_REVISIONS')) {
            $revisions = WP_POST_REVISIONS;
        } else {
            $revisions = true;
        }
        $checks['revisions'] = array(
            'label' => __('Post Revisions', 'wc-speedup'),
            'value' => is_numeric($revisions) ? $revisions : ($revisions ? __('Unlimited', 'wc-speedup') : __('Disabled', 'wc-speedup')),
            'status' => (is_numeric($revisions) && $revisions <= 5) || $revisions === false ? 'good' : 'warning',
            'message' => (is_numeric($revisions) && $revisions <= 5) || $revisions === false
                ? __('Revisions are limited', 'wc-speedup')
                : __('Limit post revisions to reduce DB size', 'wc-speedup')
        );

        // Autosave Interval
        $autosave = defined('AUTOSAVE_INTERVAL') ? AUTOSAVE_INTERVAL : 60;
        $checks['autosave'] = array(
            'label' => __('Autosave Interval', 'wc-speedup'),
            'value' => $autosave . 's',
            'status' => $autosave >= 120 ? 'good' : 'warning',
            'message' => $autosave >= 120
                ? __('Autosave interval is optimized', 'wc-speedup')
                : __('Consider increasing AUTOSAVE_INTERVAL', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Check WooCommerce configuration
     */
    public function check_woocommerce() {
        $checks = array();

        if (!class_exists('WooCommerce')) {
            return array(
                'wc_active' => array(
                    'label' => __('WooCommerce', 'wc-speedup'),
                    'value' => __('Not Active', 'wc-speedup'),
                    'status' => 'warning',
                    'message' => __('WooCommerce is not installed', 'wc-speedup')
                )
            );
        }

        // WooCommerce Version
        $wc_version = WC()->version;
        $checks['wc_version'] = array(
            'label' => __('WooCommerce Version', 'wc-speedup'),
            'value' => $wc_version,
            'status' => version_compare($wc_version, '7.0', '>=') ? 'good' : 'warning',
            'message' => version_compare($wc_version, '7.0', '>=')
                ? __('WooCommerce is up to date', 'wc-speedup')
                : __('Update WooCommerce', 'wc-speedup')
        );

        // Product Count
        $product_count = wp_count_posts('product');
        $total_products = isset($product_count->publish) ? $product_count->publish : 0;
        $checks['product_count'] = array(
            'label' => __('Total Products', 'wc-speedup'),
            'value' => number_format($total_products),
            'status' => $total_products < 10000 ? 'good' : ($total_products < 50000 ? 'warning' : 'bad'),
            'message' => $total_products < 10000
                ? __('Product count is manageable', 'wc-speedup')
                : __('Large catalog - consider optimizations', 'wc-speedup')
        );

        // Order Count
        $order_count = 0;
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders(array('limit' => 1, 'return' => 'ids'));
            global $wpdb;
            $order_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders") ?:
                           $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'");
        }
        $checks['order_count'] = array(
            'label' => __('Total Orders', 'wc-speedup'),
            'value' => number_format($order_count),
            'status' => $order_count < 50000 ? 'good' : ($order_count < 200000 ? 'warning' : 'bad'),
            'message' => $order_count < 50000
                ? __('Order count is manageable', 'wc-speedup')
                : __('Consider archiving old orders', 'wc-speedup')
        );

        // Cart Fragments
        $cart_fragments = !(has_action('wp_enqueue_scripts', 'wc_cart_fragments') === false);
        $checks['cart_fragments'] = array(
            'label' => __('Cart Fragments AJAX', 'wc-speedup'),
            'value' => $cart_fragments ? __('Active', 'wc-speedup') : __('Disabled', 'wc-speedup'),
            'status' => !$cart_fragments ? 'good' : 'warning',
            'message' => !$cart_fragments
                ? __('Cart fragments optimized', 'wc-speedup')
                : __('Consider disabling cart fragments', 'wc-speedup')
        );

        // Geolocation
        $geolocation = get_option('woocommerce_default_customer_address');
        $checks['geolocation'] = array(
            'label' => __('Geolocation', 'wc-speedup'),
            'value' => $geolocation,
            'status' => $geolocation !== 'geolocation_ajax' ? 'good' : 'warning',
            'message' => $geolocation !== 'geolocation_ajax'
                ? __('Geolocation is optimized', 'wc-speedup')
                : __('AJAX geolocation may slow down pages', 'wc-speedup')
        );

        // Session Handler
        $session_handler = class_exists('WC_Session_Handler') ? 'WC_Session_Handler' : 'Unknown';
        $checks['session_handler'] = array(
            'label' => __('Session Handler', 'wc-speedup'),
            'value' => $session_handler,
            'status' => 'good',
            'message' => __('Using default session handler', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Check database status
     */
    public function check_database() {
        global $wpdb;
        $checks = array();

        // Database Size
        $db_size = $this->get_database_size();
        $checks['db_size'] = array(
            'label' => __('Database Size', 'wc-speedup'),
            'value' => size_format($db_size),
            'status' => $db_size < 500 * 1024 * 1024 ? 'good' : ($db_size < 1024 * 1024 * 1024 ? 'warning' : 'bad'),
            'message' => $db_size < 500 * 1024 * 1024
                ? __('Database size is optimal', 'wc-speedup')
                : __('Consider cleaning the database', 'wc-speedup')
        );

        // Autoloaded Options
        $autoload_size = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'");
        $checks['autoload_size'] = array(
            'label' => __('Autoloaded Options', 'wc-speedup'),
            'value' => size_format($autoload_size),
            'status' => $autoload_size < 800000 ? 'good' : ($autoload_size < 2000000 ? 'warning' : 'bad'),
            'message' => $autoload_size < 800000
                ? __('Autoloaded data is optimized', 'wc-speedup')
                : __('Too much autoloaded data', 'wc-speedup')
        );

        // Transients
        $expired_transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
        $checks['expired_transients'] = array(
            'label' => __('Expired Transients', 'wc-speedup'),
            'value' => number_format($expired_transients),
            'status' => $expired_transients < 100 ? 'good' : ($expired_transients < 500 ? 'warning' : 'bad'),
            'message' => $expired_transients < 100
                ? __('Transients are clean', 'wc-speedup')
                : __('Clean expired transients', 'wc-speedup')
        );

        // Post Revisions
        $revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $checks['revisions'] = array(
            'label' => __('Post Revisions', 'wc-speedup'),
            'value' => number_format($revisions),
            'status' => $revisions < 1000 ? 'good' : ($revisions < 5000 ? 'warning' : 'bad'),
            'message' => $revisions < 1000
                ? __('Revisions count is acceptable', 'wc-speedup')
                : __('Consider cleaning old revisions', 'wc-speedup')
        );

        // Auto Drafts
        $auto_drafts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
        $checks['auto_drafts'] = array(
            'label' => __('Auto Drafts', 'wc-speedup'),
            'value' => number_format($auto_drafts),
            'status' => $auto_drafts < 50 ? 'good' : ($auto_drafts < 200 ? 'warning' : 'bad'),
            'message' => $auto_drafts < 50
                ? __('Auto drafts are minimal', 'wc-speedup')
                : __('Clean auto drafts', 'wc-speedup')
        );

        // Trashed Posts
        $trashed = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
        $checks['trashed'] = array(
            'label' => __('Trashed Items', 'wc-speedup'),
            'value' => number_format($trashed),
            'status' => $trashed < 100 ? 'good' : ($trashed < 500 ? 'warning' : 'bad'),
            'message' => $trashed < 100
                ? __('Trash is clean', 'wc-speedup')
                : __('Empty the trash', 'wc-speedup')
        );

        // Spam Comments
        $spam = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $checks['spam_comments'] = array(
            'label' => __('Spam Comments', 'wc-speedup'),
            'value' => number_format($spam),
            'status' => $spam < 100 ? 'good' : ($spam < 1000 ? 'warning' : 'bad'),
            'message' => $spam < 100
                ? __('Spam is clean', 'wc-speedup')
                : __('Delete spam comments', 'wc-speedup')
        );

        // Orphaned Post Meta
        $orphan_meta = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL");
        $checks['orphan_meta'] = array(
            'label' => __('Orphaned Post Meta', 'wc-speedup'),
            'value' => number_format($orphan_meta),
            'status' => $orphan_meta < 100 ? 'good' : ($orphan_meta < 1000 ? 'warning' : 'bad'),
            'message' => $orphan_meta < 100
                ? __('Post meta is clean', 'wc-speedup')
                : __('Clean orphaned post meta', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Check active plugins
     */
    public function check_plugins() {
        $checks = array();
        $active_plugins = get_option('active_plugins', array());
        $plugin_count = count($active_plugins);

        $checks['plugin_count'] = array(
            'label' => __('Active Plugins', 'wc-speedup'),
            'value' => $plugin_count,
            'status' => $plugin_count < 20 ? 'good' : ($plugin_count < 40 ? 'warning' : 'bad'),
            'message' => $plugin_count < 20
                ? __('Plugin count is reasonable', 'wc-speedup')
                : __('Too many plugins may slow down your site', 'wc-speedup')
        );

        // Check for known slow plugins
        $slow_plugins = array(
            'broken-link-checker' => 'Broken Link Checker',
            'yet-another-related-posts-plugin' => 'YARPP',
            'wp-statistics' => 'WP Statistics',
            'google-analytics-for-wordpress' => 'MonsterInsights',
            'jetpack' => 'Jetpack (many features)',
            'wp-smushit' => 'Smush (on-the-fly optimization)',
            'ewww-image-optimizer' => 'EWWW Image Optimizer',
            'wordfence' => 'Wordfence (heavy scanning)',
            'all-in-one-seo-pack' => 'All in One SEO',
            'siteorigin-panels' => 'Page Builder by SiteOrigin'
        );

        $found_slow = array();
        foreach ($active_plugins as $plugin) {
            foreach ($slow_plugins as $slug => $name) {
                if (strpos($plugin, $slug) !== false) {
                    $found_slow[] = $name;
                }
            }
        }

        $checks['slow_plugins'] = array(
            'label' => __('Known Heavy Plugins', 'wc-speedup'),
            'value' => count($found_slow) > 0 ? implode(', ', $found_slow) : __('None detected', 'wc-speedup'),
            'status' => count($found_slow) === 0 ? 'good' : (count($found_slow) < 3 ? 'warning' : 'bad'),
            'message' => count($found_slow) === 0
                ? __('No known slow plugins detected', 'wc-speedup')
                : __('These plugins may impact performance', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Check theme
     */
    public function check_theme() {
        $checks = array();
        $theme = wp_get_theme();

        $checks['theme_name'] = array(
            'label' => __('Active Theme', 'wc-speedup'),
            'value' => $theme->get('Name'),
            'status' => 'good',
            'message' => __('Theme information', 'wc-speedup')
        );

        // Check if it's a child theme
        $is_child = $theme->parent() !== false;
        $checks['child_theme'] = array(
            'label' => __('Child Theme', 'wc-speedup'),
            'value' => $is_child ? __('Yes', 'wc-speedup') : __('No', 'wc-speedup'),
            'status' => 'good',
            'message' => $is_child
                ? __('Using child theme (recommended)', 'wc-speedup')
                : __('Consider using a child theme', 'wc-speedup')
        );

        // Check for known slow themes
        $slow_themes = array('avada', 'enfold', 'x-theme', 'betheme', 'bridge', 'salient', 'jupiter', 'the7');
        $theme_slug = strtolower($theme->get_stylesheet());
        $is_slow_theme = false;
        foreach ($slow_themes as $slow) {
            if (strpos($theme_slug, $slow) !== false) {
                $is_slow_theme = true;
                break;
            }
        }

        $checks['theme_performance'] = array(
            'label' => __('Theme Performance', 'wc-speedup'),
            'value' => $is_slow_theme ? __('Heavy Theme', 'wc-speedup') : __('Standard', 'wc-speedup'),
            'status' => !$is_slow_theme ? 'good' : 'warning',
            'message' => !$is_slow_theme
                ? __('Theme is not known to be slow', 'wc-speedup')
                : __('This theme may have performance overhead', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Check caching configuration
     */
    public function check_caching() {
        $checks = array();

        // Object Cache
        $object_cache = wp_using_ext_object_cache();
        $checks['object_cache'] = array(
            'label' => __('Object Cache', 'wc-speedup'),
            'value' => $object_cache ? __('External', 'wc-speedup') : __('Internal', 'wc-speedup'),
            'status' => $object_cache ? 'good' : 'warning',
            'message' => $object_cache
                ? __('Using external object cache (Redis/Memcached)', 'wc-speedup')
                : __('Consider using Redis or Memcached', 'wc-speedup')
        );

        // Page Cache (detect common caching plugins)
        $cache_plugins = array(
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
            'breeze/breeze.php' => 'Breeze',
            'sg-cachepress/sg-cachepress.php' => 'SG Optimizer',
            'autoptimize/autoptimize.php' => 'Autoptimize',
        );

        $active_plugins = get_option('active_plugins', array());
        $found_cache_plugin = __('None detected', 'wc-speedup');

        foreach ($cache_plugins as $plugin => $name) {
            if (in_array($plugin, $active_plugins)) {
                $found_cache_plugin = $name;
                break;
            }
        }

        // Check for our built-in page cache
        $options = get_option('wcsu_options', array());
        $builtin_cache_enabled = !empty($options['enable_page_cache']);

        $has_cache = $found_cache_plugin !== __('None detected', 'wc-speedup') || $builtin_cache_enabled;

        if ($builtin_cache_enabled && $found_cache_plugin === __('None detected', 'wc-speedup')) {
            $found_cache_plugin = __('WC SpeedUp Page Cache', 'wc-speedup');
        }

        $checks['page_cache'] = array(
            'label' => __('Page Caching', 'wc-speedup'),
            'value' => $found_cache_plugin,
            'status' => $has_cache ? 'good' : 'bad',
            'message' => $has_cache
                ? __('Page caching is active', 'wc-speedup')
                : __('Enable page cache in WC SpeedUp settings!', 'wc-speedup')
        );

        // Check for CDN headers
        $cdn_detected = isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_X_SUCURI_ID']) ||
                        isset($_SERVER['HTTP_X_CDN']) || isset($_SERVER['HTTP_X_EDGE_LOCATION']);
        $checks['cdn'] = array(
            'label' => __('CDN', 'wc-speedup'),
            'value' => $cdn_detected ? __('Detected', 'wc-speedup') : __('Not Detected', 'wc-speedup'),
            'status' => $cdn_detected ? 'good' : 'warning',
            'message' => $cdn_detected
                ? __('CDN is being used', 'wc-speedup')
                : __('Consider using a CDN', 'wc-speedup')
        );

        return $checks;
    }

    /**
     * Get slow queries from Query Monitor or custom logging
     */
    public function get_slow_queries() {
        global $wpdb;
        $slow_queries = array();

        // Check if we have logged slow queries
        $logged_queries = get_transient('wcsu_slow_queries');
        if ($logged_queries) {
            return $logged_queries;
        }

        // Common slow query patterns
        $slow_patterns = array(
            'autoload_options' => $wpdb->prepare(
                "SELECT option_name, LENGTH(option_value) as size
                 FROM {$wpdb->options}
                 WHERE autoload = %s
                 ORDER BY size DESC
                 LIMIT 10",
                'yes'
            ),
        );

        foreach ($slow_patterns as $name => $query) {
            $results = $wpdb->get_results($query);
            if ($results) {
                $slow_queries[$name] = $results;
            }
        }

        return $slow_queries;
    }

    /**
     * Calculate overall performance score
     */
    private function calculate_score($results) {
        $total_checks = 0;
        $good_checks = 0;
        $weights = array(
            'server' => 1.5,
            'wordpress' => 1,
            'woocommerce' => 1.5,
            'database' => 2,
            'plugins' => 1,
            'theme' => 0.5,
            'caching' => 2
        );

        foreach ($results as $category => $checks) {
            if ($category === 'overall_score' || !is_array($checks)) {
                continue;
            }

            $weight = isset($weights[$category]) ? $weights[$category] : 1;

            foreach ($checks as $check) {
                if (!isset($check['status'])) {
                    continue;
                }
                $total_checks += $weight;
                if ($check['status'] === 'good') {
                    $good_checks += $weight;
                } elseif ($check['status'] === 'warning') {
                    $good_checks += ($weight * 0.5);
                }
            }
        }

        return $total_checks > 0 ? round(($good_checks / $total_checks) * 100) : 0;
    }

    /**
     * Get database size
     */
    private function get_database_size() {
        global $wpdb;
        $size = 0;

        $tables = $wpdb->get_results("SHOW TABLE STATUS FROM `" . DB_NAME . "`", ARRAY_A);
        if ($tables) {
            foreach ($tables as $table) {
                $size += $table['Data_length'] + $table['Index_length'];
            }
        }

        return $size;
    }

    /**
     * Convert memory string to bytes
     */
    private function convert_to_bytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Run daily diagnostics and store results
     */
    public function run_daily_diagnostics() {
        $results = $this->run_full_diagnostics();
        update_option('wcsu_last_diagnostics', array(
            'time' => current_time('timestamp'),
            'results' => $results
        ));
    }

    /**
     * Get recommendations based on diagnostics
     */
    public function get_recommendations() {
        $results = $this->run_full_diagnostics();
        $recommendations = array();

        foreach ($results as $category => $checks) {
            if ($category === 'overall_score' || !is_array($checks)) {
                continue;
            }

            foreach ($checks as $key => $check) {
                if (!isset($check['status'])) {
                    continue;
                }

                if ($check['status'] === 'bad') {
                    $recommendations[] = array(
                        'priority' => 'high',
                        'category' => $category,
                        'check' => $key,
                        'label' => $check['label'],
                        'message' => $check['message'],
                        'current' => $check['value']
                    );
                } elseif ($check['status'] === 'warning') {
                    $recommendations[] = array(
                        'priority' => 'medium',
                        'category' => $category,
                        'check' => $key,
                        'label' => $check['label'],
                        'message' => $check['message'],
                        'current' => $check['value']
                    );
                }
            }
        }

        // Sort by priority
        usort($recommendations, function($a, $b) {
            $priority_order = array('high' => 0, 'medium' => 1, 'low' => 2);
            return $priority_order[$a['priority']] <=> $priority_order[$b['priority']];
        });

        return $recommendations;
    }
}
