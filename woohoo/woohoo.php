<?php
/**
 * Plugin Name: WooHoo - WooCommerce Performance Optimizer
 * Plugin URI: https://github.com/elpeer/woocommerce-speedup
 * Description: WooCommerce, but faster. WooHoo! Your shop on espresso: faster browsing, smoother checkout.
 * Version: 1.1.2
 * Author: ElPeer
 * Author URI: https://github.com/elpeer
 * Text Domain: woohoo
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WCSU_VERSION', '1.1.2');
define('WCSU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCSU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCSU_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WCSU_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
final class WC_SpeedUp {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Plugin modules
     */
    public $diagnostics;
    public $database;
    public $cache;
    public $page_cache;
    public $woo_optimizer;
    public $query_profiler;
    public $auto_optimizer;
    public $admin;

    // New performance modules
    public $cart_fragments;
    public $heartbeat;
    public $sessions_cleanup;
    public $transients_cleanup;
    public $lazy_loading;
    public $dns_prefetch;
    public $browser_caching;
    public $email_queue;

    // PageSpeed optimization modules
    public $defer_js;
    public $delay_js;
    public $remove_query_strings;
    public $font_optimization;
    public $minify_html;
    public $preload_resources;

    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core includes
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-diagnostics.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-database.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-cache.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-page-cache.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-woo-optimizer.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-query-profiler.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-auto-optimizer.php';

        // New performance modules
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-cart-fragments.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-heartbeat.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-sessions-cleanup.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-transients-cleanup.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-lazy-loading.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-dns-prefetch.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-browser-caching.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-email-queue.php';

        // PageSpeed optimization modules - TEMPORARILY DISABLED FOR DEBUGGING
        // Uncomment these lines once the issue is resolved
        /*
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-defer-js.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-delay-js.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-remove-query-strings.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-font-optimization.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-minify-html.php';
        require_once WCSU_PLUGIN_DIR . 'includes/class-wcsu-preload-resources.php';
        */

        // Admin includes
        if (is_admin()) {
            require_once WCSU_PLUGIN_DIR . 'admin/class-wcsu-admin.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init'), 0);
        add_action('init', array($this, 'load_textdomain'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize core modules
        $this->diagnostics = new WCSU_Diagnostics();
        $this->database = new WCSU_Database();
        $this->cache = new WCSU_Cache();
        $this->page_cache = new WCSU_Page_Cache();
        $this->woo_optimizer = new WCSU_Woo_Optimizer();
        $this->query_profiler = new WCSU_Query_Profiler();
        $this->auto_optimizer = new WCSU_Auto_Optimizer();

        // Initialize new performance modules
        $this->cart_fragments = new WCSU_Cart_Fragments();
        $this->heartbeat = new WCSU_Heartbeat();
        $this->sessions_cleanup = new WCSU_Sessions_Cleanup();
        $this->transients_cleanup = new WCSU_Transients_Cleanup();
        // $this->lazy_loading = new WCSU_Lazy_Loading(); // TEMPORARILY DISABLED
        $this->dns_prefetch = new WCSU_DNS_Prefetch();
        $this->browser_caching = new WCSU_Browser_Caching();
        $this->email_queue = new WCSU_Email_Queue();

        // Initialize PageSpeed optimization modules - TEMPORARILY DISABLED
        /*
        $this->defer_js = new WCSU_Defer_JS();
        $this->delay_js = new WCSU_Delay_JS();
        $this->remove_query_strings = new WCSU_Remove_Query_Strings();
        $this->font_optimization = new WCSU_Font_Optimization();
        $this->minify_html = new WCSU_Minify_HTML();
        $this->preload_resources = new WCSU_Preload_Resources();
        */

        if (is_admin()) {
            $this->admin = new WCSU_Admin();
        }

        // Apply optimizations if enabled
        $this->apply_optimizations();
    }

    /**
     * Load textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-speedup', false, dirname(WCSU_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Apply enabled optimizations
     */
    private function apply_optimizations() {
        $options = get_option('wcsu_options', array());

        // Disable cart fragments AJAX
        if (!empty($options['disable_cart_fragments'])) {
            add_action('wp_enqueue_scripts', array($this, 'disable_cart_fragments'), 99);
        }

        // Disable WooCommerce widgets
        if (!empty($options['disable_widgets'])) {
            add_action('widgets_init', array($this, 'disable_woo_widgets'), 99);
        }

        // Limit post revisions
        if (!empty($options['limit_revisions'])) {
            add_filter('wp_revisions_to_keep', array($this, 'limit_revisions'), 10, 2);
        }

        // Disable emojis
        if (!empty($options['disable_emojis'])) {
            $this->disable_emojis();
        }

        // Disable embeds
        if (!empty($options['disable_embeds'])) {
            $this->disable_embeds();
        }

        // Optimize heartbeat
        if (!empty($options['optimize_heartbeat'])) {
            add_filter('heartbeat_settings', array($this, 'optimize_heartbeat'));
        }

        // Lazy load images
        if (!empty($options['lazy_load'])) {
            add_filter('wp_get_attachment_image_attributes', array($this, 'add_lazy_load'), 10, 3);
        }

        // Disable query strings
        if (!empty($options['remove_query_strings'])) {
            add_filter('script_loader_src', array($this, 'remove_query_strings'), 15);
            add_filter('style_loader_src', array($this, 'remove_query_strings'), 15);
        }

        // Disable XML-RPC
        if (!empty($options['disable_xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
        }

        // Disable REST API for non-logged users
        if (!empty($options['restrict_rest_api'])) {
            add_filter('rest_authentication_errors', array($this, 'restrict_rest_api'));
        }

        // Preload key resources
        if (!empty($options['preload_resources'])) {
            add_action('wp_head', array($this, 'preload_resources'), 1);
        }
    }

    /**
     * Disable cart fragments
     */
    public function disable_cart_fragments() {
        if (!is_cart() && !is_checkout()) {
            wp_dequeue_script('wc-cart-fragments');
        }
    }

    /**
     * Disable WooCommerce widgets
     */
    public function disable_woo_widgets() {
        $widgets = array(
            'WC_Widget_Cart',
            'WC_Widget_Layered_Nav',
            'WC_Widget_Layered_Nav_Filters',
            'WC_Widget_Price_Filter',
            'WC_Widget_Product_Categories',
            'WC_Widget_Product_Search',
            'WC_Widget_Product_Tag_Cloud',
            'WC_Widget_Products',
            'WC_Widget_Recently_Viewed',
            'WC_Widget_Top_Rated_Products',
            'WC_Widget_Recent_Reviews',
            'WC_Widget_Rating_Filter'
        );

        foreach ($widgets as $widget) {
            unregister_widget($widget);
        }
    }

    /**
     * Limit post revisions
     */
    public function limit_revisions($num, $post) {
        return 3;
    }

    /**
     * Disable emojis
     */
    private function disable_emojis() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('tiny_mce_plugins', array($this, 'disable_emojis_tinymce'));
        add_filter('wp_resource_hints', array($this, 'disable_emojis_dns_prefetch'), 10, 2);
    }

    /**
     * Disable emojis in TinyMCE
     */
    public function disable_emojis_tinymce($plugins) {
        if (is_array($plugins)) {
            return array_diff($plugins, array('wpemoji'));
        }
        return array();
    }

    /**
     * Disable emoji DNS prefetch
     */
    public function disable_emojis_dns_prefetch($urls, $relation_type) {
        if ('dns-prefetch' === $relation_type) {
            $urls = array_filter($urls, function($url) {
                return strpos($url, 'https://s.w.org/images/core/emoji/') === false;
            });
        }
        return $urls;
    }

    /**
     * Disable embeds
     */
    private function disable_embeds() {
        remove_action('rest_api_init', 'wp_oembed_register_route');
        remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        add_filter('embed_oembed_discover', '__return_false');
        remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
        add_filter('rewrite_rules_array', array($this, 'disable_embeds_rewrites'));
    }

    /**
     * Disable embed rewrites
     */
    public function disable_embeds_rewrites($rules) {
        foreach ($rules as $rule => $rewrite) {
            if (strpos($rewrite, 'embed=true') !== false) {
                unset($rules[$rule]);
            }
        }
        return $rules;
    }

    /**
     * Optimize heartbeat
     */
    public function optimize_heartbeat($settings) {
        $settings['interval'] = 60;
        return $settings;
    }

    /**
     * Add lazy load attribute
     */
    public function add_lazy_load($attr, $attachment, $size) {
        if (!is_admin()) {
            $attr['loading'] = 'lazy';
        }
        return $attr;
    }

    /**
     * Remove query strings from static resources
     */
    public function remove_query_strings($src) {
        if (strpos($src, '?ver=') !== false) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }

    /**
     * Restrict REST API
     */
    public function restrict_rest_api($result) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', __('REST API restricted to authenticated users.', 'wc-speedup'), array('status' => 401));
        }
        return $result;
    }

    /**
     * Preload key resources
     */
    public function preload_resources() {
        $preloads = array(
            'fonts' => array(),
            'styles' => array(),
            'scripts' => array()
        );

        $preloads = apply_filters('wcsu_preload_resources', $preloads);

        foreach ($preloads as $type => $resources) {
            foreach ($resources as $resource) {
                switch ($type) {
                    case 'fonts':
                        echo '<link rel="preload" href="' . esc_url($resource) . '" as="font" type="font/woff2" crossorigin>' . "\n";
                        break;
                    case 'styles':
                        echo '<link rel="preload" href="' . esc_url($resource) . '" as="style">' . "\n";
                        break;
                    case 'scripts':
                        echo '<link rel="preload" href="' . esc_url($resource) . '" as="script">' . "\n";
                        break;
                }
            }
        }
    }

    /**
     * Activation hook
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'disable_cart_fragments' => 1,
            'disable_widgets' => 0,
            'limit_revisions' => 1,
            'disable_emojis' => 1,
            'disable_embeds' => 1,
            'optimize_heartbeat' => 1,
            'lazy_load' => 1,
            'remove_query_strings' => 1,
            'disable_xmlrpc' => 1,
            'restrict_rest_api' => 0,
            'preload_resources' => 0
        );

        if (!get_option('wcsu_options')) {
            update_option('wcsu_options', $default_options);
        }

        // Schedule cleanup cron
        if (!wp_next_scheduled('wcsu_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wcsu_daily_cleanup');
        }

        flush_rewrite_rules();
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wcsu_daily_cleanup');
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function wcsu() {
    return WC_SpeedUp::instance();
}

// Start the plugin
wcsu();
