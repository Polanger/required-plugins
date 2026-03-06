# Polanger Required Plugins

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**A lightweight TGMPA alternative for WordPress themes.**

Simple. Modern. Dependency-free.

## Benchmark

```
TGMPA:                    ~4000+ lines
Polanger Required Plugins:  ~500 lines

Result: ~87% less code
```

## Why?

TGMPA has been the standard solution for requiring plugins in WordPress themes for many years. However, the project has grown large and complex over time.

Polanger Required Plugins focuses on a simpler approach: a modern, minimal library that solves the same problem using WordPress core APIs.

| Feature | TGMPA | Polanger |
|---------|-------|----------|
| Code Size | 4000+ lines | **~500 lines** |
| Files | Multiple | **Single file** |
| Dependencies | Many | **Zero** |
| PHP Support | 5.2+ | **7.4+** |
| WordPress | 3.0+ | **6.0+** |
| Maintenance | Low activity | **Active** |

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

- **Single file library** - Just one PHP file
- **~500 lines of code** - Minimal, readable
- **Zero dependencies** - No external libraries
- **Uses WordPress core installer** - Native Plugin_Upgrader API
- **WordPress.org plugin support** - Install from repository
- **Bundled plugin support** - Install from theme ZIP files
- **Automatic activation** - Plugins activate after install
- **Native WordPress admin UI** - Uses WP admin table styling
- **Required and recommended** - Distinguish plugin importance
- **Smart admin notice** - Required can't be dismissed, recommended can

## Requirements

- WordPress 6.0+
- PHP 7.4+

Designed for modern WordPress themes.

## License

GPL-2.0-or-later

## Credits

Created by [Polanger](https://polanger.com).

Inspired by the long-standing TGMPA library, but redesigned with a modern, minimal architecture.

---

If you find this project useful, consider giving it a ⭐ on GitHub.

## Changelog

### 1.0.0
- Initial release
- WordPress.org and bundled plugin support
- Admin notice with smart dismissal
- Native WordPress admin UI
- Auto-activate after installation
