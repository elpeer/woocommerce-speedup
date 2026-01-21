<?php
/**
 * Query Profiler Module - מזהה שאילתות איטיות
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Query_Profiler {

    /**
     * Logged queries
     */
    private $queries = array();

    /**
     * Start time
     */
    private $start_time;

    /**
     * Is profiling enabled
     */
    private $profiling_enabled = false;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_get_query_log', array($this, 'ajax_get_query_log'));
        add_action('wp_ajax_wcsu_analyze_autoload', array($this, 'ajax_analyze_autoload'));
        add_action('wp_ajax_wcsu_fix_autoload', array($this, 'ajax_fix_autoload'));
        add_action('wp_ajax_wcsu_check_indexes', array($this, 'ajax_check_indexes'));
        add_action('wp_ajax_wcsu_add_index', array($this, 'ajax_add_index'));
    }

    /**
     * Initialize profiler
     */
    public function init() {
        $options = get_option('wcsu_options', array());

        // Enable query profiling if requested
        if (!empty($options['enable_query_profiler']) || isset($_GET['wcsu_profile'])) {
            $this->enable_profiling();
        }

        // Add admin bar menu for quick access
        if (current_user_can('manage_options')) {
            add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 999);
            add_action('shutdown', array($this, 'log_page_stats'), 0);
        }
    }

    /**
     * Enable query profiling
     */
    public function enable_profiling() {
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        $this->profiling_enabled = true;
        $this->start_time = microtime(true);
    }

    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        global $wpdb;

        $total_time = microtime(true) - $this->start_time;
        $query_count = $wpdb->num_queries;

        // Calculate total query time
        $query_time = 0;
        if (!empty($wpdb->queries)) {
            foreach ($wpdb->queries as $query) {
                $query_time += $query[1];
            }
        }

        $color = $query_time > 1 ? '#dc3545' : ($query_time > 0.5 ? '#ffc107' : '#28a745');

        $wp_admin_bar->add_node(array(
            'id' => 'wcsu-profiler',
            'title' => sprintf(
                '<span style="color:%s">⚡ %d queries (%.3fs)</span>',
                $color,
                $query_count,
                $query_time
            ),
            'href' => admin_url('admin.php?page=wc-speedup-profiler')
        ));

        // Add sub-items
        $wp_admin_bar->add_node(array(
            'parent' => 'wcsu-profiler',
            'id' => 'wcsu-profiler-page',
            'title' => sprintf(__('Page Load: %.3fs', 'wc-speedup'), $total_time),
            'href' => '#'
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'wcsu-profiler',
            'id' => 'wcsu-profiler-queries',
            'title' => sprintf(__('DB Queries: %.3fs', 'wc-speedup'), $query_time),
            'href' => admin_url('admin.php?page=wc-speedup-profiler')
        ));

        $memory = memory_get_peak_usage(true);
        $wp_admin_bar->add_node(array(
            'parent' => 'wcsu-profiler',
            'id' => 'wcsu-profiler-memory',
            'title' => sprintf(__('Memory: %s', 'wc-speedup'), size_format($memory)),
            'href' => '#'
        ));
    }

    /**
     * Log page stats
     */
    public function log_page_stats() {
        if (!$this->profiling_enabled) {
            return;
        }

        global $wpdb;

        $stats = array(
            'url' => $_SERVER['REQUEST_URI'],
            'time' => microtime(true) - $this->start_time,
            'query_count' => $wpdb->num_queries,
            'query_time' => 0,
            'memory' => memory_get_peak_usage(true),
            'slow_queries' => array(),
            'timestamp' => current_time('timestamp')
        );

        if (!empty($wpdb->queries)) {
            foreach ($wpdb->queries as $query) {
                $stats['query_time'] += $query[1];

                // Log slow queries (> 0.05 seconds)
                if ($query[1] > 0.05) {
                    $stats['slow_queries'][] = array(
                        'sql' => $query[0],
                        'time' => $query[1],
                        'caller' => $query[2]
                    );
                }
            }
        }

        // Store last 50 page loads
        $log = get_option('wcsu_query_log', array());
        array_unshift($log, $stats);
        $log = array_slice($log, 0, 50);
        update_option('wcsu_query_log', $log, false);
    }

    /**
     * Get slow queries from current request
     */
    public function get_slow_queries($threshold = 0.01) {
        global $wpdb;

        $slow = array();

        if (empty($wpdb->queries)) {
            return $slow;
        }

        foreach ($wpdb->queries as $query) {
            if ($query[1] >= $threshold) {
                $slow[] = array(
                    'sql' => $query[0],
                    'time' => round($query[1] * 1000, 2), // Convert to ms
                    'caller' => $this->parse_caller($query[2])
                );
            }
        }

        // Sort by time descending
        usort($slow, function($a, $b) {
            return $b['time'] <=> $a['time'];
        });

        return $slow;
    }

    /**
     * Parse caller string to identify source
     */
    private function parse_caller($caller) {
        $parts = explode(', ', $caller);
        $source = array(
            'function' => '',
            'file' => '',
            'plugin' => '',
            'type' => 'core'
        );

        foreach ($parts as $part) {
            // Check if it's a plugin
            if (strpos($part, 'plugins/') !== false) {
                preg_match('/plugins\/([^\/]+)/', $part, $matches);
                if (!empty($matches[1])) {
                    $source['plugin'] = $matches[1];
                    $source['type'] = 'plugin';
                }
            }
            // Check if it's a theme
            elseif (strpos($part, 'themes/') !== false) {
                preg_match('/themes\/([^\/]+)/', $part, $matches);
                if (!empty($matches[1])) {
                    $source['plugin'] = $matches[1];
                    $source['type'] = 'theme';
                }
            }
        }

        $source['function'] = end($parts);
        return $source;
    }

    /**
     * Analyze autoloaded options
     */
    public function analyze_autoload() {
        global $wpdb;

        $results = array(
            'total_size' => 0,
            'total_count' => 0,
            'large_options' => array(),
            'by_plugin' => array(),
            'recommendations' => array()
        );

        // Get all autoloaded options with their sizes
        $options = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as size, option_value
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             ORDER BY size DESC"
        );

        foreach ($options as $option) {
            $results['total_size'] += $option->size;
            $results['total_count']++;

            // Identify plugin/source from option name
            $plugin = $this->identify_option_source($option->option_name);

            if (!isset($results['by_plugin'][$plugin])) {
                $results['by_plugin'][$plugin] = array(
                    'size' => 0,
                    'count' => 0,
                    'options' => array()
                );
            }

            $results['by_plugin'][$plugin]['size'] += $option->size;
            $results['by_plugin'][$plugin]['count']++;

            // Track large options (> 10KB)
            if ($option->size > 10000) {
                $results['large_options'][] = array(
                    'name' => $option->option_name,
                    'size' => $option->size,
                    'plugin' => $plugin,
                    'can_disable' => $this->can_disable_autoload($option->option_name)
                );
                $results['by_plugin'][$plugin]['options'][] = $option->option_name;
            }
        }

        // Sort by_plugin by size
        uasort($results['by_plugin'], function($a, $b) {
            return $b['size'] <=> $a['size'];
        });

        // Generate recommendations
        if ($results['total_size'] > 1000000) { // > 1MB
            $results['recommendations'][] = array(
                'type' => 'critical',
                'message' => sprintf(
                    __('Autoloaded data is %s - this significantly slows down every page load!', 'wc-speedup'),
                    size_format($results['total_size'])
                )
            );
        }

        foreach ($results['large_options'] as $option) {
            if ($option['can_disable']) {
                $results['recommendations'][] = array(
                    'type' => 'action',
                    'message' => sprintf(
                        __('Option "%s" (%s) can be set to not autoload', 'wc-speedup'),
                        $option['name'],
                        size_format($option['size'])
                    ),
                    'option' => $option['name']
                );
            }
        }

        return $results;
    }

    /**
     * Identify which plugin an option belongs to
     */
    private function identify_option_source($option_name) {
        $prefixes = array(
            'woocommerce' => 'WooCommerce',
            'wc_' => 'WooCommerce',
            'widget_woocommerce' => 'WooCommerce',
            'jetpack' => 'Jetpack',
            'yoast' => 'Yoast SEO',
            'wpseo' => 'Yoast SEO',
            'elementor' => 'Elementor',
            'wpforms' => 'WPForms',
            'wordfence' => 'Wordfence',
            'akismet' => 'Akismet',
            'litespeed' => 'LiteSpeed',
            'wpml' => 'WPML',
            'icl_' => 'WPML',
            'rank_math' => 'Rank Math',
            'aioseo' => 'All in One SEO',
            'widget_' => 'Widgets',
            'theme_mods' => 'Theme',
            'sidebars_widgets' => 'WordPress Core',
            'cron' => 'WordPress Core',
            'rewrite_rules' => 'WordPress Core',
            'active_plugins' => 'WordPress Core',
            '_site_transient' => 'Transients',
            '_transient' => 'Transients'
        );

        foreach ($prefixes as $prefix => $plugin) {
            if (strpos($option_name, $prefix) === 0) {
                return $plugin;
            }
        }

        return 'Other';
    }

    /**
     * Check if autoload can be disabled for an option
     */
    private function can_disable_autoload($option_name) {
        $critical = array(
            'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email',
            'users_can_register', 'start_of_week', 'date_format', 'time_format',
            'permalink_structure', 'rewrite_rules', 'active_plugins', 'template',
            'stylesheet', 'current_theme', 'default_role', 'db_version',
            'blog_charset', 'blog_public', 'WPLANG', 'wp_user_roles',
            'cron', 'widget_', 'sidebars_widgets', 'theme_mods_'
        );

        foreach ($critical as $prefix) {
            if (strpos($option_name, $prefix) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Disable autoload for an option
     */
    public function disable_autoload($option_name) {
        global $wpdb;

        if (!$this->can_disable_autoload($option_name)) {
            return false;
        }

        return $wpdb->update(
            $wpdb->options,
            array('autoload' => 'no'),
            array('option_name' => $option_name)
        );
    }

    /**
     * Check for missing indexes
     */
    public function check_indexes() {
        global $wpdb;

        $results = array(
            'missing' => array(),
            'recommendations' => array()
        );

        // Check postmeta index
        $postmeta_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = 'meta_value'");
        if (empty($postmeta_index)) {
            $results['missing'][] = array(
                'table' => $wpdb->postmeta,
                'index' => 'meta_value',
                'reason' => __('Speeds up queries filtering by meta_value', 'wc-speedup'),
                'sql' => "ALTER TABLE {$wpdb->postmeta} ADD INDEX meta_value (meta_value(191))"
            );
        }

        // Check options autoload index
        $options_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->options} WHERE Key_name = 'autoload'");
        if (empty($options_index)) {
            $results['missing'][] = array(
                'table' => $wpdb->options,
                'index' => 'autoload',
                'reason' => __('Speeds up loading autoloaded options', 'wc-speedup'),
                'sql' => "ALTER TABLE {$wpdb->options} ADD INDEX autoload (autoload)"
            );
        }

        // Check WooCommerce tables
        if (class_exists('WooCommerce')) {
            // Order items
            $order_items_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}woocommerce_order_items WHERE Key_name = 'order_id'");
            if (empty($order_items_index)) {
                $results['missing'][] = array(
                    'table' => $wpdb->prefix . 'woocommerce_order_items',
                    'index' => 'order_id',
                    'reason' => __('Speeds up order item queries', 'wc-speedup'),
                    'sql' => "ALTER TABLE {$wpdb->prefix}woocommerce_order_items ADD INDEX order_id (order_id)"
                );
            }

            // Order itemmeta
            $order_itemmeta_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE Key_name = 'meta_key'");
            if (empty($order_itemmeta_index)) {
                $results['missing'][] = array(
                    'table' => $wpdb->prefix . 'woocommerce_order_itemmeta',
                    'index' => 'meta_key',
                    'reason' => __('Speeds up order item meta queries', 'wc-speedup'),
                    'sql' => "ALTER TABLE {$wpdb->prefix}woocommerce_order_itemmeta ADD INDEX meta_key (meta_key(191))"
                );
            }
        }

        // Check for slow query patterns
        $slow_patterns = $this->detect_slow_query_patterns();
        if (!empty($slow_patterns)) {
            $results['recommendations'] = array_merge($results['recommendations'], $slow_patterns);
        }

        return $results;
    }

    /**
     * Detect slow query patterns
     */
    private function detect_slow_query_patterns() {
        global $wpdb;

        $recommendations = array();

        // Check for too many autoloaded transients
        $transient_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE autoload = 'yes' AND option_name LIKE '%_transient_%'"
        );
        if ($transient_count > 100) {
            $recommendations[] = array(
                'type' => 'warning',
                'message' => sprintf(
                    __('%d transients are set to autoload - this slows down every page', 'wc-speedup'),
                    $transient_count
                )
            );
        }

        // Check for large postmeta table
        $postmeta_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
        if ($postmeta_count > 500000) {
            $recommendations[] = array(
                'type' => 'warning',
                'message' => sprintf(
                    __('postmeta table has %s rows - consider cleaning orphaned meta', 'wc-speedup'),
                    number_format($postmeta_count)
                )
            );
        }

        // Check for WooCommerce sessions
        if (class_exists('WooCommerce')) {
            $session_count = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions"
            );
            if ($session_count > 10000) {
                $recommendations[] = array(
                    'type' => 'warning',
                    'message' => sprintf(
                        __('%s WooCommerce sessions stored - clean old sessions', 'wc-speedup'),
                        number_format($session_count)
                    )
                );
            }
        }

        return $recommendations;
    }

    /**
     * Add missing index - with safe error handling
     */
    public function add_index($table, $index_name, $sql) {
        global $wpdb;

        // Verify the SQL is safe (only ALTER TABLE ADD INDEX)
        if (strpos($sql, 'ALTER TABLE') !== 0 || strpos($sql, 'ADD INDEX') === false) {
            return array('success' => false, 'message' => __('Invalid SQL statement', 'wc-speedup'));
        }

        // Sanitize table and index names
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $index_name = preg_replace('/[^a-zA-Z0-9_]/', '', $index_name);

        // Verify table exists using information_schema (safer method)
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ));

        if (!$table_exists) {
            return array('success' => false, 'message' => sprintf(__('Table %s does not exist', 'wc-speedup'), $table));
        }

        // Check if index already exists using information_schema
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = %s",
            DB_NAME,
            $table,
            $index_name
        ));

        if ($index_exists) {
            return array('success' => true, 'message' => __('Index already exists', 'wc-speedup'));
        }

        // Suppress errors and attempt to add the index
        $wpdb->suppress_errors(true);
        $result = $wpdb->query($sql);
        $last_error = $wpdb->last_error;
        $wpdb->suppress_errors(false);

        if ($result === false || !empty($last_error)) {
            return array('success' => false, 'message' => sprintf(__('Failed to add index: %s', 'wc-speedup'), $last_error));
        }

        return array('success' => true, 'message' => __('Index added successfully', 'wc-speedup'));
    }

    /**
     * Get query statistics
     */
    public function get_query_stats() {
        $log = get_option('wcsu_query_log', array());

        if (empty($log)) {
            return null;
        }

        $stats = array(
            'avg_queries' => 0,
            'avg_query_time' => 0,
            'avg_page_time' => 0,
            'slowest_pages' => array(),
            'most_queries' => array(),
            'slow_query_sources' => array()
        );

        $total_queries = 0;
        $total_query_time = 0;
        $total_page_time = 0;

        foreach ($log as $entry) {
            $total_queries += $entry['query_count'];
            $total_query_time += $entry['query_time'];
            $total_page_time += $entry['time'];

            // Track slow query sources
            foreach ($entry['slow_queries'] as $slow) {
                $source = $slow['caller']['plugin'] ?: $slow['caller']['type'];
                if (!isset($stats['slow_query_sources'][$source])) {
                    $stats['slow_query_sources'][$source] = array(
                        'count' => 0,
                        'total_time' => 0
                    );
                }
                $stats['slow_query_sources'][$source]['count']++;
                $stats['slow_query_sources'][$source]['total_time'] += $slow['time'];
            }
        }

        $count = count($log);
        $stats['avg_queries'] = round($total_queries / $count);
        $stats['avg_query_time'] = round($total_query_time / $count, 3);
        $stats['avg_page_time'] = round($total_page_time / $count, 3);

        // Sort pages by query time
        usort($log, function($a, $b) {
            return $b['query_time'] <=> $a['query_time'];
        });
        $stats['slowest_pages'] = array_slice($log, 0, 10);

        // Sort by query count
        usort($log, function($a, $b) {
            return $b['query_count'] <=> $a['query_count'];
        });
        $stats['most_queries'] = array_slice($log, 0, 10);

        // Sort sources by total time
        uasort($stats['slow_query_sources'], function($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });

        return $stats;
    }

    /**
     * AJAX: Get query log
     */
    public function ajax_get_query_log() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $stats = $this->get_query_stats();
        wp_send_json_success($stats);
    }

    /**
     * AJAX: Analyze autoload
     */
    public function ajax_analyze_autoload() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $analysis = $this->analyze_autoload();
        wp_send_json_success($analysis);
    }

    /**
     * AJAX: Fix autoload
     */
    public function ajax_fix_autoload() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $option = isset($_POST['option']) ? sanitize_text_field($_POST['option']) : '';

        if (empty($option)) {
            wp_send_json_error(__('Invalid option', 'wc-speedup'));
        }

        $result = $this->disable_autoload($option);

        if ($result) {
            wp_send_json_success(__('Autoload disabled for option', 'wc-speedup'));
        } else {
            wp_send_json_error(__('Could not disable autoload', 'wc-speedup'));
        }
    }

    /**
     * AJAX: Check indexes
     */
    public function ajax_check_indexes() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $indexes = $this->check_indexes();
        wp_send_json_success($indexes);
    }

    /**
     * AJAX: Add index
     */
    public function ajax_add_index() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $table = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';
        $index = isset($_POST['index']) ? sanitize_text_field($_POST['index']) : '';
        $sql = isset($_POST['sql']) ? wp_unslash($_POST['sql']) : '';

        if (empty($table) || empty($index) || empty($sql)) {
            wp_send_json_error(__('Invalid parameters', 'wc-speedup'));
        }

        $result = $this->add_index($table, $index, $sql);

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}
