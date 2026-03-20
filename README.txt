=== WP ShrtFly Integration ===
Contributors: Vincenzo Luongo
Tags: ShrtFly integration, ShrtFly dashboard, ShrtFly stats, ShrtFly script massive, ShrtFly, ShrtFly plugin
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Secure and optimized ShrtFly integration plugin with improved performance and enhanced security features.

== Description ==

WP ShrtFly Integration is a secure and optimized WordPress plugin that allows you to easily integrate ShrtFly's monetization services into your website. This plugin provides a safe way to configure Full Page Script integration with comprehensive security measures and performance optimizations.

**Key Features:**

* Secure integration with proper input sanitization
* Optimized performance with proper WordPress standards
* Modern admin interface with visual status indicators
* Enhanced security with capability checks and whitelist validation
* Full AMP support for mobile pages
* Domain include/exclude functionality
* Support for different ad types (Mainstream/Adult)
* Proper cleanup on plugin uninstall
* WordPress 7.0 compatible
* PHP 8.0+ with typed properties and return types

== Installation ==

1. Upload `wp-shrtfly-integration` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Edit the plugin settings by clicking "ShrtFly Settings" on the settings navbar

== Frequently Asked Questions ==

= This is official plugin? =

No

= Is there a premium version available? =

There is currently no premium version available.

= Where can I get the api token? =

Setup an free account and get your credential from [ShrtFly - Developer API](https://shrtfly.com/member/tools/api "Developer API")


== Screenshots ==

1. Settings Page

== Changelog ==

= 2.0.0 =
* BREAKING: Minimum PHP version raised to 8.0
* COMPATIBILITY: Tested and compatible with WordPress 7.0
* SECURITY: Added whitelist validation for ads_type and domain mode options
* SECURITY: Escaped error message output in domain validation
* SECURITY: Removed redundant nonce field (handled by Settings API)
* FIX: Domain validation now supports wildcard patterns (*.example.com) as expected by ShrtFly script
* FIX: Made constructor private to enforce singleton pattern correctly
* FIX: Moved admin_init registration to constructor (was incorrectly nested in admin_menu)
* FIX: Removed unused enabled_stats option
* IMPROVEMENT: Replaced jQuery dependency with vanilla JavaScript
* IMPROVEMENT: Added PHP 8.0 typed properties and return types
* IMPROVEMENT: Moved inline CSS to wp_add_inline_style for proper asset loading
* IMPROVEMENT: Cleaned up uninstall to remove only existing options
* UI: Complete admin page redesign with modern card-based layout
* UI: Added branded header with gradient, plugin version badge, and live status indicator
* UI: Toggle switches replacing plain checkboxes for boolean settings
* UI: Pill-style radio buttons for ADS type and domain mode selection
* UI: Section cards with contextual color-coded icons (settings, API, domains)
* UI: Inline API token status badge with dot indicator
* UI: Improved input styling with focus states and consistent border-radius
* UI: Added plugin page link in footer
* UI: Fixed Plugin URI and removed broken donate link pointing to non-existent anchors

= 1.6.0 =
* SECURITY: Fixed critical file inclusion vulnerability
* SECURITY: Added comprehensive input sanitization and validation
* SECURITY: Implemented nonce verification and capability checks
* FIX: Corrected domain validation logic
* FIX: Fixed AMP plugin detection typo
* IMPROVEMENT: Complete code refactoring with WordPress standards
* IMPROVEMENT: Enhanced admin interface with better UX
* IMPROVEMENT: Added performance optimizations
* IMPROVEMENT: Proper script enqueueing and loading
* IMPROVEMENT: Added internationalization support
* NEW: Added activation/deactivation/uninstall hooks
* NEW: Implemented singleton pattern
* NEW: Added comprehensive error handling

= 1.5.0 =
* Add support for different ads type (Mainstream or Adult)
* Minor fix

= 1.4.0 =
* Add support for new ShrtFly API v2 2022
* Support for Wordpress 6.1 added

= 1.3.0 =
* Add option for load javascript lib with defer mode
* Support for Wordpress 6.x added

= 1.2.0 =
* Support for Wordpress 5.9 added

= 1.1.0 =
* Support for Wordpress 5.9 added
* Minor bug fix

= 1.0.0 =
* Public release
