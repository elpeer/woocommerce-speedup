<?php
/**
 * Lazy Loading - טעינה עצלה לתמונות ו-iframes
 *
 * מודול זה מוסיף native lazy loading לתמונות ו-iframes
 * באמצעות loading="lazy" שנתמך בכל הדפדפנים המודרניים.
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
    private $skip_first_images = 3;
    private $image_count = 0;

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
        $this->enabled = !empty($options['enable_lazy_loading']);
        $this->lazy_images = !isset($options['lazy_images']) || !empty($options['lazy_images']);
        $this->lazy_iframes = !isset($options['lazy_iframes']) || !empty($options['lazy_iframes']);
        $this->skip_first_images = isset($options['lazy_skip_first']) ? intval($options['lazy_skip_first']) : 3;

        if (!$this->enabled) {
            return;
        }

        // Don't lazy load in admin, feeds, or AJAX
        if (is_admin() || is_feed() || wp_doing_ajax()) {
            return;
        }

        // Add native lazy loading to images
        if ($this->lazy_images) {
            add_filter('wp_get_attachment_image_attributes', array($this, 'add_lazy_to_attachment'), 10, 3);
            add_filter('the_content', array($this, 'process_content_images'), 99);
            add_filter('post_thumbnail_html', array($this, 'process_thumbnail'), 99, 5);
            add_filter('woocommerce_product_get_image', array($this, 'process_product_image'), 99, 2);
        }

        // Add native lazy loading to iframes
        if ($this->lazy_iframes) {
            add_filter('the_content', array($this, 'process_content_iframes'), 99);
            add_filter('embed_oembed_html', array($this, 'process_oembed'), 99);
        }

        // Add fetchpriority="high" to LCP image
        add_action('wp_head', array($this, 'add_lcp_hints'), 1);
    }

    /**
     * Add lazy loading to attachment images
     */
    public function add_lazy_to_attachment($attr, $attachment, $size) {
        // Skip if already has loading attribute
        if (isset($attr['loading'])) {
            return $attr;
        }

        // Count images for above-fold detection
        $this->image_count++;

        // Skip first N images (above fold)
        if ($this->image_count <= $this->skip_first_images) {
            // Add fetchpriority to first image (LCP)
            if ($this->image_count === 1) {
                $attr['fetchpriority'] = 'high';
            }
            return $attr;
        }

        // Add native lazy loading
        $attr['loading'] = 'lazy';
        $attr['decoding'] = 'async';

        return $attr;
    }

    /**
     * Process content images
     */
    public function process_content_images($content) {
        if (empty($content)) {
            return $content;
        }

        // Reset counter for content
        $content_image_count = 0;

        // Add loading="lazy" to images that don't have it
        $content = preg_replace_callback(
            '/<img\s+([^>]+)>/i',
            function($matches) use (&$content_image_count) {
                $attributes = $matches[0];

                // Skip if already has loading attribute
                if (strpos($attributes, 'loading=') !== false) {
                    return $attributes;
                }

                // Skip data URIs
                if (preg_match('/src=["\']data:/i', $attributes)) {
                    return $attributes;
                }

                // Skip no-lazy classes
                $skip_classes = array('no-lazy', 'skip-lazy', 'no-lazyload');
                foreach ($skip_classes as $class) {
                    if (strpos($attributes, $class) !== false) {
                        return $attributes;
                    }
                }

                $content_image_count++;

                // Skip first N images in content
                if ($content_image_count <= 2) {
                    return $attributes;
                }

                // Add loading="lazy" before closing >
                return str_replace('>', ' loading="lazy" decoding="async">', $attributes);
            },
            $content
        );

        return $content;
    }

    /**
     * Process thumbnail
     */
    public function process_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        // Featured images are usually above fold, so add fetchpriority
        if (strpos($html, 'fetchpriority=') === false && strpos($html, 'loading=') === false) {
            $html = str_replace('<img', '<img fetchpriority="high"', $html);
        }
        return $html;
    }

    /**
     * Process WooCommerce product image
     */
    public function process_product_image($image, $product) {
        if (empty($image)) {
            return $image;
        }

        // Skip if already has loading
        if (strpos($image, 'loading=') !== false) {
            return $image;
        }

        // Add lazy loading
        return str_replace('<img', '<img loading="lazy" decoding="async"', $image);
    }

    /**
     * Process content iframes
     */
    public function process_content_iframes($content) {
        if (empty($content)) {
            return $content;
        }

        // Add loading="lazy" to iframes
        $content = preg_replace_callback(
            '/<iframe\s+([^>]*)>/i',
            function($matches) {
                $attributes = $matches[0];

                // Skip if already has loading attribute
                if (strpos($attributes, 'loading=') !== false) {
                    return $attributes;
                }

                return str_replace('<iframe', '<iframe loading="lazy"', $attributes);
            },
            $content
        );

        return $content;
    }

    /**
     * Process oEmbed
     */
    public function process_oembed($html) {
        if (empty($html)) {
            return $html;
        }

        // Add lazy loading to iframe in oembed
        if (strpos($html, '<iframe') !== false && strpos($html, 'loading=') === false) {
            $html = str_replace('<iframe', '<iframe loading="lazy"', $html);
        }

        return $html;
    }

    /**
     * Add LCP hints in head
     */
    public function add_lcp_hints() {
        // Preconnect to common image CDNs
        $cdns = array(
            'https://images.unsplash.com',
            'https://i0.wp.com',
        );

        foreach ($cdns as $cdn) {
            echo '<link rel="preconnect" href="' . esc_url($cdn) . '" crossorigin>' . "\n";
        }
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'lazy_images' => $this->lazy_images,
            'lazy_iframes' => $this->lazy_iframes,
            'skip_first_images' => $this->skip_first_images,
        );
    }
}
