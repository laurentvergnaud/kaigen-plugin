# Tamiro Connector - WordPress Plugin

Connect your WordPress site to Tamiro for AI-powered content generation and management.

## Features

- **Dual Authentication**: Support for both API Key and WordPress Application Password authentication
- **Custom Post Types**: Select which post types to expose to Tamiro
- **Content Discovery**: Automatically detect and share your site structure, custom fields (ACF, Meta Box, Pods, CMB2), and editor type
- **Bidirectional Sync**: Generate content in Tamiro and publish directly to WordPress, or update existing posts
- **Editor Integration**: "Open in Tamiro" button in post editor for seamless editing
- **Role-Based Permissions**: Configure which user roles can access Tamiro features
- **Audit Logs**: Track all synchronization activities

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- HTTPS enabled (required for secure API communication)

## Installation

### From ZIP File

1. Download the plugin ZIP file
2. Go to WordPress Admin > Plugins > Add New
3. Click "Upload Plugin"
4. Choose the ZIP file and click "Install Now"
5. Activate the plugin

### Manual Installation

1. Upload the `kaigen-connector` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress

## Updates

This plugin is configured to check for updates from the GitHub repository:

- Repository: `https://github.com/laurentvergnaud/kaigen-plugin`
- Delivery method: GitHub Releases ZIP assets

To publish an update that WordPress can install correctly:

1. Build the release ZIP with `scripts/build-kaigen-plugin-release.sh`
2. Create a GitHub release in `laurentvergnaud/kaigen-plugin`
3. Upload the generated `kaigen-connector.zip` as a release asset

The uploaded asset name should start with `kaigen-connector` and end with `.zip`.

## Configuration

### Method 1: API Key Authentication (Recommended)

1. Go to **Settings > Tamiro Connector**
2. Select **API Key** as authentication method
3. Log in to your Tamiro dashboard
4. Navigate to WordPress Integration settings
5. Generate a new API key
6. Copy the API key (shown only once!)
7. Paste it into the WordPress plugin settings
8. Click **Test Connection** to verify
9. Save settings

### Method 2: Application Password

1. Go to **Settings > Tamiro Connector**
2. Select **Application Password** as authentication method
3. Go to your WordPress profile
4. Scroll to **Application Passwords**
5. Create a new application password named "Tamiro"
6. Copy the generated password
7. Enter your username and password in Tamiro project settings
8. Test the connection

## Usage

### Selecting Post Types

1. Go to **Settings > Tamiro Connector > Post Types** tab
2. Check the post types you want to expose to Tamiro
3. Click **Save Changes**

### Setting Permissions

1. Go to **Settings > Tamiro Connector > Permissions** tab
2. Select which user roles can use Tamiro features
3. Click **Save Changes**

### Syncing Content

1. Go to **Settings > Tamiro Connector > Logs** tab
2. Click **Sync Content Now**
3. Wait for the sync to complete
4. View sync logs for details

### Editing Posts in Tamiro

1. Open any post in WordPress editor
2. Look for the **Tamiro** meta box in the sidebar (Gutenberg) or the **Open in Tamiro** button (Classic Editor)
3. Click the button to open the post in Tamiro
4. Edit the content using AI-powered tools
5. Save changes back to WordPress

## REST API Endpoints

The plugin registers the following REST API endpoints:

