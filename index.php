<?php
/**
 * Plugin Name: WP ShrtFly Integration
 * Plugin URI: https://wordpress-plugins.luongovincenzo.it/#wp-shrtfly-integration
 * Description: This plugin allows you to configure Full Page Script and widget for stats
 * Version: 1.6.0
 * Author: Vincenzo Luongo
 * Author URI: https://www.luongovincenzo.it/
 * License: GPLv2 or later
 * Text Domain: wp-shrtfly-integration
 */
if (!defined('ABSPATH')) {
    exit;
}

define("SHRTFLY_INTEGRATION_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX", 'wp_shrtfly_integration_option');
define("SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP", 'wp_shrtfly_integration-settings-group');

class WPShrtFlyDashboardIntegration {

    protected $pluginDetails;
    protected $pluginOptions = [];
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    function __construct() {

        if (!function_exists('get_plugin_data')) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        $this->pluginDetails = get_plugin_data(__FILE__);
        $this->pluginOptions = [
            'enabled' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled'),
            'enabled_stats' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_stats'),
            'enabled_amp' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp'),
            'api_token' => trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token')) ?: '-1',
            'include_exclude_domains_choose' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude'),
            'include_exclude_domains_value' => trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value')),
            'ads_type' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream'),
        ];

        add_action('wp_enqueue_scripts', [$this, 'gen_script']);
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_actions']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        register_uninstall_hook(__FILE__, array(__CLASS__, 'uninstall'));

        /*
         * Support for AMPforWP - ampforwp.com
         */

        if ($this->is_amp_plugin_active() && $this->pluginOptions['enabled_amp']) {
            add_action('amp_post_template_head', [$this, 'gen_amp_script']);
        }
    }

