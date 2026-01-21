<?php
/**
 * Lazy Loading - טעינה עצלה לתמונות ו-iframes
 *
 * מודול זה מוסיף lazy loading לתמונות ו-iframes
 * כדי לשפר את זמן הטעינה הראשוני של הדף.
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
    private $exclude_above_fold = true;
    private $placeholder_color = '#f0f0f0';

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
        $this->exclude_above_fold = !isset($options['lazy_exclude_above_fold']) || !empty($options['lazy_exclude_above_fold']);

        if (!$this->enabled) {
            return;
        }

        // Add native lazy loading
        if ($this->lazy_images) {
            add_filter('wp_get_attachment_image_attributes', array($this, 'add_lazy_to_attachment'), 10, 3);
            add_filter('the_content', array($this, 'add_lazy_to_content_images'), 99);
            add_filter('post_thumbnail_html', array($this, 'add_lazy_to_thumbnail'), 10, 5);
            add_filter('woocommerce_product_get_image', array($this, 'add_lazy_to_product_image'), 10, 5);
        }

        if ($this->lazy_iframes) {
            add_filter('the_content', array($this, 'add_lazy_to_iframes'), 99);
            add_filter('embed_oembed_html', array($this, 'add_lazy_to_embeds'), 10, 4);
        }

        // Add CSS for placeholders
        add_action('wp_head', array($this, 'add_placeholder_css'));

        // Add JS for enhanced lazy loading
        add_action('wp_footer', array($this, 'add_lazy_loading_js'));
    }

    /**
     * Add lazy loading to attachment images
     */
    public function add_lazy_to_attachment($attr, $attachment, $size) {
        // Skip if already has loading attribute
        if (isset($attr['loading'])) {
            return $attr;
        }

        $attr['loading'] = 'lazy';

        return $attr;
    }

    /**
     * Add lazy loading to content images
     */
    public function add_lazy_to_content_images($content) {
        if (empty($content)) {
            return $content;
        }

        // Don't process in admin or feeds
        if (is_admin() || is_feed()) {
            return $content;
        }

        // Find all images without loading attribute
        $content = preg_replace_callback(
            '/<img([^>]+)>/i',
            array($this, 'process_image_tag'),
            $content
        );

        return $content;
    }

    /**
     * Process image tag
     */
    private function process_image_tag($matches) {
        $img_tag = $matches[0];
        $attributes = $matches[1];

        // Skip if already has loading attribute
        if (strpos($attributes, 'loading=') !== false) {
            return $img_tag;
        }

        // Skip certain images
        $skip_classes = array('no-lazy', 'skip-lazy', 'logo', 'custom-logo');
        foreach ($skip_classes as $class) {
            if (strpos($attributes, $class) !== false) {
                return $img_tag;
            }
        }

        // Add loading="lazy" attribute
        $img_tag = str_replace('<img', '<img loading="lazy"', $img_tag);

        return $img_tag;
    }

    /**
     * Add lazy loading to thumbnails
     */
    public function add_lazy_to_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (strpos($html, 'loading=') !== false) {
            return $html;
        }

        return str_replace('<img', '<img loading="lazy"', $html);
    }

    /**
     * Add lazy loading to WooCommerce product images
     */
    public function add_lazy_to_product_image($image, $product, $size, $attr, $placeholder) {
        if (strpos($image, 'loading=') !== false) {
            return $image;
        }

        return str_replace('<img', '<img loading="lazy"', $image);
    }

    /**
     * Add lazy loading to iframes
     */
    public function add_lazy_to_iframes($content) {
        if (empty($content)) {
            return $content;
        }

        // Don't process in admin or feeds
        if (is_admin() || is_feed()) {
            return $content;
        }

        // Find all iframes without loading attribute
        $content = preg_replace_callback(
            '/<iframe([^>]+)>/i',
            array($this, 'process_iframe_tag'),
            $content
        );

        return $content;
    }

    /**
     * Process iframe tag
     */
    private function process_iframe_tag($matches) {
        $iframe_tag = $matches[0];
        $attributes = $matches[1];

        // Skip if already has loading attribute
        if (strpos($attributes, 'loading=') !== false) {
            return $iframe_tag;
        }

        // Add loading="lazy" attribute
        $iframe_tag = str_replace('<iframe', '<iframe loading="lazy"', $iframe_tag);

        return $iframe_tag;
    }

    /**
     * Add lazy loading to embeds
     */
    public function add_lazy_to_embeds($html, $url, $attr, $post_id) {
        if (strpos($html, '<iframe') === false) {
            return $html;
        }

        if (strpos($html, 'loading=') !== false) {
            return $html;
        }

        return str_replace('<iframe', '<iframe loading="lazy"', $html);
    }

    /**
     * Add placeholder CSS
     */
    public function add_placeholder_css() {
        ?>
        <style>
            img[loading="lazy"] {
                background-color: <?php echo esc_attr($this->placeholder_color); ?>;
            }
            img[loading="lazy"]:not([src]) {
                visibility: hidden;
            }
        </style>
        <?php
    }

    /**
     * Add lazy loading JavaScript for enhanced behavior
     */
    public function add_lazy_loading_js() {
        if (!$this->exclude_above_fold) {
            return;
        }
        ?>
        <script>
        (function() {
            // Remove lazy loading from images above the fold
            var viewportHeight = window.innerHeight;
            var images = document.querySelectorAll('img[loading="lazy"]');

            images.forEach(function(img) {
                var rect = img.getBoundingClientRect();
                // If image is in the first viewport, remove lazy loading
                if (rect.top < viewportHeight && rect.bottom > 0) {
                    img.removeAttribute('loading');
                }
            });
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
            'exclude_above_fold' => $this->exclude_above_fold,
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
        $options['enable_lazy_loading'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['lazy_images'] = isset($_POST['lazy_images']) ? intval($_POST['lazy_images']) : 1;
        $options['lazy_iframes'] = isset($_POST['lazy_iframes']) ? intval($_POST['lazy_iframes']) : 1;
        $options['lazy_videos'] = isset($_POST['lazy_videos']) ? intval($_POST['lazy_videos']) : 1;
        $options['lazy_exclude_above_fold'] = isset($_POST['exclude_above_fold']) ? intval($_POST['exclude_above_fold']) : 1;

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }
}
