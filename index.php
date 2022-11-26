<?php
/**
 * Plugin Name: WP ShrtFly Integration
 * Plugin URI: https://wordpress-plugins.luongovincenzo.it/#wp-shrtfly-integration
 * Description: This plugin allows you to configure Full Page Script and widget for stats
 * Version: 1.5.0
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

    function __construct() {

        if (!function_exists('get_plugin_data')) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        $this->pluginDetails = get_plugin_data(__FILE__);
        $this->pluginOptions = [
            'enabled' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled'),
            'js_defer_enabled' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_js_defer_enabled'),
            'enabled_stats' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_stats'),
            'enabled_amp' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp'),
            'api_token' => trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token')) ?: '-1',
            'include_exclude_domains_choose' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose') ?: 'exclude',
            'include_exclude_domains_value' => trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value')),
            'ads_type' => get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type') ?: 'mainstream',
        ];

        add_action('wp_head', [$this, 'gen_script']);
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_actions']);

        /*
         * Support for AMPforWP - ampforwp.com
         */

        if (in_array('accelerated-mobile-pages/accelerated-moblie-pages.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            if ($this->pluginOptions['enabled_amp']) {
                add_action('amp_post_template_head', [$this, 'gen_script']);
            }
        }
    }

    public function add_plugin_actions($links) {
        $links[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=shrtfly-integration%2Findex.php')) . '">Settings</a>';
        $links[] = '<a href="https://wordpress-plugins.luongovincenzo.it/#donate" target="_blank">Donate</a>';
        return $links;
    }

    private function includeExcludeDomainScript($options) {
        $script = 'var ';
        if ($options['include_exclude_domains_choose'] == 'include') {
            $script .= 'app_domains = [';
        } else if ($options['include_exclude_domains_choose'] == 'exclude') {
            $script .= 'app_exclude_domains = [';
        }
        if (trim($options['include_exclude_domains_value'])) {
            $script .= implode(', ', array_map(function ($x) {
                        return json_encode(trim($x));
                    }, explode(',', trim($options['include_exclude_domains_value']))));
        }

        $script .= '];';
        return $script;
    }

    public function gen_script() {
        if (get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled')) {
            $options = $this->pluginOptions;

            $loadJSDefer = '';
            if (get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_js_defer_enabled')) {
                $loadJSDefer = 'defer';
            }

            $adsType = 1;

            if ($options['ads_type'] == 'adult') {
                $adsType = 2;
            } else if ($options['ads_type'] == 'mainstream') {
                $adsType = 1;
            }

            //var app_advert = 2; 1 = Mainstream | 2 = Adult
            print '<!-- [START]  wp_shrtfly_integration -->
                <script type="text/javascript">
                    var app_url = "https://shrtfly.com/";
                    var app_api_token = ' . json_encode($options['api_token']) . ';
                    var app_advert = ' . $adsType . ';
                    ' . $this->includeExcludeDomainScript($options) . '
                </script>
                <script ' . $loadJSDefer . ' src="//shrtfly.com/js/full-page-script.js"></script>
                <!-- [END] wp_shrtfly_integration -->';
        } else {
            return false;
        }
    }

    public function create_admin_menu() {
        add_options_page('ShrtFly Settings', 'ShrtFly Settings', 'administrator', __FILE__, [$this, 'viewAdminSettingsPage']);
        add_action('admin_init', [$this, '_registerOptions']);
    }

    private function domainNameValidate($value) {
        return preg_match('/^(?!\-)(?:[a-zA-Z\d\-]{0,62}[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/', $value);
    }

    private function includeExcludeDomainsValueValidate($value) {
        $arr = array_filter(array_map(function ($x) {
                    return trim($x);
                }, explode(',', trim($value))), function ($x) {
                    return $x ? true : false;
                });

        if (count($arr)) {
            array_map(function ($x) {
                if (!$this->domainNameValidate($x)) {
                    /* NULL */
                }
            }, $arr);
        } else {
            add_settings_error(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_id', [$this, 'includeExcludeDomainsValueValidate'], 'You must specify at least one domain name to include/exclude.', 'error');
        }

        return implode(',', $arr);
    }

    public function _registerOptions() {
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_js_defer_enabled');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_domain');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type');
        register_setting(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP, SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value', [$this, 'includeExcludeDomainsValueValidate']);
    }

    public function viewAdminSettingsPage() {
        ?>

        <style>
            .left_shrtfly_bar {
                width:200px;
            }
            
            #domains_demo_list {
                display: none;
                width: 64%;
            }
        </style>
        <div class="wrap">
            <h2>WP ShrtFly Settings</h2>

            <form method="post" action="options.php">
                <?php settings_fields(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP); ?>
                <?php do_settings_sections(SHRTFLY_INTEGRATION_PLUGIN_SETTINGS_GROUP); ?>
                <table class="form-table">
                    <tbody>
                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar">Integration Enabled</td>
                            <td><input type="checkbox" <?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled') ? 'checked="checked"' : '' ?> value="1" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_enabled" /></td>
                        </tr>

                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar">Load Javascript Lib with Defer Mode</td>
                            <td><input type="checkbox" <?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_js_defer_enabled') ? 'checked="checked"' : '' ?> value="1" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_js_defer_enabled" /></td>
                        </tr>

                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar">Enable AMPforWP Integration</td>
                            <td><input type="checkbox" <?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_enabled_amp') ? 'checked="checked"' : '' ?> value="1" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_enabled_amp" /></td>
                        </tr>

                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar">ADS Type</td>
                            <td>
                                <div>
                                    <label>
                                        <input type="radio" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_ads_type" value="mainstream" <?php echo!get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type') || get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type') == 'mainstream' ? 'checked="checked"' : '' ?> />
                                        Mainstream
                                    </label>
                                    <label>
                                        <input type="radio" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_ads_type" value="adult" <?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_ads_type') == 'adult' ? 'checked="checked"' : '' ?> />
                                        Adult
                                    </label>
                                </div>
                            </td>
                        </tr>

                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar">API Token</td>
                            <td>
                                <input type="text" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_api_token" value="<?php echo htmlspecialchars(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_api_token'), ENT_QUOTES) ?>" required />
                                <p class="description">
                                    Simply visit <a href="https://shrtfly.com/publisher/developer-api" target="_blank">Developer API</a> page.
                                    Read <strong>Your API Token</strong> string
                                </p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <td scope="row" class="left_shrtfly_bar">Include/Exclude Domains</td>
                            <td>
                                <div>
                                    <label>
                                        <input type="radio" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_include_exclude_domains_choose" value="include" <?php echo!get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose') || get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose') == 'include' ? 'checked="checked"' : '' ?> />
                                        Include
                                    </label>
                                    <label>
                                        <input type="radio" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_include_exclude_domains_choose" value="exclude" <?php echo get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_choose') == 'exclude' ? 'checked="checked"' : '' ?> />
                                        Exclude
                                    </label>
                                </div>
                                <div>
                                    <textarea rows="4" style="width: 64%;" name="<?php print SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX; ?>_include_exclude_domains_value"><?php echo htmlspecialchars(trim(get_option(SHRTFLY_INTEGRATION_PLUGIN_OPTIONS_PREFIX . '_include_exclude_domains_value')), ENT_QUOTES) ?></textarea>
                                    <p class="description">
                                        Comma-separated list of domains. you can view a list of demo domains <a href="javascript:jQuery('#domains_demo_list').toggle();">here</a>
                                        <br />
                                        <textarea rows="4" id="domains_demo_list" readonly=""><?php include('domains'); ?></textarea>
                                    </p>

                                </div>
                            </td>
                        </tr>                      
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php _e('Update Settings') ?>" />
                </p>
            </form>
        </div>
        <?php
    }

}

new WPShrtFlyDashboardIntegration();
?>