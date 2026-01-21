<?php
/**
 * Transients Cleanup - ניקוי Transients של WordPress
 *
 * WordPress שומר transients (קאש זמני) במסד הנתונים.
 * מודול זה מנקה transients שפג תוקפם.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Transients_Cleanup {

    /**
     * Settings
     */
    private $enabled = false;
    private $auto_cleanup = false;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_cleanup_transients', array($this, 'ajax_cleanup_transients'));
        add_action('wp_ajax_wcsu_get_transients_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_wcsu_save_transients_settings', array($this, 'ajax_save_settings'));

        // Schedule cleanup
        add_action('wcsu_transients_cleanup', array($this, 'do_scheduled_cleanup'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_transients_cleanup']);
        $this->auto_cleanup = !empty($options['transients_auto_cleanup']);

        if ($this->enabled && $this->auto_cleanup) {
            // Schedule daily cleanup if not already scheduled
            if (!wp_next_scheduled('wcsu_transients_cleanup')) {
                wp_schedule_event(time(), 'daily', 'wcsu_transients_cleanup');
            }
        } else {
            // Unschedule if disabled
            $timestamp = wp_next_scheduled('wcsu_transients_cleanup');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'wcsu_transients_cleanup');
            }
        }
    }

    /**
     * Get transients statistics
     */
    public function get_transients_stats() {
        global $wpdb;

        $stats = array(
            'total_transients' => 0,
            'expired_transients' => 0,
            'total_size' => '0 B',
            'largest_transients' => array(),
        );

        $current_time = time();

        // Count total transients
        $stats['total_transients'] = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_%'
            AND option_name NOT LIKE '_transient_timeout_%'
        ");

        // Count expired transients
        $expired_transients = $wpdb->get_results("
            SELECT option_name, option_value FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_timeout_%'
        ");

        $expired_count = 0;
        foreach ($expired_transients as $transient) {
            if ((int) $transient->option_value < $current_time) {
                $expired_count++;
            }
        }
        $stats['expired_transients'] = $expired_count;

        // Calculate total size
        $total_size = $wpdb->get_var("
            SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_%'
        ");
        $stats['total_size'] = size_format($total_size ?: 0);

        // Get largest transients
        $largest = $wpdb->get_results("
            SELECT
                REPLACE(option_name, '_transient_', '') AS name,
                LENGTH(option_value) AS size
            FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_%'
            AND option_name NOT LIKE '_transient_timeout_%'
            ORDER BY LENGTH(option_value) DESC
            LIMIT 10
        ");

        foreach ($largest as $item) {
            $stats['largest_transients'][] = array(
                'name' => $item->name,
                'size' => size_format($item->size),
            );
        }

        return $stats;
    }

    /**
     * Cleanup expired transients
     */
    public function cleanup_transients() {
        global $wpdb;

        $current_time = time();
        $deleted_count = 0;

        // Get all expired transient timeouts
        $expired = $wpdb->get_col("
            SELECT option_name FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_timeout_%'
            AND option_value < $current_time
        ");

        foreach ($expired as $transient_timeout) {
            $transient_name = str_replace('_transient_timeout_', '_transient_', $transient_timeout);

            // Delete both the transient and its timeout
            $wpdb->delete($wpdb->options, array('option_name' => $transient_name));
            $wpdb->delete($wpdb->options, array('option_name' => $transient_timeout));
            $deleted_count++;
        }

        // Also handle site transients for multisite
        if (is_multisite()) {
            $expired_site = $wpdb->get_col("
                SELECT option_name FROM {$wpdb->sitemeta}
                WHERE meta_key LIKE '_site_transient_timeout_%'
                AND meta_value < $current_time
            ");

            foreach ($expired_site as $transient_timeout) {
                $transient_name = str_replace('_site_transient_timeout_', '_site_transient_', $transient_timeout);

                $wpdb->delete($wpdb->sitemeta, array('meta_key' => $transient_name));
                $wpdb->delete($wpdb->sitemeta, array('meta_key' => $transient_timeout));
                $deleted_count++;
            }
        }

        // Optimize options table if we deleted a lot
        if ($deleted_count > 50) {
            $wpdb->query("OPTIMIZE TABLE {$wpdb->options}");
        }

        // Log cleanup
        $this->log_cleanup($deleted_count);

        return array(
            'success' => true,
            'message' => sprintf('Deleted %d expired transients', $deleted_count),
            'deleted' => $deleted_count,
        );
    }

    /**
     * Do scheduled cleanup
     */
    public function do_scheduled_cleanup() {
        if (!$this->enabled || !$this->auto_cleanup) {
            return;
        }

        $this->cleanup_transients();
    }

    /**
     * Log cleanup
     */
    private function log_cleanup($deleted) {
        $log = get_option('wcsu_transients_cleanup_log', array());

        $log[] = array(
            'date' => current_time('Y-m-d H:i:s'),
            'deleted' => $deleted,
        );

        // Keep only last 30 entries
        if (count($log) > 30) {
            $log = array_slice($log, -30);
        }

        update_option('wcsu_transients_cleanup_log', $log);
    }

    /**
     * Get cleanup log
     */
    public function get_cleanup_log() {
        return get_option('wcsu_transients_cleanup_log', array());
    }

    /**
     * AJAX: Cleanup transients
     */
    public function ajax_cleanup_transients() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $result = $this->cleanup_transients();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Get transients stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $stats = $this->get_transients_stats();
        $stats['cleanup_log'] = $this->get_cleanup_log();

        wp_send_json_success($stats);
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
        $options['enable_transients_cleanup'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['transients_auto_cleanup'] = isset($_POST['auto_cleanup']) ? intval($_POST['auto_cleanup']) : 0;

        update_option('wcsu_options', $options);

        // Reschedule if needed
        $this->enabled = !empty($options['enable_transients_cleanup']);
        $this->auto_cleanup = !empty($options['transients_auto_cleanup']);
        $this->init();

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }
}
