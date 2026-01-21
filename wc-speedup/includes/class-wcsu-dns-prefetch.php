<?php
/**
 * DNS Prefetch & Preconnect - טעינה מוקדמת של DNS
 *
 * מודול זה מוסיף dns-prefetch ו-preconnect לדומיינים חיצוניים
 * כדי לשפר את זמן הטעינה.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCSU_DNS_Prefetch {

    /**
     * Settings
     */
    private $enabled = false;
    private $auto_detect = true;
    private $custom_domains = array();
    private $detected_domains = array();

    /**
     * Common external domains
     */
    private $common_domains = array(
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'www.google-analytics.com',
        'www.googletagmanager.com',
        'connect.facebook.net',
        'www.facebook.com',
        'platform.twitter.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'ajax.googleapis.com',
        'maps.googleapis.com',
        'www.youtube.com',
        'player.vimeo.com',
        'js.stripe.com',
        'checkout.stripe.com',
        'www.paypal.com',
        'www.paypalobjects.com',
    );

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_wcsu_save_dns_prefetch_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_wcsu_detect_domains', array($this, 'ajax_detect_domains'));
    }

    /**
     * Initialize
     */
    public function init() {
        $options = get_option('wcsu_options', array());
        $this->enabled = !empty($options['enable_dns_prefetch']);
        $this->auto_detect = !isset($options['dns_auto_detect']) || !empty($options['dns_auto_detect']);

        if (!empty($options['dns_custom_domains'])) {
            $this->custom_domains = array_filter(array_map('trim', explode("\n", $options['dns_custom_domains'])));
        }

        if (!$this->enabled) {
            return;
        }

        // Add prefetch/preconnect hints
        add_action('wp_head', array($this, 'output_resource_hints'), 1);

        // Detect domains if auto-detect is enabled
        if ($this->auto_detect) {
            add_action('wp_footer', array($this, 'detect_external_domains'), 999);
        }
    }

    /**
     * Output resource hints
     */
    public function output_resource_hints() {
        $domains = $this->get_domains_to_prefetch();

        if (empty($domains)) {
            return;
        }

        echo "\n<!-- WCSU DNS Prefetch -->\n";

        foreach ($domains as $domain) {
            // Clean domain
            $domain = $this->clean_domain($domain);
            if (empty($domain)) {
                continue;
            }

            // Use preconnect for important domains, dns-prefetch for others
            $important_domains = array(
                'fonts.googleapis.com',
                'fonts.gstatic.com',
                'js.stripe.com',
            );

            if (in_array($domain, $important_domains)) {
                echo '<link rel="preconnect" href="https://' . esc_attr($domain) . '" crossorigin>' . "\n";
            } else {
                echo '<link rel="dns-prefetch" href="//' . esc_attr($domain) . '">' . "\n";
            }
        }

        echo "<!-- /WCSU DNS Prefetch -->\n\n";
    }

    /**
     * Get domains to prefetch
     */
    private function get_domains_to_prefetch() {
        $domains = array();

        // Add custom domains
        if (!empty($this->custom_domains)) {
            $domains = array_merge($domains, $this->custom_domains);
        }

        // Add auto-detected domains
        if ($this->auto_detect) {
            $detected = get_option('wcsu_detected_domains', array());
            if (!empty($detected)) {
                $domains = array_merge($domains, $detected);
            }
        }

        // Remove duplicates and current domain
        $domains = array_unique($domains);
        $current_host = parse_url(home_url(), PHP_URL_HOST);
        $domains = array_filter($domains, function($domain) use ($current_host) {
            return $domain !== $current_host;
        });

        return $domains;
    }

    /**
     * Clean domain
     */
    private function clean_domain($domain) {
        $domain = trim($domain);

        // Remove protocol
        $domain = preg_replace('#^https?://#', '', $domain);

        // Remove path
        $domain = preg_replace('#/.*$#', '', $domain);

        // Remove www. for consistency
        // $domain = preg_replace('#^www\.#', '', $domain);

        return $domain;
    }

    /**
     * Detect external domains (runs in footer)
     */
    public function detect_external_domains() {
        // This is a client-side detection that sends results via AJAX
        ?>
        <script>
        (function() {
            // Only run once per session
            if (sessionStorage.getItem('wcsu_domains_detected')) return;
            sessionStorage.setItem('wcsu_domains_detected', '1');

            var domains = new Set();
            var currentHost = window.location.hostname;

            // Check all scripts
            document.querySelectorAll('script[src]').forEach(function(el) {
                try {
                    var url = new URL(el.src);
                    if (url.hostname !== currentHost) {
                        domains.add(url.hostname);
                    }
                } catch(e) {}
            });

            // Check all stylesheets
            document.querySelectorAll('link[rel="stylesheet"][href]').forEach(function(el) {
                try {
                    var url = new URL(el.href);
                    if (url.hostname !== currentHost) {
                        domains.add(url.hostname);
                    }
                } catch(e) {}
            });

            // Check all images
            document.querySelectorAll('img[src]').forEach(function(el) {
                try {
                    var url = new URL(el.src);
                    if (url.hostname !== currentHost) {
                        domains.add(url.hostname);
                    }
                } catch(e) {}
            });

            // Check all iframes
            document.querySelectorAll('iframe[src]').forEach(function(el) {
                try {
                    var url = new URL(el.src);
                    if (url.hostname !== currentHost) {
                        domains.add(url.hostname);
                    }
                } catch(e) {}
            });

            // Send to server if we found new domains
            if (domains.size > 0) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('action=wcsu_save_detected_domains&domains=' + encodeURIComponent(Array.from(domains).join(',')));
            }
        })();
        </script>
        <?php
    }

    /**
     * Get statistics
     */
    public function get_stats() {
        return array(
            'enabled' => $this->enabled,
            'auto_detect' => $this->auto_detect,
            'custom_domains' => $this->custom_domains,
            'detected_domains' => get_option('wcsu_detected_domains', array()),
            'common_domains' => $this->common_domains,
        );
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
        $options['enable_dns_prefetch'] = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
        $options['dns_auto_detect'] = isset($_POST['auto_detect']) ? intval($_POST['auto_detect']) : 1;
        $options['dns_custom_domains'] = isset($_POST['custom_domains']) ? sanitize_textarea_field($_POST['custom_domains']) : '';

        update_option('wcsu_options', $options);

        wp_send_json_success(__('Settings saved', 'wc-speedup'));
    }

    /**
     * AJAX: Detect domains
     */
    public function ajax_detect_domains() {
        check_ajax_referer('wcsu_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'wc-speedup'));
        }

        // Clear detected domains
        delete_option('wcsu_detected_domains');

        wp_send_json_success(array(
            'message' => 'Detection reset. Visit your site pages to detect domains.',
        ));
    }
}

// Handle domain saving (no nonce for client-side detection)
add_action('wp_ajax_nopriv_wcsu_save_detected_domains', 'wcsu_save_detected_domains');
add_action('wp_ajax_wcsu_save_detected_domains', 'wcsu_save_detected_domains');

function wcsu_save_detected_domains() {
    if (empty($_POST['domains'])) {
        wp_die();
    }

    $new_domains = array_filter(array_map('sanitize_text_field', explode(',', $_POST['domains'])));
    $existing_domains = get_option('wcsu_detected_domains', array());

    $all_domains = array_unique(array_merge($existing_domains, $new_domains));

    // Limit to 50 domains
    if (count($all_domains) > 50) {
        $all_domains = array_slice($all_domains, 0, 50);
    }

    update_option('wcsu_detected_domains', $all_domains);

    wp_die();
}
