# Polanger Required Plugins

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
    'id'          => 'my-theme',
    'menu_title'  => 'Install Plugins',
    'menu_slug'   => 'my-theme-plugins',
    'parent_slug' => 'themes.php',
    'capability'  => 'install_plugins',
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

## Credits

Created by [Polanger](https://polanger.com).

Inspired by the long-standing [TGM Plugin Activation](http://tgmpluginactivation.com/) library, which has served the WordPress ecosystem well for many years.

---

If you find this project useful, consider giving it a ⭐ on GitHub.

## Changelog

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
