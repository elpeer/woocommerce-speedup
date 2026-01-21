<?php
/**
 * Browser Caching - כותרות קאש לדפדפן
 *
 * מודול זה מגדיר כותרות Cache-Control ו-Expires
 * לקבצים סטטיים כדי לאפשר קאש בדפדפן.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Browser_Caching {

    /**
     * Settings
     */
    private $enabled = false;
    private $html_ttl = 0;          // seconds (0 = no cache)
    private $css_js_ttl = 2592000;  // 30 days
    private $images_ttl = 31536000; // 1 year
    private $fonts_ttl = 31536000;  // 1 year

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_browser_caching_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_wcsu_generate_htaccess', array($this, 'ajax_generate_htaccess'));
        add_action('wp_ajax_wcsu_remove_htaccess', array($this, 'ajax_remove_htaccess'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_browser_caching']);
        $this->html_ttl = isset($options['browser_cache_html']) ? intval($options['browser_cache_html']) : 0;
        $this->css_js_ttl = isset($options['browser_cache_css_js']) ? intval($options['browser_cache_css_js']) : 2592000;
        $this->images_ttl = isset($options['browser_cache_images']) ? intval($options['browser_cache_images']) : 31536000;
        $this->fonts_ttl = isset($options['browser_cache_fonts']) ? intval($options['browser_cache_fonts']) : 31536000;

        if (!$this->enabled) {
            return;
        }

        // Add cache headers for HTML pages via PHP
        add_action('send_headers', array($this, 'add_html_cache_headers'));
    }

    /**
     * Add cache headers for HTML pages
     */
    public function add_html_cache_headers() {
        // Don't add headers for admin, logged in users, or POST requests
        if (is_admin() || is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }

        // Don't cache cart, checkout, my-account pages
        if (function_exists('is_cart') && is_cart()) {
            return;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return;
        }

        if ($this->html_ttl > 0) {
            header('Cache-Control: public, max-age=' . $this->html_ttl);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $this->html_ttl) . ' GMT');
        }
    }

    /**
     * Generate .htaccess rules
     */
    public function generate_htaccess_rules() {
        $rules = "\n# BEGIN WCSU Browser Caching\n";
        $rules .= "<IfModule mod_expires.c>\n";
        $rules .= "    ExpiresActive On\n\n";

        // HTML
        if ($this->html_ttl > 0) {
            $rules .= "    # HTML\n";
            $rules .= "    ExpiresByType text/html \"access plus " . $this->seconds_to_htaccess_time($this->html_ttl) . "\"\n\n";
        }

        // CSS & JavaScript
        $rules .= "    # CSS & JavaScript\n";
        $rules .= "    ExpiresByType text/css \"access plus " . $this->seconds_to_htaccess_time($this->css_js_ttl) . "\"\n";
        $rules .= "    ExpiresByType application/javascript \"access plus " . $this->seconds_to_htaccess_time($this->css_js_ttl) . "\"\n";
        $rules .= "    ExpiresByType text/javascript \"access plus " . $this->seconds_to_htaccess_time($this->css_js_ttl) . "\"\n\n";

        // Images
        $rules .= "    # Images\n";
        $rules .= "    ExpiresByType image/jpeg \"access plus " . $this->seconds_to_htaccess_time($this->images_ttl) . "\"\n";
        $rules .= "    ExpiresByType image/png \"access plus " . $this->seconds_to_htaccess_time($this->images_ttl) . "\"\n";
        $rules .= "    ExpiresByType image/gif \"access plus " . $this->seconds_to_htaccess_time($this->images_ttl) . "\"\n";
        $rules .= "    ExpiresByType image/webp \"access plus " . $this->seconds_to_htaccess_time($this->images_ttl) . "\"\n";
        $rules .= "    ExpiresByType image/svg+xml \"access plus " . $this->seconds_to_htaccess_time($this->images_ttl) . "\"\n";
        $rules .= "    ExpiresByType image/x-icon \"access plus " . $this->seconds_to_htaccess_time($this->images_ttl) . "\"\n\n";

        // Fonts
        $rules .= "    # Fonts\n";
        $rules .= "    ExpiresByType font/woff \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n";
        $rules .= "    ExpiresByType font/woff2 \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n";
        $rules .= "    ExpiresByType application/font-woff \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n";
        $rules .= "    ExpiresByType application/font-woff2 \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n";
        $rules .= "    ExpiresByType application/vnd.ms-fontobject \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n";
        $rules .= "    ExpiresByType font/ttf \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n";
        $rules .= "    ExpiresByType font/otf \"access plus " . $this->seconds_to_htaccess_time($this->fonts_ttl) . "\"\n\n";

        $rules .= "</IfModule>\n\n";

        // Cache-Control headers
        $rules .= "<IfModule mod_headers.c>\n";

        // CSS & JS
        $rules .= "    <FilesMatch \"\\.(css|js)$\">\n";
        $rules .= "        Header set Cache-Control \"public, max-age=" . $this->css_js_ttl . "\"\n";
        $rules .= "    </FilesMatch>\n\n";

        // Images
        $rules .= "    <FilesMatch \"\\.(jpg|jpeg|png|gif|webp|svg|ico)$\">\n";
        $rules .= "        Header set Cache-Control \"public, max-age=" . $this->images_ttl . "\"\n";
        $rules .= "    </FilesMatch>\n\n";

        // Fonts
        $rules .= "    <FilesMatch \"\\.(woff|woff2|ttf|otf|eot)$\">\n";
        $rules .= "        Header set Cache-Control \"public, max-age=" . $this->fonts_ttl . "\"\n";
        $rules .= "    </FilesMatch>\n";

        $rules .= "</IfModule>\n";
        $rules .= "# END WCSU Browser Caching\n";

        return $rules;
    }

    /**
     * Convert seconds to htaccess time format
     */
    private function seconds_to_htaccess_time($seconds) {
        if ($seconds >= 31536000) {
            $years = floor($seconds / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '');
        } elseif ($seconds >= 2592000) {
            $months = floor($seconds / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '');
        } elseif ($seconds >= 86400) {
            $days = floor($seconds / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '');
        } elseif ($seconds >= 3600) {
            $hours = floor($seconds / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '');
        } else {
            return $seconds . ' seconds';
        }
    }

    /**
     * Add rules to .htaccess
     */
    public function add_htaccess_rules() {
        $htaccess_file = ABSPATH . '.htaccess';

        if (!is_writable($htaccess_file)) {
            return array(
                'success' => false,
                'message' => '.htaccess file is not writable',
            );
        }

        // Remove existing rules first
        $this->remove_htaccess_rules();

        $rules = $this->generate_htaccess_rules();
        $current_content = file_get_contents($htaccess_file);

        // Add rules at the beginning (after WordPress block if exists)
        if (strpos($current_content, '# BEGIN WordPress') !== false) {
            $current_content = preg_replace(
                '/(# BEGIN WordPress)/',
                $rules . "\n$1",
                $current_content
            );
        } else {
            $current_content = $rules . $current_content;
        }

        $result = file_put_contents($htaccess_file, $current_content);

        if ($result === false) {
            return array(
                'success' => false,
                'message' => 'Failed to write to .htaccess',
            );
        }

        return array(
            'success' => true,
            'message' => 'Browser caching rules added to .htaccess',
        );
    }

    /**
     * Remove rules from .htaccess
     */
    public function remove_htaccess_rules() {
        $htaccess_file = ABSPATH . '.htaccess';

        if (!file_exists($htaccess_file)) {
            return array(
                'success' => true,
                'message' => '.htaccess file does not exist',
            );
        }

        if (!is_writable($htaccess_file)) {
            return array(
                'success' => false,
                'message' => '.htaccess file is not writable',
            );
        }

        $content = file_get_contents($htaccess_file);

        // Remove our rules block
        $content = preg_replace(
            '/\n?# BEGIN WCSU Browser Caching.*?# END WCSU Browser Caching\n?/s',
            '',
            $content
        );

        file_put_contents($htaccess_file, $content);

        return array(
            'success' => true,
            'message' => 'Browser caching rules removed from .htaccess',
        );
    }

    /**
     * Check if rules are in .htaccess
     */
    public function has_htaccess_rules() {
        $htaccess_file = ABSPATH . '.htaccess';

        if (!file_exists($htaccess_file)) {
            return false;
        }

        $content = file_get_contents($htaccess_file);
        return strpos($content, '# BEGIN WCSU Browser Caching') !== false;
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'html_ttl' => $this->html_ttl,
            'css_js_ttl' => $this->css_js_ttl,
            'images_ttl' => $this->images_ttl,
            'fonts_ttl' => $this->fonts_ttl,
            'htaccess_writable' => is_writable(ABSPATH . '.htaccess'),
            'has_htaccess_rules' => $this->has_htaccess_rules(),
        );
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
        $options['enable_browser_caching'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['browser_cache_html'] = isset($_POST['html_ttl']) ? intval($_POST['html_ttl']) : 0;
        $options['browser_cache_css_js'] = isset($_POST['css_js_ttl']) ? intval($_POST['css_js_ttl']) : 2592000;
        $options['browser_cache_images'] = isset($_POST['images_ttl']) ? intval($_POST['images_ttl']) : 31536000;
        $options['browser_cache_fonts'] = isset($_POST['fonts_ttl']) ? intval($_POST['fonts_ttl']) : 31536000;

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }

    /**
     * AJAX: Generate .htaccess rules
     */
    public function ajax_generate_htaccess() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        // Reload settings
        $options = get_option('wcsu_options', array());
        $this->css_js_ttl = isset($options['browser_cache_css_js']) ? intval($options['browser_cache_css_js']) : 2592000;
        $this->images_ttl = isset($options['browser_cache_images']) ? intval($options['browser_cache_images']) : 31536000;
        $this->fonts_ttl = isset($options['browser_cache_fonts']) ? intval($options['browser_cache_fonts']) : 31536000;

        $result = $this->add_htaccess_rules();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Remove .htaccess rules
     */
    public function ajax_remove_htaccess() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $result = $this->remove_htaccess_rules();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
