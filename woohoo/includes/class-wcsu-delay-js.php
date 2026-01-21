<?php
/**
 * Delay JavaScript - עיכוב טעינת JavaScript עד אינטראקציה
 *
 * מודול זה מעכב טעינת סקריפטים לא קריטיים עד שהמשתמש
 * מתחיל לגלול, ללחוץ או להקליד. מצוין לסקריפטים של צד שלישי.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Delay_JS {

    /**
     * Settings
     */
    private $enabled = false;
    private $delay_timeout = 5000; // Max delay in ms
    private $scripts_to_delay = array();

    /**
     * Default scripts to delay (third-party scripts that don't affect initial render)
     */
    private $default_delay_patterns = array(
        // Analytics
        'google-analytics',
        'gtag',
        'analytics',
        'gtm.js',
        'googletagmanager',

        // Chat widgets
        'livechat',
        'tawk',
        'intercom',
        'drift',
        'crisp',
        'zendesk',
        'glassix',

        // Marketing
        'facebook',
        'fbevents',
        'pixel',
        'hotjar',
        'clarity',
        'adoric',
        'adoric-om',

        // Accessibility widgets (load after interaction)
        'accessible.vagas',
        'nagich',
        'accessibility-statement',

        // Social
        'twitter',
        'linkedin',
        'pinterest',

        // Reviews
        'trustpilot',
        'yotpo',
        'reviews',
    );

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_delay_js_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_delay_js']);
        $this->delay_timeout = isset($options['delay_js_timeout']) ? intval($options['delay_js_timeout']) : 5000;

        // Custom delay patterns from settings
        if (!empty($options['delay_js_patterns'])) {
            $custom_patterns = array_map('trim', explode("\n", $options['delay_js_patterns']));
            $this->scripts_to_delay = array_merge($this->default_delay_patterns, $custom_patterns);
        } else {
            $this->scripts_to_delay = $this->default_delay_patterns;
        }

        if (!$this->enabled) {
            return;
        }

        // Don't run in admin
        if (is_admin()) {
            return;
        }

        // Process scripts through output buffer
        add_action('template_redirect', array($this, 'start_buffer'), 2);
    }

    /**
     * Start output buffer
     */
    public function start_buffer() {
        ob_start(array($this, 'process_html'));
    }

    /**
     * Process HTML to delay scripts
     */
    public function process_html($html) {
        if (empty($html)) {
            return $html;
        }

        $delayed_scripts = array();

        // Find all script tags
        $html = preg_replace_callback(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            function($matches) use (&$delayed_scripts) {
                $attributes = $matches[1];
                $content = $matches[2];

                // Check if this script should be delayed
                if ($this->should_delay_script($attributes, $content)) {
                    // Store the script for later
                    $delayed_scripts[] = array(
                        'attributes' => $attributes,
                        'content' => $content,
                    );

                    // Remove from current position
                    return '<!-- WooHoo: Script delayed -->';
                }

                return $matches[0];
            },
            $html
        );

        // If we have delayed scripts, add the loader
        if (!empty($delayed_scripts)) {
            $loader_script = $this->generate_loader_script($delayed_scripts);

            // Insert before </body>
            $html = str_replace('</body>', $loader_script . '</body>', $html);
        }

        return $html;
    }

    /**
     * Check if script should be delayed
     */
    private function should_delay_script($attributes, $content) {
        // Never delay critical WooCommerce/WordPress scripts
        $never_delay = array(
            'jquery',
            'woocommerce',
            'wc-',
            'cart',
            'checkout',
            'add-to-cart',
            'wp-includes',
            'wp-content/plugins',
            'wp-content/themes',
        );

        foreach ($never_delay as $safe) {
            if (stripos($attributes, $safe) !== false) {
                return false;
            }
            if (!empty($content) && stripos($content, $safe) !== false) {
                return false;
            }
        }

        // Only delay scripts with external src (not local)
        if (strpos($attributes, 'src=') !== false) {
            // Check if it's an external script
            preg_match('/src=["\']([^"\']+)["\']/i', $attributes, $src_match);
            if (isset($src_match[1])) {
                $src = $src_match[1];
                // Only delay if it matches delay patterns
                foreach ($this->scripts_to_delay as $pattern) {
                    if (empty($pattern)) continue;
                    if (stripos($src, $pattern) !== false) {
                        return true;
                    }
                }
            }
            return false;
        }

        // For inline scripts, check content against patterns
        if (!empty(trim($content))) {
            foreach ($this->scripts_to_delay as $pattern) {
                if (empty($pattern)) continue;
                if (stripos($content, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate the loader script
     */
    private function generate_loader_script($delayed_scripts) {
        $scripts_json = array();

        foreach ($delayed_scripts as $script) {
            // Extract src if present
            preg_match('/src=["\']([^"\']+)["\']/i', $script['attributes'], $src_match);
            $src = isset($src_match[1]) ? $src_match[1] : null;

            $scripts_json[] = array(
                'src' => $src,
                'content' => $src ? null : $script['content'],
                'attributes' => $script['attributes'],
            );
        }

        $timeout = $this->delay_timeout;

        ob_start();
        ?>
        <script id="woohoo-delay-js-loader">
        (function() {
            'use strict';

            var scripts = <?php echo json_encode($scripts_json); ?>;
            var timeout = <?php echo intval($timeout); ?>;
            var loaded = false;

            function loadScripts() {
                if (loaded) return;
                loaded = true;

                scripts.forEach(function(script) {
                    var el = document.createElement('script');

                    if (script.src) {
                        el.src = script.src;
                        el.async = true;
                    } else if (script.content) {
                        el.textContent = script.content;
                    }

                    // Copy other attributes
                    if (script.attributes) {
                        var temp = document.createElement('div');
                        temp.innerHTML = '<script ' + script.attributes + '><\/script>';
                        var tempScript = temp.querySelector('script');
                        if (tempScript) {
                            Array.from(tempScript.attributes).forEach(function(attr) {
                                if (attr.name !== 'src') {
                                    el.setAttribute(attr.name, attr.value);
                                }
                            });
                        }
                    }

                    document.body.appendChild(el);
                });
            }

            // Load on user interaction
            var events = ['scroll', 'click', 'touchstart', 'mousemove', 'keydown'];
            var loadOnce = function() {
                events.forEach(function(e) {
                    window.removeEventListener(e, loadOnce, {passive: true});
                });
                loadScripts();
            };

            events.forEach(function(e) {
                window.addEventListener(e, loadOnce, {passive: true});
            });

            // Also load after timeout (fallback)
            setTimeout(loadScripts, timeout);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Get list of patterns
     */
    public function get_delay_patterns() {
        return $this->scripts_to_delay;
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'delay_timeout' => $this->delay_timeout,
            'patterns_count' => count($this->scripts_to_delay),
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
        $options['enable_delay_js'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['delay_js_timeout'] = isset($_POST['timeout']) ? intval($_POST['timeout']) : 5000;
        $options['delay_js_patterns'] = isset($_POST['patterns']) ? sanitize_textarea_field($_POST['patterns']) : '';

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'woohoo'));
    }
}
