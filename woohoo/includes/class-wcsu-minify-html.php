<?php
/**
 * Minify HTML - דחיסת HTML
 *
 * מודול זה מסיר רווחים מיותרים, הערות וירידות שורה
 * מה-HTML כדי להקטין את גודל העמוד.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Minify_HTML {

    /**
     * Settings
     */
    private $enabled = false;
    private $remove_comments = true;
    private $remove_whitespace = true;

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
        $this->enabled = !empty($options['enable_minify_html']);
        $this->remove_comments = !isset($options['minify_remove_comments']) || !empty($options['minify_remove_comments']);
        $this->remove_whitespace = !isset($options['minify_remove_whitespace']) || !empty($options['minify_remove_whitespace']);

        if (!$this->enabled) {
            return;
        }

        // Don't run in admin or AJAX
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Start output buffer
        add_action('template_redirect', array($this, 'start_buffer'), 999);
    }

    /**
     * Start output buffer
     */
    public function start_buffer() {
        ob_start(array($this, 'minify_html'));
    }

    /**
     * Minify HTML
     */
    public function minify_html($html) {
        if (empty($html)) {
            return $html;
        }

        // Don't minify if not HTML
        if (strpos($html, '<html') === false && strpos($html, '<!DOCTYPE') === false) {
            return $html;
        }

        // Preserve certain elements
        $preserved = array();
        $preserve_count = 0;

        // Preserve <pre> tags
        $html = preg_replace_callback(
            '/<pre[^>]*>.*?<\/pre>/is',
            function($matches) use (&$preserved, &$preserve_count) {
                $placeholder = '<!--WOOHOO_PRESERVE_' . $preserve_count . '-->';
                $preserved[$placeholder] = $matches[0];
                $preserve_count++;
                return $placeholder;
            },
            $html
        );

        // Preserve <script> tags
        $html = preg_replace_callback(
            '/<script[^>]*>.*?<\/script>/is',
            function($matches) use (&$preserved, &$preserve_count) {
                $placeholder = '<!--WOOHOO_PRESERVE_' . $preserve_count . '-->';
                $preserved[$placeholder] = $matches[0];
                $preserve_count++;
                return $placeholder;
            },
            $html
        );

        // Preserve <style> tags
        $html = preg_replace_callback(
            '/<style[^>]*>.*?<\/style>/is',
            function($matches) use (&$preserved, &$preserve_count) {
                $placeholder = '<!--WOOHOO_PRESERVE_' . $preserve_count . '-->';
                $preserved[$placeholder] = $matches[0];
                $preserve_count++;
                return $placeholder;
            },
            $html
        );

        // Preserve <textarea> tags
        $html = preg_replace_callback(
            '/<textarea[^>]*>.*?<\/textarea>/is',
            function($matches) use (&$preserved, &$preserve_count) {
                $placeholder = '<!--WOOHOO_PRESERVE_' . $preserve_count . '-->';
                $preserved[$placeholder] = $matches[0];
                $preserve_count++;
                return $placeholder;
            },
            $html
        );

        // Remove HTML comments (except IE conditionals and preserved placeholders)
        if ($this->remove_comments) {
            $html = preg_replace('/<!--(?!\[if|\[endif|WOOHOO_PRESERVE).*?-->/s', '', $html);
        }

        // Remove whitespace
        if ($this->remove_whitespace) {
            // Remove whitespace between tags
            $html = preg_replace('/>\s+</', '><', $html);

            // Remove multiple spaces
            $html = preg_replace('/\s{2,}/', ' ', $html);

            // Remove newlines
            $html = preg_replace('/\n/', '', $html);

            // Remove tabs
            $html = preg_replace('/\t/', '', $html);
        }

        // Restore preserved elements
        foreach ($preserved as $placeholder => $content) {
            $html = str_replace($placeholder, $content, $html);
        }

        return $html;
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'remove_comments' => $this->remove_comments,
            'remove_whitespace' => $this->remove_whitespace,
        );
    }
}
