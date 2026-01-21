<?php
/**
 * Auto Optimizer - מתקן אוטומטי לבעיות מסד נתונים
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Auto_Optimizer {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_wcsu_auto_optimize', array($this, 'ajax_auto_optimize'));
        add_action('wp_ajax_wcsu_fix_autoload_all', array($this, 'ajax_fix_autoload_all'));
        add_action('wp_ajax_wcsu_add_all_indexes', array($this, 'ajax_add_all_indexes'));
    }

    /**
     * Run full auto optimization
     */
    public function run_auto_optimize() {
        global $wpdb;

        $results = array(
            'success' => true,
            'actions' => array(),
            'errors' => array()
        );

        // 1. Add missing indexes
        $index_results = $this->add_all_indexes();
        $results['actions'][] = array(
            'name' => __('Database Indexes', 'wc-speedup'),
            'result' => $index_results
        );

        // 2. Clean expired transients
        $transient_results = $this->clean_transients();
        $results['actions'][] = array(
            'name' => __('Expired Transients', 'wc-speedup'),
            'result' => $transient_results
        );

        // 3. Fix autoloaded transients
        $autoload_results = $this->fix_autoload_transients();
        $results['actions'][] = array(
            'name' => __('Autoload Optimization', 'wc-speedup'),
            'result' => $autoload_results
        );

        // 4. Clean orphaned data
        $orphan_results = $this->clean_orphaned_data();
        $results['actions'][] = array(
            'name' => __('Orphaned Data', 'wc-speedup'),
            'result' => $orphan_results
        );

        // 5. Optimize tables
        $optimize_results = $this->optimize_tables();
        $results['actions'][] = array(
            'name' => __('Table Optimization', 'wc-speedup'),
            'result' => $optimize_results
        );

        // 6. Clean WooCommerce data
        if (class_exists('WooCommerce')) {
            $wc_results = $this->clean_woocommerce_data();
            $results['actions'][] = array(
                'name' => __('WooCommerce Cleanup', 'wc-speedup'),
                'result' => $wc_results
            );
        }

        // Save optimization log
        update_option('wcsu_last_optimization', array(
            'time' => current_time('timestamp'),
            'results' => $results
        ), false);

        return $results;
    }

    /**
     * Add all recommended indexes
     */
    public function add_all_indexes() {
        global $wpdb;

        $added = 0;
        $skipped = 0;
        $errors = array();

        // Define indexes to add
        $indexes = array();

        // postmeta - meta_value index
        $indexes[] = array(
            'table' => $wpdb->postmeta,
            'name' => 'wcsu_meta_value',
            'column' => 'meta_value(191)'
        );

        // options - autoload index
        $indexes[] = array(
            'table' => $wpdb->options,
            'name' => 'wcsu_autoload',
            'column' => 'autoload'
        );

        // postmeta - compound index for common queries
        $indexes[] = array(
            'table' => $wpdb->postmeta,
            'name' => 'wcsu_post_meta_key',
            'column' => 'post_id, meta_key(191)'
        );

        // WooCommerce specific indexes
        if (class_exists('WooCommerce')) {
            // Check if tables exist before adding indexes
            $wc_tables = array(
                'woocommerce_order_items' => array(
                    array('name' => 'wcsu_order_id', 'column' => 'order_id'),
                    array('name' => 'wcsu_order_type', 'column' => 'order_item_type')
                ),
                'woocommerce_order_itemmeta' => array(
                    array('name' => 'wcsu_item_meta_key', 'column' => 'meta_key(191)')
                ),
                'wc_product_meta_lookup' => array(
                    array('name' => 'wcsu_sku', 'column' => 'sku(100)'),
                    array('name' => 'wcsu_price', 'column' => 'min_price, max_price')
                )
            );

            foreach ($wc_tables as $table_suffix => $table_indexes) {
                $table_name = $wpdb->prefix . $table_suffix;

                // Check if table exists
                $table_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $table_name
                ));

                if ($table_exists) {
                    foreach ($table_indexes as $idx) {
                        $indexes[] = array(
                            'table' => $table_name,
                            'name' => $idx['name'],
                            'column' => $idx['column']
                        );
                    }
                }
            }
        }

        // Add each index
        foreach ($indexes as $index) {
            $result = $this->safe_add_index($index['table'], $index['name'], $index['column']);

            if ($result === true) {
                $added++;
            } elseif ($result === 'exists') {
                $skipped++;
            } else {
                $errors[] = $result;
            }
        }

        return array(
            'added' => $added,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => sprintf(__('Added %d indexes, %d already existed', 'wc-speedup'), $added, $skipped)
        );
    }

    /**
     * Safely add an index with error handling
     */
    private function safe_add_index($table, $index_name, $columns) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ));

        if (!$table_exists) {
            return "Table {$table} does not exist";
        }

        // Check if index already exists
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = %s",
            DB_NAME,
            $table,
            $index_name
        ));

        if ($index_exists) {
            return 'exists';
        }

        // Add the index with error suppression
        $wpdb->suppress_errors(true);
        $sql = "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` ({$columns})";
        $result = $wpdb->query($sql);
        $wpdb->suppress_errors(false);

        if ($result === false) {
            $error = $wpdb->last_error;
            // Check if it's a duplicate key error (index already exists with different name)
            if (strpos($error, 'Duplicate') !== false) {
                return 'exists';
            }
            return "Error adding index to {$table}: {$error}";
        }

        return true;
    }

    /**
     * Clean expired transients
     */
    public function clean_transients() {
        global $wpdb;

        $deleted = 0;

        // Delete expired transients
        $expired = $wpdb->query(
            "DELETE a, b FROM {$wpdb->options} a
             LEFT JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_timeout', '')
             WHERE a.option_name LIKE '%_transient_timeout_%'
             AND a.option_value < UNIX_TIMESTAMP()"
        );

        $deleted += $expired !== false ? $expired : 0;

        // Delete orphaned transient timeouts
        $orphaned = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '%_transient_timeout_%'
             AND option_value < UNIX_TIMESTAMP()"
        );

        $deleted += $orphaned !== false ? $orphaned : 0;

        return array(
            'deleted' => $deleted,
            'message' => sprintf(__('Deleted %d expired transients', 'wc-speedup'), $deleted)
        );
    }

    /**
     * Fix autoloaded transients (set to no)
     */
    public function fix_autoload_transients() {
        global $wpdb;

        // Set transients to not autoload
        $updated = $wpdb->query(
            "UPDATE {$wpdb->options}
             SET autoload = 'no'
             WHERE option_name LIKE '%_transient_%'
             AND autoload = 'yes'"
        );

        return array(
            'updated' => $updated !== false ? $updated : 0,
            'message' => sprintf(__('Set %d transients to not autoload', 'wc-speedup'), $updated)
        );
    }

    /**
     * Clean orphaned data
     */
    public function clean_orphaned_data() {
        global $wpdb;

        $cleaned = 0;

        // Orphaned postmeta
        $result = $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );
        $cleaned += $result !== false ? $result : 0;

        // Orphaned commentmeta
        $result = $wpdb->query(
            "DELETE cm FROM {$wpdb->commentmeta} cm
             LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
             WHERE c.comment_ID IS NULL"
        );
        $cleaned += $result !== false ? $result : 0;

        // Orphaned term relationships
        $result = $wpdb->query(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
             WHERE p.ID IS NULL"
        );
        $cleaned += $result !== false ? $result : 0;

        return array(
            'cleaned' => $cleaned,
            'message' => sprintf(__('Cleaned %d orphaned records', 'wc-speedup'), $cleaned)
        );
    }

    /**
     * Optimize database tables
     */
    public function optimize_tables() {
        global $wpdb;

        $optimized = 0;
        $tables_to_optimize = array(
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->options,
            $wpdb->comments,
            $wpdb->commentmeta,
            $wpdb->terms,
            $wpdb->term_taxonomy,
            $wpdb->term_relationships,
            $wpdb->termmeta
        );

        // Add WooCommerce tables
        if (class_exists('WooCommerce')) {
            $wc_tables = array(
                'woocommerce_sessions',
                'woocommerce_order_items',
                'woocommerce_order_itemmeta',
                'wc_product_meta_lookup'
            );

            foreach ($wc_tables as $wc_table) {
                $full_name = $wpdb->prefix . $wc_table;
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $full_name
                ));
                if ($exists) {
                    $tables_to_optimize[] = $full_name;
                }
            }
        }

        foreach ($tables_to_optimize as $table) {
            $wpdb->suppress_errors(true);
            $result = $wpdb->query("OPTIMIZE TABLE `{$table}`");
            $wpdb->suppress_errors(false);

            if ($result !== false) {
                $optimized++;
            }
        }

        return array(
            'optimized' => $optimized,
            'message' => sprintf(__('Optimized %d tables', 'wc-speedup'), $optimized)
        );
    }

    /**
     * Clean WooCommerce specific data
     */
    public function clean_woocommerce_data() {
        global $wpdb;

        $cleaned = 0;

        // Clean expired sessions
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $sessions_table
        ));

        if ($exists) {
            $result = $wpdb->query(
                "DELETE FROM {$sessions_table} WHERE session_expiry < UNIX_TIMESTAMP()"
            );
            $cleaned += $result !== false ? $result : 0;
        }

        // Clean orphaned order item meta
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $order_itemmeta_table
        ));

        if ($exists) {
            $result = $wpdb->query(
                "DELETE oim FROM {$order_itemmeta_table} oim
                 LEFT JOIN {$order_items_table} oi ON oim.order_item_id = oi.order_item_id
                 WHERE oi.order_item_id IS NULL"
            );
            $cleaned += $result !== false ? $result : 0;
        }

        // Clear WC transients
        wc_delete_product_transients();
        wc_delete_shop_order_transients();

        return array(
            'cleaned' => $cleaned,
            'message' => sprintf(__('Cleaned %d WooCommerce records and cleared transients', 'wc-speedup'), $cleaned)
        );
    }

    /**
     * Fix large autoloaded options
     */
    public function fix_large_autoload() {
        global $wpdb;

        $fixed = 0;

        // Options that are safe to not autoload
        $safe_patterns = array(
            '%_transient_%',
            '%_cache%',
            'rewrite_rules',
            '%_log%',
            '%_backup%',
            '%elementor%',
            '%_version%',
            'wpseo_%',
            'rank_math_%',
            'aioseo_%',
            '%dismissed%',
            '%notice%'
        );

        // Critical options that must remain autoloaded
        $critical = array(
            'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email',
            'active_plugins', 'template', 'stylesheet', 'current_theme',
            'wp_user_roles', 'cron', 'sidebars_widgets'
        );

        // Get large autoloaded options (> 10KB)
        $large_options = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as size
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             AND LENGTH(option_value) > 10000
             ORDER BY size DESC"
        );

        foreach ($large_options as $option) {
            // Skip critical options
            $is_critical = false;
            foreach ($critical as $critical_name) {
                if (strpos($option->option_name, $critical_name) !== false) {
                    $is_critical = true;
                    break;
                }
            }

            if (!$is_critical) {
                $result = $wpdb->update(
                    $wpdb->options,
                    array('autoload' => 'no'),
                    array('option_name' => $option->option_name)
                );

                if ($result) {
                    $fixed++;
                }
            }
        }

        return array(
            'fixed' => $fixed,
            'message' => sprintf(__('Disabled autoload for %d large options', 'wc-speedup'), $fixed)
        );
    }

    /**
     * Get optimization status
     */
    public function get_status() {
        global $wpdb;

        $status = array();

        // Autoload size
        $autoload_size = $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );
        $status['autoload_size'] = (int) $autoload_size;
        $status['autoload_status'] = $autoload_size > 1000000 ? 'bad' : ($autoload_size > 500000 ? 'warning' : 'good');

        // Check for missing indexes
        $missing_indexes = 0;
        $index_check = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = '" . DB_NAME . "'
             AND table_name = '{$wpdb->postmeta}'
             AND index_name = 'wcsu_meta_value'"
        );
        if (!$index_check) $missing_indexes++;

        $index_check = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = '" . DB_NAME . "'
             AND table_name = '{$wpdb->options}'
             AND index_name = 'wcsu_autoload'"
        );
        if (!$index_check) $missing_indexes++;

        $status['missing_indexes'] = $missing_indexes;
        $status['index_status'] = $missing_indexes > 0 ? 'warning' : 'good';

        // Expired transients
        $expired = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '%_transient_timeout_%'
             AND option_value < UNIX_TIMESTAMP()"
        );
        $status['expired_transients'] = (int) $expired;
        $status['transient_status'] = $expired > 100 ? 'warning' : 'good';

        // Orphaned postmeta
        $orphaned = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );
        $status['orphaned_meta'] = (int) $orphaned;
        $status['orphan_status'] = $orphaned > 1000 ? 'warning' : 'good';

        // Overall status
        $bad_count = 0;
        foreach (['autoload_status', 'index_status', 'transient_status', 'orphan_status'] as $key) {
            if ($status[$key] === 'bad') $bad_count += 2;
            if ($status[$key] === 'warning') $bad_count += 1;
        }
        $status['overall'] = $bad_count > 3 ? 'bad' : ($bad_count > 0 ? 'warning' : 'good');

        // Last optimization
        $last = get_option('wcsu_last_optimization');
        $status['last_optimization'] = $last ? $last['time'] : null;

        return $status;
    }

    /**
     * AJAX: Run auto optimization
     */
    public function ajax_auto_optimize() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        // Increase limits for optimization
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $results = $this->run_auto_optimize();

        wp_send_json_success($results);
    }

    /**
     * AJAX: Fix all autoload issues
     */
    public function ajax_fix_autoload_all() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $results = array();
        $results['transients'] = $this->fix_autoload_transients();
        $results['large'] = $this->fix_large_autoload();

        wp_send_json_success($results);
    }

    /**
     * AJAX: Add all indexes
     */
    public function ajax_add_all_indexes() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        @set_time_limit(300);

        $results = $this->add_all_indexes();

        wp_send_json_success($results);
    }
}
