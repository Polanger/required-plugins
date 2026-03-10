# Polanger Required Plugins (Polanger RP)

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Minimal plugin dependency manager for WordPress themes.**

A simple, modern library for requiring plugins in WordPress themes. Single file, zero dependencies, built on native WordPress APIs.

## Design Philosophy

This library intentionally avoids unnecessary abstraction layers. If WordPress core already provides a solution, Polanger Required Plugins uses it directly.

**Design Goals:**
- Minimal codebase (~800 lines)
- Single-file distribution
- No external dependencies
- Native WordPress APIs (Plugin_Upgrader, plugins_api)
- Modern PHP compatibility (7.4+)
- Modern WordPress compatibility (6.0+)

## Installation

1. Copy `polanger-required-plugins.php` to your theme (e.g., `your-theme/lib/`)
2. Include in `functions.php`

**Example structure:**
```
your-theme/
├── functions.php
└── lib/
    └── polanger-required-plugins.php
```

**In functions.php:**
```php
require_once get_template_directory() . '/lib/polanger-required-plugins.php';
```

## Usage

### Simple

```php
polanger_require_plugins([
    'woocommerce',
    'elementor',
    'contact-form-7'
]);
```

### Shorthand

```php
polanger_require('woocommerce', 'elementor');
```

### With Bundled Plugin

```php
polanger_require_plugins([
    'woocommerce',
    [
        'slug'   => 'theme-core',
        'name'   => 'Theme Core Plugin',
        'source' => get_template_directory() . '/plugins/theme-core.zip',
    ],
]);
```

### With External Plugin (CDN/Remote Server)

```php
polanger_require_plugins([
    'woocommerce',
    [
        'slug'    => 'pro-addon',
        'name'    => 'Pro Addon',
        'source'  => 'https://cdn.example.com/plugins/pro-addon.zip',
        'version' => '2.0.0',
    ],
], [
    'allowed_domains' => ['cdn.example.com'], // Security: whitelist allowed domains
]);
```

### With License-Protected Plugin (Premium Themes)

For premium themes that require license validation before downloading plugins:

```php
// Step 1: Register the plugin with 'license' source
polanger_require_plugins([
    [
        'slug'   => 'theme-pro-features',
        'name'   => 'Theme Pro Features',
        'source' => 'license',
    ],
]);

// Step 2: Add your license handler (in theme's functions.php)
add_filter('polanger_license_download_url', function($url, $plugin) {
    // Your license API call here
    $response = wp_remote_post('https://api.yourtheme.com/download', [
        'body' => [
            'license' => get_option('theme_license_key'),
            'plugin'  => $plugin['slug'],
            'domain'  => home_url(),
        ]
    ]);
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['download_url'] ?? new WP_Error('license_invalid', 'Invalid license');
}, 10, 2);
```

This architecture allows you to integrate any license system (Envato, EDD, WooCommerce, custom) without modifying the library.

### Full Options

```php
polanger_require_plugins([
    [
        'slug'     => 'woocommerce',
        'name'     => 'WooCommerce',      // Optional, auto-generated from slug
        'source'   => 'wordpress',        // 'wordpress' or file path for bundled
        'required' => true,               // true = required, false = recommended
    ],
], [
    'id'              => 'my-theme',
    'menu_title'      => 'Install Plugins',
    'menu_slug'       => 'my-theme-plugins',
    'parent_slug'     => 'themes.php',
    'capability'      => 'install_plugins',
    'allowed_domains' => ['cdn.example.com'], // Whitelist for external sources
]);
```

## Example

```php
// In your theme's functions.php
require_once get_template_directory() . '/lib/polanger-required-plugins.php';

polanger_require_plugins([
    'woocommerce',
    'elementor',
]);
```

After activating the theme, WordPress will show a notice asking the user to install the required plugins.

## Features

- **Single file library** - Just one PHP file (~800 lines)
- **Queue-based bulk installer** - No timeout issues, each plugin is a separate request
- **Selective installation** - Choose specific plugins with checkboxes
- **Native WordPress update integration** - Version-based update detection
- **WordPress.org plugin support** - Install from repository
- **Bundled plugin support** - Install from theme ZIP files
- **External plugin support** - Install from CDN/remote servers (HTTPS required)
- **Automatic activation** - Plugins activate after install
- **Native WordPress admin UI** - Uses WP admin table styling
- **Required and recommended** - Distinguish plugin importance
- **Smart admin notice** - Required can't be dismissed, recommended can
- **Performance optimized** - Cached get_plugins() calls
- **Zero dependencies** - No external libraries

## Requirements

- WordPress 6.0+
- PHP 7.4+

Designed for modern WordPress themes.

## License

GPL-2.0-or-later

## Links

- **Official Page:** [polanger.com/polanger-required-plugins-polanger-rp](https://polanger.com/polanger-required-plugins-polanger-rp/)
- **GitHub:** [github.com/Polanger/required-plugins](https://github.com/Polanger/required-plugins)

## Credits

Created by [Polanger](https://polanger.com).

Inspired by the long-standing [TGM Plugin Activation](http://tgmpluginactivation.com/) library, which has served the WordPress ecosystem well for many years.

---

If you find this project useful, consider giving it a ⭐ on GitHub.

## Changelog

### 4.1.0
- **Activate/Deactivate buttons** - Full plugin lifecycle management from the UI
- **Smart admin notice** - Update-only notifications are now dismissible with "Updates available" message
- **Improved notice logic** - `needs_update` status no longer triggers "required plugin" warning
- **deactivate_plugin()** - New method for plugin deactivation

### 4.0.0
- **External plugin support** - Install plugins from CDN/remote servers via HTTPS URLs
- **License source support** - Hook-based architecture for premium theme license integration (optional)
- **Source Resolver** - Central `resolve_download_url()` for clean separation of concerns
- **`polanger_license_download_url` filter** - Integrate any license system (Envato, EDD, WooCommerce)
- **Security layer** - HTTPS required, .zip validation, optional domain whitelist
- **Four source types** - WordPress.org, Bundled, External, License
- **Detailed error messages** - Developer-friendly error codes and descriptions
- **`allowed_domains` config** - Whitelist trusted domains for external sources

### 3.3.0
- **TextDomain matching** - get_plugin_file() now matches by TextDomain for edge cases
- **Strict page scope** - handle_actions() only runs on plugin page, preventing all conflicts
- **URL cleanup** - prp_failed/prp_error params cleaned via history.replaceState after display
- **Native update prevention** - Bundled plugins added to no_update list (WP update button won't appear)
- **Queue error tracking** - Failed plugins tracked and displayed after bulk operations
- **Config protection** - Multiple register() calls no longer override config
- **Safer bundled updates** - Added is_readable() check before delete+reinstall
- **Better error messages** - Uses get_name() for consistent plugin name display

### 3.1.0
- **Screen scope control** - Actions only run on plugin page, preventing conflicts
- **Error handling** - User-friendly error messages for failed install/update/activate
- **Bundled update fix** - Proper delete+reinstall for bundled plugin updates
- **Update transient fix** - Only bundled plugins injected, WP.org handled by core

### 3.0.0
- **Plugin update system** - Version-based update detection
- **Update button** - One-click plugin updates
- **Performance optimization** - Cached get_plugins() calls
- **Selective installation** - Install only selected plugins with checkboxes

### 2.0.0
- **Bulk installation** - "Install All" button with queue-based processing
- No JavaScript required for bulk install
- No timeout issues - each plugin is a separate request

### 1.0.0
- Initial release
- WordPress.org and bundled plugin support
- Admin notice with smart dismissal
- Native WordPress admin UI
- Auto-activate after installation
