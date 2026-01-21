<?php
/**
 * Font Optimization - אופטימיזציית פונטים
 *
 * מודול זה מוסיף font-display: swap לפונטים,
 * מבצע preload לפונטים קריטיים, ומייעל Google Fonts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Font_Optimization {

    /**
     * Settings
     */
    private $enabled = false;
    private $preload_fonts = array();
    private $local_google_fonts = false;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_font_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_font_optimization']);
        $this->local_google_fonts = !empty($options['local_google_fonts']);

        if (!empty($options['preload_fonts'])) {
            $this->preload_fonts = array_filter(array_map('trim', explode("\n", $options['preload_fonts'])));
        }

        if (!$this->enabled) {
            return;
        }

        // Don't run in admin
        if (is_admin()) {
            return;
        }

        // Add font-display: swap to inline styles
        add_action('wp_head', array($this, 'add_font_display_swap'), 1);

        // Preload fonts
        add_action('wp_head', array($this, 'preload_fonts'), 2);

        // Optimize Google Fonts
        add_filter('style_loader_tag', array($this, 'optimize_google_fonts'), 10, 4);

        // Add font-display to @font-face in output
        add_action('template_redirect', array($this, 'start_buffer'), 3);
    }

    /**
     * Add font-display: swap CSS
     */
    public function add_font_display_swap() {
        ?>
        <style id="woohoo-font-display">
            /* Force font-display: swap for all fonts */
            @font-face { font-display: swap !important; }
        </style>
        <?php
    }

    /**
     * Preload fonts
     */
    public function preload_fonts() {
        // Auto-detect fonts from theme/plugins
        $fonts_to_preload = $this->preload_fonts;

        // Output preload links
        foreach ($fonts_to_preload as $font_url) {
            if (empty($font_url)) continue;

            // Determine font type
            $type = 'font/woff2';
            if (strpos($font_url, '.woff2') !== false) {
                $type = 'font/woff2';
            } elseif (strpos($font_url, '.woff') !== false) {
                $type = 'font/woff';
            } elseif (strpos($font_url, '.ttf') !== false) {
                $type = 'font/ttf';
            } elseif (strpos($font_url, '.otf') !== false) {
                $type = 'font/otf';
            }

            echo '<link rel="preload" href="' . esc_url($font_url) . '" as="font" type="' . esc_attr($type) . '" crossorigin>' . "\n";
        }
    }

    /**
     * Optimize Google Fonts loading
     */
    public function optimize_google_fonts($tag, $handle, $href, $media) {
        // Check if this is a Google Fonts stylesheet
        if (strpos($href, 'fonts.googleapis.com') === false) {
            return $tag;
        }

        // Add display=swap if not present
        if (strpos($href, 'display=') === false) {
            $href = add_query_arg('display', 'swap', $href);
            $tag = str_replace($this->get_original_href($tag), $href, $tag);
        }

        // Add preconnect hints
        static $preconnect_added = false;
        if (!$preconnect_added) {
            $preconnect_added = true;
            $preconnect = '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
            $preconnect .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
            $tag = $preconnect . $tag;
        }

        return $tag;
    }

    /**
     * Extract href from link tag
     */
    private function get_original_href($tag) {
        preg_match('/href=["\']([^"\']+)["\']/i', $tag, $matches);
        return isset($matches[1]) ? $matches[1] : '';
    }

    /**
     * Start output buffer
     */
    public function start_buffer() {
        ob_start(array($this, 'process_html'));
    }

    /**
     * Process HTML to add font-display: swap to @font-face
     */
    public function process_html($html) {
        if (empty($html)) {
            return $html;
        }

        // Add font-display: swap to @font-face declarations that don't have it
        $html = preg_replace_callback(
            '/@font-face\s*\{([^}]+)\}/is',
            function($matches) {
                $content = $matches[1];

                // Check if font-display is already present
                if (stripos($content, 'font-display') !== false) {
                    return $matches[0];
                }

                // Add font-display: swap before the closing brace
                return '@font-face {' . $content . 'font-display: swap;' . '}';
            },
            $html
        );

        return $html;
    }

    /**
     * Get list of detected fonts
     */
    public function detect_fonts() {
        $fonts = array();

        // This would need to analyze stylesheets to detect fonts
        // For now, return common font locations

        $upload_dir = wp_upload_dir();
        $theme_dir = get_template_directory_uri();

        // Common font locations
        $fonts[] = array(
            'name' => 'Theme fonts',
            'path' => $theme_dir . '/fonts/',
        );

        return $fonts;
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'preload_count' => count($this->preload_fonts),
            'local_google_fonts' => $this->local_google_fonts,
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
        $options['enable_font_optimization'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['preload_fonts'] = isset($_POST['preload_fonts']) ? sanitize_textarea_field($_POST['preload_fonts']) : '';
        $options['local_google_fonts'] = isset($_POST['local_google_fonts']) ? intval($_POST['local_google_fonts']) : 0;

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'woohoo'));
    }
}
