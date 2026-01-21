<?php
/**
 * Preload Critical Resources - טעינה מוקדמת של משאבים קריטיים
 *
 * מודול זה מוסיף preload hints לתמונות LCP, פונטים ומשאבים קריטיים אחרים.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Preload_Resources {

    /**
     * Settings
     */
    private $enabled = false;
    private $preload_featured_image = true;
    private $preload_logo = true;
    private $custom_preloads = array();

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_preload_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_preload_resources']);
        $this->preload_featured_image = !isset($options['preload_featured_image']) || !empty($options['preload_featured_image']);
        $this->preload_logo = !isset($options['preload_logo']) || !empty($options['preload_logo']);

        if (!empty($options['custom_preloads'])) {
            $this->custom_preloads = array_filter(array_map('trim', explode("\n", $options['custom_preloads'])));
        }

        if (!$this->enabled) {
            return;
        }

        // Don't run in admin
        if (is_admin()) {
            return;
        }

        // Add preload hints early in head
        add_action('wp_head', array($this, 'add_preload_hints'), 1);

        // Add preconnect hints
        add_action('wp_head', array($this, 'add_preconnect_hints'), 1);
    }

    /**
     * Add preload hints
     */
    public function add_preload_hints() {
        // Preload featured image (likely LCP image)
        if ($this->preload_featured_image && is_singular()) {
            $this->preload_featured_image_func();
        }

        // Preload WooCommerce product image
        if ($this->preload_featured_image && function_exists('is_product') && is_product()) {
            $this->preload_product_image();
        }

        // Preload logo
        if ($this->preload_logo) {
            $this->preload_site_logo();
        }

        // Custom preloads
        foreach ($this->custom_preloads as $url) {
            if (empty($url)) continue;
            $this->output_preload_tag($url);
        }
    }

    /**
     * Preload featured image
     */
    private function preload_featured_image_func() {
        if (!has_post_thumbnail()) {
            return;
        }

        $thumbnail_id = get_post_thumbnail_id();
        $image_src = wp_get_attachment_image_src($thumbnail_id, 'large');

        if ($image_src && !empty($image_src[0])) {
            $this->output_preload_tag($image_src[0], 'image', true);
        }
    }

    /**
     * Preload WooCommerce product image
     */
    private function preload_product_image() {
        global $product;

        if (!$product) {
            $product = wc_get_product(get_the_ID());
        }

        if (!$product) {
            return;
        }

        $image_id = $product->get_image_id();

        if ($image_id) {
            $image_src = wp_get_attachment_image_src($image_id, 'woocommerce_single');
            if ($image_src && !empty($image_src[0])) {
                $this->output_preload_tag($image_src[0], 'image', true);
            }
        }
    }

    /**
     * Preload site logo
     */
    private function preload_site_logo() {
        // Check for custom logo
        $custom_logo_id = get_theme_mod('custom_logo');

        if ($custom_logo_id) {
            $logo_src = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo_src && !empty($logo_src[0])) {
                $this->output_preload_tag($logo_src[0], 'image');
            }
        }
    }

    /**
     * Output preload tag
     */
    private function output_preload_tag($url, $as = null, $fetchpriority = false) {
        if (empty($url)) {
            return;
        }

        // Auto-detect resource type
        if (!$as) {
            $as = $this->detect_resource_type($url);
        }

        $attrs = array(
            'rel' => 'preload',
            'href' => esc_url($url),
            'as' => $as,
        );

        // Add type for fonts
        if ($as === 'font') {
            $attrs['type'] = $this->detect_font_type($url);
            $attrs['crossorigin'] = '';
        }

        // Add fetchpriority for LCP images
        if ($fetchpriority && $as === 'image') {
            $attrs['fetchpriority'] = 'high';
        }

        // Build tag
        $tag = '<link';
        foreach ($attrs as $name => $value) {
            if ($value === '') {
                $tag .= ' ' . $name;
            } else {
                $tag .= ' ' . $name . '="' . esc_attr($value) . '"';
            }
        }
        $tag .= '>' . "\n";

        echo $tag;
    }

    /**
     * Detect resource type from URL
     */
    private function detect_resource_type($url) {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        switch ($extension) {
            case 'css':
                return 'style';
            case 'js':
                return 'script';
            case 'woff':
            case 'woff2':
            case 'ttf':
            case 'otf':
            case 'eot':
                return 'font';
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
            case 'avif':
            case 'svg':
                return 'image';
            default:
                return 'fetch';
        }
    }

    /**
     * Detect font type
     */
    private function detect_font_type($url) {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        switch ($extension) {
            case 'woff2':
                return 'font/woff2';
            case 'woff':
                return 'font/woff';
            case 'ttf':
                return 'font/ttf';
            case 'otf':
                return 'font/otf';
            default:
                return 'font/woff2';
        }
    }

    /**
     * Add preconnect hints for common external resources
     */
    public function add_preconnect_hints() {
        $preconnect_domains = array(
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
        );

        // Add custom domains from content
        $options = get_option('wcsu_options', array());
        if (!empty($options['preconnect_domains'])) {
            $custom_domains = array_filter(array_map('trim', explode("\n", $options['preconnect_domains'])));
            $preconnect_domains = array_merge($preconnect_domains, $custom_domains);
        }

        foreach ($preconnect_domains as $domain) {
            if (empty($domain)) continue;

            // Check if crossorigin is needed
            $crossorigin = (strpos($domain, 'fonts.gstatic.com') !== false) ? ' crossorigin' : '';

            echo '<link rel="preconnect" href="' . esc_url($domain) . '"' . $crossorigin . '>' . "\n";
        }
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'preload_featured_image' => $this->preload_featured_image,
            'preload_logo' => $this->preload_logo,
            'custom_preloads_count' => count($this->custom_preloads),
        );
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'woohoo'));
        }

        $options = get_option('wcsu_options', array());
        $options['enable_preload_resources'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['preload_featured_image'] = isset($_POST['preload_featured_image']) ? intval($_POST['preload_featured_image']) : 1;
        $options['preload_logo'] = isset($_POST['preload_logo']) ? intval($_POST['preload_logo']) : 1;
        $options['custom_preloads'] = isset($_POST['custom_preloads']) ? sanitize_textarea_field($_POST['custom_preloads']) : '';
        $options['preconnect_domains'] = isset($_POST['preconnect_domains']) ? sanitize_textarea_field($_POST['preconnect_domains']) : '';

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'woohoo'));
    }
}
