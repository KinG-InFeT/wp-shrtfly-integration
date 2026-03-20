<?php
/**
 * Plugin Name: WP ShrtFly Integration
 * Plugin URI: https://wordpress-plugins.luongovincenzo.it/plugin/shrtfly-integration
 * Description: This plugin allows you to configure Full Page Script and widget for stats
 * Version: 2.0.0
 * Author: Vincenzo Luongo
 * Author URI: https://www.luongovincenzo.it/
 * License: GPLv2 or later
 * Text Domain: wp-shrtfly-integration
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) {
    exit;
}

define("SHRTFLY_INTEGRATION_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX", 'wp_shrtfly_integration_option');
define("SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP", 'wp_shrtfly_integration-settings-group');

class WPShrtFlyDashboardIntegration {

    private const ALLOWED_ADS_TYPES = ['mainstream', 'adult'];
    private const ALLOWED_DOMAIN_MODES = ['include', 'exclude'];

    protected array $pluginDetails;
    protected array $pluginOptions = [];
    private static ?self $instance = null;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {

        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $this->pluginDetails = get_plugin_data(__FILE__);
        $this->pluginOptions = [
            'enabled' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled'),
            'enabled_amp' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp'),
            'api_token' => trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token')) ?: '-1',
            'include_exclude_domains_choose' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude'),
            'include_exclude_domains_value' => trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value')),
            'ads_type' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream'),
        ];

        add_action('wp_enqueue_scripts', [$this, 'gen_script']);
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, '_registerOptions']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_actions']);

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);

        if ($this->is_amp_plugin_active() && $this->pluginOptions['enabled_amp']) {
            add_action('amp_post_template_head', [$this, 'gen_amp_script']);
        }
    }

    public function add_plugin_actions(array $links): array {
        if (!current_user_can('manage_options')) {
            return $links;
        }
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . urlencode(__FILE__))) . '">' . esc_html__('Settings', 'wp-shrtfly-integration') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    private function includeExcludeDomainScript(array $options): string {
        $script = '';

        if ($options['include_exclude_domains_choose'] === 'include') {
            $script .= 'var app_domains = [';
            if (trim($options['include_exclude_domains_value'])) {
                $script .= implode(', ', array_map(function ($x) {
                    return wp_json_encode(trim($x));
                }, explode(',', trim($options['include_exclude_domains_value']))));
            }
            $script .= '];';
        } else {
            $script .= 'var app_exclude_domains = [';
            if ($options['include_exclude_domains_choose'] === 'exclude' && trim($options['include_exclude_domains_value'])) {
                $script .= implode(', ', array_map(function ($x) {
                    return wp_json_encode(trim($x));
                }, explode(',', trim($options['include_exclude_domains_value']))));
            }
            $script .= '];';
        }

        return $script;
    }

    public function gen_script(): void {
        if (!$this->pluginOptions['enabled'] || is_admin()) {
            return;
        }

        $options = $this->pluginOptions;
        $adsType = ($options['ads_type'] === 'adult') ? 2 : 1;

        $script_args = ['strategy' => 'defer', 'in_footer' => true];
        wp_enqueue_script('wp-shrtfly-integration', 'https://shrtfly.com/js/full-page-script.js', [], '1.0', $script_args);

        $script_vars = [
            'app_url' => 'https://shrtfly.com/',
            'app_api_token' => sanitize_text_field($options['api_token']),
            'app_advert' => intval($adsType)
        ];

        $inline_script = '';
        foreach ($script_vars as $var => $value) {
            $inline_script .= 'var ' . $var . ' = ' . wp_json_encode($value) . ';';
        }

        $inline_script .= $this->includeExcludeDomainScript($options);

        wp_add_inline_script('wp-shrtfly-integration', $inline_script, 'before');
    }

    public function create_admin_menu(): void {
        add_options_page(
            __('ShrtFly Settings', 'wp-shrtfly-integration'),
            __('ShrtFly Settings', 'wp-shrtfly-integration'),
            'manage_options',
            __FILE__,
            [$this, 'viewAdminSettingsPage']
        );
    }

    private function domainNameValidate(string $value): bool {
        $value = strtolower(trim($value));
        if (empty($value)) {
            return false;
        }
        // Support wildcard domains (e.g. *.example.com) as ShrtFly script handles them
        $value = ltrim($value, '*.');
        return (bool) preg_match('/^(?!\-)(?:[a-zA-Z\d\-]{0,62}[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/', $value);
    }

    private function getSafeDomainsList(): string {
        $domains_file = SHRTFLY_INTEGRATION_PLUGIN_DIR . 'domains';
        if (!file_exists($domains_file) || !is_readable($domains_file)) {
            return __('Domain list file not found or not readable.', 'wp-shrtfly-integration');
        }

        $content = file_get_contents($domains_file);
        if ($content === false) {
            return __('Unable to read domain list file.', 'wp-shrtfly-integration');
        }

        return sanitize_textarea_field($content);
    }

    private function is_amp_plugin_active(): bool {
        return in_array('accelerated-mobile-pages/accelerated-mobile-pages.php', apply_filters('active_plugins', get_option('active_plugins')));
    }

    public function gen_amp_script(): void {
        if (!$this->pluginOptions['enabled']) {
            return;
        }

        $options = $this->pluginOptions;
        $adsType = ($options['ads_type'] === 'adult') ? 2 : 1;

        echo '<!-- [START] wp_shrtfly_integration AMP -->' . "\n";
        echo '<script type="application/json" data-vars-shrtfly>' . "\n";
        echo wp_json_encode([
            'app_url' => 'https://shrtfly.com/',
            'app_api_token' => sanitize_text_field($options['api_token']),
            'app_advert' => intval($adsType)
        ]) . "\n";
        echo '</script>' . "\n";
        echo '<!-- [END] wp_shrtfly_integration AMP -->' . "\n";
    }

    public function activate(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $default_options = [
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled' => '0',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp' => '0',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose' => 'exclude',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type' => 'mainstream'
        ];

        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    public function deactivate(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        wp_clear_scheduled_hook('shrtfly_cleanup_transients');
    }

    public static function uninstall(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit;
        }

        $options_to_delete = [
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type'
        ];

        foreach ($options_to_delete as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook('shrtfly_cleanup_transients');
    }

    public function includeExcludeDomainsValueValidate(string $value): string {
        if (empty(trim($value))) {
            return '';
        }

        $domains = array_filter(array_map('trim', explode(',', trim($value))));
        $valid_domains = [];
        $invalid_domains = [];

        foreach ($domains as $domain) {
            if ($this->domainNameValidate($domain)) {
                $valid_domains[] = $domain;
            } else {
                $invalid_domains[] = $domain;
            }
        }

        if (!empty($invalid_domains)) {
            add_settings_error(
                SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value',
                'invalid_domains',
                sprintf(
                    __('Invalid domain names found: %s', 'wp-shrtfly-integration'),
                    esc_html(implode(', ', $invalid_domains))
                ),
                'error'
            );
        }

        return implode(',', $valid_domains);
    }

    public function sanitizeAdsType(string $value): string {
        return in_array($value, self::ALLOWED_ADS_TYPES, true) ? $value : 'mainstream';
    }

    public function sanitizeDomainMode(string $value): string {
        return in_array($value, self::ALLOWED_DOMAIN_MODES, true) ? $value : 'exclude';
    }

    public function _registerOptions(): void {
        $sanitize_checkbox = function ($value): string { return !empty($value) ? '1' : ''; };

        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled', ['sanitize_callback' => $sanitize_checkbox]);
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp', ['sanitize_callback' => $sanitize_checkbox]);
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', ['sanitize_callback' => [$this, 'sanitizeDomainMode']]);
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', ['sanitize_callback' => [$this, 'sanitizeAdsType']]);
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value', ['sanitize_callback' => [$this, 'includeExcludeDomainsValueValidate']]);
    }

    public function viewAdminSettingsPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-shrtfly-integration'));
        }

        $domains_list = $this->getSafeDomainsList();
        ?>

        <style>
            .shrtfly-wrap { max-width: 860px; margin-top: 20px; }
            .shrtfly-wrap > h1 { display: none; }

            /* Header */
            .shrtfly-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%);
                color: #fff;
                padding: 24px 28px;
                border-radius: 8px 8px 0 0;
                margin-bottom: 0;
            }
            .shrtfly-header-left { display: flex; align-items: center; gap: 14px; }
            .shrtfly-header-icon {
                width: 44px; height: 44px;
                background: rgba(255,255,255,.15);
                border-radius: 10px;
                display: flex; align-items: center; justify-content: center;
            }
            .shrtfly-header-icon svg { width: 24px; height: 24px; fill: #fff; }
            .shrtfly-header-title { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
            .shrtfly-header-version {
                font-size: 11px;
                background: rgba(255,255,255,.2);
                padding: 3px 10px;
                border-radius: 20px;
                font-weight: 500;
                letter-spacing: 0.3px;
            }
            .shrtfly-badge {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 6px 14px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
            .shrtfly-badge.active { background: rgba(72,199,142,.25); color: #a7f3d0; }
            .shrtfly-badge.inactive { background: rgba(252,165,165,.2); color: #fecaca; }
            .shrtfly-badge-dot {
                width: 8px; height: 8px;
                border-radius: 50%;
                display: inline-block;
            }
            .shrtfly-badge.active .shrtfly-badge-dot { background: #48c78e; }
            .shrtfly-badge.inactive .shrtfly-badge-dot { background: #f87171; }

            /* Cards */
            .shrtfly-body {
                background: #f0f2f5;
                padding: 24px 28px 28px;
                border-radius: 0 0 8px 8px;
                border: 1px solid #dcdfe4;
                border-top: none;
            }
            .shrtfly-card {
                background: #fff;
                border: 1px solid #e2e4e9;
                border-radius: 8px;
                padding: 0;
                margin-bottom: 18px;
                overflow: hidden;
                transition: box-shadow .2s;
            }
            .shrtfly-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.06); }
            .shrtfly-card-header {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 16px 22px;
                border-bottom: 1px solid #f0f1f3;
                background: #fafbfc;
            }
            .shrtfly-card-icon {
                width: 32px; height: 32px;
                border-radius: 8px;
                display: flex; align-items: center; justify-content: center;
                flex-shrink: 0;
            }
            .shrtfly-card-icon svg { width: 16px; height: 16px; }
            .shrtfly-card-icon.general { background: #ede9fe; }
            .shrtfly-card-icon.general svg { fill: #7c3aed; }
            .shrtfly-card-icon.api { background: #dbeafe; }
            .shrtfly-card-icon.api svg { fill: #2563eb; }
            .shrtfly-card-icon.domains { background: #d1fae5; }
            .shrtfly-card-icon.domains svg { fill: #059669; }
            .shrtfly-card-title {
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
                margin: 0;
            }
            .shrtfly-card-body { padding: 20px 22px; }

            /* Form rows */
            .shrtfly-field {
                display: flex;
                align-items: flex-start;
                padding: 14px 0;
                border-bottom: 1px solid #f5f5f5;
            }
            .shrtfly-field:last-child { border-bottom: none; padding-bottom: 0; }
            .shrtfly-field:first-child { padding-top: 0; }
            .shrtfly-field-label {
                width: 200px;
                flex-shrink: 0;
                font-size: 13px;
                font-weight: 600;
                color: #374151;
                padding-top: 4px;
            }
            .shrtfly-field-control { flex: 1; min-width: 0; }

            /* Toggle switch */
            .shrtfly-toggle { position: relative; display: inline-block; width: 44px; height: 24px; }
            .shrtfly-toggle input { opacity: 0; width: 0; height: 0; }
            .shrtfly-toggle-slider {
                position: absolute; cursor: pointer;
                top: 0; left: 0; right: 0; bottom: 0;
                background: #cbd5e1;
                border-radius: 24px;
                transition: background .25s;
            }
            .shrtfly-toggle-slider:before {
                content: "";
                position: absolute;
                height: 18px; width: 18px;
                left: 3px; bottom: 3px;
                background: #fff;
                border-radius: 50%;
                transition: transform .25s;
                box-shadow: 0 1px 3px rgba(0,0,0,.15);
            }
            .shrtfly-toggle input:checked + .shrtfly-toggle-slider { background: #2563eb; }
            .shrtfly-toggle input:checked + .shrtfly-toggle-slider:before { transform: translateX(20px); }
            .shrtfly-toggle input:focus + .shrtfly-toggle-slider { box-shadow: 0 0 0 3px rgba(37,99,235,.2); }

            /* Pill radio */
            .shrtfly-pills { display: inline-flex; border-radius: 6px; overflow: hidden; border: 1px solid #e2e4e9; }
            .shrtfly-pills label {
                display: inline-flex; align-items: center;
                padding: 7px 18px;
                font-size: 13px; font-weight: 500;
                cursor: pointer;
                color: #4b5563;
                background: #fff;
                border-right: 1px solid #e2e4e9;
                transition: all .15s;
                margin: 0;
            }
            .shrtfly-pills label:last-child { border-right: none; }
            .shrtfly-pills input { display: none; }
            .shrtfly-pills label.selected {
                background: #2563eb;
                color: #fff;
            }

            /* Inputs */
            .shrtfly-input {
                width: 100%;
                max-width: 380px;
                padding: 8px 12px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 13px;
                transition: border-color .15s, box-shadow .15s;
                box-sizing: border-box;
            }
            .shrtfly-input:focus {
                outline: none;
                border-color: #2563eb;
                box-shadow: 0 0 0 3px rgba(37,99,235,.12);
            }
            .shrtfly-textarea {
                width: 100%;
                max-width: 460px;
                padding: 10px 12px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 12px;
                resize: vertical;
                transition: border-color .15s, box-shadow .15s;
                box-sizing: border-box;
            }
            .shrtfly-textarea:focus {
                outline: none;
                border-color: #2563eb;
                box-shadow: 0 0 0 3px rgba(37,99,235,.12);
            }

            /* Status badge inline */
            .shrtfly-token-status {
                display: inline-flex; align-items: center; gap: 5px;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                margin-left: 10px;
                vertical-align: middle;
            }
            .shrtfly-token-status.ok { background: #d1fae5; color: #065f46; }
            .shrtfly-token-status.missing { background: #fee2e2; color: #991b1b; }
            .shrtfly-token-status-dot {
                width: 6px; height: 6px; border-radius: 50%; display: inline-block;
            }
            .shrtfly-token-status.ok .shrtfly-token-status-dot { background: #10b981; }
            .shrtfly-token-status.missing .shrtfly-token-status-dot { background: #ef4444; }

            /* Domains demo */
            .shrtfly-domains-demo {
                display: none;
                width: 100%;
                max-width: 460px;
                margin-top: 10px;
                background: #f8fafc;
                border: 1px solid #e2e4e9;
                border-radius: 6px;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 11px;
                padding: 12px;
                color: #64748b;
                box-sizing: border-box;
            }
            .shrtfly-link {
                color: #2563eb;
                text-decoration: none;
                font-weight: 500;
                cursor: pointer;
            }
            .shrtfly-link:hover { text-decoration: underline; }
            .shrtfly-desc {
                font-size: 12px;
                color: #6b7280;
                margin-top: 6px;
                line-height: 1.5;
            }
            .shrtfly-desc a { color: #2563eb; text-decoration: none; }
            .shrtfly-desc a:hover { text-decoration: underline; }

            /* Submit */
            .shrtfly-submit { padding-top: 6px; }
            .shrtfly-submit .button-primary {
                padding: 8px 28px;
                height: auto;
                font-size: 13px;
                font-weight: 600;
                border-radius: 6px;
                background: #2563eb;
                border-color: #1d4ed8;
                box-shadow: 0 1px 2px rgba(0,0,0,.08);
            }
            .shrtfly-submit .button-primary:hover { background: #1d4ed8; }

            /* Footer */
            .shrtfly-footer {
                text-align: center;
                padding: 14px 0 0;
                color: #9ca3af;
                font-size: 11px;
            }
            .shrtfly-footer a { color: #6b7280; text-decoration: none; }
            .shrtfly-footer a:hover { color: #2563eb; }
        </style>

        <div class="wrap shrtfly-wrap">
            <h1><?php esc_html_e('WP ShrtFly Integration Settings', 'wp-shrtfly-integration'); ?></h1>

            <?php settings_errors(); ?>

            <!-- Header -->
            <div class="shrtfly-header">
                <div class="shrtfly-header-left">
                    <div class="shrtfly-header-icon">
                        <svg viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                    </div>
                    <span class="shrtfly-header-title"><?php esc_html_e('ShrtFly Integration', 'wp-shrtfly-integration'); ?></span>
                    <span class="shrtfly-header-version">v<?php echo esc_html($this->pluginDetails['Version']); ?></span>
                </div>
                <?php if ($this->pluginOptions['enabled']): ?>
                    <span class="shrtfly-badge active"><span class="shrtfly-badge-dot"></span> <?php esc_html_e('Active', 'wp-shrtfly-integration'); ?></span>
                <?php else: ?>
                    <span class="shrtfly-badge inactive"><span class="shrtfly-badge-dot"></span> <?php esc_html_e('Inactive', 'wp-shrtfly-integration'); ?></span>
                <?php endif; ?>
            </div>

            <!-- Body -->
            <div class="shrtfly-body">
                <form method="post" action="options.php">
                    <?php settings_fields(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP); ?>
                    <?php do_settings_sections(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP); ?>

                    <!-- General Settings -->
                    <div class="shrtfly-card">
                        <div class="shrtfly-card-header">
                            <div class="shrtfly-card-icon general">
                                <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.48.48 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1115.6 12 3.6 3.6 0 0112 15.6z"/></svg>
                            </div>
                            <h3 class="shrtfly-card-title"><?php esc_html_e('General Settings', 'wp-shrtfly-integration'); ?></h3>
                        </div>
                        <div class="shrtfly-card-body">
                            <div class="shrtfly-field">
                                <div class="shrtfly-field-label"><?php esc_html_e('Integration Enabled', 'wp-shrtfly-integration'); ?></div>
                                <div class="shrtfly-field-control">
                                    <label class="shrtfly-toggle">
                                        <input type="checkbox" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled')); ?> value="1" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_enabled" />
                                        <span class="shrtfly-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="shrtfly-field">
                                <div class="shrtfly-field-label"><?php esc_html_e('AMPforWP Integration', 'wp-shrtfly-integration'); ?></div>
                                <div class="shrtfly-field-control">
                                    <label class="shrtfly-toggle">
                                        <input type="checkbox" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp')); ?> value="1" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_enabled_amp" />
                                        <span class="shrtfly-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="shrtfly-field">
                                <div class="shrtfly-field-label"><?php esc_html_e('ADS Type', 'wp-shrtfly-integration'); ?></div>
                                <div class="shrtfly-field-control">
                                    <div class="shrtfly-pills">
                                        <label class="<?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream') === 'mainstream' ? 'selected' : ''; ?>">
                                            <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_ads_type" value="mainstream" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream'), 'mainstream'); ?> />
                                            <span><?php esc_html_e('Mainstream', 'wp-shrtfly-integration'); ?></span>
                                        </label>
                                        <label class="<?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream') === 'adult' ? 'selected' : ''; ?>">
                                            <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_ads_type" value="adult" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream'), 'adult'); ?> />
                                            <span><?php esc_html_e('Adult', 'wp-shrtfly-integration'); ?></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- API Configuration -->
                    <div class="shrtfly-card">
                        <div class="shrtfly-card-header">
                            <div class="shrtfly-card-icon api">
                                <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1s3.1 1.39 3.1 3.1v2z"/></svg>
                            </div>
                            <h3 class="shrtfly-card-title"><?php esc_html_e('API Configuration', 'wp-shrtfly-integration'); ?></h3>
                        </div>
                        <div class="shrtfly-card-body">
                            <div class="shrtfly-field">
                                <div class="shrtfly-field-label"><?php esc_html_e('API Token', 'wp-shrtfly-integration'); ?></div>
                                <div class="shrtfly-field-control">
                                    <input type="text" class="shrtfly-input" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_api_token" value="<?php echo esc_attr(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token', '')); ?>" required placeholder="<?php esc_attr_e('Enter your ShrtFly API token', 'wp-shrtfly-integration'); ?>" />
                                    <?php if (get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token')): ?>
                                        <span class="shrtfly-token-status ok"><span class="shrtfly-token-status-dot"></span> <?php esc_html_e('Configured', 'wp-shrtfly-integration'); ?></span>
                                    <?php else: ?>
                                        <span class="shrtfly-token-status missing"><span class="shrtfly-token-status-dot"></span> <?php esc_html_e('Required', 'wp-shrtfly-integration'); ?></span>
                                    <?php endif; ?>
                                    <p class="shrtfly-desc">
                                        <?php esc_html_e('Get your API token from the', 'wp-shrtfly-integration'); ?> <a href="https://shrtfly.com/publisher/developer-api" target="_blank" rel="noopener"><?php esc_html_e('ShrtFly Developer API', 'wp-shrtfly-integration'); ?></a> <?php esc_html_e('page.', 'wp-shrtfly-integration'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Domain Management -->
                    <div class="shrtfly-card">
                        <div class="shrtfly-card-header">
                            <div class="shrtfly-card-icon domains">
                                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                            </div>
                            <h3 class="shrtfly-card-title"><?php esc_html_e('Domain Management', 'wp-shrtfly-integration'); ?></h3>
                        </div>
                        <div class="shrtfly-card-body">
                            <div class="shrtfly-field">
                                <div class="shrtfly-field-label"><?php esc_html_e('Domain Mode', 'wp-shrtfly-integration'); ?></div>
                                <div class="shrtfly-field-control">
                                    <div class="shrtfly-pills">
                                        <label class="<?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude') === 'include' ? 'selected' : ''; ?>">
                                            <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_include_exclude_domains_choose" value="include" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude'), 'include'); ?> />
                                            <span><?php esc_html_e('Include', 'wp-shrtfly-integration'); ?></span>
                                        </label>
                                        <label class="<?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude') === 'exclude' ? 'selected' : ''; ?>">
                                            <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_include_exclude_domains_choose" value="exclude" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude'), 'exclude'); ?> />
                                            <span><?php esc_html_e('Exclude', 'wp-shrtfly-integration'); ?></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="shrtfly-field">
                                <div class="shrtfly-field-label"><?php esc_html_e('Domains List', 'wp-shrtfly-integration'); ?></div>
                                <div class="shrtfly-field-control">
                                    <textarea rows="4" class="shrtfly-textarea" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_include_exclude_domains_value" placeholder="<?php esc_attr_e('example.com, test.org, *.sample.net', 'wp-shrtfly-integration'); ?>"><?php echo esc_textarea(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value', '')); ?></textarea>
                                    <p class="shrtfly-desc">
                                        <?php esc_html_e('Comma-separated list of domains. Wildcards supported (*.example.com).', 'wp-shrtfly-integration'); ?>
                                        <a href="#" class="shrtfly-link" id="shrtfly-toggle-domains"><?php esc_html_e('View demo domains', 'wp-shrtfly-integration'); ?></a>
                                    </p>
                                    <textarea rows="4" class="shrtfly-domains-demo" id="shrtfly-domains-demo" readonly><?php echo esc_textarea($domains_list); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="shrtfly-submit">
                        <input type="submit" class="button-primary" value="<?php esc_attr_e('Save Settings', 'wp-shrtfly-integration'); ?>" />
                    </div>
                </form>

                <div class="shrtfly-footer">
                    WP ShrtFly Integration v<?php echo esc_html($this->pluginDetails['Version']); ?>
                    &middot;
                    <a href="https://wordpress-plugins.luongovincenzo.it/plugin/shrtfly-integration" target="_blank" rel="noopener"><?php esc_html_e('Plugin Page', 'wp-shrtfly-integration'); ?></a>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var toggle = document.getElementById('shrtfly-toggle-domains');
            var demo = document.getElementById('shrtfly-domains-demo');
            if (toggle && demo) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    demo.style.display = demo.style.display === 'none' || demo.style.display === '' ? 'block' : 'none';
                });
            }

            document.querySelectorAll('.shrtfly-pills').forEach(function(group) {
                var labels = group.querySelectorAll('label');
                labels.forEach(function(label) {
                    label.addEventListener('click', function() {
                        labels.forEach(function(l) { l.classList.remove('selected'); });
                        label.classList.add('selected');
                    });
                });
            });
        });
        </script>
        <?php
    }

}

function wp_shrtfly_integration_init(): void {
    WPShrtFlyDashboardIntegration::getInstance();
}
add_action('plugins_loaded', 'wp_shrtfly_integration_init');
