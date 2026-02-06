# Kaigen Connector - WordPress Plugin

Connect your WordPress site to Kaigen for AI-powered content generation and management.

## Features

- **Dual Authentication**: Support for both API Key and WordPress Application Password authentication
- **Custom Post Types**: Select which post types to expose to Kaigen
- **Content Discovery**: Automatically detect and share your site structure, custom fields (ACF, Meta Box, Pods, CMB2), and editor type
- **Bidirectional Sync**: Generate content in Kaigen and publish directly to WordPress, or update existing posts
- **Editor Integration**: "Open in Kaigen" button in post editor for seamless editing
- **Role-Based Permissions**: Configure which user roles can access Kaigen features
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

## Configuration

### Method 1: API Key Authentication (Recommended)

1. Go to **Settings > Kaigen Connector**
2. Select **API Key** as authentication method
3. Log in to your Kaigen dashboard
4. Navigate to WordPress Integration settings
5. Generate a new API key
6. Copy the API key (shown only once!)
7. Paste it into the WordPress plugin settings
8. Click **Test Connection** to verify
9. Save settings

### Method 2: Application Password

1. Go to **Settings > Kaigen Connector**
2. Select **Application Password** as authentication method
3. Go to your WordPress profile
4. Scroll to **Application Passwords**
5. Create a new application password named "Kaigen"
6. Copy the generated password
7. Enter your username and password in Kaigen project settings
8. Test the connection

## Usage

### Selecting Post Types

1. Go to **Settings > Kaigen Connector > Post Types** tab
2. Check the post types you want to expose to Kaigen
3. Click **Save Changes**

### Setting Permissions

1. Go to **Settings > Kaigen Connector > Permissions** tab
2. Select which user roles can use Kaigen features
3. Click **Save Changes**

### Syncing Content

1. Go to **Settings > Kaigen Connector > Logs** tab
2. Click **Sync Content Now**
3. Wait for the sync to complete
4. View sync logs for details

### Editing Posts in Kaigen

1. Open any post in WordPress editor
2. Look for the **Kaigen** meta box in the sidebar (Gutenberg) or the **Open in Kaigen** button (Classic Editor)
3. Click the button to open the post in Kaigen
4. Edit the content using AI-powered tools
5. Save changes back to WordPress

## REST API Endpoints

The plugin registers the following REST API endpoints:

- `GET /wp-json/kaigen/v1/structure` - Get site structure
- `GET /wp-json/kaigen/v1/content` - Get content library
- `GET /wp-json/kaigen/v1/content/{id}` - Get specific post
- `POST /wp-json/kaigen/v1/content/{id}` - Update post
- `GET /wp-json/kaigen/v1/links` - Get internal linking candidates

All endpoints require authentication via API key or application password.

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
3. Ensure no firewall is blocking requests from Kaigen
4. Check WordPress error logs for details

### "Open in Kaigen" Button Not Showing

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
- GitHub Issues: https://github.com/kaigen/wordpress-plugin

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

Developed by the Kaigen team.





