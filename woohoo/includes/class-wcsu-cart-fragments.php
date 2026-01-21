<?php
/**
 * Cart Fragments Optimizer - אופטימיזציית Cart Fragments של WooCommerce
 *
 * WooCommerce שולח בקשת AJAX בכל טעינת דף לעדכון העגלה.
 * מודול זה מאפשר להשבית או לייעל את הפונקציונליות הזו.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Cart_Fragments {

    /**
     * Settings
     */
    private $enabled = false;
    private $mode = 'defer'; // defer, disable, or optimize

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_cart_fragments_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_cart_fragments_optimizer']);
        $this->mode = isset($options['cart_fragments_mode']) ? $options['cart_fragments_mode'] : 'defer';

        if (!$this->enabled) {
            return;
        }

        // Apply optimization based on mode
        switch ($this->mode) {
            case 'disable':
                // Completely disable cart fragments
                add_action('wp_enqueue_scripts', array($this, 'disable_cart_fragments'), 99);
                break;

            case 'defer':
                // Defer loading - only load after user interaction
                add_action('wp_enqueue_scripts', array($this, 'defer_cart_fragments'), 99);
                break;

            case 'optimize':
                // Optimize - reduce frequency and use localStorage
                add_action('wp_enqueue_scripts', array($this, 'optimize_cart_fragments'), 99);
                break;
        }
    }

    /**
     * Disable cart fragments completely
     */
    public function disable_cart_fragments() {
        // Don't disable on cart or checkout pages
        if (function_exists('is_cart') && is_cart()) {
            return;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return;
        }

        wp_dequeue_script('wc-cart-fragments');
    }

    /**
     * Defer cart fragments loading
     */
    public function defer_cart_fragments() {
        // Don't modify on cart or checkout pages
        if (function_exists('is_cart') && is_cart()) {
            return;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return;
        }

        wp_dequeue_script('wc-cart-fragments');

        // Add deferred loading script
        wp_add_inline_script('jquery', $this->get_deferred_script());
    }

    /**
     * Get deferred loading script
     */
    private function get_deferred_script() {
        return "
        (function($) {
            var cartFragmentsLoaded = false;
            var wcCartFragmentsUrl = '" . esc_url(WC()->plugin_url() . '/assets/js/frontend/cart-fragments.min.js') . "';

            function loadCartFragments() {
                if (cartFragmentsLoaded) return;
                cartFragmentsLoaded = true;

                var script = document.createElement('script');
                script.src = wcCartFragmentsUrl;
                script.onload = function() {
                    $(document.body).trigger('wc_fragment_refresh');
                };
                document.body.appendChild(script);
            }

            // Load on user interaction
            ['mouseover', 'touchstart', 'scroll', 'keydown'].forEach(function(event) {
                document.addEventListener(event, function handler() {
                    loadCartFragments();
                    document.removeEventListener(event, handler);
                }, { once: true, passive: true });
            });

            // Also load if cart widget is visible
            if ($('.widget_shopping_cart').length && $('.widget_shopping_cart').is(':visible')) {
                setTimeout(loadCartFragments, 2000);
            }
        })(jQuery);
        ";
    }

    /**
     * Optimize cart fragments with localStorage
     */
    public function optimize_cart_fragments() {
        // Don't modify on cart or checkout pages
        if (function_exists('is_cart') && is_cart()) {
            return;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return;
        }

        // Add optimization script after cart-fragments
        wp_add_inline_script('wc-cart-fragments', $this->get_optimization_script(), 'before');
    }

    /**
     * Get optimization script
     */
    private function get_optimization_script() {
        return "
        (function($) {
            // Use localStorage to reduce AJAX calls
            var cacheKey = 'wcsu_cart_fragments';
            var cacheTime = 'wcsu_cart_fragments_time';
            var cacheDuration = 300000; // 5 minutes

            // Check if we have cached fragments
            var cachedFragments = localStorage.getItem(cacheKey);
            var cachedTime = localStorage.getItem(cacheTime);

            if (cachedFragments && cachedTime) {
                var now = new Date().getTime();
                if (now - parseInt(cachedTime) < cacheDuration) {
                    // Use cached fragments
                    try {
                        var fragments = JSON.parse(cachedFragments);
                        $.each(fragments, function(key, value) {
                            $(key).replaceWith(value);
                        });
                    } catch(e) {}
                }
            }

            // Cache new fragments when they arrive
            $(document.body).on('wc_fragments_refreshed', function() {
                var fragments = sessionStorage.getItem(wc_cart_fragments_params.fragment_name);
                if (fragments) {
                    localStorage.setItem(cacheKey, fragments);
                    localStorage.setItem(cacheTime, new Date().getTime().toString());
                }
            });
        })(jQuery);
        ";
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'mode' => $this->mode,
            'mode_label' => $this->get_mode_label($this->mode),
        );
    }

    /**
     * Get mode label
     */
    private function get_mode_label($mode) {
        $labels = array(
            'disable' => 'מושבת לחלוטין',
            'defer' => 'טעינה מושהית',
            'optimize' => 'מותאם עם localStorage',
        );
        return isset($labels[$mode]) ? $labels[$mode] : $mode;
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $options = get_option('wcsu_options', array());
        $options['enable_cart_fragments_optimizer'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['cart_fragments_mode'] = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'defer';

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }
}
