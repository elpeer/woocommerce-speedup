<?php
/**
 * WooCommerce Specific Optimizer - אופטימיזציות ייעודיות לווקומרס
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Woo_Optimizer {

    /**
     * Constructor
     */
    public function __construct() {
        // Only load if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_clear_wc_transients', array($this, 'ajax_clear_wc_transients'));
    }

    /**
     * Initialize WooCommerce optimizations
     */
    public function init() {
        $options = get_option('wcsu_options', array());

        // Disable cart fragments on non-cart pages
        if (!empty($options['disable_cart_fragments'])) {
            add_action('wp_enqueue_scripts', array($this, 'optimize_cart_fragments'), 999);
        }

        // Disable password strength meter
        if (!empty($options['disable_password_meter'])) {
            add_action('wp_print_scripts', array($this, 'disable_password_meter'), 100);
        }

        // Limit product variations loaded
        if (!empty($options['limit_variations'])) {
            add_filter('woocommerce_ajax_variation_threshold', array($this, 'limit_ajax_variations'));
        }

        // Disable WooCommerce styles on non-WC pages
        if (!empty($options['conditional_wc_assets'])) {
            add_action('wp_enqueue_scripts', array($this, 'conditional_wc_assets'), 99);
        }

        // Optimize product queries
        if (!empty($options['optimize_product_queries'])) {
            add_action('pre_get_posts', array($this, 'optimize_product_queries'));
        }

        // Disable WooCommerce admin features
        if (!empty($options['disable_wc_admin'])) {
            add_filter('woocommerce_admin_disabled', '__return_true');
        }

        // Limit order notes in admin
        if (!empty($options['limit_order_notes'])) {
            add_filter('woocommerce_order_notes', array($this, 'limit_order_notes'), 10, 2);
        }

        // Disable WooCommerce Analytics
        if (!empty($options['disable_wc_analytics'])) {
            add_filter('woocommerce_analytics_enabled', '__return_false');
        }

        // Optimize image sizes
        if (!empty($options['optimize_wc_images'])) {
            add_filter('woocommerce_get_image_size_gallery_thumbnail', array($this, 'optimize_gallery_thumbnails'));
        }

        // Reduce related products query
        if (!empty($options['limit_related_products'])) {
            add_filter('woocommerce_output_related_products_args', array($this, 'limit_related_products'));
        }

        // Disable WooCommerce status widget
        if (!empty($options['disable_status_widget'])) {
            add_action('wp_dashboard_setup', array($this, 'disable_status_widget'), 40);
        }

        // Optimize checkout
        if (!empty($options['optimize_checkout'])) {
            $this->optimize_checkout();
        }

        // Disable marketing hub
        if (!empty($options['disable_marketing_hub'])) {
            add_filter('woocommerce_marketing_menu_items', '__return_empty_array');
            add_filter('woocommerce_admin_features', array($this, 'disable_marketing_features'));
        }
    }

    /**
     * Optimize cart fragments
     */
    public function optimize_cart_fragments() {
        // Only dequeue on pages that don't need it
        if (!is_cart() && !is_checkout() && !is_woocommerce() && !is_account_page()) {
            wp_dequeue_script('wc-cart-fragments');
        }
    }

    /**
     * Disable password strength meter on account page
     */
    public function disable_password_meter() {
        if (wp_script_is('wc-password-strength-meter', 'enqueued')) {
            wp_dequeue_script('wc-password-strength-meter');
        }
    }

    /**
     * Limit AJAX variations threshold
     */
    public function limit_ajax_variations($threshold) {
        return 15; // Default is 30
    }

    /**
     * Conditionally load WooCommerce assets
     */
    public function conditional_wc_assets() {
        // Don't touch cart and checkout pages
        if (is_cart() || is_checkout() || is_woocommerce() || is_account_page()) {
            return;
        }

        // Check if we're on a page with WooCommerce shortcodes
        global $post;
        if ($post && (
            has_shortcode($post->post_content, 'products') ||
            has_shortcode($post->post_content, 'product') ||
            has_shortcode($post->post_content, 'woocommerce_cart') ||
            has_shortcode($post->post_content, 'woocommerce_checkout') ||
            has_shortcode($post->post_content, 'woocommerce_my_account')
        )) {
            return;
        }

        // Remove WooCommerce styles
        wp_dequeue_style('woocommerce-layout');
        wp_dequeue_style('woocommerce-smallscreen');
        wp_dequeue_style('woocommerce-general');
        wp_dequeue_style('wc-blocks-style');
        wp_dequeue_style('wc-blocks-vendors-style');

        // Remove WooCommerce scripts
        wp_dequeue_script('wc-add-to-cart');
        wp_dequeue_script('wc-cart-fragments');
        wp_dequeue_script('woocommerce');
        wp_dequeue_script('wc-add-to-cart-variation');
    }

    /**
     * Optimize product queries
     */
    public function optimize_product_queries($query) {
        if (!$query->is_main_query() || is_admin()) {
            return;
        }

        // Only apply to product archives
        if (!is_post_type_archive('product') && !is_tax('product_cat') && !is_tax('product_tag')) {
            return;
        }

        // Don't load unnecessary meta
        $query->set('no_found_rows', true);

        // Optimize for product listings
        $query->set('update_post_meta_cache', false);
        $query->set('update_post_term_cache', false);
    }

    /**
     * Limit order notes in admin
     */
    public function limit_order_notes($notes, $order) {
        if (count($notes) > 20) {
            return array_slice($notes, 0, 20);
        }
        return $notes;
    }

    /**
     * Optimize gallery thumbnails size
     */
    public function optimize_gallery_thumbnails($size) {
        $size['width'] = 100;
        $size['height'] = 100;
        $size['crop'] = 1;
        return $size;
    }

    /**
     * Limit related products
     */
    public function limit_related_products($args) {
        $args['posts_per_page'] = 4;
        return $args;
    }

    /**
     * Disable WooCommerce status dashboard widget
     */
    public function disable_status_widget() {
        remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');
        remove_meta_box('woocommerce_dashboard_recent_reviews', 'dashboard', 'normal');
    }

    /**
     * Optimize checkout page
     */
    private function optimize_checkout() {
        // Defer address autocomplete
        add_filter('woocommerce_checkout_fields', array($this, 'optimize_checkout_fields'));

        // Reduce checkout fragments refresh
        add_filter('woocommerce_update_order_review_fragments', array($this, 'reduce_checkout_fragments'));
    }

    /**
     * Optimize checkout fields
     */
    public function optimize_checkout_fields($fields) {
        // Remove unnecessary attributes
        foreach ($fields as $fieldset_key => $fieldset) {
            foreach ($fieldset as $key => $field) {
                // Remove autocomplete for speed
                if (isset($fields[$fieldset_key][$key]['autocomplete'])) {
                    // Keep only essential autocomplete
                    $keep_autocomplete = array(
                        'billing_email',
                        'billing_phone',
                        'billing_postcode',
                        'shipping_postcode'
                    );
                    if (!in_array($key, $keep_autocomplete)) {
                        unset($fields[$fieldset_key][$key]['autocomplete']);
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Reduce checkout fragments
     */
    public function reduce_checkout_fragments($fragments) {
        // Remove unnecessary fragments
        $unnecessary = array(
            '.woocommerce-checkout-review-order-table'
        );

        foreach ($unnecessary as $selector) {
            if (isset($fragments[$selector])) {
                // Only include if it's actually changed
            }
        }

        return $fragments;
    }

    /**
     * Disable marketing features
     */
    public function disable_marketing_features($features) {
        $disable = array(
            'marketing',
            'coupons',
            'homescreen',
            'navigation',
            'remote-inbox-notifications',
            'analytics'
        );

        return array_diff($features, $disable);
    }

    /**
     * Clear WooCommerce transients
     */
    public function clear_wc_transients() {
        if (!class_exists('WooCommerce')) {
            return 0;
        }

        $cleared = 0;

        // Clear product transients
        wc_delete_product_transients();
        $cleared++;

        // Clear shop order transients
        wc_delete_shop_order_transients();
        $cleared++;

        // Clear layered nav counts
        delete_transient('wc_layered_nav_counts');
        $cleared++;

        // Clear shipping transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_ship%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_ship%'");
        $cleared++;

        // Clear customer transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_customer%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_customer%'");
        $cleared++;

        // Clear term counts
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_term_counts'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_term_counts'");
        $cleared++;

        return $cleared;
    }

    /**
     * Get WooCommerce performance stats
     */
    public function get_wc_stats() {
        if (!class_exists('WooCommerce')) {
            return null;
        }

        global $wpdb;

        $stats = array();

        // Products count
        $products = wp_count_posts('product');
        $stats['products'] = array(
            'total' => $products->publish,
            'draft' => $products->draft,
            'pending' => $products->pending
        );

        // Orders count
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'")) {
            $stats['orders'] = array(
                'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
                'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE status = 'wc-pending'"),
                'processing' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE status = 'wc-processing'")
            );
        } else {
            $orders_count = wp_count_posts('shop_order');
            $stats['orders'] = array(
                'total' => array_sum((array) $orders_count),
                'pending' => isset($orders_count->{'wc-pending'}) ? $orders_count->{'wc-pending'} : 0,
                'processing' => isset($orders_count->{'wc-processing'}) ? $orders_count->{'wc-processing'} : 0
            );
        }

        // Variations count
        $variations = wp_count_posts('product_variation');
        $stats['variations'] = $variations->publish;

        // Categories count
        $stats['categories'] = wp_count_terms('product_cat');

        // Tags count
        $stats['tags'] = wp_count_terms('product_tag');

        // Sessions
        $stats['sessions'] = array(
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions"),
            'expired' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry < UNIX_TIMESTAMP()")
        );

        // Transients
        $stats['transients'] = array(
            'wc_transients' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_%'"),
            'wc_timeout' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_%'")
        );

        return $stats;
    }

    /**
     * Get slow WooCommerce queries
     */
    public function get_slow_wc_queries() {
        global $wpdb;

        $slow_queries = array();

        // Check for large postmeta on products
        $large_product_meta = $wpdb->get_results(
            "SELECT p.ID, p.post_title, COUNT(pm.meta_id) as meta_count, SUM(LENGTH(pm.meta_value)) as meta_size
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type IN ('product', 'product_variation')
             GROUP BY p.ID
             HAVING meta_count > 50 OR meta_size > 100000
             ORDER BY meta_size DESC
             LIMIT 20"
        );

        if ($large_product_meta) {
            $slow_queries['large_product_meta'] = $large_product_meta;
        }

        // Check for products with many variations
        $many_variations = $wpdb->get_results(
            "SELECT p.ID, p.post_title, COUNT(v.ID) as variation_count
             FROM {$wpdb->posts} p
             JOIN {$wpdb->posts} v ON v.post_parent = p.ID AND v.post_type = 'product_variation'
             WHERE p.post_type = 'product'
             GROUP BY p.ID
             HAVING variation_count > 30
             ORDER BY variation_count DESC
             LIMIT 20"
        );

        if ($many_variations) {
            $slow_queries['many_variations'] = $many_variations;
        }

        return $slow_queries;
    }

    /**
     * Optimize WooCommerce database tables
     */
    public function optimize_wc_tables() {
        if (!class_exists('WooCommerce')) {
            return array();
        }

        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'woocommerce_sessions',
            $wpdb->prefix . 'woocommerce_order_items',
            $wpdb->prefix . 'woocommerce_order_itemmeta',
            $wpdb->prefix . 'wc_orders',
            $wpdb->prefix . 'wc_orders_meta',
            $wpdb->prefix . 'wc_product_meta_lookup',
            $wpdb->prefix . 'wc_customer_lookup',
            $wpdb->prefix . 'wc_order_product_lookup',
            $wpdb->prefix . 'wc_order_stats'
        );

        $results = array();

        foreach ($tables as $table) {
            // Check if table exists
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$exists) {
                continue;
            }

            $result = $wpdb->query("OPTIMIZE TABLE `{$table}`");
            $results[$table] = $result !== false ? 'optimized' : 'failed';
        }

        return $results;
    }

    /**
     * AJAX handler for clearing WC transients
     */
    public function ajax_clear_wc_transients() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $cleared = $this->clear_wc_transients();

        wp_send_json_success(array(
            'cleared' => $cleared,
            'message' => sprintf(__('Cleared %d WooCommerce transient types', 'wc-speedup'), $cleared)
        ));
    }

    /**
     * Get optimization recommendations for WooCommerce
     */
    public function get_recommendations() {
        if (!class_exists('WooCommerce')) {
            return array();
        }

        $recommendations = array();
        $stats = $this->get_wc_stats();

        // Check products count
        if ($stats['products']['total'] > 10000) {
            $recommendations[] = array(
                'type' => 'warning',
                'title' => __('Large Product Catalog', 'wc-speedup'),
                'message' => sprintf(
                    __('You have %d products. Consider using pagination, lazy loading, and AJAX for product filtering.', 'wc-speedup'),
                    $stats['products']['total']
                )
            );
        }

        // Check variations
        if ($stats['variations'] > 5000) {
            $recommendations[] = array(
                'type' => 'warning',
                'title' => __('Many Variations', 'wc-speedup'),
                'message' => sprintf(
                    __('You have %d variations. Consider limiting variations per product to 30.', 'wc-speedup'),
                    $stats['variations']
                )
            );
        }

        // Check expired sessions
        if ($stats['sessions']['expired'] > 100) {
            $recommendations[] = array(
                'type' => 'action',
                'title' => __('Expired Sessions', 'wc-speedup'),
                'message' => sprintf(
                    __('You have %d expired sessions. Clean them to improve performance.', 'wc-speedup'),
                    $stats['sessions']['expired']
                ),
                'action' => 'clean_sessions'
            );
        }

        // Check transients
        if ($stats['transients']['wc_transients'] > 500) {
            $recommendations[] = array(
                'type' => 'action',
                'title' => __('WooCommerce Transients', 'wc-speedup'),
                'message' => sprintf(
                    __('You have %d WooCommerce transients. Consider clearing them.', 'wc-speedup'),
                    $stats['transients']['wc_transients']
                ),
                'action' => 'clear_wc_transients'
            );
        }

        // Check for HPOS
        if (!$this->is_hpos_enabled()) {
            $recommendations[] = array(
                'type' => 'info',
                'title' => __('High-Performance Order Storage', 'wc-speedup'),
                'message' => __('Consider enabling HPOS (High-Performance Order Storage) for better order handling.', 'wc-speedup')
            );
        }

        return $recommendations;
    }

    /**
     * Check if HPOS is enabled
     */
    private function is_hpos_enabled() {
        if (!class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
