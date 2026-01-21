<?php
/**
 * Email Queue - תור למיילים של WooCommerce
 *
 * מודול זה שומר מיילים בתור ושולח אותם ברקע
 * כדי למנוע עיכובים בתהליך ההזמנה.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Email_Queue {

    /**
     * Settings
     */
    private $enabled = false;
    private $batch_size = 10;
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wcsu_email_queue';

        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_email_queue_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_wcsu_get_email_queue_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_wcsu_process_email_queue', array($this, 'ajax_process_queue'));
        add_action('wp_ajax_wcsu_clear_email_queue', array($this, 'ajax_clear_queue'));

        // Schedule queue processing
        add_action('wcsu_process_email_queue', array($this, 'process_queue'));

        // Plugin activation hook
        register_activation_hook(WCSU_PLUGIN_FILE, array($this, 'create_table'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_email_queue']);
        $this->batch_size = isset($options['email_queue_batch_size']) ? intval($options['email_queue_batch_size']) : 10;

        if (!$this->enabled) {
            // Unschedule if disabled
            $timestamp = wp_next_scheduled('wcsu_process_email_queue');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'wcsu_process_email_queue');
            }
            return;
        }

        // Create table if not exists
        $this->maybe_create_table();

        // Hook into wp_mail to queue emails
        add_filter('pre_wp_mail', array($this, 'maybe_queue_email'), 10, 2);

        // Schedule queue processing every minute
        if (!wp_next_scheduled('wcsu_process_email_queue')) {
            wp_schedule_event(time(), 'every_minute', 'wcsu_process_email_queue');
        }

        // Add custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Add custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'wc-speedup'),
        );
        return $schedules;
    }

    /**
     * Create queue table
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            to_email varchar(255) NOT NULL,
            subject varchar(500) NOT NULL,
            message longtext NOT NULL,
            headers text,
            attachments text,
            attempts int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Maybe create table if not exists
     */
    private function maybe_create_table() {
        global $wpdb;

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            $this->create_table();
        }
    }

    /**
     * Maybe queue email instead of sending immediately
     */
    public function maybe_queue_email($null, $atts) {
        // Don't queue if processing queue (prevent infinite loop)
        if (defined('WCSU_PROCESSING_EMAIL_QUEUE')) {
            return $null;
        }

        // Only queue WooCommerce emails
        $wc_email_domains = array(
            'woocommerce',
            'order',
            'customer',
            'new_order',
            'cancelled_order',
            'failed_order',
        );

        $subject = isset($atts['subject']) ? $atts['subject'] : '';
        $should_queue = false;

        foreach ($wc_email_domains as $keyword) {
            if (stripos($subject, $keyword) !== false) {
                $should_queue = true;
                break;
            }
        }

        // Also check if it's from WooCommerce by checking backtrace
        if (!$should_queue) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($backtrace as $trace) {
                if (isset($trace['file']) && strpos($trace['file'], 'woocommerce') !== false) {
                    $should_queue = true;
                    break;
                }
            }
        }

        if (!$should_queue) {
            return $null; // Let WordPress handle it normally
        }

        // Queue the email
        $this->add_to_queue($atts);

        // Return false to prevent immediate sending
        return false;
    }

    /**
     * Add email to queue
     */
    public function add_to_queue($atts) {
        global $wpdb;

        $to = isset($atts['to']) ? (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']) : '';
        $subject = isset($atts['subject']) ? $atts['subject'] : '';
        $message = isset($atts['message']) ? $atts['message'] : '';
        $headers = isset($atts['headers']) ? (is_array($atts['headers']) ? implode("\r\n", $atts['headers']) : $atts['headers']) : '';
        $attachments = isset($atts['attachments']) ? (is_array($atts['attachments']) ? json_encode($atts['attachments']) : $atts['attachments']) : '';

        $wpdb->insert(
            $this->table_name,
            array(
                'to_email' => $to,
                'subject' => $subject,
                'message' => $message,
                'headers' => $headers,
                'attachments' => $attachments,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Process email queue
     */
    public function process_queue() {
        global $wpdb;

        if (!$this->enabled) {
            return;
        }

        // Mark that we're processing queue
        define('WCSU_PROCESSING_EMAIL_QUEUE', true);

        // Get pending emails
        $emails = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE status = 'pending' AND attempts < 3
            ORDER BY created_at ASC
            LIMIT %d",
            $this->batch_size
        ));

        if (empty($emails)) {
            return;
        }

        foreach ($emails as $email) {
            // Update attempts
            $wpdb->update(
                $this->table_name,
                array('attempts' => $email->attempts + 1),
                array('id' => $email->id)
            );

            // Prepare attachments
            $attachments = array();
            if (!empty($email->attachments)) {
                $decoded = json_decode($email->attachments, true);
                if (is_array($decoded)) {
                    $attachments = $decoded;
                }
            }

            // Send email
            $sent = wp_mail(
                $email->to_email,
                $email->subject,
                $email->message,
                $email->headers,
                $attachments
            );

            if ($sent) {
                $wpdb->update(
                    $this->table_name,
                    array(
                        'status' => 'sent',
                        'sent_at' => current_time('mysql'),
                    ),
                    array('id' => $email->id)
                );
            } else {
                // Check if max attempts reached
                if ($email->attempts + 1 >= 3) {
                    $wpdb->update(
                        $this->table_name,
                        array(
                            'status' => 'failed',
                            'error_message' => 'Max attempts reached',
                        ),
                        array('id' => $email->id)
                    );
                }
            }
        }

        // Clean up old sent emails (older than 7 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name}
            WHERE status = 'sent' AND sent_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
    }

    /**
     * Get queue statistics
     */
    public function get_stats() {
        global $wpdb;

        $stats = array(
            'enabled' => $this->enabled,
            'batch_size' => $this->batch_size,
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'total' => 0,
        );

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            return $stats;
        }

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status"
        );

        foreach ($results as $row) {
            $stats[$row->status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        // Get recent emails
        $stats['recent'] = $wpdb->get_results(
            "SELECT id, to_email, subject, status, created_at, sent_at
            FROM {$this->table_name}
            ORDER BY created_at DESC
            LIMIT 20"
        );

        return $stats;
    }

    /**
     * Clear queue
     */
    public function clear_queue($status = null) {
        global $wpdb;

        if ($status) {
            return $wpdb->delete($this->table_name, array('status' => $status));
        } else {
            return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        }
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
        $options['enable_email_queue'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['email_queue_batch_size'] = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

        update_option('wcsu_options', $options);

        // Create table if enabling
        if (!empty($options['enable_email_queue'])) {
            $this->create_table();
        }

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }

    /**
     * AJAX: Get queue stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        wp_send_json_success($this->get_stats());
    }

    /**
     * AJAX: Process queue manually
     */
    public function ajax_process_queue() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $this->enabled = true; // Force enable for manual processing
        $this->process_queue();

        wp_send_json_success(__('Queue processed', 'wc-speedup'));
    }

    /**
     * AJAX: Clear queue
     */
    public function ajax_clear_queue() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;
        $this->clear_queue($status);

        wp_send_json_success(__('Queue cleared', 'wc-speedup'));
    }
}
