<?php
/**
 * Cache Optimization Module - מטמון ואופטימיזציית מהירות
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Cache {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_wcsu_preload_cache', array($this, 'ajax_preload_cache'));
    }

    /**
     * Initialize cache optimizations
     */
    public function init() {
        $options = get_option('wcsu_options', array());

        // Add browser caching headers
        if (!empty($options['browser_caching'])) {
            add_action('send_headers', array($this, 'add_browser_caching_headers'));
        }

        // Add GZIP compression
        if (!empty($options['gzip_compression']) && !$this->is_gzip_enabled()) {
            $this->enable_gzip();
        }

        // Minify HTML - DISABLED (can break site)
        // if (!empty($options['minify_html']) && !is_admin()) {
        //     add_action('template_redirect', array($this, 'start_html_minification'));
        // }

        // Defer JavaScript - DISABLED (can break site)
        // if (!empty($options['defer_js']) && !is_admin()) {
        //     add_filter('script_loader_tag', array($this, 'defer_scripts'), 10, 3);
        // }

        // Async CSS - DISABLED (can break site)
        // if (!empty($options['async_css']) && !is_admin()) {
        //     add_filter('style_loader_tag', array($this, 'async_styles'), 10, 4);
        // }

        // DNS Prefetch
        if (!empty($options['dns_prefetch'])) {
            add_action('wp_head', array($this, 'dns_prefetch'), 1);
        }

        // Preconnect
        if (!empty($options['preconnect'])) {
            add_action('wp_head', array($this, 'preconnect'), 1);
        }
    }

    /**
     * Add browser caching headers
     */
    public function add_browser_caching_headers() {
        if (is_admin()) {
            return;
        }

        // Check if headers already sent
        if (headers_sent()) {
            return;
        }

        $cache_time = 31536000; // 1 year

        // Cache static resources
        if (preg_match('/\.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$/i', $_SERVER['REQUEST_URI'])) {
            header('Cache-Control: public, max-age=' . $cache_time);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cache_time) . ' GMT');
        }
    }

    /**
     * Check if GZIP is enabled
     */
    private function is_gzip_enabled() {
        return extension_loaded('zlib') && ini_get('zlib.output_compression');
    }

    /**
     * Enable GZIP compression
     */
    private function enable_gzip() {
        if (extension_loaded('zlib') && !ob_get_level()) {
            ob_start('ob_gzhandler');
        }
    }

    /**
     * Start HTML minification
     */
    public function start_html_minification() {
        if (!is_admin() && !is_feed() && !is_preview()) {
            ob_start(array($this, 'minify_html_output'));
        }
    }

    /**
     * Minify HTML output
     */
    public function minify_html_output($buffer) {
        if (empty($buffer)) {
            return $buffer;
        }

        // Don't minify JSON or XML
        if (strpos($buffer, '<?xml') === 0 || strpos($buffer, '{') === 0) {
            return $buffer;
        }

        // Preserve pre, script, style, textarea content
        $protected = array();
        $patterns = array(
            '/<pre[^>]*>.*?<\/pre>/is',
            '/<script[^>]*>.*?<\/script>/is',
            '/<style[^>]*>.*?<\/style>/is',
            '/<textarea[^>]*>.*?<\/textarea>/is'
        );

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $buffer, $matches);
            foreach ($matches[0] as $match) {
                $placeholder = '<!--WCSU_PROTECTED_' . count($protected) . '-->';
                $protected[$placeholder] = $match;
                $buffer = str_replace($match, $placeholder, $buffer);
            }
        }

        // Minify
        $buffer = preg_replace('/<!--(?!\[if).*?-->/s', '', $buffer); // Remove HTML comments (except IE conditionals)
        $buffer = preg_replace('/\s+/', ' ', $buffer); // Multiple spaces to single
        $buffer = preg_replace('/>\s+</', '><', $buffer); // Remove spaces between tags
        $buffer = preg_replace('/\s+\/>/', '/>', $buffer); // Remove spaces before self-closing

        // Restore protected content
        foreach ($protected as $placeholder => $content) {
            $buffer = str_replace($placeholder, $content, $buffer);
        }

        return $buffer;
    }

    /**
     * Defer JavaScript loading
     */
    public function defer_scripts($tag, $handle, $src) {
        // Don't defer jQuery and critical scripts
        $exclude = array(
            'jquery',
            'jquery-core',
            'jquery-migrate',
            'wc-add-to-cart',
            'wc-checkout',
            'wc-cart',
            'woocommerce'
        );

        $exclude = apply_filters('wcsu_defer_exclude', $exclude);

        if (in_array($handle, $exclude)) {
            return $tag;
        }

        // Don't add if already has defer or async
        if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
            return $tag;
        }

        return str_replace(' src=', ' defer src=', $tag);
    }

    /**
     * Async CSS loading
     */
    public function async_styles($tag, $handle, $href, $media) {
        // Critical styles to load normally
        $exclude = array(
            'wp-block-library',
            'woocommerce-layout',
            'woocommerce-smallscreen',
            'woocommerce-general'
        );

        $exclude = apply_filters('wcsu_async_css_exclude', $exclude);

        if (in_array($handle, $exclude)) {
            return $tag;
        }

        // Convert to async loading with loadCSS pattern
        $noscript = '<noscript>' . $tag . '</noscript>';
        $async_tag = '<link rel="preload" href="' . esc_url($href) . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'" media="' . esc_attr($media) . '">';

        return $async_tag . $noscript;
    }

    /**
     * Add DNS prefetch
     */
    public function dns_prefetch() {
        $domains = array(
            '//fonts.googleapis.com',
            '//fonts.gstatic.com',
            '//ajax.googleapis.com',
            '//www.google-analytics.com',
            '//www.googletagmanager.com',
            '//connect.facebook.net',
            '//platform.twitter.com'
        );

        $domains = apply_filters('wcsu_dns_prefetch_domains', $domains);

        foreach ($domains as $domain) {
            echo '<link rel="dns-prefetch" href="' . esc_url($domain) . '">' . "\n";
        }
    }

    /**
     * Add preconnect
     */
    public function preconnect() {
        $origins = array(
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com'
        );

        $origins = apply_filters('wcsu_preconnect_origins', $origins);

        foreach ($origins as $origin) {
            echo '<link rel="preconnect" href="' . esc_url($origin) . '" crossorigin>' . "\n";
        }
    }

    /**
     * Clear all caches
     */
    public function clear_all_caches() {
        $cleared = array();

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cleared[] = 'object_cache';
        }

        // Clear transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'");
        $cleared[] = 'transients';

        // Clear WooCommerce transients
        if (class_exists('WooCommerce')) {
            wc_delete_product_transients();
            wc_delete_shop_order_transients();
            $cleared[] = 'woocommerce_transients';
        }

        // Clear WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared[] = 'wp_super_cache';
        }

        // Clear W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared[] = 'w3_total_cache';
        }

        // Clear WP Fastest Cache
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache();
            $cleared[] = 'wp_fastest_cache';
        }

        // Clear LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $cleared[] = 'litespeed_cache';
        }

        // Clear WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $cleared[] = 'wp_rocket';
        }

        // Clear Cache Enabler
        if (class_exists('Cache_Enabler')) {
            Cache_Enabler::clear_total_cache();
            $cleared[] = 'cache_enabler';
        }

        // Clear Autoptimize
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
            $cleared[] = 'autoptimize';
        }

        // Clear SG Optimizer
        if (function_exists('sg_cachepress_purge_everything')) {
            sg_cachepress_purge_everything();
            $cleared[] = 'sg_optimizer';
        }

        // Clear Cloudflare
        if (class_exists('CF\WordPress\Hooks')) {
            do_action('cloudflare_purge_everything');
            $cleared[] = 'cloudflare';
        }

        // Fire action for other caching plugins
        do_action('wcsu_clear_caches');

        return $cleared;
    }

    /**
     * Preload cache for important pages
     */
    public function preload_pages($pages = array()) {
        if (empty($pages)) {
            $pages = $this->get_important_pages();
        }

        $results = array();

        foreach ($pages as $url) {
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'sslverify' => false
            ));

            $results[$url] = array(
                'status' => wp_remote_retrieve_response_code($response),
                'time' => microtime(true)
            );
        }

        return $results;
    }

    /**
     * Get important pages to preload
     */
    public function get_important_pages() {
        $pages = array(
            home_url('/'),
            home_url('/shop/'),
        );

        // Add product category pages
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'number' => 5
        ));

        if (!is_wp_error($categories)) {
            foreach ($categories as $cat) {
                $pages[] = get_term_link($cat);
            }
        }

        // Add popular products
        if (class_exists('WooCommerce')) {
            $products = wc_get_products(array(
                'limit' => 10,
                'orderby' => 'popularity',
                'order' => 'DESC',
                'return' => 'ids'
            ));

            foreach ($products as $product_id) {
                $pages[] = get_permalink($product_id);
            }
        }

        return apply_filters('wcsu_preload_pages', $pages);
    }

    /**
     * Get cache status
     */
    public function get_cache_status() {
        $status = array();

        // Object Cache
        $status['object_cache'] = array(
            'label' => __('Object Cache', 'wc-speedup'),
            'enabled' => wp_using_ext_object_cache(),
            'type' => $this->get_object_cache_type()
        );

        // Check for caching plugins
        $status['page_cache'] = array(
            'label' => __('Page Cache', 'wc-speedup'),
            'enabled' => $this->is_page_cache_active(),
            'plugin' => $this->get_page_cache_plugin()
        );

        // OPcache
        $status['opcache'] = array(
            'label' => __('OPcache', 'wc-speedup'),
            'enabled' => function_exists('opcache_get_status') && opcache_get_status(),
            'stats' => function_exists('opcache_get_status') ? opcache_get_status(false) : null
        );

        // Browser caching
        $options = get_option('wcsu_options', array());
        $status['browser_cache'] = array(
            'label' => __('Browser Caching', 'wc-speedup'),
            'enabled' => !empty($options['browser_caching'])
        );

        return $status;
    }

    /**
     * Get object cache type
     */
    private function get_object_cache_type() {
        global $_wp_using_ext_object_cache;

        if (!$_wp_using_ext_object_cache) {
            return 'internal';
        }

        // Check for Redis
        if (class_exists('Redis') || class_exists('Predis\Client')) {
            return 'redis';
        }

        // Check for Memcached
        if (class_exists('Memcached') || class_exists('Memcache')) {
            return 'memcached';
        }

        return 'external';
    }

    /**
     * Check if page cache is active
     */
    private function is_page_cache_active() {
        $cache_plugins = array(
            'wp-super-cache/wp-cache.php',
            'w3-total-cache/w3-total-cache.php',
            'wp-fastest-cache/wpFastestCache.php',
            'litespeed-cache/litespeed-cache.php',
            'cache-enabler/cache-enabler.php',
            'wp-rocket/wp-rocket.php',
            'hummingbird-performance/wp-hummingbird.php',
            'breeze/breeze.php',
            'sg-cachepress/sg-cachepress.php'
        );

        $active_plugins = get_option('active_plugins', array());

        foreach ($cache_plugins as $plugin) {
            if (in_array($plugin, $active_plugins)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get active page cache plugin
     */
    private function get_page_cache_plugin() {
        $cache_plugins = array(
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
            'breeze/breeze.php' => 'Breeze',
            'sg-cachepress/sg-cachepress.php' => 'SG Optimizer'
        );

        $active_plugins = get_option('active_plugins', array());

        foreach ($cache_plugins as $plugin => $name) {
            if (in_array($plugin, $active_plugins)) {
                return $name;
            }
        }

        return __('None', 'wc-speedup');
    }

    /**
     * AJAX handler for clearing cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $cleared = $this->clear_all_caches();

        wp_send_json_success(array(
            'cleared' => $cleared,
            'message' => sprintf(__('Cleared %d cache types', 'wc-speedup'), count($cleared))
        ));
    }

    /**
     * AJAX handler for preloading cache
     */
    public function ajax_preload_cache() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $results = $this->preload_pages();

        wp_send_json_success(array(
            'results' => $results,
            'message' => sprintf(__('Preloaded %d pages', 'wc-speedup'), count($results))
        ));
    }

    /**
     * Generate critical CSS
     */
    public function generate_critical_css($url) {
        // This is a placeholder - real critical CSS generation would require
        // a headless browser or external service
        $critical_css = '';

        // Add basic above-the-fold styles
        $critical_css .= '
            body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            header { display: block; }
            .site-header { width: 100%; }
            .site-branding { padding: 1rem; }
            .main-navigation { display: flex; }
            .hero, .banner { display: block; }
        ';

        return apply_filters('wcsu_critical_css', $critical_css, $url);
    }

    /**
     * Add critical CSS inline
     */
    public function add_critical_css() {
        $options = get_option('wcsu_options', array());

        if (empty($options['critical_css'])) {
            return;
        }

        $critical_css = get_option('wcsu_critical_css', '');

        if (!empty($critical_css)) {
            echo '<style id="wcsu-critical-css">' . $critical_css . '</style>';
        }
    }
}
