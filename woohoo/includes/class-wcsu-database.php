<?php
/**
 * Database Optimization Module - ניקוי ואופטימיזציה של מסד הנתונים
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Database {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wcsu_daily_cleanup', array($this, 'daily_cleanup'));
        add_action('wp_ajax_wcsu_db_cleanup', array($this, 'ajax_cleanup'));
        add_action('wp_ajax_wcsu_optimize_tables', array($this, 'ajax_optimize_tables'));
    }

    /**
     * Get cleanup statistics
     */
    public function get_cleanup_stats() {
        global $wpdb;

        $stats = array(
            'revisions' => array(
                'label' => __('Post Revisions', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
                'action' => 'revisions'
            ),
            'auto_drafts' => array(
                'label' => __('Auto Drafts', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
                'action' => 'auto_drafts'
            ),
            'trashed_posts' => array(
                'label' => __('Trashed Posts', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
                'action' => 'trashed_posts'
            ),
            'spam_comments' => array(
                'label' => __('Spam Comments', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
                'action' => 'spam_comments'
            ),
            'trashed_comments' => array(
                'label' => __('Trashed Comments', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"),
                'action' => 'trashed_comments'
            ),
            'expired_transients' => array(
                'label' => __('Expired Transients', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"),
                'action' => 'expired_transients'
            ),
            'orphaned_postmeta' => array(
                'label' => __('Orphaned Post Meta', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"),
                'action' => 'orphaned_postmeta'
            ),
            'orphaned_commentmeta' => array(
                'label' => __('Orphaned Comment Meta', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"),
                'action' => 'orphaned_commentmeta'
            ),
            'orphaned_usermeta' => array(
                'label' => __('Orphaned User Meta', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL"),
                'action' => 'orphaned_usermeta'
            ),
            'orphaned_termmeta' => array(
                'label' => __('Orphaned Term Meta', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL"),
                'action' => 'orphaned_termmeta'
            ),
            'orphaned_term_relationships' => array(
                'label' => __('Orphaned Term Relationships', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID WHERE p.ID IS NULL"),
                'action' => 'orphaned_term_relationships'
            ),
            'duplicated_postmeta' => array(
                'label' => __('Duplicate Post Meta', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_id NOT IN (SELECT MIN(meta_id) FROM {$wpdb->postmeta} GROUP BY post_id, meta_key, meta_value)"),
                'action' => 'duplicated_postmeta'
            ),
            'oembed_cache' => array(
                'label' => __('oEmbed Cache', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '_oembed_%'"),
                'action' => 'oembed_cache'
            )
        );

        // WooCommerce specific
        if (class_exists('WooCommerce')) {
            $stats['wc_sessions'] = array(
                'label' => __('WC Expired Sessions', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry < UNIX_TIMESTAMP()"),
                'action' => 'wc_sessions'
            );

            // Check for orphaned WooCommerce data
            $stats['orphaned_order_itemmeta'] = array(
                'label' => __('Orphaned Order Item Meta', 'wc-speedup'),
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta oim LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_item_id IS NULL"),
                'action' => 'orphaned_order_itemmeta'
            );
        }

        return $stats;
    }

    /**
     * Clean specific item type
     */
    public function clean($type) {
        global $wpdb;
        $deleted = 0;

        switch ($type) {
            case 'revisions':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
                break;

            case 'auto_drafts':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
                break;

            case 'trashed_posts':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
                break;

            case 'spam_comments':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
                break;

            case 'trashed_comments':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
                break;

            case 'expired_transients':
                $deleted = $this->clean_expired_transients();
                break;

            case 'orphaned_postmeta':
                $deleted = $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL");
                break;

            case 'orphaned_commentmeta':
                $deleted = $wpdb->query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL");
                break;

            case 'orphaned_usermeta':
                $deleted = $wpdb->query("DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL");
                break;

            case 'orphaned_termmeta':
                $deleted = $wpdb->query("DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL");
                break;

            case 'orphaned_term_relationships':
                $deleted = $wpdb->query("DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID WHERE p.ID IS NULL");
                break;

            case 'duplicated_postmeta':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id NOT IN (SELECT * FROM (SELECT MIN(meta_id) FROM {$wpdb->postmeta} GROUP BY post_id, meta_key, meta_value) AS temp)");
                break;

            case 'oembed_cache':
                $deleted = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_oembed_%'");
                break;

            case 'wc_sessions':
                if (class_exists('WooCommerce')) {
                    $deleted = $wpdb->query("DELETE FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry < UNIX_TIMESTAMP()");
                }
                break;

            case 'orphaned_order_itemmeta':
                if (class_exists('WooCommerce')) {
                    $deleted = $wpdb->query("DELETE oim FROM {$wpdb->prefix}woocommerce_order_itemmeta oim LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_item_id IS NULL");
                }
                break;

            case 'all':
                $deleted = $this->clean_all();
                break;
        }

        return $deleted;
    }

    /**
     * Clean all items
     */
    public function clean_all() {
        $total = 0;
        $types = array(
            'revisions',
            'auto_drafts',
            'trashed_posts',
            'spam_comments',
            'trashed_comments',
            'expired_transients',
            'orphaned_postmeta',
            'orphaned_commentmeta',
            'orphaned_usermeta',
            'orphaned_termmeta',
            'orphaned_term_relationships',
            'duplicated_postmeta',
            'oembed_cache',
            'wc_sessions',
            'orphaned_order_itemmeta'
        );

        foreach ($types as $type) {
            $total += $this->clean($type);
        }

        return $total;
    }

    /**
     * Clean expired transients
     */
    private function clean_expired_transients() {
        global $wpdb;
        $deleted = 0;

        // Get expired transient timeouts
        $expired = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '%_transient_timeout_%'
             AND option_value < UNIX_TIMESTAMP()"
        );

        foreach ($expired as $transient_timeout) {
            $transient_name = str_replace('_transient_timeout_', '', $transient_timeout);
            delete_transient($transient_name);
            $deleted++;
        }

        // Clean site transients for multisite
        if (is_multisite()) {
            $expired_site = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->sitemeta}
                 WHERE meta_key LIKE '%_site_transient_timeout_%'
                 AND meta_value < UNIX_TIMESTAMP()"
            );

            foreach ($expired_site as $transient_timeout) {
                $transient_name = str_replace('_site_transient_timeout_', '', $transient_timeout);
                delete_site_transient($transient_name);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Optimize database tables
     */
    public function optimize_tables() {
        global $wpdb;
        $results = array();

        $tables = $wpdb->get_results("SHOW TABLE STATUS FROM `" . DB_NAME . "`", ARRAY_A);

        foreach ($tables as $table) {
            $table_name = $table['Name'];

            // Only optimize WordPress tables
            if (strpos($table_name, $wpdb->prefix) !== 0) {
                continue;
            }

            $result = $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
            $results[$table_name] = $result !== false ? 'optimized' : 'failed';
        }

        return $results;
    }

    /**
     * Get table sizes
     */
    public function get_table_sizes() {
        global $wpdb;
        $tables = array();

        $results = $wpdb->get_results("SHOW TABLE STATUS FROM `" . DB_NAME . "`", ARRAY_A);

        foreach ($results as $table) {
            if (strpos($table['Name'], $wpdb->prefix) !== 0) {
                continue;
            }

            $tables[] = array(
                'name' => $table['Name'],
                'rows' => (int) $table['Rows'],
                'data_size' => (int) $table['Data_length'],
                'index_size' => (int) $table['Index_length'],
                'total_size' => (int) $table['Data_length'] + (int) $table['Index_length'],
                'engine' => $table['Engine'],
                'overhead' => (int) $table['Data_free']
            );
        }

        // Sort by total size descending
        usort($tables, function($a, $b) {
            return $b['total_size'] <=> $a['total_size'];
        });

        return $tables;
    }

    /**
     * Get autoloaded options
     */
    public function get_autoloaded_options() {
        global $wpdb;

        $options = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as size
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             ORDER BY size DESC
             LIMIT 50"
        );

        return $options;
    }

    /**
     * Disable autoload for specific option
     */
    public function disable_autoload($option_name) {
        global $wpdb;

        // Whitelist - don't disable critical options
        $critical_options = array(
            'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register',
            'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies',
            'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt',
            'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port',
            'default_category', 'default_comment_status', 'default_ping_status',
            'default_pingback_flag', 'posts_per_page', 'date_format', 'time_format',
            'links_updated_date_format', 'comment_moderation', 'moderation_notify',
            'permalink_structure', 'rewrite_rules', 'hack_file', 'blog_charset',
            'moderation_keys', 'active_plugins', 'category_base', 'ping_sites',
            'comment_max_links', 'gmt_offset', 'default_email_category', 'template',
            'stylesheet', 'comment_whitelist', 'blacklist_keys', 'comment_registration',
            'html_type', 'use_trackback', 'default_role', 'db_version', 'uploads_use_yearmonth_folders',
            'upload_path', 'blog_public', 'default_link_category', 'show_on_front',
            'tag_base', 'show_avatars', 'avatar_rating', 'upload_url_path',
            'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w',
            'medium_size_h', 'avatar_default', 'large_size_w', 'large_size_h',
            'image_default_link_type', 'image_default_size', 'image_default_align',
            'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments',
            'thread_comments_depth', 'page_comments', 'comments_per_page',
            'default_comments_page', 'comment_order', 'sticky_posts', 'widget_categories',
            'widget_text', 'widget_rss', 'uninstall_plugins', 'timezone_string',
            'page_for_posts', 'page_on_front', 'default_post_format', 'link_manager_enabled',
            'finished_splitting_shared_terms', 'site_icon', 'medium_large_size_w',
            'medium_large_size_h', 'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in',
            'initial_db_version', 'wp_user_roles', 'WPLANG', 'current_theme',
            'cron', 'recently_edited'
        );

        if (in_array($option_name, $critical_options)) {
            return false;
        }

        return $wpdb->update(
            $wpdb->options,
            array('autoload' => 'no'),
            array('option_name' => $option_name)
        );
    }

    /**
     * Get large postmeta entries
     */
    public function get_large_postmeta($limit = 50) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_key, LENGTH(pm.meta_value) as size, p.post_title, p.post_type
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 ORDER BY size DESC
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Daily cleanup cron job
     */
    public function daily_cleanup() {
        $options = get_option('wcsu_options', array());

        if (!empty($options['auto_cleanup'])) {
            $this->clean('expired_transients');
            $this->clean('spam_comments');
            $this->clean('trashed_comments');

            if (class_exists('WooCommerce')) {
                $this->clean('wc_sessions');
            }

            // Log the cleanup
            update_option('wcsu_last_auto_cleanup', array(
                'time' => current_time('timestamp'),
                'items_cleaned' => 'transients, spam, trash, sessions'
            ));
        }
    }

    /**
     * AJAX handler for database cleanup
     */
    public function ajax_cleanup() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';

        if (empty($type)) {
            wp_send_json_error(__('Invalid cleanup type', 'wc-speedup'));
        }

        $deleted = $this->clean($type);

        wp_send_json_success(array(
            'deleted' => $deleted,
            'message' => sprintf(__('Deleted %d items', 'wc-speedup'), $deleted)
        ));
    }

    /**
     * AJAX handler for table optimization
     */
    public function ajax_optimize_tables() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $results = $this->optimize_tables();

        wp_send_json_success(array(
            'results' => $results,
            'message' => sprintf(__('Optimized %d tables', 'wc-speedup'), count($results))
        ));
    }

    /**
     * Get database overview
     */
    public function get_database_overview() {
        global $wpdb;

        $total_size = 0;
        $total_tables = 0;
        $total_overhead = 0;

        $tables = $wpdb->get_results("SHOW TABLE STATUS FROM `" . DB_NAME . "`", ARRAY_A);

        foreach ($tables as $table) {
            if (strpos($table['Name'], $wpdb->prefix) !== 0) {
                continue;
            }
            $total_tables++;
            $total_size += (int) $table['Data_length'] + (int) $table['Index_length'];
            $total_overhead += (int) $table['Data_free'];
        }

        // Get posts count
        $posts_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");

        // Get comments count
        $comments_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}");

        // Get users count
        $users_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

        // Get options count
        $options_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");

        return array(
            'total_size' => $total_size,
            'total_tables' => $total_tables,
            'total_overhead' => $total_overhead,
            'posts_count' => (int) $posts_count,
            'comments_count' => (int) $comments_count,
            'users_count' => (int) $users_count,
            'options_count' => (int) $options_count
        );
    }
}
