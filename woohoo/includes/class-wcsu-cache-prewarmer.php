<?php
/**
 * Cache Prewarmer - חימום קאש ברקע
 *
 * מחמם את הקאש ברקע על כל העמודים באתר
 * בצורה מבוקרת שלא משפיעה על ביצועי האתר
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Cache_Prewarmer {

    /**
     * Option names
     */
    const OPTION_ENABLED = 'wcsu_prewarm_enabled';
    const OPTION_QUEUE = 'wcsu_prewarm_queue';
    const OPTION_STATUS = 'wcsu_prewarm_status';
    const OPTION_LAST_RUN = 'wcsu_prewarm_last_run';

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'wcsu_prewarm_cache_cron';

    /**
     * Settings
     */
    private $batch_size = 5; // URLs per batch
    private $delay_between_requests = 500000; // microseconds (0.5 sec)
    private $interval_between_batches = 60; // seconds

    /**
     * Constructor
     */
    public function __construct() {
        // Register cron hook
        add_action(self::CRON_HOOK, array($this, 'process_queue'));

        // Schedule cron on plugin activation
        add_action('init', array($this, 'maybe_schedule_cron'));

        // Clear queue on cache clear
        add_action('wcsu_cache_cleared', array($this, 'restart_prewarming'));

        // AJAX handlers
        add_action('wp_ajax_wcsu_start_prewarm', array($this, 'ajax_start_prewarm'));
        add_action('wp_ajax_wcsu_stop_prewarm', array($this, 'ajax_stop_prewarm'));
        add_action('wp_ajax_wcsu_get_prewarm_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_wcsu_toggle_auto_prewarm', array($this, 'ajax_toggle_auto_prewarm'));
    }

    /**
     * Maybe schedule cron
     */
    public function maybe_schedule_cron() {
        if (get_option(self::OPTION_ENABLED) && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'wcsu_prewarm_interval', self::CRON_HOOK);
        }
    }

    /**
     * Register custom cron interval
     */
    public static function register_cron_interval($schedules) {
        $schedules['wcsu_prewarm_interval'] = array(
            'interval' => 60, // Every minute
            'display' => __('Every Minute (WooHoo Prewarm)', 'wc-speedup')
        );
        return $schedules;
    }

    /**
     * Get all URLs to prewarm
     */
    public function get_all_urls() {
        $urls = array();

        // Home page
        $urls[] = home_url('/');

        // Shop page
        $shop_page_id = wc_get_page_id('shop');
        if ($shop_page_id > 0) {
            $urls[] = get_permalink($shop_page_id);
        }

        // All public pages
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        foreach ($pages as $page_id) {
            $urls[] = get_permalink($page_id);
        }

        // All products
        if (class_exists('WooCommerce')) {
            $products = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
            foreach ($products as $product_id) {
                $urls[] = get_permalink($product_id);
            }

            // Product categories
            $categories = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => true
            ));
            if (!is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    $urls[] = get_term_link($cat);
                }
            }

            // Product tags
            $tags = get_terms(array(
                'taxonomy' => 'product_tag',
                'hide_empty' => true
            ));
            if (!is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $urls[] = get_term_link($tag);
                }
            }
        }

        // Blog posts
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        foreach ($posts as $post_id) {
            $urls[] = get_permalink($post_id);
        }

        // Blog categories
        $blog_cats = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => true
        ));
        if (!is_wp_error($blog_cats)) {
            foreach ($blog_cats as $cat) {
                $urls[] = get_term_link($cat);
            }
        }

        // Remove duplicates and invalid URLs
        $urls = array_unique(array_filter($urls, function($url) {
            return !is_wp_error($url) && !empty($url);
        }));

        return array_values($urls);
    }

    /**
     * Start prewarming
     */
    public function start_prewarming() {
        // Get all URLs
        $urls = $this->get_all_urls();

        if (empty($urls)) {
            return false;
        }

        // Save queue
        update_option(self::OPTION_QUEUE, $urls);
        update_option(self::OPTION_STATUS, array(
            'total' => count($urls),
            'processed' => 0,
            'started' => time(),
            'running' => true,
            'last_url' => ''
        ));

        // Schedule immediate processing
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }

        return count($urls);
    }

    /**
     * Stop prewarming
     */
    public function stop_prewarming() {
        delete_option(self::OPTION_QUEUE);
        $status = get_option(self::OPTION_STATUS, array());
        $status['running'] = false;
        update_option(self::OPTION_STATUS, $status);
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Restart prewarming (after cache clear)
     */
    public function restart_prewarming() {
        if (get_option(self::OPTION_ENABLED)) {
            $this->start_prewarming();
        }
    }

    /**
     * Process queue (called by cron)
     */
    public function process_queue() {
        $queue = get_option(self::OPTION_QUEUE, array());
        $status = get_option(self::OPTION_STATUS, array());

        if (empty($queue)) {
            // Queue empty - prewarming complete
            $status['running'] = false;
            $status['completed'] = time();
            update_option(self::OPTION_STATUS, $status);
            update_option(self::OPTION_LAST_RUN, time());

            // If auto-prewarm is enabled, restart after interval
            if (get_option(self::OPTION_ENABLED)) {
                wp_schedule_single_event(time() + 3600, self::CRON_HOOK); // Restart in 1 hour
            }
            return;
        }

        // Get batch
        $batch = array_splice($queue, 0, $this->batch_size);

        // Process batch
        foreach ($batch as $url) {
            $this->warm_url($url);
            $status['processed']++;
            $status['last_url'] = $url;

            // Delay between requests
            usleep($this->delay_between_requests);
        }

        // Update queue and status
        update_option(self::OPTION_QUEUE, $queue);
        update_option(self::OPTION_STATUS, $status);

        // Schedule next batch if queue not empty
        if (!empty($queue)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK); // 5 seconds between batches
        }
    }

    /**
     * Warm a single URL
     */
    private function warm_url($url) {
        // Use non-blocking request with short timeout
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'sslverify' => false,
            'blocking' => true,
            'user-agent' => 'WooHoo-Cache-Prewarmer/1.0',
            'cookies' => array(
                'allow-cookies' => '1' // Simulate accepted cookie consent
            )
        ));

        return !is_wp_error($response);
    }

    /**
     * Get current status
     */
    public function get_status() {
        $status = get_option(self::OPTION_STATUS, array(
            'total' => 0,
            'processed' => 0,
            'running' => false,
            'last_url' => ''
        ));

        $queue = get_option(self::OPTION_QUEUE, array());
        $status['remaining'] = count($queue);
        $status['auto_enabled'] = (bool) get_option(self::OPTION_ENABLED);
        $status['last_run'] = get_option(self::OPTION_LAST_RUN, 0);

        if ($status['total'] > 0) {
            $status['progress'] = round(($status['processed'] / $status['total']) * 100);
        } else {
            $status['progress'] = 0;
        }

        return $status;
    }

    /**
     * AJAX: Start prewarm
     */
    public function ajax_start_prewarm() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $count = $this->start_prewarming();

        if ($count) {
            wp_send_json_success(array(
                'message' => sprintf(__('Started prewarming %d pages', 'wc-speedup'), $count),
                'total' => $count
            ));
        } else {
            wp_send_json_error(__('No pages to prewarm', 'wc-speedup'));
        }
    }

    /**
     * AJAX: Stop prewarm
     */
    public function ajax_stop_prewarm() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $this->stop_prewarming();
        wp_send_json_success(__('Prewarming stopped', 'wc-speedup'));
    }

    /**
     * AJAX: Get status
     */
    public function ajax_get_status() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        wp_send_json_success($this->get_status());
    }

    /**
     * AJAX: Toggle auto prewarm
     */
    public function ajax_toggle_auto_prewarm() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;
        update_option(self::OPTION_ENABLED, $enabled);

        if ($enabled) {
            $this->start_prewarming();
            $message = __('Auto prewarm enabled', 'wc-speedup');
        } else {
            $this->stop_prewarming();
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $message = __('Auto prewarm disabled', 'wc-speedup');
        }

        wp_send_json_success($message);
    }
}

// Register cron interval
add_filter('cron_schedules', array('WCSU_Cache_Prewarmer', 'register_cron_interval'));
