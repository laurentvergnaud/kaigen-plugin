=== Tamiro Connector ===
Contributors: kaigen
Tags: ai, content, seo, automation, integration
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Tamiro for AI-powered content generation and management.

== Description ==

Tamiro Connector seamlessly integrates your WordPress site with the Tamiro platform, enabling:

* **Dual Authentication**: Support for both API Key and WordPress Application Password authentication
* **Custom Post Types**: Select which post types to expose to Tamiro
* **Content Discovery**: Automatically detect and share your site structure, custom fields (ACF, Meta Box, Pods, CMB2), and editor type
* **Bidirectional Sync**: Generate content in Tamiro and publish directly to WordPress, or update existing posts
* **Editor Integration**: "Open in Tamiro" button in post editor for seamless editing
* **Role-Based Permissions**: Configure which user roles can access Tamiro features
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
3. Go to Settings > Tamiro Connector to configure
4. Choose authentication method (API Key recommended)
5. If using API Key: Generate a key in your Tamiro dashboard and paste it here
6. If using Application Password: Generate one in your WordPress profile and configure in Tamiro
7. Select which post types to expose to Tamiro
8. Configure role permissions
9. Test the connection

== Updates ==

This plugin can check for updates from the GitHub repository:

* https://github.com/laurentvergnaud/kaigen-plugin

To publish a dashboard-installable update, create a GitHub Release and upload a packaged ZIP asset named like `kaigen-connector.zip`.

== Frequently Asked Questions ==

= What is Tamiro? =

Tamiro is an AI-powered content generation and management platform that helps you create high-quality, SEO-optimized content.

= Which authentication method should I use? =

We recommend using API Key authentication for better security and easier management. Application Password is available for backward compatibility.

= Can I control which post types are accessible? =

Yes! In the Post Types tab of the settings page, you can select exactly which custom post types should be exposed to Tamiro.

= Does it work with custom field plugins? =

Yes! The plugin automatically detects and supports ACF, Meta Box, Pods, and CMB2.

= Is my data secure? =

Yes. All communication is encrypted via HTTPS, API keys are stored securely in your WordPress database, and you have full control over what data is shared.

== Screenshots ==

1. Settings page - Authentication tab
2. Settings page - Post Types selection
3. Settings page - Role permissions
4. "Open in Tamiro" button in post editor
5. Sync logs viewer

== Changelog ==

= 1.6.1 =
* The measurement script is served on `/wp-json/kaigen/v1/m/script`: a host's web server answered 404 on the `.js` route before WordPress, so the tag was never posed there.
* The goal rules are read every ten minutes while none is posed, so a goal validated in Tamiro counts within minutes.

= 1.6.0 =
* The measurement goals the client validated in Tamiro are posed on the site: a page reached, a click, a form sent, each counted as one named event; what the page holds for each rule is told back to Tamiro (`/m/goals`).

= 1.5.0 =
* Audience measurement: the plugin serves the measurement script Tamiro runs (Umami) under the site's own domain and relays the visits to it, first-party; the website and the instance come from Tamiro
* The native tracker (pageviews sent to Tamiro, `/wp-json/kaigen/v1/track`) is removed; the WooCommerce events are not sent until the next version

= 1.4.1 =
* « Save Changes » with a new API key activates it as « Save and activate Tamiro » does: the project is linked and site tracking starts, or the page says why Tamiro refused the key
* After a successful activation the settings page reloads and shows the linked project; a key left in the field no longer unlinked the project at the next save

= 1.4.0 =
* An update may carry the date Tamiro read the post: when the post changed since, nothing is written (409)
* Tamiro places an internal link on words a paragraph or a list already holds, and demotes or removes an H1 of a post's content; the rest of the content is written back unchanged
* Schema templates: Article, Service, FAQ and Event, filled on every page view from the post's own data, applied to a post type, a post type and a term, or to chosen posts; joined to Yoast's or Rank Math's graph, never as a second node of a kind the graph has
* Tamiro reads and sets the schema type of a post type in Yoast and Rank Math
* The JSON-LD of Tamiro's automations joins the SEO plugin's graph: a post no longer carries two Article nodes

= 1.3.0 =
* A Tamiro key writes only to the post types enabled in the settings; it cannot write meta WordPress protects (SEO plugin fields excepted), set an author who cannot edit the post type, or use a taxonomy the post type does not have
* An update writes only the fields Tamiro changes; it no longer rewrites the other meta of the post
* The tracker counts pageviews only: form submissions are no longer read
* Internal links list only the enabled post types
* JSON-LD is escaped inside its script tag (1.2.1, never released on its own)

= 1.2.0 =
* Kaigen is now Tamiro: plugin name, settings, editor button and messages
* New installations point to https://app.tamiro.ai; an existing API URL is kept
* Tracking errors are handled more gracefully (1.1.8, never released on its own)

= 1.0.0 =
* Initial release
* API Key and Application Password authentication
* Custom post type selection
* Content discovery and sync
* Editor integration
* Role-based permissions
* Audit logging

== Upgrade Notice ==

= 1.6.1 =
The measurement tag is served on a route without extension; update if the site's tag never appeared.

= 1.6.0 =
The conversions asked in Tamiro are counted on the site; nothing to configure.

= 1.5.0 =
Audience measurement served by the site and relayed to Tamiro's measurement; the native tracker is removed.

= 1.4.1 =
Saving a new API key links the Tamiro project without a separate activation.

= 1.4.0 =
Internal links, heading fixes and schema templates approved in Tamiro, written by the plugin.

= 1.3.0 =
Security update: a narrower write scope for the Tamiro key, updates that write only what changed, no more form capture.

= 1.2.0 =
Kaigen is now Tamiro. Your settings and connection are kept.

= 1.0.0 =
Initial release of Tamiro Connector.




