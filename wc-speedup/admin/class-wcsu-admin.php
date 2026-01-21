<?php
/**
 * Admin Dashboard - ממשק ניהול הפלאגין
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wcsu_run_diagnostics', array($this, 'ajax_run_diagnostics'));
        add_action('wp_ajax_wcsu_save_options', array($this, 'ajax_save_options'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('WC SpeedUp', 'wc-speedup'),
            __('WC SpeedUp', 'wc-speedup'),
            'manage_options',
            'wc-speedup',
            array($this, 'render_dashboard'),
            'dashicons-performance',
            58
        );

        add_submenu_page(
            'wc-speedup',
            __('Dashboard', 'wc-speedup'),
            __('Dashboard', 'wc-speedup'),
            'manage_options',
            'wc-speedup',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'wc-speedup',
            __('Diagnostics', 'wc-speedup'),
            __('Diagnostics', 'wc-speedup'),
            'manage_options',
            'wc-speedup-diagnostics',
            array($this, 'render_diagnostics')
        );

        add_submenu_page(
            'wc-speedup',
            __('Database', 'wc-speedup'),
            __('Database', 'wc-speedup'),
            'manage_options',
            'wc-speedup-database',
            array($this, 'render_database')
        );

        add_submenu_page(
            'wc-speedup',
            __('Settings', 'wc-speedup'),
            __('Settings', 'wc-speedup'),
            'manage_options',
            'wc-speedup-settings',
            array($this, 'render_settings')
        );

        add_submenu_page(
            'wc-speedup',
            __('Query Profiler', 'wc-speedup'),
            __('Query Profiler', 'wc-speedup'),
            'manage_options',
            'wc-speedup-profiler',
            array($this, 'render_profiler')
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wc-speedup') === false) {
            return;
        }

        wp_enqueue_style(
            'wcsu-admin',
            WCSU_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            WCSU_VERSION
        );

        wp_enqueue_script(
            'wcsu-admin',
            WCSU_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            WCSU_VERSION,
            true
        );

        wp_localize_script('wcsu-admin', 'wcsu_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcsu_nonce'),
            'strings' => array(
                'loading' => __('Loading...', 'wc-speedup'),
                'success' => __('Success!', 'wc-speedup'),
                'error' => __('Error occurred', 'wc-speedup'),
                'confirm_cleanup' => __('Are you sure you want to clean this data? This cannot be undone.', 'wc-speedup'),
                'confirm_optimize' => __('Are you sure you want to optimize all tables?', 'wc-speedup'),
                'running_diagnostics' => __('Running diagnostics...', 'wc-speedup'),
                'cleaning' => __('Cleaning...', 'wc-speedup'),
                'optimizing' => __('Optimizing...', 'wc-speedup'),
                'clearing_cache' => __('Clearing cache...', 'wc-speedup')
            )
        ));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wcsu_options_group', 'wcsu_options');
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard() {
        $diagnostics = wcsu()->diagnostics->run_full_diagnostics();
        $recommendations = wcsu()->diagnostics->get_recommendations();
        $cache_status = wcsu()->cache->get_cache_status();
        $optimizer_status = wcsu()->auto_optimizer->get_status();

        ?>
        <div class="wrap wcsu-wrap">
            <h1><?php _e('WC SpeedUp - Performance Dashboard', 'wc-speedup'); ?></h1>

            <!-- ONE CLICK OPTIMIZER - Main Feature -->
            <div class="wcsu-one-click-optimizer">
                <div class="wcsu-optimizer-header">
                    <div class="wcsu-optimizer-icon">
                        <span class="dashicons dashicons-superhero"></span>
                    </div>
                    <div class="wcsu-optimizer-info">
                        <h2><?php _e('One-Click Database Optimizer', 'wc-speedup'); ?></h2>
                        <p><?php _e('Fix all database performance issues automatically - adds missing indexes, cleans autoload, removes orphaned data, and optimizes tables.', 'wc-speedup'); ?></p>
                    </div>
                </div>

                <div class="wcsu-optimizer-status">
                    <div class="wcsu-status-item wcsu-status-<?php echo $optimizer_status['autoload_status']; ?>">
                        <span class="wcsu-status-label"><?php _e('Autoload Size', 'wc-speedup'); ?></span>
                        <span class="wcsu-status-value"><?php echo size_format($optimizer_status['autoload_size']); ?></span>
                    </div>
                    <div class="wcsu-status-item wcsu-status-<?php echo $optimizer_status['index_status']; ?>">
                        <span class="wcsu-status-label"><?php _e('Missing Indexes', 'wc-speedup'); ?></span>
                        <span class="wcsu-status-value"><?php echo $optimizer_status['missing_indexes']; ?></span>
                    </div>
                    <div class="wcsu-status-item wcsu-status-<?php echo $optimizer_status['transient_status']; ?>">
                        <span class="wcsu-status-label"><?php _e('Expired Transients', 'wc-speedup'); ?></span>
                        <span class="wcsu-status-value"><?php echo number_format($optimizer_status['expired_transients']); ?></span>
                    </div>
                    <div class="wcsu-status-item wcsu-status-<?php echo $optimizer_status['orphan_status']; ?>">
                        <span class="wcsu-status-label"><?php _e('Orphaned Data', 'wc-speedup'); ?></span>
                        <span class="wcsu-status-value"><?php echo number_format($optimizer_status['orphaned_meta']); ?></span>
                    </div>
                </div>

                <div class="wcsu-optimizer-actions">
                    <button class="wcsu-big-button wcsu-auto-optimize-btn" id="wcsu-auto-optimize">
                        <span class="dashicons dashicons-performance"></span>
                        <?php _e('Fix All Issues Now', 'wc-speedup'); ?>
                    </button>

                    <?php if ($optimizer_status['last_optimization']): ?>
                    <p class="wcsu-last-run">
                        <?php printf(
                            __('Last optimization: %s', 'wc-speedup'),
                            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $optimizer_status['last_optimization'])
                        ); ?>
                    </p>
                    <?php endif; ?>
                </div>

                <div id="wcsu-optimize-results" class="wcsu-optimize-results" style="display:none;"></div>
            </div>

            <!-- Score Card -->
            <div class="wcsu-score-card">
                <div class="wcsu-score-circle <?php echo $this->get_score_class($diagnostics['overall_score']); ?>">
                    <span class="wcsu-score-number"><?php echo $diagnostics['overall_score']; ?></span>
                    <span class="wcsu-score-label"><?php _e('Score', 'wc-speedup'); ?></span>
                </div>
                <div class="wcsu-score-details">
                    <h2><?php _e('Performance Score', 'wc-speedup'); ?></h2>
                    <p><?php echo $this->get_score_message($diagnostics['overall_score']); ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="wcsu-quick-actions">
                <h2><?php _e('Quick Actions', 'wc-speedup'); ?></h2>
                <div class="wcsu-actions-grid">
                    <button class="wcsu-action-btn" data-action="clear_cache">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Clear All Caches', 'wc-speedup'); ?>
                    </button>
                    <button class="wcsu-action-btn" data-action="run_diagnostics">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('Run Diagnostics', 'wc-speedup'); ?>
                    </button>
                    <button class="wcsu-action-btn" data-action="cleanup_all">
                        <span class="dashicons dashicons-database-remove"></span>
                        <?php _e('Clean Database', 'wc-speedup'); ?>
                    </button>
                    <button class="wcsu-action-btn" data-action="optimize_tables">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php _e('Optimize Tables', 'wc-speedup'); ?>
                    </button>
                </div>
            </div>

            <!-- Recommendations -->
            <?php if (!empty($recommendations)): ?>
            <div class="wcsu-recommendations">
                <h2><?php _e('Recommendations', 'wc-speedup'); ?></h2>
                <div class="wcsu-rec-list">
                    <?php foreach (array_slice($recommendations, 0, 5) as $rec): ?>
                    <div class="wcsu-rec-item wcsu-rec-<?php echo esc_attr($rec['priority']); ?>">
                        <span class="wcsu-rec-priority"><?php echo ucfirst($rec['priority']); ?></span>
                        <div class="wcsu-rec-content">
                            <strong><?php echo esc_html($rec['label']); ?></strong>
                            <p><?php echo esc_html($rec['message']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="<?php echo admin_url('admin.php?page=wc-speedup-diagnostics'); ?>" class="button">
                    <?php _e('View All Diagnostics', 'wc-speedup'); ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Status Overview -->
            <div class="wcsu-status-grid">
                <!-- Cache Status -->
                <div class="wcsu-status-box">
                    <h3><?php _e('Cache Status', 'wc-speedup'); ?></h3>
                    <ul>
                        <li>
                            <span><?php _e('Object Cache:', 'wc-speedup'); ?></span>
                            <span class="wcsu-status-<?php echo $cache_status['object_cache']['enabled'] ? 'good' : 'warning'; ?>">
                                <?php echo $cache_status['object_cache']['type']; ?>
                            </span>
                        </li>
                        <li>
                            <span><?php _e('Page Cache:', 'wc-speedup'); ?></span>
                            <span class="wcsu-status-<?php echo $cache_status['page_cache']['enabled'] ? 'good' : 'bad'; ?>">
                                <?php echo $cache_status['page_cache']['plugin']; ?>
                            </span>
                        </li>
                        <li>
                            <span><?php _e('OPcache:', 'wc-speedup'); ?></span>
                            <span class="wcsu-status-<?php echo $cache_status['opcache']['enabled'] ? 'good' : 'warning'; ?>">
                                <?php echo $cache_status['opcache']['enabled'] ? __('Enabled', 'wc-speedup') : __('Disabled', 'wc-speedup'); ?>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Server Status -->
                <div class="wcsu-status-box">
                    <h3><?php _e('Server Status', 'wc-speedup'); ?></h3>
                    <ul>
                        <?php foreach ($diagnostics['server'] as $key => $check): ?>
                        <li>
                            <span><?php echo esc_html($check['label']); ?>:</span>
                            <span class="wcsu-status-<?php echo esc_attr($check['status']); ?>">
                                <?php echo esc_html($check['value']); ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Database Status -->
                <div class="wcsu-status-box">
                    <h3><?php _e('Database Status', 'wc-speedup'); ?></h3>
                    <ul>
                        <?php foreach (array_slice($diagnostics['database'], 0, 5) as $key => $check): ?>
                        <li>
                            <span><?php echo esc_html($check['label']); ?>:</span>
                            <span class="wcsu-status-<?php echo esc_attr($check['status']); ?>">
                                <?php echo esc_html($check['value']); ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo admin_url('admin.php?page=wc-speedup-database'); ?>" class="button button-small">
                        <?php _e('Manage Database', 'wc-speedup'); ?>
                    </a>
                </div>

                <!-- WooCommerce Status -->
                <?php if (class_exists('WooCommerce')): ?>
                <div class="wcsu-status-box">
                    <h3><?php _e('WooCommerce Status', 'wc-speedup'); ?></h3>
                    <ul>
                        <?php foreach ($diagnostics['woocommerce'] as $key => $check): ?>
                        <li>
                            <span><?php echo esc_html($check['label']); ?>:</span>
                            <span class="wcsu-status-<?php echo esc_attr($check['status']); ?>">
                                <?php echo esc_html($check['value']); ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /**
     * Render diagnostics page
     */
    public function render_diagnostics() {
        $diagnostics = wcsu()->diagnostics->run_full_diagnostics();

        ?>
        <div class="wrap wcsu-wrap">
            <h1><?php _e('Performance Diagnostics', 'wc-speedup'); ?></h1>

            <div class="wcsu-diagnostics-header">
                <div class="wcsu-score-mini <?php echo $this->get_score_class($diagnostics['overall_score']); ?>">
                    <span><?php echo $diagnostics['overall_score']; ?>/100</span>
                </div>
                <button class="button button-primary" id="wcsu-run-diagnostics">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Run Again', 'wc-speedup'); ?>
                </button>
            </div>

            <?php
            $categories = array(
                'server' => __('Server Configuration', 'wc-speedup'),
                'wordpress' => __('WordPress Configuration', 'wc-speedup'),
                'woocommerce' => __('WooCommerce Configuration', 'wc-speedup'),
                'database' => __('Database Status', 'wc-speedup'),
                'plugins' => __('Plugins', 'wc-speedup'),
                'theme' => __('Theme', 'wc-speedup'),
                'caching' => __('Caching', 'wc-speedup')
            );

            foreach ($categories as $key => $title):
                if (!isset($diagnostics[$key]) || !is_array($diagnostics[$key])) continue;
            ?>
            <div class="wcsu-diagnostic-section">
                <h2><?php echo esc_html($title); ?></h2>
                <table class="wcsu-diagnostic-table">
                    <thead>
                        <tr>
                            <th><?php _e('Check', 'wc-speedup'); ?></th>
                            <th><?php _e('Value', 'wc-speedup'); ?></th>
                            <th><?php _e('Status', 'wc-speedup'); ?></th>
                            <th><?php _e('Recommendation', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diagnostics[$key] as $check_key => $check): ?>
                        <tr class="wcsu-row-<?php echo esc_attr($check['status']); ?>">
                            <td><?php echo esc_html($check['label']); ?></td>
                            <td><?php echo esc_html($check['value']); ?></td>
                            <td>
                                <span class="wcsu-status-badge wcsu-status-<?php echo esc_attr($check['status']); ?>">
                                    <?php echo $this->get_status_label($check['status']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($check['message']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>

        </div>
        <?php
    }

    /**
     * Render database page
     */
    public function render_database() {
        $cleanup_stats = wcsu()->database->get_cleanup_stats();
        $table_sizes = wcsu()->database->get_table_sizes();
        $db_overview = wcsu()->database->get_database_overview();
        $autoloaded = wcsu()->database->get_autoloaded_options();

        ?>
        <div class="wrap wcsu-wrap">
            <h1><?php _e('Database Optimization', 'wc-speedup'); ?></h1>

            <!-- Database Overview -->
            <div class="wcsu-db-overview">
                <div class="wcsu-db-stat">
                    <span class="wcsu-db-stat-value"><?php echo size_format($db_overview['total_size']); ?></span>
                    <span class="wcsu-db-stat-label"><?php _e('Database Size', 'wc-speedup'); ?></span>
                </div>
                <div class="wcsu-db-stat">
                    <span class="wcsu-db-stat-value"><?php echo $db_overview['total_tables']; ?></span>
                    <span class="wcsu-db-stat-label"><?php _e('Tables', 'wc-speedup'); ?></span>
                </div>
                <div class="wcsu-db-stat">
                    <span class="wcsu-db-stat-value"><?php echo size_format($db_overview['total_overhead']); ?></span>
                    <span class="wcsu-db-stat-label"><?php _e('Overhead', 'wc-speedup'); ?></span>
                </div>
                <div class="wcsu-db-stat">
                    <span class="wcsu-db-stat-value"><?php echo number_format($db_overview['posts_count']); ?></span>
                    <span class="wcsu-db-stat-label"><?php _e('Posts', 'wc-speedup'); ?></span>
                </div>
            </div>

            <!-- Cleanup Section -->
            <div class="wcsu-cleanup-section">
                <h2><?php _e('Database Cleanup', 'wc-speedup'); ?></h2>
                <p><?php _e('Remove unnecessary data from your database to improve performance.', 'wc-speedup'); ?></p>

                <table class="wcsu-cleanup-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Item', 'wc-speedup'); ?></th>
                            <th><?php _e('Count', 'wc-speedup'); ?></th>
                            <th><?php _e('Action', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cleanup_stats as $key => $stat): ?>
                        <tr>
                            <td><?php echo esc_html($stat['label']); ?></td>
                            <td>
                                <span class="wcsu-count <?php echo $stat['count'] > 0 ? 'has-items' : ''; ?>">
                                    <?php echo number_format($stat['count']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="button wcsu-cleanup-btn" data-type="<?php echo esc_attr($stat['action']); ?>" <?php echo $stat['count'] === 0 ? 'disabled' : ''; ?>>
                                    <?php _e('Clean', 'wc-speedup'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">
                                <button class="button button-primary wcsu-cleanup-btn" data-type="all">
                                    <?php _e('Clean All', 'wc-speedup'); ?>
                                </button>
                                <button class="button wcsu-optimize-btn">
                                    <?php _e('Optimize Tables', 'wc-speedup'); ?>
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Table Sizes -->
            <div class="wcsu-tables-section">
                <h2><?php _e('Table Sizes', 'wc-speedup'); ?></h2>
                <table class="wcsu-tables-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Table', 'wc-speedup'); ?></th>
                            <th><?php _e('Rows', 'wc-speedup'); ?></th>
                            <th><?php _e('Data Size', 'wc-speedup'); ?></th>
                            <th><?php _e('Index Size', 'wc-speedup'); ?></th>
                            <th><?php _e('Total', 'wc-speedup'); ?></th>
                            <th><?php _e('Overhead', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($table_sizes, 0, 20) as $table): ?>
                        <tr>
                            <td><code><?php echo esc_html($table['name']); ?></code></td>
                            <td><?php echo number_format($table['rows']); ?></td>
                            <td><?php echo size_format($table['data_size']); ?></td>
                            <td><?php echo size_format($table['index_size']); ?></td>
                            <td><?php echo size_format($table['total_size']); ?></td>
                            <td class="<?php echo $table['overhead'] > 0 ? 'wcsu-warning' : ''; ?>">
                                <?php echo size_format($table['overhead']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Autoloaded Options -->
            <div class="wcsu-autoload-section">
                <h2><?php _e('Large Autoloaded Options', 'wc-speedup'); ?></h2>
                <p><?php _e('These options are loaded on every page request. Large autoloaded data can slow down your site.', 'wc-speedup'); ?></p>
                <table class="wcsu-autoload-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Option Name', 'wc-speedup'); ?></th>
                            <th><?php _e('Size', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($autoloaded, 0, 15) as $option): ?>
                        <tr>
                            <td><code><?php echo esc_html($option->option_name); ?></code></td>
                            <td class="<?php echo $option->size > 50000 ? 'wcsu-warning' : ''; ?>">
                                <?php echo size_format($option->size); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings() {
        $options = get_option('wcsu_options', array());

        ?>
        <div class="wrap wcsu-wrap">
            <h1><?php _e('WC SpeedUp Settings', 'wc-speedup'); ?></h1>

            <form method="post" action="" id="wcsu-settings-form">
                <?php wp_nonce_field('wcsu_save_settings', 'wcsu_nonce'); ?>

                <!-- General Optimizations -->
                <div class="wcsu-settings-section">
                    <h2><?php _e('General Optimizations', 'wc-speedup'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Disable Emojis', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_emojis]" value="1" <?php checked(!empty($options['disable_emojis'])); ?>>
                                    <?php _e('Remove WordPress emoji scripts and styles', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable Embeds', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_embeds]" value="1" <?php checked(!empty($options['disable_embeds'])); ?>>
                                    <?php _e('Disable oEmbed functionality', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Limit Revisions', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[limit_revisions]" value="1" <?php checked(!empty($options['limit_revisions'])); ?>>
                                    <?php _e('Limit post revisions to 3', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Optimize Heartbeat', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[optimize_heartbeat]" value="1" <?php checked(!empty($options['optimize_heartbeat'])); ?>>
                                    <?php _e('Reduce heartbeat API frequency to 60 seconds', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable XML-RPC', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_xmlrpc]" value="1" <?php checked(!empty($options['disable_xmlrpc'])); ?>>
                                    <?php _e('Disable XML-RPC (if not using Jetpack or mobile apps)', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Lazy Load Images', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[lazy_load]" value="1" <?php checked(!empty($options['lazy_load'])); ?>>
                                    <?php _e('Add native lazy loading to images', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Remove Query Strings', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[remove_query_strings]" value="1" <?php checked(!empty($options['remove_query_strings'])); ?>>
                                    <?php _e('Remove version query strings from static resources', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- WooCommerce Optimizations -->
                <div class="wcsu-settings-section">
                    <h2><?php _e('WooCommerce Optimizations', 'wc-speedup'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Disable Cart Fragments', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_cart_fragments]" value="1" <?php checked(!empty($options['disable_cart_fragments'])); ?>>
                                    <?php _e('Disable cart fragments AJAX on non-cart pages', 'wc-speedup'); ?>
                                </label>
                                <p class="description"><?php _e('This can significantly improve page load times', 'wc-speedup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable Password Meter', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_password_meter]" value="1" <?php checked(!empty($options['disable_password_meter'])); ?>>
                                    <?php _e('Disable password strength meter on account pages', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Limit Variations', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[limit_variations]" value="1" <?php checked(!empty($options['limit_variations'])); ?>>
                                    <?php _e('Limit AJAX variations threshold to 15', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Conditional WC Assets', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[conditional_wc_assets]" value="1" <?php checked(!empty($options['conditional_wc_assets'])); ?>>
                                    <?php _e('Load WooCommerce assets only on WC pages', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable WC Widgets', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_widgets]" value="1" <?php checked(!empty($options['disable_widgets'])); ?>>
                                    <?php _e('Disable all WooCommerce widgets', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Limit Related Products', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[limit_related_products]" value="1" <?php checked(!empty($options['limit_related_products'])); ?>>
                                    <?php _e('Limit related products to 4', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable WC Admin', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_wc_admin]" value="1" <?php checked(!empty($options['disable_wc_admin'])); ?>>
                                    <?php _e('Disable WooCommerce Admin features', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable Marketing Hub', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[disable_marketing_hub]" value="1" <?php checked(!empty($options['disable_marketing_hub'])); ?>>
                                    <?php _e('Disable WooCommerce Marketing Hub', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Speed Optimizations -->
                <div class="wcsu-settings-section">
                    <h2><?php _e('Speed Optimizations', 'wc-speedup'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Defer JavaScript', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[defer_js]" value="1" <?php checked(!empty($options['defer_js'])); ?>>
                                    <?php _e('Add defer attribute to non-critical scripts', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('DNS Prefetch', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[dns_prefetch]" value="1" <?php checked(!empty($options['dns_prefetch'])); ?>>
                                    <?php _e('Add DNS prefetch for common third-party domains', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Preconnect', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[preconnect]" value="1" <?php checked(!empty($options['preconnect'])); ?>>
                                    <?php _e('Add preconnect for Google Fonts', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Minify HTML', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[minify_html]" value="1" <?php checked(!empty($options['minify_html'])); ?>>
                                    <?php _e('Minify HTML output (remove whitespace)', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Database Maintenance -->
                <div class="wcsu-settings-section">
                    <h2><?php _e('Database Maintenance', 'wc-speedup'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Auto Cleanup', 'wc-speedup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcsu_options[auto_cleanup]" value="1" <?php checked(!empty($options['auto_cleanup'])); ?>>
                                    <?php _e('Automatically clean transients and spam daily', 'wc-speedup'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('Save Settings', 'wc-speedup'); ?></button>
                </p>

            </form>

        </div>
        <?php
    }

    /**
     * Render query profiler page
     */
    public function render_profiler() {
        $options = get_option('wcsu_options', array());
        $autoload_analysis = wcsu()->query_profiler->analyze_autoload();
        $index_check = wcsu()->query_profiler->check_indexes();
        $query_stats = wcsu()->query_profiler->get_query_stats();

        ?>
        <div class="wrap wcsu-wrap">
            <h1><?php _e('Query Profiler - Database Performance Analyzer', 'wc-speedup'); ?></h1>

            <p class="wcsu-intro">
                <?php _e('This tool helps you identify slow database queries, missing indexes, and autoloaded data that slows down every page load.', 'wc-speedup'); ?>
            </p>

            <!-- Enable Profiler -->
            <div class="wcsu-settings-section">
                <h2><?php _e('Enable Query Profiling', 'wc-speedup'); ?></h2>
                <p>
                    <label>
                        <input type="checkbox" id="wcsu-enable-profiler" name="enable_query_profiler" value="1" <?php checked(!empty($options['enable_query_profiler'])); ?>>
                        <?php _e('Enable query profiling (shows query stats in admin bar)', 'wc-speedup'); ?>
                    </label>
                </p>
                <p class="description">
                    <?php _e('When enabled, you will see query count and time in the admin bar. Browse your site to collect data.', 'wc-speedup'); ?>
                </p>
            </div>

            <!-- Autoload Analysis -->
            <div class="wcsu-profiler-section">
                <h2><?php _e('Autoloaded Options Analysis', 'wc-speedup'); ?></h2>
                <p class="description">
                    <?php _e('Autoloaded options are loaded on EVERY page request. Large autoload data is a major cause of slow sites.', 'wc-speedup'); ?>
                </p>

                <div class="wcsu-autoload-summary">
                    <div class="wcsu-db-stat <?php echo $autoload_analysis['total_size'] > 1000000 ? 'wcsu-stat-bad' : ($autoload_analysis['total_size'] > 500000 ? 'wcsu-stat-warning' : 'wcsu-stat-good'); ?>">
                        <span class="wcsu-db-stat-value"><?php echo size_format($autoload_analysis['total_size']); ?></span>
                        <span class="wcsu-db-stat-label"><?php _e('Total Autoload Size', 'wc-speedup'); ?></span>
                    </div>
                    <div class="wcsu-db-stat">
                        <span class="wcsu-db-stat-value"><?php echo number_format($autoload_analysis['total_count']); ?></span>
                        <span class="wcsu-db-stat-label"><?php _e('Autoloaded Options', 'wc-speedup'); ?></span>
                    </div>
                </div>

                <?php if ($autoload_analysis['total_size'] > 800000): ?>
                <div class="wcsu-alert wcsu-alert-danger">
                    <strong><?php _e('Critical:', 'wc-speedup'); ?></strong>
                    <?php _e('Your autoloaded data is very large! This significantly slows down every page load.', 'wc-speedup'); ?>
                </div>
                <?php endif; ?>

                <!-- By Plugin -->
                <h3><?php _e('Autoload Size by Source', 'wc-speedup'); ?></h3>
                <table class="wcsu-profiler-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Source', 'wc-speedup'); ?></th>
                            <th><?php _e('Size', 'wc-speedup'); ?></th>
                            <th><?php _e('Options', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($autoload_analysis['by_plugin'], 0, 15, true) as $plugin => $data): ?>
                        <tr>
                            <td><strong><?php echo esc_html($plugin); ?></strong></td>
                            <td class="<?php echo $data['size'] > 100000 ? 'wcsu-warning' : ''; ?>">
                                <?php echo size_format($data['size']); ?>
                            </td>
                            <td><?php echo number_format($data['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Large Options -->
                <?php if (!empty($autoload_analysis['large_options'])): ?>
                <h3><?php _e('Large Autoloaded Options (> 10KB)', 'wc-speedup'); ?></h3>
                <table class="wcsu-profiler-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Option Name', 'wc-speedup'); ?></th>
                            <th><?php _e('Size', 'wc-speedup'); ?></th>
                            <th><?php _e('Source', 'wc-speedup'); ?></th>
                            <th><?php _e('Action', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($autoload_analysis['large_options'] as $option): ?>
                        <tr>
                            <td><code><?php echo esc_html($option['name']); ?></code></td>
                            <td class="wcsu-warning"><?php echo size_format($option['size']); ?></td>
                            <td><?php echo esc_html($option['plugin']); ?></td>
                            <td>
                                <?php if ($option['can_disable']): ?>
                                <button class="button wcsu-disable-autoload-btn" data-option="<?php echo esc_attr($option['name']); ?>">
                                    <?php _e('Disable Autoload', 'wc-speedup'); ?>
                                </button>
                                <?php else: ?>
                                <span class="wcsu-critical-option"><?php _e('Critical option', 'wc-speedup'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Missing Indexes -->
            <div class="wcsu-profiler-section">
                <h2><?php _e('Database Indexes', 'wc-speedup'); ?></h2>
                <p class="description">
                    <?php _e('Missing indexes can make database queries extremely slow. These indexes are recommended for optimal performance.', 'wc-speedup'); ?>
                </p>

                <?php if (!empty($index_check['missing'])): ?>
                <div class="wcsu-alert wcsu-alert-warning">
                    <strong><?php _e('Missing Indexes Found:', 'wc-speedup'); ?></strong>
                    <?php printf(__('%d recommended indexes are missing from your database.', 'wc-speedup'), count($index_check['missing'])); ?>
                </div>

                <table class="wcsu-profiler-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Table', 'wc-speedup'); ?></th>
                            <th><?php _e('Index', 'wc-speedup'); ?></th>
                            <th><?php _e('Reason', 'wc-speedup'); ?></th>
                            <th><?php _e('Action', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($index_check['missing'] as $index): ?>
                        <tr>
                            <td><code><?php echo esc_html($index['table']); ?></code></td>
                            <td><code><?php echo esc_html($index['index']); ?></code></td>
                            <td><?php echo esc_html($index['reason']); ?></td>
                            <td>
                                <button class="button button-primary wcsu-add-index-btn"
                                        data-table="<?php echo esc_attr($index['table']); ?>"
                                        data-index="<?php echo esc_attr($index['index']); ?>"
                                        data-sql="<?php echo esc_attr($index['sql']); ?>">
                                    <?php _e('Add Index', 'wc-speedup'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="wcsu-alert wcsu-alert-success">
                    <?php _e('All recommended indexes are in place.', 'wc-speedup'); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($index_check['recommendations'])): ?>
                <h3><?php _e('Additional Recommendations', 'wc-speedup'); ?></h3>
                <ul class="wcsu-recommendations-list">
                    <?php foreach ($index_check['recommendations'] as $rec): ?>
                    <li class="wcsu-rec-<?php echo esc_attr($rec['type']); ?>">
                        <?php echo esc_html($rec['message']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Query Stats -->
            <?php if ($query_stats): ?>
            <div class="wcsu-profiler-section">
                <h2><?php _e('Query Statistics (Last 50 Page Loads)', 'wc-speedup'); ?></h2>

                <div class="wcsu-db-overview">
                    <div class="wcsu-db-stat">
                        <span class="wcsu-db-stat-value"><?php echo $query_stats['avg_queries']; ?></span>
                        <span class="wcsu-db-stat-label"><?php _e('Avg Queries/Page', 'wc-speedup'); ?></span>
                    </div>
                    <div class="wcsu-db-stat <?php echo $query_stats['avg_query_time'] > 1 ? 'wcsu-stat-bad' : ($query_stats['avg_query_time'] > 0.5 ? 'wcsu-stat-warning' : 'wcsu-stat-good'); ?>">
                        <span class="wcsu-db-stat-value"><?php echo $query_stats['avg_query_time']; ?>s</span>
                        <span class="wcsu-db-stat-label"><?php _e('Avg Query Time', 'wc-speedup'); ?></span>
                    </div>
                    <div class="wcsu-db-stat <?php echo $query_stats['avg_page_time'] > 3 ? 'wcsu-stat-bad' : ($query_stats['avg_page_time'] > 1.5 ? 'wcsu-stat-warning' : 'wcsu-stat-good'); ?>">
                        <span class="wcsu-db-stat-value"><?php echo $query_stats['avg_page_time']; ?>s</span>
                        <span class="wcsu-db-stat-label"><?php _e('Avg Page Time', 'wc-speedup'); ?></span>
                    </div>
                </div>

                <!-- Slow Query Sources -->
                <?php if (!empty($query_stats['slow_query_sources'])): ?>
                <h3><?php _e('Slow Query Sources', 'wc-speedup'); ?></h3>
                <p class="description"><?php _e('These plugins/themes are generating the most slow queries:', 'wc-speedup'); ?></p>
                <table class="wcsu-profiler-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Source', 'wc-speedup'); ?></th>
                            <th><?php _e('Slow Queries', 'wc-speedup'); ?></th>
                            <th><?php _e('Total Time', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($query_stats['slow_query_sources'], 0, 10, true) as $source => $data): ?>
                        <tr>
                            <td><strong><?php echo esc_html($source); ?></strong></td>
                            <td><?php echo number_format($data['count']); ?></td>
                            <td class="wcsu-warning"><?php echo number_format($data['total_time'], 2); ?>ms</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- Slowest Pages -->
                <?php if (!empty($query_stats['slowest_pages'])): ?>
                <h3><?php _e('Slowest Pages', 'wc-speedup'); ?></h3>
                <table class="wcsu-profiler-table widefat">
                    <thead>
                        <tr>
                            <th><?php _e('URL', 'wc-speedup'); ?></th>
                            <th><?php _e('Queries', 'wc-speedup'); ?></th>
                            <th><?php _e('Query Time', 'wc-speedup'); ?></th>
                            <th><?php _e('Page Time', 'wc-speedup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($query_stats['slowest_pages'], 0, 10) as $page): ?>
                        <tr>
                            <td><code><?php echo esc_html($page['url']); ?></code></td>
                            <td><?php echo number_format($page['query_count']); ?></td>
                            <td class="<?php echo $page['query_time'] > 1 ? 'wcsu-warning' : ''; ?>">
                                <?php echo number_format($page['query_time'], 3); ?>s
                            </td>
                            <td class="<?php echo $page['time'] > 3 ? 'wcsu-warning' : ''; ?>">
                                <?php echo number_format($page['time'], 3); ?>s
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <p>
                    <button class="button" id="wcsu-clear-query-log">
                        <?php _e('Clear Query Log', 'wc-speedup'); ?>
                    </button>
                </p>
            </div>
            <?php else: ?>
            <div class="wcsu-profiler-section">
                <h2><?php _e('Query Statistics', 'wc-speedup'); ?></h2>
                <p><?php _e('No query data collected yet. Enable the profiler and browse your site to collect data.', 'wc-speedup'); ?></p>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Get score class
     */
    private function get_score_class($score) {
        if ($score >= 80) return 'score-good';
        if ($score >= 50) return 'score-warning';
        return 'score-bad';
    }

    /**
     * Get score message
     */
    private function get_score_message($score) {
        if ($score >= 80) {
            return __('Your site is well optimized!', 'wc-speedup');
        }
        if ($score >= 50) {
            return __('There is room for improvement. Check the recommendations below.', 'wc-speedup');
        }
        return __('Your site needs optimization. Follow the recommendations below to improve performance.', 'wc-speedup');
    }

    /**
     * Get status label
     */
    private function get_status_label($status) {
        $labels = array(
            'good' => __('Good', 'wc-speedup'),
            'warning' => __('Warning', 'wc-speedup'),
            'bad' => __('Critical', 'wc-speedup')
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * AJAX handler for running diagnostics
     */
    public function ajax_run_diagnostics() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $diagnostics = wcsu()->diagnostics->run_full_diagnostics();

        wp_send_json_success($diagnostics);
    }

    /**
     * AJAX handler for saving options
     */
    public function ajax_save_options() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        $options = isset($_POST['options']) ? $_POST['options'] : array();

        // Sanitize options
        $sanitized = array();
        foreach ($options as $key => $value) {
            $sanitized[sanitize_key($key)] = absint($value);
        }

        update_option('wcsu_options', $sanitized);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }
}
