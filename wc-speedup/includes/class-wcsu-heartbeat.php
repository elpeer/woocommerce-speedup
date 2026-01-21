<?php
/**
 * Heartbeat Control - בקרת WordPress Heartbeat API
 *
 * WordPress Heartbeat API רץ כל 15-60 שניות ושולח בקשות AJAX.
 * מודול זה מאפשר לשלוט בתדירות או להשבית לחלוטין.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Heartbeat {

    /**
     * Settings
     */
    private $enabled = false;
    private $frontend_mode = 'disable';     // disable, slow, default
    private $admin_mode = 'slow';           // disable, slow, default
    private $editor_mode = 'default';       // disable, slow, default
    private $slow_interval = 60;            // seconds

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'), 1);
        add_action('wp_ajax_wcsu_save_heartbeat_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_heartbeat_control']);
        $this->frontend_mode = isset($options['heartbeat_frontend']) ? $options['heartbeat_frontend'] : 'disable';
        $this->admin_mode = isset($options['heartbeat_admin']) ? $options['heartbeat_admin'] : 'slow';
        $this->editor_mode = isset($options['heartbeat_editor']) ? $options['heartbeat_editor'] : 'default';
        $this->slow_interval = isset($options['heartbeat_interval']) ? intval($options['heartbeat_interval']) : 60;

        if (!$this->enabled) {
            return;
        }

        // Apply settings
        add_action('wp_enqueue_scripts', array($this, 'control_frontend_heartbeat'), 99);
        add_action('admin_enqueue_scripts', array($this, 'control_admin_heartbeat'), 99);
        add_filter('heartbeat_settings', array($this, 'modify_heartbeat_settings'));
    }

    /**
     * Control frontend heartbeat
     */
    public function control_frontend_heartbeat() {
        if ($this->frontend_mode === 'disable') {
            wp_deregister_script('heartbeat');
        }
    }

    /**
     * Control admin heartbeat
     */
    public function control_admin_heartbeat() {
        global $pagenow;

        // Check if we're in the post editor
        $is_editor = in_array($pagenow, array('post.php', 'post-new.php'));

        if ($is_editor) {
            // Use editor settings
            if ($this->editor_mode === 'disable') {
                wp_deregister_script('heartbeat');
            }
        } else {
            // Use admin settings
            if ($this->admin_mode === 'disable') {
                wp_deregister_script('heartbeat');
            }
        }
    }

    /**
     * Modify heartbeat settings
     */
    public function modify_heartbeat_settings($settings) {
        // Determine which mode to use based on context
        if (!is_admin()) {
            $mode = $this->frontend_mode;
        } else {
            global $pagenow;
            $is_editor = in_array($pagenow, array('post.php', 'post-new.php'));
            $mode = $is_editor ? $this->editor_mode : $this->admin_mode;
        }

        // Apply interval if in slow mode
        if ($mode === 'slow') {
            $settings['interval'] = $this->slow_interval;
        }

        return $settings;
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'frontend_mode' => $this->frontend_mode,
            'admin_mode' => $this->admin_mode,
            'editor_mode' => $this->editor_mode,
            'slow_interval' => $this->slow_interval,
        );
    }

    /**
     * Get mode label
     */
    public function get_mode_label($mode) {
        $labels = array(
            'disable' => 'מושבת',
            'slow' => 'מואט',
            'default' => 'ברירת מחדל',
        );
        return isset($labels[$mode]) ? $labels[$mode] : $mode;
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
        $options['enable_heartbeat_control'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['heartbeat_frontend'] = isset($_POST['frontend']) ? sanitize_text_field($_POST['frontend']) : 'disable';
        $options['heartbeat_admin'] = isset($_POST['admin']) ? sanitize_text_field($_POST['admin']) : 'slow';
        $options['heartbeat_editor'] = isset($_POST['editor']) ? sanitize_text_field($_POST['editor']) : 'default';
        $options['heartbeat_interval'] = isset($_POST['interval']) ? intval($_POST['interval']) : 60;

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }
}
