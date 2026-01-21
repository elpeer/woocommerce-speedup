<?php
/**
 * Remove Query Strings - הסרת query strings מנכסים סטטיים
 *
 * מודול זה מסיר ?ver= ופרמטרים דומים מקבצי CSS ו-JS
 * כדי לשפר caching בדפדפן ו-CDN.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Remove_Query_Strings {

    /**
     * Settings
     */
    private $enabled = false;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_remove_query_strings']);

        if (!$this->enabled) {
            return;
        }

        // Don't run in admin
        if (is_admin()) {
            return;
        }

        // Remove query strings from scripts and styles
        add_filter('script_loader_src', array($this, 'remove_query_string'), 15);
        add_filter('style_loader_src', array($this, 'remove_query_string'), 15);
    }

    /**
     * Remove query string from URL
     */
    public function remove_query_string($src) {
        if (empty($src)) {
            return $src;
        }

        // Only process local resources or CDN resources
        // Don't modify external APIs that need query strings
        $skip_patterns = array(
            'googleapis.com/maps',
            'google.com/recaptcha',
            'facebook.com',
            'twitter.com',
            'api.',
            'ajax.',
        );

        foreach ($skip_patterns as $pattern) {
            if (strpos($src, $pattern) !== false) {
                return $src;
            }
        }

        // Check if URL has query string
        if (strpos($src, '?') === false) {
            return $src;
        }

        // Parse URL
        $parts = parse_url($src);

        if (!isset($parts['query'])) {
            return $src;
        }

        // Check if query string is just version
        parse_str($parts['query'], $query_params);

        // Only remove if query string is just version parameters
        $version_params = array('ver', 'v', 'version', 'rev');
        $has_only_version = true;

        foreach ($query_params as $key => $value) {
            if (!in_array($key, $version_params)) {
                $has_only_version = false;
                break;
            }
        }

        // If only version params, remove entire query string
        if ($has_only_version) {
            return strtok($src, '?');
        }

        // Otherwise, remove only version params but keep others
        foreach ($version_params as $param) {
            unset($query_params[$param]);
        }

        if (empty($query_params)) {
            return strtok($src, '?');
        }

        // Rebuild URL with remaining query params
        $base_url = strtok($src, '?');
        return $base_url . '?' . http_build_query($query_params);
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
        );
    }
}