    public function add_plugin_actions($links) {
        if (!current_user_can('manage_options')) {
            return $links;
        }
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . urlencode(__FILE__))) . '">' . esc_html__('Settings', 'wp-shrtfly-integration') . '</a>';
        $donate_link = '<a href="https://wordpress-plugins.luongovincenzo.it/#donate" target="_blank" rel="noopener">' . esc_html__('Donate', 'wp-shrtfly-integration') . '</a>';
        array_unshift($links, $settings_link);
        $links[] = $donate_link;
        return $links;
    }

    private function includeExcludeDomainScript($options) {
        $script = '';

        if ($options['include_exclude_domains_choose'] == 'include') {
            $script .= 'var app_domains = [';
            if (trim($options['include_exclude_domains_value'])) {
                $script .= implode(', ', array_map(function ($x) {
                            return json_encode(trim($x));
                        }, explode(',', trim($options['include_exclude_domains_value']))));
            }
            $script .= '];';
        } else {
            // Always include app_exclude_domains for exclude mode or when not set
            $script .= 'var app_exclude_domains = [';
            if ($options['include_exclude_domains_choose'] == 'exclude' && trim($options['include_exclude_domains_value'])) {
                $script .= implode(', ', array_map(function ($x) {
                            return json_encode(trim($x));
                        }, explode(',', trim($options['include_exclude_domains_value']))));
            }
            $script .= '];';
        }

        return $script;
    }

    public function gen_script() {
        if (!$this->pluginOptions['enabled'] || is_admin()) {
            return;
        }

        $options = $this->pluginOptions;
        $adsType = ($options['ads_type'] === 'adult') ? 2 : 1;

        // Register script with defer in footer
        $script_args = array('strategy' => 'defer', 'in_footer' => true);
        wp_enqueue_script('wp-shrtfly-integration', 'https://shrtfly.com/js/full-page-script.js', array(), '1.0', $script_args);

        // Then add inline script
        $script_vars = array(
            'app_url' => 'https://shrtfly.com/',
            'app_api_token' => sanitize_text_field($options['api_token']),
            'app_advert' => intval($adsType)
        );

        $inline_script = '';
        foreach ($script_vars as $var => $value) {
            $inline_script .= 'var ' . $var . ' = ' . wp_json_encode($value) . ';';
        }

        $inline_script .= $this->includeExcludeDomainScript($options);

        wp_add_inline_script('wp-shrtfly-integration', $inline_script, 'before');
    }

    public function create_admin_menu() {
        add_options_page(
            __('ShrtFly Settings', 'wp-shrtfly-integration'),
            __('ShrtFly Settings', 'wp-shrtfly-integration'),
            'manage_options',
            __FILE__,
            [$this, 'viewAdminSettingsPage']
        );
        add_action('admin_init', [$this, '_registerOptions']);
    }

    private function domainNameValidate($value) {
        $value = strtolower(trim($value));
        if (empty($value)) {
            return false;
        }
        return preg_match('/^(?!\-)(?:[a-zA-Z\d\-]{0,62}[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/', $value);
    }

    private function getSafeDomainsList() {
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

    private function sanitizeOptions($options) {
        return array(
            'enabled' => !empty($options['enabled']),
            'enabled_stats' => !empty($options['enabled_stats']),
            'enabled_amp' => !empty($options['enabled_amp']),
            'api_token' => sanitize_text_field($options['api_token']),
            'include_exclude_domains_choose' => sanitize_text_field($options['include_exclude_domains_choose']),
            'include_exclude_domains_value' => sanitize_textarea_field($options['include_exclude_domains_value']),
            'ads_type' => sanitize_text_field($options['ads_type'])
        );
    }

    private function is_amp_plugin_active() {
        return in_array('accelerated-mobile-pages/accelerated-mobile-pages.php', apply_filters('active_plugins', get_option('active_plugins')));
    }

    public function gen_amp_script() {
        if (!$this->pluginOptions['enabled']) {
            return;
        }

        $options = $this->pluginOptions;
        $adsType = ($options['ads_type'] === 'adult') ? 2 : 1;

        echo '<!-- [START] wp_shrtfly_integration AMP -->' . "\n";
        echo '<script type="application/json" data-vars-shrtfly>' . "\n";
        echo wp_json_encode(array(
            'app_url' => 'https://shrtfly.com/',
            'app_api_token' => sanitize_text_field($options['api_token']),
            'app_advert' => intval($adsType)
        )) . "\n";
        echo '</script>' . "\n";
        echo '<!-- [END] wp_shrtfly_integration AMP -->' . "\n";
    }

    public function admin_enqueue_scripts($hook) {
        if ('settings_page_' . plugin_basename(__FILE__) !== $hook) {
            return;
        }
        wp_enqueue_script('jquery');
    }

    public function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $default_options = array(
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled' => '0',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp' => '0',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose' => 'exclude',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type' => 'mainstream'
        );

        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    public function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        wp_clear_scheduled_hook('shrtfly_cleanup_transients');
    }

    public static function uninstall() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit;
        }

        $options_to_delete = array(
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_stats',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value',
            SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type'
        );

        foreach ($options_to_delete as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook('shrtfly_cleanup_transients');
    }

    public function includeExcludeDomainsValueValidate($value) {
        if (empty(trim($value))) {
            return '';
        }

        $domains = array_filter(array_map('trim', explode(',', trim($value))));
        $valid_domains = array();
        $invalid_domains = array();

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
                    implode(', ', $invalid_domains)
                ),
                'error'
            );
        }

        return implode(',', $valid_domains);
    }

    public function _registerOptions() {
        $sanitize_checkbox = function($value) { return !empty($value) ? '1' : ''; };
        $sanitize_text = function($value) { return sanitize_text_field($value); };

        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled', array('sanitize_callback' => $sanitize_checkbox));
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token', array('sanitize_callback' => $sanitize_text));
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp', array('sanitize_callback' => $sanitize_checkbox));
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', array('sanitize_callback' => $sanitize_text));
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', array('sanitize_callback' => $sanitize_text));
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value', array('sanitize_callback' => [$this, 'includeExcludeDomainsValueValidate']));
    }

    public function viewAdminSettingsPage() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-shrtfly-integration'));
        }

        $domains_list = $this->getSafeDomainsList();
        ?>

        <style>
            .left_shrtfly_bar {
                width: 200px;
                font-weight: 600;
            }

            #domains_demo_list {
                display: none;
                width: 64%;
                background-color: #f9f9f9;
                border: 1px solid #ddd;
                font-family: monospace;
                font-size: 12px;
            }

            .shrtfly-section {
                margin-bottom: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            .shrtfly-section h3 {
                margin-top: 0;
                margin-bottom: 10px;
                color: #23282d;
            }

            .shrtfly-input-group {
                margin-bottom: 15px;
            }

            .shrtfly-input-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }

            .shrtfly-status {
                padding: 8px 12px;
                border-radius: 3px;
                font-weight: 600;
                margin-left: 10px;
            }

            .shrtfly-status.enabled {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .shrtfly-status.disabled {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
        </style>
        <div class="wrap">
            <h1><?php esc_html_e('WP ShrtFly Integration Settings', 'wp-shrtfly-integration'); ?></h1>

            <?php if ($this->pluginOptions['enabled']): ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('ShrtFly Integration is currently active', 'wp-shrtfly-integration'); ?></strong></p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('ShrtFly Integration is currently disabled', 'wp-shrtfly-integration'); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php settings_fields(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP); ?>
                <?php do_settings_sections(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP); ?>
                <?php wp_nonce_field('shrtfly_settings_update', 'shrtfly_nonce'); ?>
                <div class="shrtfly-section">
                    <h3><?php esc_html_e('General Settings', 'wp-shrtfly-integration'); ?></h3>
                    <table class="form-table">
                        <tbody>
                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar"><?php esc_html_e('Integration Enabled', 'wp-shrtfly-integration'); ?></td>
                            <td><input type="checkbox" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled')); ?> value="1" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_enabled" /></td>
                        </tr>

                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar"><?php esc_html_e('Enable AMPforWP Integration', 'wp-shrtfly-integration'); ?></td>
                            <td><input type="checkbox" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp')); ?> value="1" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_enabled_amp" /></td>
                        </tr>

                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar"><?php esc_html_e('ADS Type', 'wp-shrtfly-integration'); ?></td>
                            <td>
                                <div>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_ads_type" value="mainstream" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream'), 'mainstream'); ?> />
                                        <?php esc_html_e('Mainstream', 'wp-shrtfly-integration'); ?>
                                    </label>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_ads_type" value="adult" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type', 'mainstream'), 'adult'); ?> />
                                        <?php esc_html_e('Adult', 'wp-shrtfly-integration'); ?>
                                    </label>
                                </div>
                            </td>
                        </tr>

                    </tbody>
                    </table>
                </div>

                <div class="shrtfly-section">
                    <h3><?php esc_html_e('API Configuration', 'wp-shrtfly-integration'); ?></h3>
                    <table class="form-table">
                        <tbody>
                            <tr valign="top">
                                <td scope="row" class="left_shrtfly_bar"><?php esc_html_e('API Token', 'wp-shrtfly-integration'); ?></td>
                                <td>
                                    <input type="text" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_api_token" value="<?php echo esc_attr(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token', '')); ?>" required placeholder="<?php esc_attr_e('Enter your ShrtFly API token', 'wp-shrtfly-integration'); ?>" style="width: 400px;" />
                                    <?php if (get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token')): ?>
                                        <span class="shrtfly-status enabled"><?php esc_html_e('Configured', 'wp-shrtfly-integration'); ?></span>
                                    <?php else: ?>
                                        <span class="shrtfly-status disabled"><?php esc_html_e('Required', 'wp-shrtfly-integration'); ?></span>
                                    <?php endif; ?>
                                    <p class="description">
                                        <?php esc_html_e('Get your API token from', 'wp-shrtfly-integration'); ?> <a href="https://shrtfly.com/publisher/developer-api" target="_blank" rel="noopener"><?php esc_html_e('ShrtFly Developer API', 'wp-shrtfly-integration'); ?></a> <?php esc_html_e('page', 'wp-shrtfly-integration'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="shrtfly-section">
                    <h3><?php esc_html_e('Domain Management', 'wp-shrtfly-integration'); ?></h3>
                    <table class="form-table">
                        <tbody>
                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar"><?php esc_html_e('Include/Exclude Domains', 'wp-shrtfly-integration'); ?></td>
                            <td>
                                <div>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_include_exclude_domains_choose" value="include" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude'), 'include'); ?> />
                                        <?php esc_html_e('Include', 'wp-shrtfly-integration'); ?>
                                    </label>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_include_exclude_domains_choose" value="exclude" <?php checked(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose', 'exclude'), 'exclude'); ?> />
                                        <?php esc_html_e('Exclude', 'wp-shrtfly-integration'); ?>
                                    </label>
                                </div>
                                <div>
                                    <textarea rows="4" style="width: 64%;" name="<?php echo esc_attr(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX); ?>_include_exclude_domains_value" placeholder="<?php esc_attr_e('example.com, test.org, sample.net', 'wp-shrtfly-integration'); ?>"><?php echo esc_textarea(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value', '')); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Comma-separated list of domains. You can view a list of demo domains', 'wp-shrtfly-integration'); ?> <a href="#" onclick="jQuery('#domains_demo_list').toggle(); return false;"><?php esc_html_e('here', 'wp-shrtfly-integration'); ?></a>
                                        <br />
                                        <textarea rows="4" id="domains_demo_list" readonly=""><?php echo esc_textarea($domains_list); ?></textarea>
                                    </p>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php esc_attr_e('Update Settings', 'wp-shrtfly-integration'); ?>" />
                </p>
            </form>
        </div>
        <?php
    }

}

function wp_shrtfly_integration_init() {
    WPShrtFlyDashboardIntegration::getInstance();
}
add_action('plugins_loaded', 'wp_shrtfly_integration_init');
?>