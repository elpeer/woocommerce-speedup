<?php
/**
 * WooCommerce Sessions Cleanup - ניקוי Sessions של WooCommerce
 *
 * WooCommerce שומר sessions במסד הנתונים שיכולים להצטבר.
 * מודול זה מנקה sessions ישנים באופן אוטומטי.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Sessions_Cleanup {

    /**
     * Settings
     */
    private $enabled = false;
    private $auto_cleanup = false;
    private $cleanup_age = 7; // days

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_cleanup_sessions', array($this, 'ajax_cleanup_sessions'));
        add_action('wp_ajax_wcsu_get_sessions_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_wcsu_save_sessions_settings', array($this, 'ajax_save_settings'));

        // Schedule cleanup
        add_action('wcsu_sessions_cleanup', array($this, 'do_scheduled_cleanup'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_sessions_cleanup']);
        $this->auto_cleanup = !empty($options['sessions_auto_cleanup']);
        $this->cleanup_age = isset($options['sessions_cleanup_age']) ? intval($options['sessions_cleanup_age']) : 7;

        if ($this->enabled && $this->auto_cleanup) {
            // Schedule daily cleanup if not already scheduled
            if (!wp_next_scheduled('wcsu_sessions_cleanup')) {
                wp_schedule_event(time(), 'daily', 'wcsu_sessions_cleanup');
            }
        } else {
            // Unschedule if disabled
            $timestamp = wp_next_scheduled('wcsu_sessions_cleanup');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'wcsu_sessions_cleanup');
            }
        }
    }

    /**
     * Get sessions statistics
     */
    public function get_sessions_stats() {
        global $wpdb;

        $stats = array(
            'total_sessions' => 0,
            'expired_sessions' => 0,
            'table_size' => '0 B',
            'table_exists' => false,
            'oldest_session' => null,
        );

        $table_name = $wpdb->prefix . 'woocommerce_sessions';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if (!$table_exists) {
            return $stats;
        }

        $stats['table_exists'] = true;

        // Get total sessions
        $stats['total_sessions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        // Get expired sessions
        $current_time = time();
        $stats['expired_sessions'] = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE session_expiry < %d", $current_time)
        );

        // Get old sessions (older than cleanup age)
        $cutoff_time = $current_time - ($this->cleanup_age * DAY_IN_SECONDS);
        $stats['old_sessions'] = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE session_expiry < %d", $cutoff_time)
        );

        // Get table size
        $table_size = $wpdb->get_row("
            SELECT
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '$table_name'
        ");
        if ($table_size) {
            $stats['table_size'] = $table_size->size_mb . ' MB';
        }

        // Get oldest session
        $oldest = $wpdb->get_var("SELECT MIN(session_expiry) FROM $table_name");
        if ($oldest) {
            $stats['oldest_session'] = date('Y-m-d H:i:s', $oldest);
        }

        return $stats;
    }

    /**
     * Cleanup old sessions
     */
    public function cleanup_sessions($max_age_days = null) {
        global $wpdb;

        if ($max_age_days === null) {
            $max_age_days = $this->cleanup_age;
        }

        $table_name = $wpdb->prefix . 'woocommerce_sessions';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        if (!$table_exists) {
            return array(
                'success' => false,
                'message' => 'Sessions table does not exist',
                'deleted' => 0,
            );
        }

        // Delete expired and old sessions
        $cutoff_time = time() - ($max_age_days * DAY_IN_SECONDS);

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE session_expiry < %d", $cutoff_time)
        );

        // Also delete any sessions older than 30 days regardless
        $hard_cutoff = time() - (30 * DAY_IN_SECONDS);
        $deleted_old = $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE session_expiry < %d", $hard_cutoff)
        );

        $total_deleted = $deleted + $deleted_old;

        // Optimize table if we deleted a lot
        if ($total_deleted > 100) {
            $wpdb->query("OPTIMIZE TABLE $table_name");
        }

        // Log cleanup
        $this->log_cleanup($total_deleted);

        return array(
            'success' => true,
            'message' => sprintf('Deleted %d old sessions', $total_deleted),
            'deleted' => $total_deleted,
        );
    }

    /**
     * Do scheduled cleanup
     */
    public function do_scheduled_cleanup() {
        if (!$this->enabled || !$this->auto_cleanup) {
            return;
        }

        $this->cleanup_sessions();
    }

    /**
     * Log cleanup
     */
    private function log_cleanup($deleted) {
        $log = get_option('wcsu_sessions_cleanup_log', array());

        $log[] = array(
            'date' => current_time('Y-m-d H:i:s'),
            'deleted' => $deleted,
        );

        // Keep only last 30 entries
        if (count($log) > 30) {
            $log = array_slice($log, -30);
        }

        update_option('wcsu_sessions_cleanup_log', $log);
    }

    /**
     * Get cleanup log
     */
    public function get_cleanup_log() {
        return get_option('wcsu_sessions_cleanup_log', array());
    }

    /**
     * AJAX: Cleanup sessions
     */
    public function ajax_cleanup_sessions() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $result = $this->cleanup_sessions();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Get sessions stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $stats = $this->get_sessions_stats();
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
        $options['enable_sessions_cleanup'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['sessions_auto_cleanup'] = isset($_POST['auto_cleanup']) ? intval($_POST['auto_cleanup']) : 0;
        $options['sessions_cleanup_age'] = isset($_POST['cleanup_age']) ? intval($_POST['cleanup_age']) : 7;

        update_option('wcsu_options', $options);

        // Reschedule if needed
        $this->enabled = !empty($options['enable_sessions_cleanup']);
        $this->auto_cleanup = !empty($options['sessions_auto_cleanup']);
        $this->init();

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }
}
