/**
 * WC SpeedUp Admin JavaScript
 */

(function($) {
    'use strict';

    var WCSU_Admin = {

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Quick action buttons
            $(document).on('click', '.wcsu-action-btn', this.handleQuickAction);

            // Cleanup buttons
            $(document).on('click', '.wcsu-cleanup-btn', this.handleCleanup);

            // Optimize tables button
            $(document).on('click', '.wcsu-optimize-btn', this.handleOptimize);

            // Run diagnostics button
            $(document).on('click', '#wcsu-run-diagnostics', this.handleDiagnostics);

            // Settings form
            $(document).on('submit', '#wcsu-settings-form', this.handleSettingsSave);

            // Profiler: Enable profiler checkbox
            $(document).on('change', '#wcsu-enable-profiler', this.handleEnableProfiler);

            // Profiler: Disable autoload buttons
            $(document).on('click', '.wcsu-disable-autoload-btn', this.handleDisableAutoload);

            // Profiler: Add index buttons
            $(document).on('click', '.wcsu-add-index-btn', this.handleAddIndex);

            // Profiler: Clear query log
            $(document).on('click', '#wcsu-clear-query-log', this.handleClearQueryLog);

            // Auto Optimizer: One-click fix all
            $(document).on('click', '#wcsu-auto-optimize', this.handleAutoOptimize);
        },

        /**
         * Handle quick action buttons
         */
        handleQuickAction: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var action = $btn.data('action');

            if ($btn.hasClass('loading')) {
                return;
            }

            switch (action) {
                case 'clear_cache':
                    WCSU_Admin.clearCache($btn);
                    break;
                case 'run_diagnostics':
                    WCSU_Admin.runDiagnostics($btn);
                    break;
                case 'cleanup_all':
                    WCSU_Admin.cleanupAll($btn);
                    break;
                case 'optimize_tables':
                    WCSU_Admin.optimizeTables($btn);
                    break;
            }
        },

        /**
         * Clear cache
         */
        clearCache: function($btn) {
            if (!confirm(wcsu_vars.strings.confirm_cleanup)) {
                return;
            }

            $btn.addClass('loading').text(wcsu_vars.strings.clearing_cache);

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_clear_cache',
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading');
                    location.reload();
                }
            });
        },

        /**
         * Run diagnostics
         */
        runDiagnostics: function($btn) {
            $btn.addClass('loading').text(wcsu_vars.strings.running_diagnostics);

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_run_diagnostics',
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(wcsu_vars.strings.success, 'success');
                        location.reload();
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading');
                }
            });
        },

        /**
         * Cleanup all
         */
        cleanupAll: function($btn) {
            if (!confirm(wcsu_vars.strings.confirm_cleanup)) {
                return;
            }

            $btn.addClass('loading').text(wcsu_vars.strings.cleaning);

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_db_cleanup',
                    type: 'all',
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                        location.reload();
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading');
                }
            });
        },

        /**
         * Optimize tables
         */
        optimizeTables: function($btn) {
            if (!confirm(wcsu_vars.strings.confirm_optimize)) {
                return;
            }

            $btn.addClass('loading').text(wcsu_vars.strings.optimizing);

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_optimize_tables',
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading');
                    location.reload();
                }
            });
        },

        /**
         * Handle cleanup buttons
         */
        handleCleanup: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var type = $btn.data('type');

            if ($btn.hasClass('loading') || $btn.prop('disabled')) {
                return;
            }

            if (!confirm(wcsu_vars.strings.confirm_cleanup)) {
                return;
            }

            var originalText = $btn.text();
            $btn.addClass('loading').text(wcsu_vars.strings.cleaning);

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_db_cleanup',
                    type: type,
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data.message, 'success');
                        // Update the count
                        $btn.closest('tr').find('.wcsu-count').text('0').removeClass('has-items');
                        $btn.prop('disabled', true);
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading').text(originalText);
                }
            });
        },

        /**
         * Handle optimize button
         */
        handleOptimize: function(e) {
            e.preventDefault();
            var $btn = $(this);

            if ($btn.hasClass('loading')) {
                return;
            }

            WCSU_Admin.optimizeTables($btn);
        },

        /**
         * Handle diagnostics button
         */
        handleDiagnostics: function(e) {
            e.preventDefault();
            var $btn = $(this);

            if ($btn.hasClass('loading')) {
                return;
            }

            WCSU_Admin.runDiagnostics($btn);
        },

        /**
         * Handle settings save
         */
        handleSettingsSave: function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');

            $btn.addClass('loading').prop('disabled', true);

            // Collect form data
            var options = {};
            $form.find('input[type="checkbox"]').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    var key = name.replace('wcsu_options[', '').replace(']', '');
                    options[key] = $(this).is(':checked') ? 1 : 0;
                }
            });

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
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            // Remove existing toasts
            $('.wcsu-toast').remove();

            var $toast = $('<div class="wcsu-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);

            setTimeout(function() {
                $toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },

        /**
         * Handle enable profiler checkbox
         */
        handleEnableProfiler: function() {
            var enabled = $(this).is(':checked') ? 1 : 0;

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_save_options',
                    options: { enable_query_profiler: enabled },
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data, 'success');
                    }
                }
            });
        },

        /**
         * Handle disable autoload button
         */
        handleDisableAutoload: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var option = $btn.data('option');

            if ($btn.hasClass('loading')) {
                return;
            }

            if (!confirm('Are you sure you want to disable autoload for this option?')) {
                return;
            }

            var originalText = $btn.text();
            $btn.addClass('loading').text('...');

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_fix_autoload',
                    option: option,
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data, 'success');
                        $btn.closest('tr').fadeOut();
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading').text(originalText);
                }
            });
        },

        /**
         * Handle add index button
         */
        handleAddIndex: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var table = $btn.data('table');
            var index = $btn.data('index');
            var sql = $btn.data('sql');

            if ($btn.hasClass('loading')) {
                return;
            }

            if (!confirm('Are you sure you want to add this index? This may take a moment on large tables.')) {
                return;
            }

            var originalText = $btn.text();
            $btn.addClass('loading').text('Adding...');

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_add_index',
                    table: table,
                    index: index,
                    sql: sql,
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCSU_Admin.showToast(response.data, 'success');
                        $btn.closest('tr').fadeOut();
                    } else {
                        WCSU_Admin.showToast(response.data, 'error');
                    }
                },
                error: function() {
                    WCSU_Admin.showToast(wcsu_vars.strings.error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading').text(originalText);
                }
            });
        },

        /**
         * Handle clear query log
         */
        handleClearQueryLog: function(e) {
            e.preventDefault();

            if (!confirm('Clear all query log data?')) {
                return;
            }

            // Just delete the option via save_options with empty
            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcsu_save_options',
                    options: { clear_query_log: 1 },
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    WCSU_Admin.showToast('Query log cleared', 'success');
                    location.reload();
                }
            });
        },

        /**
         * Handle auto optimize - One click fix all
         */
        handleAutoOptimize: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $results = $('#wcsu-optimize-results');

            if ($btn.hasClass('loading')) {
                return;
            }

            var originalHtml = $btn.html();
            $btn.addClass('loading').html('<span class="dashicons dashicons-update wcsu-spin"></span> Optimizing...');
            $results.hide().empty();

            $.ajax({
                url: wcsu_vars.ajax_url,
                type: 'POST',
                timeout: 300000, // 5 minutes timeout
                data: {
                    action: 'wcsu_auto_optimize',
                    nonce: wcsu_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var html = '<h4>✅ Optimization Complete!</h4>';

                        if (response.data.actions) {
                            response.data.actions.forEach(function(action) {
                                html += '<div class="wcsu-result-item">';
                                html += '<span class="result-name">' + action.name + '</span>';
                                html += '<span class="result-value">' + action.result.message + '</span>';
                                html += '</div>';
                            });
                        }

                        $results.html(html).slideDown();
                        WCSU_Admin.showToast('Database optimized successfully!', 'success');

                        // Reload after 2 seconds to show updated stats
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        WCSU_Admin.showToast(response.data || 'Optimization failed', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    WCSU_Admin.showToast('Error: ' + error, 'error');
                },
                complete: function() {
                    $btn.removeClass('loading').html(originalHtml);
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WCSU_Admin.init();
    });

})(jQuery);
