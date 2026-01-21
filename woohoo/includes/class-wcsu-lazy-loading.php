<?php
/**
 * Lazy Loading - טעינה עצלה לתמונות ו-iframes
 *
 * מודול זה מוסיף lazy loading אמיתי לתמונות ו-iframes
 * עם Intersection Observer לביצועים מיטביים.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Lazy_Loading {

    /**
     * Settings
     */
    private $enabled = false;
    private $lazy_images = true;
    private $lazy_iframes = true;
    private $lazy_videos = true;
    private $skip_first_images = 3; // Skip first N images (above fold / LCP)
    private $threshold = 200; // px before viewport to start loading
    private $placeholder_color = '#f0f0f0';
    private $image_count = 0;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_lazy_loading_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_lazy_loading']);
        $this->lazy_images = !isset($options['lazy_images']) || !empty($options['lazy_images']);
        $this->lazy_iframes = !isset($options['lazy_iframes']) || !empty($options['lazy_iframes']);
        $this->lazy_videos = !isset($options['lazy_videos']) || !empty($options['lazy_videos']);
        $this->skip_first_images = isset($options['lazy_skip_first']) ? intval($options['lazy_skip_first']) : 3;
        $this->threshold = isset($options['lazy_threshold']) ? intval($options['lazy_threshold']) : 200;

        if (!$this->enabled) {
            return;
        }

        // Don't lazy load in admin, feeds, or AJAX
        if (is_admin() || is_feed() || wp_doing_ajax()) {
            return;
        }

        // Use output buffer to process the entire HTML
        add_action('template_redirect', array($this, 'start_buffer'), 1);

        // Add CSS and JS
        add_action('wp_head', array($this, 'add_css'), 1);
        add_action('wp_footer', array($this, 'add_js'), 99);
    }

    /**
     * Start output buffer
     */
    public function start_buffer() {
        ob_start(array($this, 'process_html'));
    }

    /**
     * Process the entire HTML output
     */
    public function process_html($html) {
        if (empty($html)) {
            return $html;
        }

        // Reset counter
        $this->image_count = 0;

        // Process images
        if ($this->lazy_images) {
            $html = preg_replace_callback(
                '/<img\s+([^>]+)>/i',
                array($this, 'process_image'),
                $html
            );
        }

        // Process iframes
        if ($this->lazy_iframes) {
            $html = preg_replace_callback(
                '/<iframe\s+([^>]*)\s*><\/iframe>/i',
                array($this, 'process_iframe'),
                $html
            );
        }

        // Process background images in style attributes
        if ($this->lazy_images) {
            $html = preg_replace_callback(
                '/<([a-z0-9]+)\s+([^>]*style=["\'][^"\']*background(-image)?:\s*url\(["\']?)([^"\')\s]+)(["\']?\)[^"\']*["\'][^>]*)>/i',
                array($this, 'process_background_image'),
                $html
            );
        }

        return $html;
    }

    /**
     * Process a single image tag
     */
    private function process_image($matches) {
        $full_tag = $matches[0];
        $attributes = $matches[1];

        // Skip images that shouldn't be lazy loaded
        if ($this->should_skip_image($attributes)) {
            return $full_tag;
        }

        // Increment counter
        $this->image_count++;

        // Skip first N images (above fold / LCP)
        if ($this->image_count <= $this->skip_first_images) {
            // Add fetchpriority="high" to first image (LCP)
            if ($this->image_count === 1 && strpos($attributes, 'fetchpriority') === false) {
                $full_tag = str_replace('<img', '<img fetchpriority="high"', $full_tag);
            }
            return $full_tag;
        }

        // Extract src
        if (preg_match('/\ssrc=["\']([^"\']+)["\']/i', $attributes, $src_match)) {
            $src = $src_match[1];

            // Replace src with data-src
            $new_attributes = preg_replace(
                '/\ssrc=["\']([^"\']+)["\']/i',
                ' src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1 1\'%3E%3C/svg%3E" data-lazy-src="' . esc_attr($src) . '"',
                $attributes
            );

            // Handle srcset
            if (preg_match('/\ssrcset=["\']([^"\']+)["\']/i', $new_attributes, $srcset_match)) {
                $srcset = $srcset_match[1];
                $new_attributes = preg_replace(
                    '/\ssrcset=["\']([^"\']+)["\']/i',
                    ' data-lazy-srcset="' . esc_attr($srcset) . '"',
                    $new_attributes
                );
            }

            // Add lazy class
            if (preg_match('/\sclass=["\']([^"\']+)["\']/i', $new_attributes, $class_match)) {
                $new_attributes = preg_replace(
                    '/\sclass=["\']([^"\']+)["\']/i',
                    ' class="' . $class_match[1] . ' woohoo-lazy"',
                    $new_attributes
                );
            } else {
                $new_attributes .= ' class="woohoo-lazy"';
            }

            // Remove loading="lazy" if present (we handle it ourselves)
            $new_attributes = preg_replace('/\sloading=["\'][^"\']+["\']/i', '', $new_attributes);

            return '<img ' . $new_attributes . '>';
        }

        return $full_tag;
    }

    /**
     * Check if image should be skipped
     */
    private function should_skip_image($attributes) {
        // Skip if no src
        if (strpos($attributes, 'src=') === false) {
            return true;
        }

        // Skip data URIs
        if (preg_match('/src=["\']data:/i', $attributes)) {
            return true;
        }

        // Skip if already has data-lazy-src
        if (strpos($attributes, 'data-lazy-src') !== false) {
            return true;
        }

        // Skip if marked to not lazy load
        $skip_classes = array(
            'no-lazy',
            'skip-lazy',
            'no-lazyload',
            'data-lazy_off',
        );

        foreach ($skip_classes as $class) {
            if (strpos($attributes, $class) !== false) {
                return true;
            }
        }

        // Skip specific image types
        $skip_patterns = array(
            'logo',
            'custom-logo',
            'site-logo',
            'avatar',
            'gravatar',
        );

        foreach ($skip_patterns as $pattern) {
            if (stripos($attributes, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process iframe
     */
    private function process_iframe($matches) {
        $full_tag = $matches[0];
        $attributes = $matches[1];

        // Skip if already lazy
        if (strpos($attributes, 'data-lazy-src') !== false) {
            return $full_tag;
        }

        // Extract src
        if (preg_match('/\ssrc=["\']([^"\']+)["\']/i', $attributes, $src_match)) {
            $src = $src_match[1];

            // Replace src with data-src
            $new_attributes = preg_replace(
                '/\ssrc=["\']([^"\']+)["\']/i',
                ' data-lazy-src="' . esc_attr($src) . '"',
                $attributes
            );

            // Add lazy class
            if (preg_match('/\sclass=["\']([^"\']+)["\']/i', $new_attributes, $class_match)) {
                $new_attributes = preg_replace(
                    '/\sclass=["\']([^"\']+)["\']/i',
                    ' class="' . $class_match[1] . ' woohoo-lazy-iframe"',
                    $new_attributes
                );
            } else {
                $new_attributes .= ' class="woohoo-lazy-iframe"';
            }

            return '<iframe ' . $new_attributes . '></iframe>';
        }

        return $full_tag;
    }

    /**
     * Process background image
     */
    private function process_background_image($matches) {
        // Only process elements after above-fold content
        $this->image_count++;

        if ($this->image_count <= $this->skip_first_images) {
            return $matches[0];
        }

        $tag = $matches[1];
        $before = $matches[2];
        $url = $matches[4];
        $after = $matches[5];

        // Replace background-image URL with data attribute
        $new_tag = '<' . $tag . ' ' . $before;
        $new_tag = preg_replace(
            '/background(-image)?:\s*url\(["\']?[^"\')\s]+["\']?\)/i',
            'background$1:none',
            $new_tag
        );
        $new_tag .= $after . ' data-lazy-bg="' . esc_attr($url) . '">';

        return $new_tag;
    }

    /**
     * Add CSS
     */
    public function add_css() {
        ?>
        <style id="woohoo-lazy-css">
            .woohoo-lazy,
            .woohoo-lazy-iframe {
                opacity: 0;
                transition: opacity 0.3s ease-in-out;
            }
            .woohoo-lazy.loaded,
            .woohoo-lazy-iframe.loaded {
                opacity: 1;
            }
            .woohoo-lazy:not(.loaded) {
                background: <?php echo esc_attr($this->placeholder_color); ?>;
            }
            /* Prevent CLS - maintain aspect ratio */
            .woohoo-lazy[width][height] {
                aspect-ratio: attr(width) / attr(height);
            }
        </style>
        <?php
    }

    /**
     * Add JavaScript with Intersection Observer
     */
    public function add_js() {
        ?>
        <script id="woohoo-lazy-js">
        (function() {
            'use strict';

            var threshold = <?php echo intval($this->threshold); ?>;

            // Check if Intersection Observer is supported
            if (!('IntersectionObserver' in window)) {
                // Fallback: load all images immediately
                loadAllImages();
                return;
            }

            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var el = entry.target;
                        loadElement(el);
                        observer.unobserve(el);
                    }
                });
            }, {
                rootMargin: threshold + 'px 0px',
                threshold: 0.01
            });

            // Observe all lazy elements
            function observeElements() {
                // Images
                document.querySelectorAll('.woohoo-lazy[data-lazy-src]').forEach(function(img) {
                    observer.observe(img);
                });

                // Iframes
                document.querySelectorAll('.woohoo-lazy-iframe[data-lazy-src]').forEach(function(iframe) {
                    observer.observe(iframe);
                });

                // Background images
                document.querySelectorAll('[data-lazy-bg]').forEach(function(el) {
                    observer.observe(el);
                });
            }

            // Load a single element
            function loadElement(el) {
                if (el.tagName === 'IMG') {
                    var src = el.getAttribute('data-lazy-src');
                    var srcset = el.getAttribute('data-lazy-srcset');

                    if (src) {
                        el.src = src;
                        el.removeAttribute('data-lazy-src');
                    }
                    if (srcset) {
                        el.srcset = srcset;
                        el.removeAttribute('data-lazy-srcset');
                    }

                    el.onload = function() {
                        el.classList.add('loaded');
                    };

                    // If image is cached, onload might not fire
                    if (el.complete) {
                        el.classList.add('loaded');
                    }
                } else if (el.tagName === 'IFRAME') {
                    var src = el.getAttribute('data-lazy-src');
                    if (src) {
                        el.src = src;
                        el.removeAttribute('data-lazy-src');
                        el.classList.add('loaded');
                    }
                } else if (el.hasAttribute('data-lazy-bg')) {
                    var bg = el.getAttribute('data-lazy-bg');
                    el.style.backgroundImage = 'url(' + bg + ')';
                    el.removeAttribute('data-lazy-bg');
                }
            }

            // Fallback for browsers without Intersection Observer
            function loadAllImages() {
                document.querySelectorAll('.woohoo-lazy[data-lazy-src], .woohoo-lazy-iframe[data-lazy-src], [data-lazy-bg]').forEach(function(el) {
                    loadElement(el);
                });
            }

            // Start observing when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', observeElements);
            } else {
                observeElements();
            }

            // Also observe after AJAX content loads (WooCommerce, infinite scroll, etc.)
            if (typeof jQuery !== 'undefined') {
                jQuery(document).ajaxComplete(function() {
                    observeElements();
                });
            }

            // Observe mutations for dynamically added content
            if ('MutationObserver' in window) {
                var mutationObserver = new MutationObserver(function(mutations) {
                    var shouldObserve = false;
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length > 0) {
                            shouldObserve = true;
                        }
                    });
                    if (shouldObserve) {
                        observeElements();
                    }
                });

                mutationObserver.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'lazy_images' => $this->lazy_images,
            'lazy_iframes' => $this->lazy_iframes,
            'lazy_videos' => $this->lazy_videos,
            'skip_first_images' => $this->skip_first_images,
            'threshold' => $this->threshold,
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
        $options['enable_lazy_loading'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['lazy_images'] = isset($_POST['lazy_images']) ? intval($_POST['lazy_images']) : 1;
        $options['lazy_iframes'] = isset($_POST['lazy_iframes']) ? intval($_POST['lazy_iframes']) : 1;
        $options['lazy_videos'] = isset($_POST['lazy_videos']) ? intval($_POST['lazy_videos']) : 1;
        $options['lazy_skip_first'] = isset($_POST['skip_first']) ? intval($_POST['skip_first']) : 3;
        $options['lazy_threshold'] = isset($_POST['threshold']) ? intval($_POST['threshold']) : 200;

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'woohoo'));
    }
}
