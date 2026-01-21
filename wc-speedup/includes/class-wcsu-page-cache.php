<?php
/**
 * Page Cache Module - קאש עמודים לווקומרס
 * Caches full HTML pages to avoid database queries on repeat visits
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Page_Cache {

    /**
     * Cache directory path
     */
    private $cache_dir;

    /**
     * Cache enabled status
     */
    private $enabled = false;

    /**
     * Cache TTL in seconds (default: 1 hour)
     */
    private $cache_ttl = 3600;

    /**
     * Excluded URLs patterns
     */
    private $excluded_patterns = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->cache_dir = WP_CONTENT_DIR . '/cache/wcsu-page-cache/';

        add_action('init', array($this, 'init'), 1);
        add_action('wp_ajax_wcsu_toggle_page_cache', array($this, 'ajax_toggle_page_cache'));
        add_action('wp_ajax_wcsu_clear_page_cache', array($this, 'ajax_clear_page_cache'));
        add_action('wp_ajax_wcsu_get_page_cache_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_wcsu_test_page_cache', array($this, 'ajax_test_cache'));

        // Clear cache on content updates
        add_action('save_post', array($this, 'clear_post_cache'), 10, 2);
        add_action('deleted_post', array($this, 'clear_all_cache'));
        add_action('switch_theme', array($this, 'clear_all_cache'));
        add_action('wp_update_nav_menu', array($this, 'clear_all_cache'));
        add_action('woocommerce_product_set_stock', array($this, 'clear_product_cache'));
        add_action('woocommerce_variation_set_stock', array($this, 'clear_product_cache'));
        add_action('woocommerce_product_set_stock_status', array($this, 'clear_product_cache'));
    }

    /**
     * Initialize page cache
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_page_cache']);
        $this->cache_ttl = isset($options['page_cache_ttl']) ? intval($options['page_cache_ttl']) : 3600;

        if (!$this->enabled) {
            return;
        }

        // Create cache directory if needed
        $this->ensure_cache_dir();

        // Try to serve from cache first (early, before WordPress fully loads)
        if (!is_admin() && $this->can_serve_cache()) {
            $this->serve_cached_page();
        }

        // Start output buffering to capture the page
        if (!is_admin() && $this->should_cache_request()) {
            add_action('template_redirect', array($this, 'start_buffering'), 0);
        }
    }

    /**
     * Ensure cache directory exists
     */
    private function ensure_cache_dir() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);

            // Create .htaccess to protect cache directory
            $htaccess = $this->cache_dir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Order deny,allow\nDeny from all");
            }

            // Create index.php for security
            $index = $this->cache_dir . 'index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }

    /**
     * Check if we can serve from cache
     */
    private function can_serve_cache() {
        // Don't serve cache for POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }

        // Don't serve cache for logged-in users
        if ($this->is_user_logged_in_check()) {
            return false;
        }

        // Don't serve cache if there's a cart cookie
        if ($this->has_woocommerce_cart()) {
            return false;
        }

        return true;
    }

    /**
     * Quick check if user might be logged in (before WordPress fully loads)
     */
    private function is_user_logged_in_check() {
        // Check for WordPress logged-in cookie
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'wordpress_logged_in_') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has WooCommerce session or cart items
     * This is CRITICAL to prevent cart data from being cached and shown to other users
     */
    private function has_woocommerce_cart() {
        // Check for WooCommerce cart-related cookies only
        // Note: we intentionally DON'T check woocommerce_recently_viewed as it doesn't contain cart data
        foreach ($_COOKIE as $name => $value) {
            // Cart items cookie - user has items in cart
            if (strpos($name, 'woocommerce_items_in_cart') === 0 && $value !== '0' && !empty($value)) {
                return true;
            }
            // WooCommerce cart hash - indicates cart has content
            if (strpos($name, 'woocommerce_cart_hash') === 0 && !empty($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if request should be cached
     */
    private function should_cache_request() {
        // Only cache GET requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }

        // Don't cache if user is logged in
        if (is_user_logged_in()) {
            return false;
        }

        // Don't cache if user has WooCommerce session/cart (CRITICAL for preventing cart leakage)
        if ($this->has_woocommerce_cart()) {
            return false;
        }

        // Don't cache admin pages
        if (is_admin()) {
            return false;
        }

        // Don't cache AJAX requests
        if (wp_doing_ajax()) {
            return false;
        }

        // Don't cache WP cron
        if (wp_doing_cron()) {
            return false;
        }

        // Don't cache REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        // Don't cache if there's a query string (except for allowed ones)
        $allowed_query_strings = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid');
        $query_string = $_SERVER['QUERY_STRING'] ?? '';
        if (!empty($query_string)) {
            parse_str($query_string, $params);
            $unknown_params = array_diff(array_keys($params), $allowed_query_strings);
            if (!empty($unknown_params)) {
                return false;
            }
        }

        // Don't cache excluded URLs
        if ($this->is_excluded_url()) {
            return false;
        }

        return true;
    }

    /**
     * Check if current URL should be excluded from cache
     */
    private function is_excluded_url() {
        $current_url = $_SERVER['REQUEST_URI'];

        // WooCommerce pages that should never be cached
        $excluded_pages = array(
            '/cart',
            '/checkout',
            '/my-account',
            '/wc-api/',
            '/add-to-cart',
            '/remove_item',
            '/undo_item',
            '/order-received',
            '/order-pay',
            '/view-order',
        );

        // Add WooCommerce endpoint patterns
        $wc_endpoints = array(
            'orders',
            'downloads',
            'edit-address',
            'edit-account',
            'payment-methods',
            'lost-password',
            'customer-logout',
        );

        foreach ($wc_endpoints as $endpoint) {
            $excluded_pages[] = "/$endpoint";
        }

        // Check against excluded patterns
        foreach ($excluded_pages as $pattern) {
            if (strpos($current_url, $pattern) !== false) {
                return true;
            }
        }

        // Check for WooCommerce-specific query parameters
        $excluded_params = array(
            'add-to-cart',
            'remove_item',
            'removed_item',
            'undo_item',
            'wc-ajax',
        );

        foreach ($excluded_params as $param) {
            if (isset($_GET[$param]) || strpos($current_url, $param . '=') !== false) {
                return true;
            }
        }

        // Custom excluded patterns from settings
        $options = get_option('wcsu_options', array());
        if (!empty($options['page_cache_exclude'])) {
            $custom_exclusions = array_filter(array_map('trim', explode("\n", $options['page_cache_exclude'])));
            foreach ($custom_exclusions as $pattern) {
                if (fnmatch($pattern, $current_url) || strpos($current_url, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Serve cached page if available
     */
    private function serve_cached_page() {
        $cache_file = $this->get_cache_file_path();

        if (!file_exists($cache_file)) {
            return;
        }

        // Check if cache is still valid
        $cache_time = filemtime($cache_file);
        if ((time() - $cache_time) > $this->cache_ttl) {
            // Cache expired, delete it
            @unlink($cache_file);
            return;
        }

        // Serve the cached file
        $content = file_get_contents($cache_file);

        if ($content === false) {
            return;
        }

        // Add cache headers
        header('X-WCSU-Cache: HIT');
        header('X-WCSU-Cache-Time: ' . date('Y-m-d H:i:s', $cache_time));
        header('Content-Type: text/html; charset=UTF-8');

        // Output cached content and exit
        echo $content;
        exit;
    }

    /**
     * Get cache file path for current request
     */
    private function get_cache_file_path() {
        $url = $_SERVER['REQUEST_URI'];

        // Remove tracking query strings for consistent caching
        $url = preg_replace('/[\?&](utm_[^&]+|fbclid|gclid)=[^&]*/i', '', $url);
        $url = rtrim($url, '?&');

        // Create a hash of the URL
        $hash = md5($url);

        // Create subdirectories for better file system performance
        $subdir = substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/';

        return $this->cache_dir . $subdir . $hash . '.html';
    }

    /**
     * Start output buffering
     */
    public function start_buffering() {
        // Additional checks after WordPress is loaded
        if (is_user_logged_in()) {
            return;
        }

        // Check WooCommerce-specific conditions
        if (function_exists('is_cart') && is_cart()) {
            return;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
            return;
        }

        // Don't cache 404 pages
        if (is_404()) {
            return;
        }

        // Don't cache search results
        if (is_search()) {
            return;
        }

        // Start buffering
        ob_start(array($this, 'save_cache'));
    }

    /**
     * Save the page to cache
     */
    public function save_cache($buffer) {
        // Don't cache empty content
        if (empty($buffer)) {
            return $buffer;
        }

        // Don't cache if there was an error
        if (http_response_code() !== 200) {
            return $buffer;
        }

        // Don't cache partial content
        if (strpos($buffer, '</html>') === false) {
            return $buffer;
        }

        // SAFETY CHECK: Double-check for WooCommerce session cookies
        // (in case something changed during page rendering)
        if ($this->has_woocommerce_cart()) {
            return $buffer;
        }

        // SAFETY CHECK: Don't cache if page contains personalized cart data
        // Look for signs of cart content that shouldn't be cached
        if ($this->contains_personal_cart_data($buffer)) {
            return $buffer;
        }

        // Get cache file path
        $cache_file = $this->get_cache_file_path();
        $cache_dir = dirname($cache_file);

        // Create directory if needed (with proper permissions)
        if (!file_exists($cache_dir)) {
            if (!wp_mkdir_p($cache_dir)) {
                // Failed to create directory, skip caching
                return $buffer;
            }
        }

        // Verify directory is writable
        if (!is_writable($cache_dir)) {
            return $buffer;
        }

        // Add cache timestamp comment to the HTML
        $timestamp = current_time('Y-m-d H:i:s');
        $cache_comment = "\n<!-- WCSU Page Cache | Generated: {$timestamp} -->\n";
        $buffer = str_replace('</html>', $cache_comment . '</html>', $buffer);

        // Save to cache file
        $saved = @file_put_contents($cache_file, $buffer, LOCK_EX);

        // If save failed, don't add cache header
        if ($saved === false) {
            return $buffer;
        }

        // Add miss header
        if (!headers_sent()) {
            header('X-WCSU-Cache: MISS');
        }

        return $buffer;
    }

    /**
     * Check if page contains personal cart data that shouldn't be cached
     * Only checks for actual cart items, not empty cart widgets
     */
    private function contains_personal_cart_data($buffer) {
        // Only check for actual cart items in mini-cart
        // Look for mini_cart_item class which only appears when there are items
        if (strpos($buffer, 'mini_cart_item') !== false) {
            // Double check it's actually a cart item, not just text
            if (preg_match('/<li[^>]*class="[^"]*mini_cart_item[^"]*"/i', $buffer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear cache for a specific post
     */
    public function clear_post_cache($post_id, $post = null) {
        if (!$post) {
            $post = get_post($post_id);
        }

        if (!$post) {
            return;
        }

        // Skip revisions and auto-drafts
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Get the permalink and clear its cache
        $permalink = get_permalink($post_id);
        if ($permalink) {
            $this->clear_url_cache($permalink);
        }

        // Clear home page cache as it might list this post
        $this->clear_url_cache(home_url('/'));

        // If it's a product, clear shop page too
        if ($post->post_type === 'product') {
            $shop_page_id = wc_get_page_id('shop');
            if ($shop_page_id > 0) {
                $this->clear_url_cache(get_permalink($shop_page_id));
            }

            // Clear product category pages
            $terms = get_the_terms($post_id, 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $term_link = get_term_link($term);
                    if (!is_wp_error($term_link)) {
                        $this->clear_url_cache($term_link);
                    }
                }
            }
        }
    }

    /**
     * Clear cache for a specific URL
     */
    public function clear_url_cache($url) {
        $parsed = parse_url($url);
        $uri = isset($parsed['path']) ? $parsed['path'] : '/';

        // Add query string if present
        if (!empty($parsed['query'])) {
            $uri .= '?' . $parsed['query'];
        }

        // Simulate the request URI
        $original_uri = $_SERVER['REQUEST_URI'];
        $_SERVER['REQUEST_URI'] = $uri;

        $cache_file = $this->get_cache_file_path();

        $_SERVER['REQUEST_URI'] = $original_uri;

        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
    }

    /**
     * Clear product cache when stock changes
     */
    public function clear_product_cache($product) {
        if (is_numeric($product)) {
            $product_id = $product;
        } else {
            $product_id = $product->get_id();
        }

        $this->clear_post_cache($product_id);
    }

    /**
     * Clear all page cache
     */
    public function clear_all_cache() {
        $this->recursive_delete($this->cache_dir);
        $this->ensure_cache_dir();

        return true;
    }

    /**
     * Recursively delete directory contents
     */
    private function recursive_delete($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursive_delete($path);
            } else {
                // Don't delete .htaccess and index.php
                if ($file !== '.htaccess' && $file !== 'index.php') {
                    @unlink($path);
                }
            }
        }

        // Only delete if it's a cache subdirectory
        if ($dir !== $this->cache_dir) {
            @rmdir($dir);
        }
    }

    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        // Make sure cache_dir is always set
        if (empty($this->cache_dir)) {
            $this->cache_dir = WP_CONTENT_DIR . '/cache/wcsu-page-cache/';
        }

        // Always try to create the directory when checking stats
        $this->ensure_cache_dir();

        $stats = array(
            'enabled' => $this->enabled,
            'cache_dir' => $this->cache_dir,
            'cache_ttl' => $this->cache_ttl,
            'total_files' => 0,
            'total_size' => 0,
            'oldest_file' => null,
            'newest_file' => null,
            'dir_exists' => is_dir($this->cache_dir),
            'dir_writable' => is_writable($this->cache_dir),
        );

        $stats['total_size_formatted'] = '0 B';

        if (!is_dir($this->cache_dir)) {
            return $stats;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $oldest_time = PHP_INT_MAX;
            $newest_time = 0;

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'html') {
                    $stats['total_files']++;
                    $stats['total_size'] += $file->getSize();

                    $mtime = $file->getMTime();
                    if ($mtime < $oldest_time) {
                        $oldest_time = $mtime;
                        $stats['oldest_file'] = date('Y-m-d H:i:s', $mtime);
                    }
                    if ($mtime > $newest_time) {
                        $newest_time = $mtime;
                        $stats['newest_file'] = date('Y-m-d H:i:s', $mtime);
                    }
                }
            }

            $stats['total_size_formatted'] = size_format($stats['total_size']);
        } catch (Exception $e) {
            // Directory iteration failed
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Test if caching is working by creating a test file
     */
    public function test_cache_write() {
        $this->ensure_cache_dir();

        if (!is_dir($this->cache_dir)) {
            return array('success' => false, 'message' => 'Cache directory does not exist and could not be created');
        }

        if (!is_writable($this->cache_dir)) {
            return array('success' => false, 'message' => 'Cache directory is not writable');
        }

        // Try to write a test file
        $test_file = $this->cache_dir . 'test_' . time() . '.html';
        $test_content = '<!-- WCSU Cache Test -->';

        $result = @file_put_contents($test_file, $test_content, LOCK_EX);

        if ($result === false) {
            return array('success' => false, 'message' => 'Could not write test file to cache directory');
        }

        // Clean up test file
        @unlink($test_file);

        return array('success' => true, 'message' => 'Cache directory is working correctly');
    }

    /**
     * Check if cache is writable
     */
    public function is_cache_writable() {
        $this->ensure_cache_dir();
        return is_writable($this->cache_dir);
    }

    /**
     * AJAX: Toggle page cache
     */
    public function ajax_toggle_page_cache() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $enable = isset($_POST['enable']) ? intval($_POST['enable']) : 0;

        $options = get_option('wcsu_options', array());
        $options['enable_page_cache'] = $enable;
        update_option('wcsu_options', $options);

        if ($enable) {
            $this->ensure_cache_dir();
            if (!$this->is_cache_writable()) {
                wp_send_json_error(__('Cache directory is not writable', 'wc-speedup'));
            }
            wp_send_json_success(__('Page cache enabled', 'wc-speedup'));
        } else {
            $this->clear_all_cache();
            wp_send_json_success(__('Page cache disabled and cleared', 'wc-speedup'));
        }
    }

    /**
     * AJAX: Clear page cache
     */
    public function ajax_clear_page_cache() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $this->clear_all_cache();

        wp_send_json_success(__('Page cache cleared successfully', 'wc-speedup'));
    }

    /**
     * AJAX: Get cache statistics
     */
    public function ajax_get_stats() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $stats = $this->get_cache_stats();
        wp_send_json_success($stats);
    }

    /**
     * AJAX: Test page cache functionality
     */
    public function ajax_test_cache() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $result = $this->test_cache_write();

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
