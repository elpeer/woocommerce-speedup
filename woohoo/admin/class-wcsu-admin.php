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
            __('WooHoo', 'woohoo'),
            __('WooHoo', 'woohoo'),
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

        add_submenu_page(
            'wc-speedup',
            __('Page Cache', 'wc-speedup'),
            __('Page Cache', 'wc-speedup'),
            'manage_options',
            'wc-speedup-page-cache',
            array($this, 'render_page_cache')
        );

        add_submenu_page(
            'wc-speedup',
            __('Performance', 'wc-speedup'),
            __('Performance', 'wc-speedup'),
            'manage_options',
            'wc-speedup-performance',
            array($this, 'render_performance')
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
        $options = get_option('wcsu_options', array());

        // Get page cache stats
        $page_cache_stats = wcsu()->page_cache->get_cache_stats();

        // Count active modules
        $modules = array(
            'enable_cart_fragments_optimizer' => 'Cart Fragments',
            'enable_heartbeat_control' => 'Heartbeat Control',
            'enable_sessions_cleanup' => 'Sessions Cleanup',
            'enable_transients_cleanup' => 'Transients Cleanup',
            'enable_lazy_loading' => 'Lazy Loading',
            'enable_dns_prefetch' => 'DNS Prefetch',
            'enable_browser_caching' => 'Browser Caching',
            'enable_email_queue' => 'Email Queue',
        );
        $active_modules = 0;
        foreach ($modules as $key => $label) {
            if (!empty($options[$key])) {
                $active_modules++;
            }
        }

        ?>
        <div class="wrap wcsu-wrap wcsu-dashboard-new">

            <!-- Header with Score -->
            <div class="wcsu-dashboard-header">
                <div class="wcsu-header-left">
                    <div class="wcsu-logo-container">
                        <?php
                        // Check for logo file with various names
                        $logo_files = array(
                            'woohoo-logo.png', 'woohoo-logo.svg', 'woohoo-logo.jpg',
                            'logo.png', 'logo.svg', 'logo.jpg',
                            'WooHoo-logo.png', 'WooHoo-logo.svg',
                            'woohoo_logo.png', 'woohoo_logo.svg'
                        );
                        $logo_found = false;
                        $logo_url = '';
                        foreach ($logo_files as $logo_file) {
                            if (file_exists(WCSU_PLUGIN_DIR . 'assets/images/' . $logo_file)) {
                                $logo_found = true;
                                $logo_url = WCSU_PLUGIN_URL . 'assets/images/' . $logo_file;
                                break;
                            }
                        }

                        if ($logo_found): ?>
                            <img src="<?php echo esc_url($logo_url); ?>" alt="WooHoo" class="wcsu-logo-img">
                        <?php else: ?>
                            <h1 class="wcsu-logo-text">
                                <span class="woohoo-w">W</span><span class="woohoo-oo">oo</span><span class="woohoo-h">H</span><span class="woohoo-oo2">oo</span>
                            </h1>
                        <?php endif; ?>
                    </div>
                    <p class="wcsu-tagline"><?php _e('Your shop on espresso!', 'woohoo'); ?></p>
                    <p class="wcsu-version">v<?php echo WCSU_VERSION; ?></p>
                </div>
                <div class="wcsu-header-score">
                    <div class="wcsu-score-badge <?php echo $this->get_score_class($diagnostics['overall_score']); ?>">
                        <span class="wcsu-score-number"><?php echo $diagnostics['overall_score']; ?></span>
                        <span class="wcsu-score-text"><?php _e('Performance Score', 'woohoo'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Status Cards Row -->
            <div class="wcsu-cards-row">

                <!-- Page Cache Card -->
                <div class="wcsu-card wcsu-card-cache">
                    <div class="wcsu-card-icon">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <div class="wcsu-card-content">
                        <h3><?php _e('Page Cache', 'wc-speedup'); ?></h3>
                        <div class="wcsu-card-stat">
                            <?php if (!empty($options['enable_page_cache'])): ?>
                                <span class="wcsu-stat-value wcsu-good"><?php echo number_format($page_cache_stats['total_pages']); ?></span>
                                <span class="wcsu-stat-label"><?php _e('Cached Pages', 'wc-speedup'); ?></span>
                            <?php else: ?>
                                <span class="wcsu-stat-value wcsu-warning"><?php _e('Off', 'wc-speedup'); ?></span>
                                <span class="wcsu-stat-label"><?php _e('Not Active', 'wc-speedup'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=wc-speedup-page-cache'); ?>" class="wcsu-card-link">
                        <?php _e('Configure', 'wc-speedup'); ?> →
                    </a>
                </div>

                <!-- Database Card -->
                <div class="wcsu-card wcsu-card-database">
                    <div class="wcsu-card-icon">
                        <span class="dashicons dashicons-admin-tools"></span>
                    </div>
                    <div class="wcsu-card-content">
                        <h3><?php _e('Database', 'wc-speedup'); ?></h3>
                        <div class="wcsu-card-stat">
                            <?php
                            $issues = $optimizer_status['missing_indexes'] + $optimizer_status['expired_transients'] + $optimizer_status['orphaned_meta'];
                            if ($issues > 0): ?>
                                <span class="wcsu-stat-value wcsu-warning"><?php echo number_format($issues); ?></span>
                                <span class="wcsu-stat-label"><?php _e('Issues Found', 'wc-speedup'); ?></span>
                            <?php else: ?>
                                <span class="wcsu-stat-value wcsu-good">✓</span>
                                <span class="wcsu-stat-label"><?php _e('Optimized', 'wc-speedup'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=wc-speedup-database'); ?>" class="wcsu-card-link">
                        <?php _e('Optimize', 'wc-speedup'); ?> →
                    </a>
                </div>

                <!-- Modules Card -->
                <div class="wcsu-card wcsu-card-modules">
                    <div class="wcsu-card-icon">
                        <span class="dashicons dashicons-admin-plugins"></span>
                    </div>
                    <div class="wcsu-card-content">
                        <h3><?php _e('Modules', 'wc-speedup'); ?></h3>
                        <div class="wcsu-card-stat">
                            <span class="wcsu-stat-value <?php echo $active_modules > 0 ? 'wcsu-good' : 'wcsu-warning'; ?>"><?php echo $active_modules; ?>/<?php echo count($modules); ?></span>
                            <span class="wcsu-stat-label"><?php _e('Active', 'wc-speedup'); ?></span>
                        </div>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=wc-speedup-performance'); ?>" class="wcsu-card-link">
                        <?php _e('Manage', 'wc-speedup'); ?> →
                    </a>
                </div>

            </div>

            <!-- One Click Optimizer -->
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

            <!-- Two Column Layout -->
            <div class="wcsu-two-columns">

                <!-- Left Column - Modules Status -->
                <div class="wcsu-column">
                    <div class="wcsu-panel">
                        <h2>
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php _e('Performance Modules', 'wc-speedup'); ?>
                        </h2>
                        <div class="wcsu-modules-list">
                            <?php foreach ($modules as $key => $label):
                                $is_active = !empty($options[$key]);
                            ?>
                            <div class="wcsu-module-item <?php echo $is_active ? 'active' : 'inactive'; ?>">
                                <span class="wcsu-module-status">
                                    <?php if ($is_active): ?>
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-marker"></span>
                                    <?php endif; ?>
                                </span>
                                <span class="wcsu-module-name"><?php echo esc_html($label); ?></span>
                                <span class="wcsu-module-badge <?php echo $is_active ? 'on' : 'off'; ?>">
                                    <?php echo $is_active ? __('ON', 'wc-speedup') : __('OFF', 'wc-speedup'); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?php echo admin_url('admin.php?page=wc-speedup-performance'); ?>" class="button button-primary wcsu-full-button">
                            <?php _e('Configure Modules', 'wc-speedup'); ?>
                        </a>
                    </div>
                </div>

                <!-- Right Column - Recommendations & Quick Actions -->
                <div class="wcsu-column">

                    <!-- Quick Actions -->
                    <div class="wcsu-panel wcsu-quick-actions-panel">
                        <h2>
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php _e('Quick Actions', 'wc-speedup'); ?>
                        </h2>
                        <div class="wcsu-actions-grid">
                            <button class="wcsu-action-btn" data-action="clear_cache">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('Clear Cache', 'wc-speedup'); ?>
                            </button>
                            <button class="wcsu-action-btn" data-action="run_diagnostics">
                                <span class="dashicons dashicons-search"></span>
                                <?php _e('Run Diagnostics', 'wc-speedup'); ?>
                            </button>
                            <button class="wcsu-action-btn" data-action="cleanup_all">
                                <span class="dashicons dashicons-database-remove"></span>
                                <?php _e('Clean DB', 'wc-speedup'); ?>
                            </button>
                            <button class="wcsu-action-btn" data-action="optimize_tables">
                                <span class="dashicons dashicons-admin-tools"></span>
                                <?php _e('Optimize', 'wc-speedup'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Recommendations -->
                    <?php if (!empty($recommendations)): ?>
                    <div class="wcsu-panel wcsu-recommendations-panel">
                        <h2>
                            <span class="dashicons dashicons-lightbulb"></span>
                            <?php _e('Recommendations', 'wc-speedup'); ?>
                        </h2>
                        <div class="wcsu-rec-list">
                            <?php foreach (array_slice($recommendations, 0, 4) as $rec): ?>
                            <div class="wcsu-rec-item wcsu-rec-<?php echo esc_attr($rec['priority']); ?>">
                                <span class="wcsu-rec-priority"><?php echo ucfirst($rec['priority']); ?></span>
                                <div class="wcsu-rec-content">
                                    <strong><?php echo esc_html($rec['label']); ?></strong>
                                    <p><?php echo esc_html($rec['message']); ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?php echo admin_url('admin.php?page=wc-speedup-diagnostics'); ?>" class="button wcsu-full-button">
                            <?php _e('View All', 'wc-speedup'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                </div>

            </div>

            <!-- System Status Row -->
            <div class="wcsu-status-grid">
                <!-- Cache Status -->
                <div class="wcsu-status-box">
                    <h3>
                        <span class="dashicons dashicons-performance"></span>
                        <?php _e('Cache Status', 'wc-speedup'); ?>
                    </h3>
                    <ul>
                        <li>
                            <span><?php _e('Object Cache:', 'wc-speedup'); ?></span>
                            <span class="wcsu-status-<?php echo $cache_status['object_cache']['enabled'] ? 'good' : 'warning'; ?>">
                                <?php echo $cache_status['object_cache']['type']; ?>
                            </span>
                        </li>
                        <li>
                            <span><?php _e('Page Cache:', 'wc-speedup'); ?></span>
                            <span class="wcsu-status-<?php echo !empty($options['enable_page_cache']) ? 'good' : 'bad'; ?>">
                                <?php echo !empty($options['enable_page_cache']) ? __('Active', 'wc-speedup') : __('Inactive', 'wc-speedup'); ?>
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
                    <h3>
                        <span class="dashicons dashicons-cloud"></span>
                        <?php _e('Server Status', 'wc-speedup'); ?>
                    </h3>
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
                    <h3>
                        <span class="dashicons dashicons-database"></span>
                        <?php _e('Database Status', 'wc-speedup'); ?>
                    </h3>
                    <ul>
                        <?php foreach (array_slice($diagnostics['database'], 0, 4) as $key => $check): ?>
                        <li>
                            <span><?php echo esc_html($check['label']); ?>:</span>
                            <span class="wcsu-status-<?php echo esc_attr($check['status']); ?>">
                                <?php echo esc_html($check['value']); ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- WooCommerce Status -->
                <?php if (class_exists('WooCommerce') && !empty($diagnostics['woocommerce'])): ?>
                <div class="wcsu-status-box">
                    <h3>
                        <span class="dashicons dashicons-cart"></span>
                        <?php _e('WooCommerce', 'wc-speedup'); ?>
                    </h3>
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

        <style>
        /* WooHoo Dashboard Styles */
        :root {
            --woohoo-blue: #2B7FD4;
            --woohoo-orange: #F7941D;
        }
        .wcsu-dashboard-new { max-width: 1400px; }

        .wcsu-dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 20px 30px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-top: 4px solid var(--woohoo-blue);
        }
        .wcsu-logo-container { margin-bottom: 5px; }
        .wcsu-logo-img { max-height: 60px; width: auto; }
        .wcsu-logo-text {
            font-size: 36px;
            font-weight: 800;
            margin: 0;
            font-family: 'Arial Black', sans-serif;
        }
        .woohoo-w { color: var(--woohoo-blue); }
        .woohoo-oo { color: var(--woohoo-blue); }
        .woohoo-h { color: var(--woohoo-orange); }
        .woohoo-oo2 { color: var(--woohoo-orange); }
        .wcsu-tagline {
            margin: 5px 0 0;
            font-size: 14px;
            font-style: italic;
            color: #666;
        }
        .wcsu-version {
            margin: 3px 0 0;
            color: #999;
            font-size: 12px;
        }
        .wcsu-score-badge {
            text-align: center;
            padding: 15px 25px;
            background: var(--woohoo-blue);
            border-radius: 10px;
            color: #fff;
        }
        .wcsu-score-badge .wcsu-score-number {
            display: block;
            font-size: 42px;
            font-weight: 700;
            line-height: 1;
        }
        .wcsu-score-badge .wcsu-score-text {
            font-size: 12px;
            opacity: 0.9;
            text-transform: uppercase;
        }
        .wcsu-score-badge.score-good { background: #46b450; }
        .wcsu-score-badge.score-warning { background: var(--woohoo-orange); }
        .wcsu-score-badge.score-bad { background: #dc3545; }

        /* Cards Row */
        .wcsu-cards-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        .wcsu-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            position: relative;
            border-top: 3px solid transparent;
        }
        .wcsu-card-cache { border-top-color: var(--woohoo-blue); }
        .wcsu-card-database { border-top-color: var(--woohoo-orange); }
        .wcsu-card-modules { border-top-color: #46b450; }
        .wcsu-card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        .wcsu-card-icon .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
            color: #fff;
        }
        .wcsu-card-cache .wcsu-card-icon { background: var(--woohoo-blue); }
        .wcsu-card-database .wcsu-card-icon { background: var(--woohoo-orange); }
        .wcsu-card-modules .wcsu-card-icon { background: linear-gradient(135deg, #46b450, #7cc67c); }

        .wcsu-card-content { flex: 1; }
        .wcsu-card-content h3 { margin: 0 0 8px; font-size: 14px; color: #666; }
        .wcsu-card-stat { display: flex; align-items: baseline; gap: 8px; }
        .wcsu-stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .wcsu-stat-value.wcsu-good { color: #46b450; }
        .wcsu-stat-value.wcsu-warning { color: var(--woohoo-orange); }
        .wcsu-stat-label { color: #888; font-size: 13px; }
        .wcsu-card-link {
            position: absolute;
            bottom: 15px;
            right: 20px;
            color: var(--woohoo-blue);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        .wcsu-card-link:hover { color: var(--woohoo-orange); text-decoration: underline; }

        /* Two Columns */
        .wcsu-two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        .wcsu-panel {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .wcsu-panel:last-child { margin-bottom: 0; }
        .wcsu-panel h2 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 15px;
            font-size: 16px;
            color: #333;
        }
        .wcsu-panel h2 .dashicons { color: var(--woohoo-blue); }

        /* Modules List */
        .wcsu-modules-list { margin-bottom: 15px; }
        .wcsu-module-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .wcsu-module-item:last-child { border-bottom: none; }
        .wcsu-module-status { margin-right: 10px; }
        .wcsu-module-item.active .wcsu-module-status .dashicons { color: #46b450; }
        .wcsu-module-item.inactive .wcsu-module-status .dashicons { color: #ccc; }
        .wcsu-module-name { flex: 1; font-size: 14px; }
        .wcsu-module-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: 600;
        }
        .wcsu-module-badge.on { background: #e6f4ea; color: #1e7e34; }
        .wcsu-module-badge.off { background: #f0f0f0; color: #888; }
        .wcsu-full-button { width: 100%; text-align: center; }

        /* Quick Actions Panel */
        .wcsu-quick-actions-panel .wcsu-actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .wcsu-quick-actions-panel .wcsu-action-btn {
            padding: 12px 15px;
            font-size: 13px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .wcsu-cards-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .wcsu-cards-row { grid-template-columns: 1fr; }
            .wcsu-two-columns { grid-template-columns: 1fr; }
        }
        </style>
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
            <h1><?php _e('WooHoo Settings', 'woohoo'); ?></h1>

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

        // Safely get profiler data with error handling
        try {
            $autoload_analysis = wcsu()->query_profiler->analyze_autoload();
        } catch (Exception $e) {
            $autoload_analysis = array(
                'total_size' => 0,
                'total_count' => 0,
                'large_options' => array(),
                'by_plugin' => array(),
                'recommendations' => array()
            );
        }

        try {
            $index_check = wcsu()->query_profiler->check_indexes();
        } catch (Exception $e) {
            $index_check = array(
                'missing' => array(),
                'recommendations' => array()
            );
        }

        try {
            $query_stats = wcsu()->query_profiler->get_query_stats();
        } catch (Exception $e) {
            $query_stats = null;
        }

        // Ensure arrays have required keys
        if (!is_array($autoload_analysis)) {
            $autoload_analysis = array('total_size' => 0, 'total_count' => 0, 'large_options' => array(), 'by_plugin' => array());
        }
        if (!is_array($index_check)) {
            $index_check = array('missing' => array(), 'recommendations' => array());
        }

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

        // Handle clear query log
        if (!empty($options['clear_query_log'])) {
            delete_option('wcsu_query_log');
            wp_send_json_success(__('Query log cleared', 'wc-speedup'));
            return;
        }

        // Get existing options and merge
        $existing = get_option('wcsu_options', array());

        // Sanitize new options
        $sanitized = array();

        // Text/textarea fields that need special handling
        $text_fields = array(
            'page_cache_exclude',
            'dns_custom_domains',
        );

        // Select/string fields
        $string_fields = array(
            'cart_fragments_mode',
            'heartbeat_frontend',
            'heartbeat_admin',
            'heartbeat_editor',
        );

        foreach ($options as $key => $value) {
            $key = sanitize_key($key);

            if (in_array($key, $text_fields)) {
                $sanitized[$key] = sanitize_textarea_field($value);
            } elseif (in_array($key, $string_fields)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif ($key === 'page_cache_ttl') {
                $sanitized[$key] = max(300, intval($value)); // Minimum 5 minutes
            } else {
                $sanitized[$key] = absint($value);
            }
        }

        // Merge with existing options
        $merged = array_merge($existing, $sanitized);
        update_option('wcsu_options', $merged);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }

    /**
     * Render page cache settings page
     */
    public function render_page_cache() {
        $options = get_option('wcsu_options', array());
        $page_cache_stats = wcsu()->page_cache->get_cache_stats();
        $is_writable = wcsu()->page_cache->is_cache_writable();

        ?>
        <div class="wrap wcsu-wrap">
            <h1><?php _e('Page Cache - WooCommerce Optimized', 'wc-speedup'); ?></h1>

            <p class="wcsu-intro">
                <?php _e('Page caching stores complete HTML pages to serve visitors without running database queries. This dramatically speeds up page loads for non-logged-in users.', 'wc-speedup'); ?>
            </p>

            <!-- Cache Status -->
            <div class="wcsu-page-cache-status">
                <div class="wcsu-status-header">
                    <h2>
                        <?php if (!empty($options['enable_page_cache'])): ?>
                            <span class="dashicons dashicons-yes-alt" style="color:#28a745;"></span>
                            <?php _e('Page Cache is Active', 'wc-speedup'); ?>
                        <?php else: ?>
                            <span class="dashicons dashicons-dismiss" style="color:#dc3545;"></span>
                            <?php _e('Page Cache is Disabled', 'wc-speedup'); ?>
                        <?php endif; ?>
                    </h2>
                </div>

                <?php if (!$is_writable): ?>
                <div class="wcsu-alert wcsu-alert-danger">
                    <strong><?php _e('Error:', 'wc-speedup'); ?></strong>
                    <?php _e('Cache directory is not writable. Please check file permissions for wp-content/cache/', 'wc-speedup'); ?>
                </div>
                <?php endif; ?>

                <!-- Cache Statistics -->
                <div class="wcsu-db-overview">
                    <div class="wcsu-db-stat">
                        <span class="wcsu-db-stat-value"><?php echo number_format($page_cache_stats['total_files']); ?></span>
                        <span class="wcsu-db-stat-label"><?php _e('Cached Pages', 'wc-speedup'); ?></span>
                    </div>
                    <div class="wcsu-db-stat">
                        <span class="wcsu-db-stat-value"><?php echo $page_cache_stats['total_size_formatted']; ?></span>
                        <span class="wcsu-db-stat-label"><?php _e('Cache Size', 'wc-speedup'); ?></span>
                    </div>
                    <div class="wcsu-db-stat">
                        <span class="wcsu-db-stat-value"><?php echo isset($options['page_cache_ttl']) ? ($options['page_cache_ttl'] / 60) . 'm' : '60m'; ?></span>
                        <span class="wcsu-db-stat-label"><?php _e('Cache Lifetime', 'wc-speedup'); ?></span>
                    </div>
                </div>

                <?php if ($page_cache_stats['newest_file']): ?>
                <p class="wcsu-cache-info">
                    <?php printf(__('Latest cached page: %s', 'wc-speedup'), $page_cache_stats['newest_file']); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Enable/Disable Toggle -->
            <div class="wcsu-settings-section">
                <h2><?php _e('Enable Page Cache', 'wc-speedup'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Page Cache', 'wc-speedup'); ?></th>
                        <td>
                            <label class="wcsu-toggle">
                                <input type="checkbox" id="wcsu-page-cache-toggle" name="enable_page_cache" value="1" <?php checked(!empty($options['enable_page_cache'])); ?>>
                                <span class="wcsu-toggle-slider"></span>
                            </label>
                            <span class="wcsu-toggle-label">
                                <?php _e('Enable page caching for non-logged-in visitors', 'wc-speedup'); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- WooCommerce Smart Exclusions -->
            <div class="wcsu-settings-section">
                <h2><?php _e('WooCommerce Smart Exclusions', 'wc-speedup'); ?></h2>
                <p class="description">
                    <?php _e('The following pages are automatically excluded from caching to ensure WooCommerce works correctly:', 'wc-speedup'); ?>
                </p>

                <div class="wcsu-exclusions-list">
                    <div class="wcsu-exclusion-item">
                        <span class="dashicons dashicons-yes"></span>
                        <strong><?php _e('Cart Page', 'wc-speedup'); ?></strong> - <?php _e('Dynamic cart contents', 'wc-speedup'); ?>
                    </div>
                    <div class="wcsu-exclusion-item">
                        <span class="dashicons dashicons-yes"></span>
                        <strong><?php _e('Checkout Page', 'wc-speedup'); ?></strong> - <?php _e('Order processing', 'wc-speedup'); ?>
                    </div>
                    <div class="wcsu-exclusion-item">
                        <span class="dashicons dashicons-yes"></span>
                        <strong><?php _e('My Account', 'wc-speedup'); ?></strong> - <?php _e('User-specific content', 'wc-speedup'); ?>
                    </div>
                    <div class="wcsu-exclusion-item">
                        <span class="dashicons dashicons-yes"></span>
                        <strong><?php _e('Logged-in Users', 'wc-speedup'); ?></strong> - <?php _e('Personalized experience', 'wc-speedup'); ?>
                    </div>
                    <div class="wcsu-exclusion-item">
                        <span class="dashicons dashicons-yes"></span>
                        <strong><?php _e('Users with Cart Items', 'wc-speedup'); ?></strong> - <?php _e('Cart data preserved', 'wc-speedup'); ?>
                    </div>
                    <div class="wcsu-exclusion-item">
                        <span class="dashicons dashicons-yes"></span>
                        <strong><?php _e('POST Requests', 'wc-speedup'); ?></strong> - <?php _e('Form submissions', 'wc-speedup'); ?>
                    </div>
                </div>
            </div>

            <!-- Cache Settings -->
            <div class="wcsu-settings-section">
                <h2><?php _e('Cache Settings', 'wc-speedup'); ?></h2>

                <form id="wcsu-page-cache-settings-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Cache Lifetime', 'wc-speedup'); ?></th>
                            <td>
                                <select name="page_cache_ttl" id="wcsu-cache-ttl">
                                    <option value="1800" <?php selected(isset($options['page_cache_ttl']) ? $options['page_cache_ttl'] : 3600, 1800); ?>><?php _e('30 Minutes', 'wc-speedup'); ?></option>
                                    <option value="3600" <?php selected(isset($options['page_cache_ttl']) ? $options['page_cache_ttl'] : 3600, 3600); ?>><?php _e('1 Hour (Recommended)', 'wc-speedup'); ?></option>
                                    <option value="7200" <?php selected(isset($options['page_cache_ttl']) ? $options['page_cache_ttl'] : 3600, 7200); ?>><?php _e('2 Hours', 'wc-speedup'); ?></option>
                                    <option value="21600" <?php selected(isset($options['page_cache_ttl']) ? $options['page_cache_ttl'] : 3600, 21600); ?>><?php _e('6 Hours', 'wc-speedup'); ?></option>
                                    <option value="86400" <?php selected(isset($options['page_cache_ttl']) ? $options['page_cache_ttl'] : 3600, 86400); ?>><?php _e('24 Hours', 'wc-speedup'); ?></option>
                                </select>
                                <p class="description"><?php _e('How long to keep cached pages before regenerating them.', 'wc-speedup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Additional Exclusions', 'wc-speedup'); ?></th>
                            <td>
                                <textarea name="page_cache_exclude" id="wcsu-cache-exclude" rows="5" class="large-text code"><?php echo esc_textarea(isset($options['page_cache_exclude']) ? $options['page_cache_exclude'] : ''); ?></textarea>
                                <p class="description">
                                    <?php _e('Enter URL patterns to exclude from caching, one per line. Supports wildcards (*).', 'wc-speedup'); ?><br>
                                    <?php _e('Examples: /product/*, /category/sale/, /custom-page/', 'wc-speedup'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="wcsu-save-cache-settings">
                            <?php _e('Save Settings', 'wc-speedup'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <!-- Cache Actions -->
            <div class="wcsu-settings-section">
                <h2><?php _e('Cache Actions', 'wc-speedup'); ?></h2>

                <p>
                    <button class="button button-secondary" id="wcsu-clear-page-cache">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Clear All Page Cache', 'wc-speedup'); ?>
                    </button>
                    &nbsp;
                    <button class="button button-secondary" id="wcsu-test-page-cache">
                        <span class="dashicons dashicons-yes"></span>
                        <?php _e('Test Cache Writing', 'wc-speedup'); ?>
                    </button>
                </p>

                <p class="description">
                    <?php _e('Cache is automatically cleared when you update posts, products, or change themes.', 'wc-speedup'); ?>
                </p>
            </div>

            <!-- Debug Info -->
            <div class="wcsu-settings-section">
                <h2><?php _e('Debug Information', 'wc-speedup'); ?></h2>
                <table class="widefat" style="max-width: 600px;">
                    <tr>
                        <td><strong><?php _e('Cache Directory', 'wc-speedup'); ?></strong></td>
                        <td><code><?php echo esc_html($page_cache_stats['cache_dir']); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Directory Exists', 'wc-speedup'); ?></strong></td>
                        <td>
                            <?php if (!empty($page_cache_stats['dir_exists'])): ?>
                                <span style="color: green;">✓ <?php _e('Yes', 'wc-speedup'); ?></span>
                            <?php else: ?>
                                <span style="color: red;">✗ <?php _e('No', 'wc-speedup'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Directory Writable', 'wc-speedup'); ?></strong></td>
                        <td>
                            <?php if (!empty($page_cache_stats['dir_writable'])): ?>
                                <span style="color: green;">✓ <?php _e('Yes', 'wc-speedup'); ?></span>
                            <?php else: ?>
                                <span style="color: red;">✗ <?php _e('No', 'wc-speedup'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Cache Enabled in Options', 'wc-speedup'); ?></strong></td>
                        <td>
                            <?php if (!empty($options['enable_page_cache'])): ?>
                                <span style="color: green;">✓ <?php _e('Yes', 'wc-speedup'); ?></span>
                            <?php else: ?>
                                <span style="color: red;">✗ <?php _e('No', 'wc-speedup'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p class="description" style="margin-top: 15px;">
                    <strong><?php _e('Important:', 'wc-speedup'); ?></strong>
                    <?php _e('Pages are only cached for NON-logged-in visitors. To test, open your site in a private/incognito browser window.', 'wc-speedup'); ?>
                </p>

                <h3 style="margin-top: 20px;"><?php _e('Debug Log', 'wc-speedup'); ?></h3>
                <p class="description"><?php _e('Visit pages in incognito mode then refresh this page to see the log.', 'wc-speedup'); ?></p>
                <textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px; background: #f0f0f1;"><?php echo esc_textarea(wcsu()->page_cache->get_debug_log()); ?></textarea>
                <p>
                    <button class="button button-secondary" id="wcsu-clear-debug-log">
                        <?php _e('Clear Debug Log', 'wc-speedup'); ?>
                    </button>
                </p>
            </div>

            <!-- How It Works -->
            <div class="wcsu-settings-section wcsu-info-section">
                <h2><?php _e('How Page Cache Works', 'wc-speedup'); ?></h2>

                <div class="wcsu-how-it-works">
                    <div class="wcsu-step">
                        <span class="wcsu-step-number">1</span>
                        <div class="wcsu-step-content">
                            <strong><?php _e('First Visit', 'wc-speedup'); ?></strong>
                            <p><?php _e('WordPress generates the page normally and saves the HTML to a cache file.', 'wc-speedup'); ?></p>
                        </div>
                    </div>
                    <div class="wcsu-step">
                        <span class="wcsu-step-number">2</span>
                        <div class="wcsu-step-content">
                            <strong><?php _e('Subsequent Visits', 'wc-speedup'); ?></strong>
                            <p><?php _e('The cached HTML is served directly - no database queries, no PHP processing.', 'wc-speedup'); ?></p>
                        </div>
                    </div>
                    <div class="wcsu-step">
                        <span class="wcsu-step-number">3</span>
                        <div class="wcsu-step-content">
                            <strong><?php _e('Auto-Refresh', 'wc-speedup'); ?></strong>
                            <p><?php _e('Cache expires after the set lifetime and regenerates on the next visit.', 'wc-speedup'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="wcsu-performance-note">
                    <span class="dashicons dashicons-performance"></span>
                    <strong><?php _e('Performance Impact:', 'wc-speedup'); ?></strong>
                    <?php _e('Page cache can reduce page load times by 50-90% for non-logged-in visitors by eliminating database queries.', 'wc-speedup'); ?>
                </div>
            </div>

        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle page cache
            $('#wcsu-page-cache-toggle').on('change', function() {
                var enabled = $(this).is(':checked') ? 1 : 0;

                $.ajax({
                    url: wcsu_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcsu_toggle_page_cache',
                        enable: enabled,
                        nonce: wcsu_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            WCSU_Admin.showToast(response.data, 'success');
                            location.reload();
                        } else {
                            WCSU_Admin.showToast(response.data, 'error');
                        }
                    }
                });
            });

            // Save cache settings
            $('#wcsu-page-cache-settings-form').on('submit', function(e) {
                e.preventDefault();

                var options = {
                    page_cache_ttl: $('#wcsu-cache-ttl').val(),
                    page_cache_exclude: $('#wcsu-cache-exclude').val()
                };

                $.ajax({
                    url: wcsu_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcsu_save_options',
                        options: options,
                        nonce: wcsu_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            WCSU_Admin.showToast(response.data, 'success');
                        } else {
                            WCSU_Admin.showToast(response.data, 'error');
                        }
                    }
                });
            });

            // Clear page cache
            $('#wcsu-clear-page-cache').on('click', function(e) {
                e.preventDefault();

                if (!confirm('<?php _e('Clear all cached pages?', 'wc-speedup'); ?>')) {
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: wcsu_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcsu_clear_page_cache',
                        nonce: wcsu_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            WCSU_Admin.showToast(response.data, 'success');
                            location.reload();
                        } else {
                            WCSU_Admin.showToast(response.data, 'error');
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Test page cache
            $('#wcsu-test-page-cache').on('click', function(e) {
                e.preventDefault();

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: wcsu_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcsu_test_page_cache',
                        nonce: wcsu_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            WCSU_Admin.showToast(response.data, 'success');
                        } else {
                            WCSU_Admin.showToast(response.data, 'error');
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Clear debug log
            $('#wcsu-clear-debug-log').on('click', function(e) {
                e.preventDefault();

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: wcsu_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcsu_clear_debug_log',
                        nonce: wcsu_vars.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            WCSU_Admin.showToast(response.data, 'success');
                            location.reload();
                        } else {
                            WCSU_Admin.showToast(response.data, 'error');
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Performance page with all module toggles
     */
    public function render_performance() {
        $options = get_option('wcsu_options', array());
        ?>
        <div class="wrap wcsu-admin">
            <h1><span class="dashicons dashicons-performance"></span> <?php _e('Performance Modules', 'wc-speedup'); ?></h1>
            <p class="description"><?php _e('הפעל והגדר מודולי ביצועים לשיפור מהירות האתר.', 'wc-speedup'); ?></p>

            <div class="wcsu-performance-grid">

                <!-- Cart Fragments Optimizer -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-cart"></span> <?php _e('Cart Fragments', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_cart_fragments_optimizer" name="enable_cart_fragments_optimizer" value="1" <?php checked(!empty($options['enable_cart_fragments_optimizer'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('WooCommerce שולח בקשת AJAX בכל טעינת דף. מודול זה מייעל או מבטל את הבקשה.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_cart_fragments_optimizer']) ? 'display:none;' : ''; ?>">
                        <label><?php _e('מצב:', 'wc-speedup'); ?></label>
                        <select name="cart_fragments_mode" id="cart_fragments_mode">
                            <option value="defer" <?php selected(isset($options['cart_fragments_mode']) ? $options['cart_fragments_mode'] : 'defer', 'defer'); ?>><?php _e('טעינה מושהית (מומלץ)', 'wc-speedup'); ?></option>
                            <option value="disable" <?php selected(isset($options['cart_fragments_mode']) ? $options['cart_fragments_mode'] : '', 'disable'); ?>><?php _e('מושבת לחלוטין', 'wc-speedup'); ?></option>
                            <option value="optimize" <?php selected(isset($options['cart_fragments_mode']) ? $options['cart_fragments_mode'] : '', 'optimize'); ?>><?php _e('מותאם עם localStorage', 'wc-speedup'); ?></option>
                        </select>
                    </div>
                </div>

                <!-- Heartbeat Control -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-heart"></span> <?php _e('Heartbeat Control', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_heartbeat_control" name="enable_heartbeat_control" value="1" <?php checked(!empty($options['enable_heartbeat_control'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('בקרת WordPress Heartbeat API שרץ ברקע ושולח בקשות AJAX.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_heartbeat_control']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label><?php _e('בחזית האתר:', 'wc-speedup'); ?></label>
                            <select name="heartbeat_frontend" id="heartbeat_frontend">
                                <option value="disable" <?php selected(isset($options['heartbeat_frontend']) ? $options['heartbeat_frontend'] : 'disable', 'disable'); ?>><?php _e('מושבת', 'wc-speedup'); ?></option>
                                <option value="slow" <?php selected(isset($options['heartbeat_frontend']) ? $options['heartbeat_frontend'] : '', 'slow'); ?>><?php _e('מואט', 'wc-speedup'); ?></option>
                                <option value="default" <?php selected(isset($options['heartbeat_frontend']) ? $options['heartbeat_frontend'] : '', 'default'); ?>><?php _e('ברירת מחדל', 'wc-speedup'); ?></option>
                            </select>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('בממשק ניהול:', 'wc-speedup'); ?></label>
                            <select name="heartbeat_admin" id="heartbeat_admin">
                                <option value="slow" <?php selected(isset($options['heartbeat_admin']) ? $options['heartbeat_admin'] : 'slow', 'slow'); ?>><?php _e('מואט (מומלץ)', 'wc-speedup'); ?></option>
                                <option value="disable" <?php selected(isset($options['heartbeat_admin']) ? $options['heartbeat_admin'] : '', 'disable'); ?>><?php _e('מושבת', 'wc-speedup'); ?></option>
                                <option value="default" <?php selected(isset($options['heartbeat_admin']) ? $options['heartbeat_admin'] : '', 'default'); ?>><?php _e('ברירת מחדל', 'wc-speedup'); ?></option>
                            </select>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('בעורך תוכן:', 'wc-speedup'); ?></label>
                            <select name="heartbeat_editor" id="heartbeat_editor">
                                <option value="default" <?php selected(isset($options['heartbeat_editor']) ? $options['heartbeat_editor'] : 'default', 'default'); ?>><?php _e('ברירת מחדל (נדרש לשמירה אוטומטית)', 'wc-speedup'); ?></option>
                                <option value="slow" <?php selected(isset($options['heartbeat_editor']) ? $options['heartbeat_editor'] : '', 'slow'); ?>><?php _e('מואט', 'wc-speedup'); ?></option>
                                <option value="disable" <?php selected(isset($options['heartbeat_editor']) ? $options['heartbeat_editor'] : '', 'disable'); ?>><?php _e('מושבת', 'wc-speedup'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Sessions Cleanup -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-database-remove"></span> <?php _e('WC Sessions Cleanup', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_sessions_cleanup" name="enable_sessions_cleanup" value="1" <?php checked(!empty($options['enable_sessions_cleanup'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('ניקוי sessions ישנים של WooCommerce שמצטברים במסד הנתונים.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_sessions_cleanup']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="sessions_auto_cleanup" id="sessions_auto_cleanup" value="1" <?php checked(!empty($options['sessions_auto_cleanup'])); ?>>
                                <?php _e('ניקוי אוטומטי יומי', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('מחיקת sessions ישנים מ:', 'wc-speedup'); ?></label>
                            <select name="sessions_cleanup_age" id="sessions_cleanup_age">
                                <option value="3" <?php selected(isset($options['sessions_cleanup_age']) ? $options['sessions_cleanup_age'] : 7, 3); ?>>3 <?php _e('ימים', 'wc-speedup'); ?></option>
                                <option value="7" <?php selected(isset($options['sessions_cleanup_age']) ? $options['sessions_cleanup_age'] : 7, 7); ?>>7 <?php _e('ימים', 'wc-speedup'); ?></option>
                                <option value="14" <?php selected(isset($options['sessions_cleanup_age']) ? $options['sessions_cleanup_age'] : 7, 14); ?>>14 <?php _e('ימים', 'wc-speedup'); ?></option>
                                <option value="30" <?php selected(isset($options['sessions_cleanup_age']) ? $options['sessions_cleanup_age'] : 7, 30); ?>>30 <?php _e('ימים', 'wc-speedup'); ?></option>
                            </select>
                        </div>
                        <button type="button" class="button wcsu-cleanup-sessions"><?php _e('נקה עכשיו', 'wc-speedup'); ?></button>
                        <span class="wcsu-sessions-stats"></span>
                    </div>
                </div>

                <!-- Transients Cleanup -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-trash"></span> <?php _e('Transients Cleanup', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_transients_cleanup" name="enable_transients_cleanup" value="1" <?php checked(!empty($options['enable_transients_cleanup'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('ניקוי transients שפג תוקפם מטבלת options.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_transients_cleanup']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="transients_auto_cleanup" id="transients_auto_cleanup" value="1" <?php checked(!empty($options['transients_auto_cleanup'])); ?>>
                                <?php _e('ניקוי אוטומטי יומי', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <button type="button" class="button wcsu-cleanup-transients"><?php _e('נקה עכשיו', 'wc-speedup'); ?></button>
                        <span class="wcsu-transients-stats"></span>
                    </div>
                </div>

                <!-- Lazy Loading -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-images-alt2"></span> <?php _e('Lazy Loading', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_lazy_loading" name="enable_lazy_loading" value="1" <?php checked(!empty($options['enable_lazy_loading'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('טעינה עצלה לתמונות ו-iframes - נטען רק כשגוללים אליהן.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_lazy_loading']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="lazy_images" id="lazy_images" value="1" <?php checked(!isset($options['lazy_images']) || !empty($options['lazy_images'])); ?>>
                                <?php _e('תמונות', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="lazy_iframes" id="lazy_iframes" value="1" <?php checked(!isset($options['lazy_iframes']) || !empty($options['lazy_iframes'])); ?>>
                                <?php _e('iframes (סרטונים, מפות)', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('דלג על תמונות ראשונות (LCP):', 'wc-speedup'); ?></label>
                            <select name="lazy_skip_first" id="lazy_skip_first">
                                <option value="1" <?php selected(isset($options['lazy_skip_first']) ? $options['lazy_skip_first'] : 3, 1); ?>>1</option>
                                <option value="2" <?php selected(isset($options['lazy_skip_first']) ? $options['lazy_skip_first'] : 3, 2); ?>>2</option>
                                <option value="3" <?php selected(isset($options['lazy_skip_first']) ? $options['lazy_skip_first'] : 3, 3); ?>>3 (<?php _e('מומלץ', 'wc-speedup'); ?>)</option>
                                <option value="5" <?php selected(isset($options['lazy_skip_first']) ? $options['lazy_skip_first'] : 3, 5); ?>>5</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- DNS Prefetch -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-networking"></span> <?php _e('DNS Prefetch', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_dns_prefetch" name="enable_dns_prefetch" value="1" <?php checked(!empty($options['enable_dns_prefetch'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('טעינה מוקדמת של DNS לדומיינים חיצוניים.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_dns_prefetch']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="dns_auto_detect" id="dns_auto_detect" value="1" <?php checked(!isset($options['dns_auto_detect']) || !empty($options['dns_auto_detect'])); ?>>
                                <?php _e('זיהוי אוטומטי של דומיינים', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('דומיינים נוספים (אחד בכל שורה):', 'wc-speedup'); ?></label>
                            <textarea name="dns_custom_domains" id="dns_custom_domains" rows="3" class="large-text code"><?php echo esc_textarea(isset($options['dns_custom_domains']) ? $options['dns_custom_domains'] : ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Browser Caching -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-clock"></span> <?php _e('Browser Caching', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_browser_caching" name="enable_browser_caching" value="1" <?php checked(!empty($options['enable_browser_caching'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('הגדרת Cache-Control headers לקבצים סטטיים.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_browser_caching']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label><?php _e('CSS & JavaScript:', 'wc-speedup'); ?></label>
                            <select name="browser_cache_css_js" id="browser_cache_css_js">
                                <option value="604800" <?php selected(isset($options['browser_cache_css_js']) ? $options['browser_cache_css_js'] : 2592000, 604800); ?>>7 <?php _e('ימים', 'wc-speedup'); ?></option>
                                <option value="2592000" <?php selected(isset($options['browser_cache_css_js']) ? $options['browser_cache_css_js'] : 2592000, 2592000); ?>>30 <?php _e('ימים', 'wc-speedup'); ?></option>
                                <option value="31536000" <?php selected(isset($options['browser_cache_css_js']) ? $options['browser_cache_css_js'] : 2592000, 31536000); ?>>1 <?php _e('שנה', 'wc-speedup'); ?></option>
                            </select>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('תמונות:', 'wc-speedup'); ?></label>
                            <select name="browser_cache_images" id="browser_cache_images">
                                <option value="2592000" <?php selected(isset($options['browser_cache_images']) ? $options['browser_cache_images'] : 31536000, 2592000); ?>>30 <?php _e('ימים', 'wc-speedup'); ?></option>
                                <option value="31536000" <?php selected(isset($options['browser_cache_images']) ? $options['browser_cache_images'] : 31536000, 31536000); ?>>1 <?php _e('שנה (מומלץ)', 'wc-speedup'); ?></option>
                            </select>
                        </div>
                        <button type="button" class="button wcsu-generate-htaccess"><?php _e('צור כללי .htaccess', 'wc-speedup'); ?></button>
                        <button type="button" class="button wcsu-remove-htaccess"><?php _e('הסר כללים', 'wc-speedup'); ?></button>
                        <?php
                        $browser_caching = wcsu()->browser_caching;
                        if ($browser_caching->has_htaccess_rules()) {
                            echo '<span class="wcsu-htaccess-status" style="color: green; margin-right: 10px;">✓ ' . __('כללים מותקנים', 'wc-speedup') . '</span>';
                        }
                        ?>
                    </div>
                </div>

                <!-- Email Queue -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-email-alt"></span> <?php _e('Email Queue', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_email_queue" name="enable_email_queue" value="1" <?php checked(!empty($options['enable_email_queue'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('תור מיילים ל-WooCommerce - שליחה ברקע במקום בזמן הזמנה.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_email_queue']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label><?php _e('גודל אצווה:', 'wc-speedup'); ?></label>
                            <select name="email_queue_batch_size" id="email_queue_batch_size">
                                <option value="5" <?php selected(isset($options['email_queue_batch_size']) ? $options['email_queue_batch_size'] : 10, 5); ?>>5</option>
                                <option value="10" <?php selected(isset($options['email_queue_batch_size']) ? $options['email_queue_batch_size'] : 10, 10); ?>>10</option>
                                <option value="20" <?php selected(isset($options['email_queue_batch_size']) ? $options['email_queue_batch_size'] : 10, 20); ?>>20</option>
                            </select>
                        </div>
                        <button type="button" class="button wcsu-process-email-queue"><?php _e('עבד תור', 'wc-speedup'); ?></button>
                        <span class="wcsu-email-queue-stats"></span>
                    </div>
                </div>

                <!-- PageSpeed Section Header -->
                <div class="wcsu-section-header" style="grid-column: 1 / -1; margin-top: 20px; padding: 15px 0; border-top: 2px solid #2271b1;">
                    <h2 style="margin: 0; color: #2271b1;"><span class="dashicons dashicons-performance"></span> <?php _e('PageSpeed Optimization', 'wc-speedup'); ?></h2>
                    <p style="margin: 5px 0 0; color: #666;"><?php _e('אופטימיזציות לשיפור ציון Google PageSpeed Insights', 'wc-speedup'); ?></p>
                </div>

                <!-- Defer JavaScript -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-editor-code"></span> <?php _e('Defer JavaScript', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_defer_js" name="enable_defer_js" value="1" <?php checked(!empty($options['enable_defer_js'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('מוסיף defer לסקריפטים לטעינה לא-חוסמת (מונע render-blocking).', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_defer_js']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="defer_js_exclude_jquery" value="1" <?php checked(!isset($options['defer_js_exclude_jquery']) || !empty($options['defer_js_exclude_jquery'])); ?>>
                                <?php _e('החרג jQuery (מומלץ)', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('החרג סקריפטים (אחד בשורה):', 'wc-speedup'); ?></label>
                            <textarea name="defer_js_excludes" rows="3" style="width:100%;"><?php echo esc_textarea(isset($options['defer_js_excludes']) ? $options['defer_js_excludes'] : ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Delay JavaScript -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-clock"></span> <?php _e('Delay JavaScript', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_delay_js" name="enable_delay_js" value="1" <?php checked(!empty($options['enable_delay_js'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('מעכב סקריפטים של צד שלישי עד אינטראקציה (גלילה, לחיצה).', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_delay_js']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label><?php _e('Timeout (מילישניות):', 'wc-speedup'); ?></label>
                            <select name="delay_js_timeout" id="delay_js_timeout">
                                <option value="3000" <?php selected(isset($options['delay_js_timeout']) ? $options['delay_js_timeout'] : 5000, 3000); ?>>3000ms</option>
                                <option value="5000" <?php selected(isset($options['delay_js_timeout']) ? $options['delay_js_timeout'] : 5000, 5000); ?>>5000ms (<?php _e('מומלץ', 'wc-speedup'); ?>)</option>
                                <option value="10000" <?php selected(isset($options['delay_js_timeout']) ? $options['delay_js_timeout'] : 5000, 10000); ?>>10000ms</option>
                            </select>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('תבניות לעיכוב (אחד בשורה):', 'wc-speedup'); ?></label>
                            <textarea name="delay_js_patterns" rows="3" style="width:100%;" placeholder="google-analytics&#10;gtag&#10;facebook"><?php echo esc_textarea(isset($options['delay_js_patterns']) ? $options['delay_js_patterns'] : ''); ?></textarea>
                            <small style="color:#666;"><?php _e('ברירת מחדל: analytics, chat widgets, marketing scripts', 'wc-speedup'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Remove Query Strings -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-editor-removeformatting"></span> <?php _e('Remove Query Strings', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_remove_query_strings" name="enable_remove_query_strings" value="1" <?php checked(!empty($options['enable_remove_query_strings'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('מסיר ?ver= מקבצי CSS/JS לשיפור caching בדפדפן ו-CDN.', 'wc-speedup'); ?></p>
                </div>

                <!-- Font Optimization -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-editor-textcolor"></span> <?php _e('Font Optimization', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_font_optimization" name="enable_font_optimization" value="1" <?php checked(!empty($options['enable_font_optimization'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('מוסיף font-display: swap למניעת FOIT ושיפור CLS.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_font_optimization']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label><?php _e('פונטים ל-Preload (URL מלא, אחד בשורה):', 'wc-speedup'); ?></label>
                            <textarea name="preload_fonts" rows="2" style="width:100%;" placeholder="https://example.com/fonts/main.woff2"><?php echo esc_textarea(isset($options['preload_fonts']) ? $options['preload_fonts'] : ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Minify HTML -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-media-text"></span> <?php _e('Minify HTML', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_minify_html" name="enable_minify_html" value="1" <?php checked(!empty($options['enable_minify_html'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('מסיר רווחים והערות מה-HTML להקטנת גודל הדף.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_minify_html']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="minify_html_remove_comments" value="1" <?php checked(!isset($options['minify_html_remove_comments']) || !empty($options['minify_html_remove_comments'])); ?>>
                                <?php _e('הסר הערות HTML', 'wc-speedup'); ?>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Preload Resources -->
                <div class="wcsu-module-card">
                    <div class="wcsu-module-header">
                        <h3><span class="dashicons dashicons-format-image"></span> <?php _e('Preload Resources', 'wc-speedup'); ?></h3>
                        <label class="wcsu-toggle">
                            <input type="checkbox" id="enable_preload_resources" name="enable_preload_resources" value="1" <?php checked(!empty($options['enable_preload_resources'])); ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <p class="wcsu-module-desc"><?php _e('טעינה מוקדמת של תמונת LCP, לוגו ומשאבים קריטיים.', 'wc-speedup'); ?></p>
                    <div class="wcsu-module-options" style="<?php echo empty($options['enable_preload_resources']) ? 'display:none;' : ''; ?>">
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="preload_featured_image" value="1" <?php checked(!isset($options['preload_featured_image']) || !empty($options['preload_featured_image'])); ?>>
                                <?php _e('Preload תמונה ראשית (LCP)', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label>
                                <input type="checkbox" name="preload_logo" value="1" <?php checked(!isset($options['preload_logo']) || !empty($options['preload_logo'])); ?>>
                                <?php _e('Preload לוגו', 'wc-speedup'); ?>
                            </label>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('משאבים נוספים (URL מלא, אחד בשורה):', 'wc-speedup'); ?></label>
                            <textarea name="custom_preloads" rows="2" style="width:100%;"><?php echo esc_textarea(isset($options['custom_preloads']) ? $options['custom_preloads'] : ''); ?></textarea>
                        </div>
                        <div class="wcsu-option-row">
                            <label><?php _e('דומיינים ל-Preconnect (אחד בשורה):', 'wc-speedup'); ?></label>
                            <textarea name="preconnect_domains" rows="2" style="width:100%;" placeholder="https://cdn.example.com"><?php echo esc_textarea(isset($options['preconnect_domains']) ? $options['preconnect_domains'] : ''); ?></textarea>
                        </div>
                    </div>
                </div>

            </div>

            <div class="wcsu-save-section">
                <button type="button" id="wcsu-save-performance" class="button button-primary button-hero"><?php _e('שמור הגדרות', 'wc-speedup'); ?></button>
            </div>
        </div>

        <style>
        .wcsu-performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .wcsu-module-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .wcsu-module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .wcsu-module-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .wcsu-module-desc {
            color: #666;
            margin-bottom: 15px;
        }
        .wcsu-module-options {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
        }
        .wcsu-option-row {
            margin-bottom: 10px;
        }
        .wcsu-option-row:last-child {
            margin-bottom: 0;
        }
        .wcsu-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .wcsu-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .wcsu-toggle .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 26px;
        }
        .wcsu-toggle .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        .wcsu-toggle input:checked + .slider {
            background-color: #2271b1;
        }
        .wcsu-toggle input:checked + .slider:before {
            transform: translateX(24px);
        }
        .wcsu-save-section {
            margin-top: 30px;
            text-align: center;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Toggle module options visibility
            $('.wcsu-module-card input[type="checkbox"]').on('change', function() {
                var $card = $(this).closest('.wcsu-module-card');
                var $options = $card.find('.wcsu-module-options');

                if ($(this).is(':checked')) {
                    $options.slideDown();
                } else {
                    $options.slideUp();
                }
            });

            // Save performance settings
            $('#wcsu-save-performance').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php _e('שומר...', 'wc-speedup'); ?>');

                var data = {
                    action: 'wcsu_save_options',
                    nonce: wcsu_vars.nonce,
                    'options[enable_cart_fragments_optimizer]': $('#enable_cart_fragments_optimizer').is(':checked') ? 1 : 0,
                    'options[cart_fragments_mode]': $('#cart_fragments_mode').val(),
                    'options[enable_heartbeat_control]': $('#enable_heartbeat_control').is(':checked') ? 1 : 0,
                    'options[heartbeat_frontend]': $('#heartbeat_frontend').val(),
                    'options[heartbeat_admin]': $('#heartbeat_admin').val(),
                    'options[heartbeat_editor]': $('#heartbeat_editor').val(),
                    'options[enable_sessions_cleanup]': $('#enable_sessions_cleanup').is(':checked') ? 1 : 0,
                    'options[sessions_auto_cleanup]': $('#sessions_auto_cleanup').is(':checked') ? 1 : 0,
                    'options[sessions_cleanup_age]': $('#sessions_cleanup_age').val(),
                    'options[enable_transients_cleanup]': $('#enable_transients_cleanup').is(':checked') ? 1 : 0,
                    'options[transients_auto_cleanup]': $('#transients_auto_cleanup').is(':checked') ? 1 : 0,
                    'options[enable_lazy_loading]': $('#enable_lazy_loading').is(':checked') ? 1 : 0,
                    'options[lazy_images]': $('#lazy_images').is(':checked') ? 1 : 0,
                    'options[lazy_iframes]': $('#lazy_iframes').is(':checked') ? 1 : 0,
                    'options[lazy_skip_first]': $('#lazy_skip_first').val(),
                    'options[enable_dns_prefetch]': $('#enable_dns_prefetch').is(':checked') ? 1 : 0,
                    'options[dns_auto_detect]': $('#dns_auto_detect').is(':checked') ? 1 : 0,
                    'options[dns_custom_domains]': $('#dns_custom_domains').val(),
                    'options[enable_browser_caching]': $('#enable_browser_caching').is(':checked') ? 1 : 0,
                    'options[browser_cache_css_js]': $('#browser_cache_css_js').val(),
                    'options[browser_cache_images]': $('#browser_cache_images').val(),
                    'options[enable_email_queue]': $('#enable_email_queue').is(':checked') ? 1 : 0,
                    'options[email_queue_batch_size]': $('#email_queue_batch_size').val(),
                    // PageSpeed options
                    'options[enable_defer_js]': $('#enable_defer_js').is(':checked') ? 1 : 0,
                    'options[defer_js_exclude_jquery]': $('input[name="defer_js_exclude_jquery"]').is(':checked') ? 1 : 0,
                    'options[defer_js_excludes]': $('textarea[name="defer_js_excludes"]').val(),
                    'options[enable_delay_js]': $('#enable_delay_js').is(':checked') ? 1 : 0,
                    'options[delay_js_timeout]': $('#delay_js_timeout').val(),
                    'options[delay_js_patterns]': $('textarea[name="delay_js_patterns"]').val(),
                    'options[enable_remove_query_strings]': $('#enable_remove_query_strings').is(':checked') ? 1 : 0,
                    'options[enable_font_optimization]': $('#enable_font_optimization').is(':checked') ? 1 : 0,
                    'options[preload_fonts]': $('textarea[name="preload_fonts"]').val(),
                    'options[enable_minify_html]': $('#enable_minify_html').is(':checked') ? 1 : 0,
                    'options[minify_html_remove_comments]': $('input[name="minify_html_remove_comments"]').is(':checked') ? 1 : 0,
                    'options[enable_preload_resources]': $('#enable_preload_resources').is(':checked') ? 1 : 0,
                    'options[preload_featured_image]': $('input[name="preload_featured_image"]').is(':checked') ? 1 : 0,
                    'options[preload_logo]': $('input[name="preload_logo"]').is(':checked') ? 1 : 0,
                    'options[custom_preloads]': $('textarea[name="custom_preloads"]').val(),
                    'options[preconnect_domains]': $('textarea[name="preconnect_domains"]').val()
                };

                $.post(wcsu_vars.ajax_url, data, function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast('<?php _e('ההגדרות נשמרו!', 'wc-speedup'); ?>', 'success');
                    } else {
                        WCSU_Admin.showToast(response.data || '<?php _e('שגיאה בשמירה', 'wc-speedup'); ?>', 'error');
                    }
                    $btn.prop('disabled', false).text('<?php _e('שמור הגדרות', 'wc-speedup'); ?>');
                }).fail(function() {
                    WCSU_Admin.showToast('<?php _e('שגיאת תקשורת', 'wc-speedup'); ?>', 'error');
                    $btn.prop('disabled', false).text('<?php _e('שמור הגדרות', 'wc-speedup'); ?>');
                });
            });

            // Cleanup sessions
            $('.wcsu-cleanup-sessions').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(wcsu_vars.ajax_url, {
                    action: 'wcsu_cleanup_sessions',
                    nonce: wcsu_vars.nonce
                }, function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                    $btn.prop('disabled', false);
                });
            });

            // Cleanup transients
            $('.wcsu-cleanup-transients').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(wcsu_vars.ajax_url, {
                    action: 'wcsu_cleanup_transients',
                    nonce: wcsu_vars.nonce
                }, function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                    $btn.prop('disabled', false);
                });
            });

            // Generate .htaccess rules
            $('.wcsu-generate-htaccess').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(wcsu_vars.ajax_url, {
                    action: 'wcsu_generate_htaccess',
                    nonce: wcsu_vars.nonce
                }, function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                        location.reload();
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                    $btn.prop('disabled', false);
                });
            });

            // Remove .htaccess rules
            $('.wcsu-remove-htaccess').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(wcsu_vars.ajax_url, {
                    action: 'wcsu_remove_htaccess',
                    nonce: wcsu_vars.nonce
                }, function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                        location.reload();
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                    $btn.prop('disabled', false);
                });
            });

            // Process email queue
            $('.wcsu-process-email-queue').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(wcsu_vars.ajax_url, {
                    action: 'wcsu_process_email_queue',
                    nonce: wcsu_vars.nonce
                }, function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data, 'success');
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }
}