- `GET /wp-json/kaigen/v1/structure` - Get site structure
- `GET /wp-json/kaigen/v1/content` - Get content library
- `POST /wp-json/kaigen/v1/content` - Create post
- `GET /wp-json/kaigen/v1/content/{id}` - Get specific post
- `POST /wp-json/kaigen/v1/content/{id}` - Update post (a v2 patch)
- `POST /wp-json/kaigen/v1/media/import` - Import a remote image into the media library
- `GET /wp-json/kaigen/v1/links` - Get internal linking candidates
- `GET /wp-json/kaigen/v1/m/script` - The audience measurement script (Umami's, read on Tamiro's instance once a day), no key (1.5.0; `/m.js` until 1.6.0, a `.js` route a host's web server answers 404 before WordPress)
- `POST /wp-json/kaigen/v1/m/api/send` - Relay one measurement call to Tamiro's instance, same origin only, no key (1.5.0)
- `POST /wp-json/kaigen/v1/m/goals` - What a page found for each measurement goal, from `kaigen-goals.js`, same origin only, passed to Tamiro at most every ten minutes (1.6.0, ADR 0104)
- `GET /wp-json/kaigen/v1/schema` - Schema templates and the SEO plugin's schema settings per post type (1.4.0)
- `POST /wp-json/kaigen/v1/schema/templates` - Save or delete schema templates (1.4.0)
- `POST /wp-json/kaigen/v1/schema/settings` - Set the schema type of a post type in Yoast or Rank Math, closed lists (1.4.0)
- `POST /wp-json/kaigen/v1/schema/assign` - Name a template on posts, `_kaigen_schema_template` (1.4.0)
- `GET /wp-json/kaigen/v1/schema/samples` - A few posts of a scope with their custom fields and terms (1.4.0)
- `POST /wp-json/kaigen/v1/schema/preview` - The node a template prints on given posts, nothing saved (1.4.0)
- `POST /wp-json/kaigen/v1/resolve` - The posts behind addresses: their type and terms (1.4.0)

All endpoints but `m/script`, `m/api/send` and `m/goals` require authentication via API key or application password.

A request authenticated by the API key runs without a WordPress user, so no capability check applies to it. Since 1.3.0 (security audit 2026-10-05, `validate_write_scope` of `includes/class-kaigen-update.php`), what it may write is bounded, and a refusal answers `403` or `422` naming what it refused:

- read, list, create and update only the post types enabled in the **Post Types** tab;
- no meta WordPress protects (a leading underscore), except the SEO plugins' keys (`_yoast_wpseo_*`, `rank_math_*`, `_seopress_*`); `seo.raw_meta` holds SEO keys only; with ACF active, only fields ACF knows;
- an author only if that user can edit the post type, terms only of the taxonomies of the post type.

An update writes only the fields its patch changes. Up to 1.2.x it wrote back the whole merged document: every meta of the post, read serialized, was serialized a second time (another plugin's arrays broke), and `wp_update_post` ran even for a meta-only change, passing the content through the HTML filter of a request without a user.

Since 1.4.0 (ADR 0064) an update may carry `expected_modified`, the post's `modified_gmt` as Tamiro read it: when the post changed since, nothing is written (`409`). Its changes may hold `links` (`text`, `sentence`, `href`: the words of a paragraph's or a list's text get the link, never inside a heading, a link or code) and `headings` (`text`, `action` `demote` or `remove`: an H1 of the content, its Gutenberg block included), all or nothing (`422` naming the one not found); the content is cut into its tags and texts, the fragment changed, the rest written back unchanged, without WordPress's HTML filter, which would strip from the rest of the post what a request without a user may not write (`includes/class-kaigen-content-edits.php`). Schema templates (`includes/class-kaigen-schema.php`, option `kaigen_schema_templates`, a post's own in `_kaigen_schema_template`) are rendered on every page view from the post's data (`post:`, `meta:` but never a protected one, `acf:`, `term:`, `const:` sources), nothing printed when a required property is missing; the node joins Yoast's graph (`wpseo_schema_graph`) or Rank Math's (`rank_math/json_ld`), never beside a node of its family, else the plugin prints its own graph; the automations' JSON-LD (`_kaigen_structured_data_json`) joins the same graph under the same rule.

The audience measurement (1.5.0, ADR 0075): the plugin prints on every public page a tag that loads `m/script`, Umami's tracker read on the instance Tamiro runs and kept a day in a transient, with `data-host-url` pointing at `m` of this site, so that the script posts its visits to `m/api/send`, which relays them to the instance with the visitor's address (first of `X-Forwarded-For`), their browser and Umami's session token (`x-umami-cache`) both ways; same origin only, 120 calls a minute per visitor at most. The website id and the instance URL come from `POST api/wordpress/umami-config` on Tamiro, asked once and kept in `kaigen_settings` (`umami_website_id`, `umami_base_url`), retried ten times at most ten minutes apart when it fails. The native tracker of the versions before (`public/js/kaigen-tracker.js`, `/track`) is gone: Tamiro answers its calls 410.

The measurement goals (1.6.0, ADR 0104): the rules the client validated in Tamiro (a page reached, a click on a text, a link or a selector, a form sent, including the Ajax success events of Contact Form 7, WPForms, Gravity Forms, Ninja Forms and Elementor) are read with `umami-goals` every hour, kept in the option `kaigen_measure_goals`, printed as `window.kaigenGoals` before `public/js/kaigen-goals.js`, which sends one named event to Umami per rule met and tells the plugin once per session what the page holds for each rule. A form's content is never read.

## Supported Plugins

### Custom Fields

- Advanced Custom Fields (ACF)
- Meta Box
- Pods
- CMB2

### SEO Plugins

- Yoast SEO
- Rank Math
- SEOPress
- All in One SEO

### Page Builders

- Gutenberg (Block Editor)
- Classic Editor
- Elementor
- Beaver Builder
- Divi

## Developer Hooks

### Filters

```php
// Modify post types list
add_filter('kaigen_post_types', function($post_types) {
    // Your custom logic
    return $post_types;
});

// Modify custom fields
add_filter('kaigen_custom_fields', function($fields, $post_type) {
    // Your custom logic
    return $fields;
}, 10, 2);

// Modify editor URL
add_filter('kaigen_editor_url', function($url, $post_id) {
    // Your custom logic
    return $url;
}, 10, 2);
```

### Actions

```php
// Before post update
add_action('kaigen_before_update', function($post_id, $data) {
    // Your custom logic
}, 10, 2);

// After post update
add_action('kaigen_after_update', function($post_id, $result) {
    // Your custom logic
}, 10, 2);
```

## Troubleshooting

### Connection Test Fails

1. Verify your API key or application password is correct
2. Check that your WordPress site is accessible via HTTPS
3. Ensure no firewall is blocking requests from Tamiro
4. Check WordPress error logs for details

### "Open in Tamiro" Button Not Showing

1. Verify the post type is enabled in settings
2. Check that your user role has `kaigen_edit_posts` capability
3. Clear browser cache and reload

### Sync Fails

1. Check that you have posts in the enabled post types
2. Verify your API key has write permissions
3. Check sync logs for error details
4. Increase PHP memory limit if needed

## Security

- API keys are encrypted using WordPress salts before storage
- All communication uses HTTPS
- Rate limiting prevents abuse (60 requests per minute)
- Role-based access control
- Input sanitization and validation
- Audit logging of all activities

## Support

For support, please contact:
- Email: support@kaigen.app
- Documentation: https://docs.kaigen.app
- GitHub Issues: https://github.com/laurentvergnaud/kaigen-plugin/issues

## Changelog

### 1.0.0 (2024-11-24)
- Initial release
- API Key and Application Password authentication
- Custom post type selection
- Content discovery and sync
- Editor integration
- Role-based permissions
- Audit logging
- ACF, Meta Box, Pods, CMB2 support
- Yoast, Rank Math, SEOPress integration

## License

GPL v2 or later

## Credits

Developed by the Tamiro team.




