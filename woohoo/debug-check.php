<?php
/**
 * Debug Check - בדיקת דיאגנוסטיקה
 *
 * הוסף את הקוד הזה לקובץ functions.php של התבנית או פשוט טען אותו
 * כדי לראות מה קורה
 */

// Add to WordPress init
add_action('wp_footer', 'wcsu_debug_output', 9999);

function wcsu_debug_output() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = get_option('wcsu_options', array());

    echo '<!-- WOOHOO DEBUG START -->';
    echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#333;color:#fff;padding:10px;font-size:12px;z-index:999999;max-height:200px;overflow:auto;">';
    echo '<strong>WooHoo Debug:</strong><br>';

    // Check which modules are enabled
    $modules = array(
        'enable_page_cache' => 'Page Cache',
        'enable_lazy_loading' => 'Lazy Loading',
        'enable_defer_js' => 'Defer JS',
        'enable_delay_js' => 'Delay JS',
        'enable_minify_html' => 'Minify HTML',
        'enable_font_optimization' => 'Font Optimization',
        'enable_remove_query_strings' => 'Remove Query Strings',
        'enable_preload_resources' => 'Preload Resources',
        // Old options that might conflict
        'defer_js' => 'Old Defer JS',
        'minify_html' => 'Old Minify HTML',
        'gzip_compression' => 'GZIP',
    );

    foreach ($modules as $key => $name) {
        $status = !empty($options[$key]) ? '<span style="color:#0f0">ON</span>' : '<span style="color:#f00">OFF</span>';
        echo $name . ': ' . $status . ' | ';
    }

    // Check output buffer level
    echo '<br>Output Buffer Level: ' . ob_get_level();

    // Check if lazy loading class exists and is using native loading
    if (class_exists('WCSU_Lazy_Loading')) {
        $lazy = wcsu()->lazy_loading;
        if (method_exists($lazy, 'get_stats')) {
            $stats = $lazy->get_stats();
            echo '<br>Lazy Loading Stats: ' . json_encode($stats);
        }
    }

    echo '</div>';
    echo '<!-- WOOHOO DEBUG END -->';
}

// Also add a way to see errors
add_action('wp_footer', 'wcsu_show_php_errors', 9998);
function wcsu_show_php_errors() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $error = error_get_last();
    if ($error) {
        echo '<!-- PHP Error: ' . esc_html(json_encode($error)) . ' -->';
    }
}
