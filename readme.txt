=== Kaigen Connector ===
Contributors: kaigen
Tags: ai, content, seo, automation, integration
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Kaigen for AI-powered content generation and management.

== Description ==

Kaigen Connector seamlessly integrates your WordPress site with the Kaigen platform, enabling:

* **Dual Authentication**: Support for both API Key and WordPress Application Password authentication
* **Custom Post Types**: Select which post types to expose to Kaigen
* **Content Discovery**: Automatically detect and share your site structure, custom fields (ACF, Meta Box, Pods, CMB2), and editor type
* **Bidirectional Sync**: Generate content in Kaigen and publish directly to WordPress, or update existing posts
* **Editor Integration**: "Open in Kaigen" button in post editor for seamless editing
* **Role-Based Permissions**: Configure which user roles can access Kaigen features
* **Audit Logs**: Track all synchronization activities

= Features =

* Support for Gutenberg, Classic Editor, and custom editors
* ACF, Meta Box, Pods, and CMB2 custom fields detection
* Yoast SEO, Rank Math, and SEOPress integration
* Internal linking suggestions
* Content library for avoiding duplicates
* Secure API key management

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kaigen-connector` directory, or install through WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Kaigen Connector to configure
4. Choose authentication method (API Key recommended)
5. If using API Key: Generate a key in your Kaigen dashboard and paste it here
6. If using Application Password: Generate one in your WordPress profile and configure in Kaigen
7. Select which post types to expose to Kaigen
8. Configure role permissions
9. Test the connection

== Frequently Asked Questions ==

= What is Kaigen? =

Kaigen is an AI-powered content generation and management platform that helps you create high-quality, SEO-optimized content.

= Which authentication method should I use? =

We recommend using API Key authentication for better security and easier management. Application Password is available for backward compatibility.

= Can I control which post types are accessible? =

Yes! In the Post Types tab of the settings page, you can select exactly which custom post types should be exposed to Kaigen.

= Does it work with custom field plugins? =

Yes! The plugin automatically detects and supports ACF, Meta Box, Pods, and CMB2.

= Is my data secure? =

Yes. All communication is encrypted via HTTPS, API keys are stored securely in your WordPress database, and you have full control over what data is shared.

== Screenshots ==

1. Settings page - Authentication tab
2. Settings page - Post Types selection
3. Settings page - Role permissions
4. "Open in Kaigen" button in post editor
5. Sync logs viewer

== Changelog ==

= 1.0.0 =
* Initial release
* API Key and Application Password authentication
* Custom post type selection
* Content discovery and sync
* Editor integration
* Role-based permissions
* Audit logging

== Upgrade Notice ==

= 1.0.0 =
Initial release of Kaigen Connector.





