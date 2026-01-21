<?php
/**
 * Defer JavaScript - דחיית טעינת JavaScript
 *
 * מודול זה מוסיף defer לסקריפטים שחוסמים רינדור
 * כדי לשפר את ה-FCP וה-LCP.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Defer_JS {

    /**
     * Settings
     */
    private $enabled = false;
    private $defer_jquery = false;
    private $exclude_scripts = array();

    /**
     * Default scripts to exclude from defer
     */
    private $default_excludes = array(
        'jquery-core',
        'jquery',
        'jquery-migrate',
        'wc-cart-fragments', // Needs to run early for cart
    );

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_defer_js_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_defer_js']);
        $this->defer_jquery = !empty($options['defer_jquery']);

        // Custom excludes from settings
        if (!empty($options['defer_js_exclude'])) {
            $custom_excludes = array_map('trim', explode("\n", $options['defer_js_exclude']));
            $this->exclude_scripts = array_merge($this->default_excludes, $custom_excludes);
        } else {
            $this->exclude_scripts = $this->default_excludes;
        }

        // If defer jquery is disabled, keep it in excludes
        if (!$this->defer_jquery) {
            if (!in_array('jquery-core', $this->exclude_scripts)) {
                $this->exclude_scripts[] = 'jquery-core';
            }
            if (!in_array('jquery', $this->exclude_scripts)) {
                $this->exclude_scripts[] = 'jquery';
            }
        } else {
            // Remove jquery from excludes if defer is enabled
            $this->exclude_scripts = array_diff($this->exclude_scripts, array('jquery-core', 'jquery', 'jquery-migrate'));
        }

        if (!$this->enabled) {
            return;
        }

        // Don't run in admin
        if (is_admin()) {
            return;
        }

        // Add defer to script tags
        add_filter('script_loader_tag', array($this, 'add_defer_attribute'), 10, 3);
    }

    /**
     * Add defer attribute to scripts
     */
    public function add_defer_attribute($tag, $handle, $src) {
        // Skip if already has defer or async
        if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
            return $tag;
        }

        // Skip inline scripts
        if (empty($src)) {
            return $tag;
        }

        // Check if script should be excluded
        if ($this->should_exclude($handle, $src)) {
            return $tag;
        }

        // Add defer attribute
        $tag = str_replace(' src=', ' defer src=', $tag);

        return $tag;
    }

    /**
     * Check if script should be excluded from defer
     */
    private function should_exclude($handle, $src) {
        // Check handle
        foreach ($this->exclude_scripts as $exclude) {
            if (empty($exclude)) continue;

            // Check exact handle match
            if ($handle === $exclude) {
                return true;
            }

            // Check if handle contains exclude pattern
            if (strpos($handle, $exclude) !== false) {
                return true;
            }

            // Check if src contains exclude pattern
            if (strpos($src, $exclude) !== false) {
                return true;
            }
        }

        // Never defer WooCommerce critical scripts
        $wc_critical = array(
            'wc-',
            'woocommerce',
            'cart',
            'checkout',
            'add-to-cart',
            'selectWoo',
            'select2',
        );

        foreach ($wc_critical as $wc_script) {
            if (stripos($handle, $wc_script) !== false || stripos($src, $wc_script) !== false) {
                return true;
            }
        }

        // Don't defer scripts from certain critical sources
        $critical_sources = array(
            'recaptcha',
            'grecaptcha',
        );

        foreach ($critical_sources as $source) {
            if (strpos($src, $source) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of scripts that will be deferred
     */
    public function get_deferred_scripts() {
        global $wp_scripts;

        if (!is_object($wp_scripts)) {
            return array();
        }

        $deferred = array();
        foreach ($wp_scripts->registered as $handle => $script) {
            if (!empty($script->src) && !$this->should_exclude($handle, $script->src)) {
                $deferred[] = array(
                    'handle' => $handle,
                    'src' => $script->src,
                );
            }
        }

        return $deferred;
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'defer_jquery' => $this->defer_jquery,
            'exclude_count' => count($this->exclude_scripts),
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
        $options['enable_defer_js'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['defer_jquery'] = isset($_POST['defer_jquery']) ? intval($_POST['defer_jquery']) : 0;
        $options['defer_js_exclude'] = isset($_POST['exclude']) ? sanitize_textarea_field($_POST['exclude']) : '';

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'woohoo'));
    }
}
