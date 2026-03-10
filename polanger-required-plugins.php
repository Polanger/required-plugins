<?php
/**
 * Polanger Required Plugins (Polanger RP)
 *
 * A minimal, modern library for WordPress theme developers to require plugins.
 * Single file, zero dependencies, built on native WordPress APIs.
 *
 * @package Polanger_Required_Plugins
 * @version 4.1.0
 * @author  Polanger
 * @license GPL-2.0-or-later
 * @link    https://polanger.com/polanger-required-plugins-polanger-rp/
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'polanger_require_plugins' ) ) {
/**
 * Main function to require plugins.
 *
 * Usage:
 *   polanger_require_plugins(['woocommerce', 'elementor']);
 *
 *   polanger_require_plugins([
 *       'woocommerce',
 *       ['slug' => 'theme-core', 'source' => '/path/to/theme-core.zip'],
 *       ['slug' => 'pro-addon', 'source' => 'https://cdn.example.com/pro-addon.zip'],
 *   ]);
 *
 * @param array $plugins Array of plugin slugs or plugin config arrays.
 * @param array $config  Optional configuration.
 * @return void
 */
function polanger_require_plugins( array $plugins, array $config = array() ) {
    if ( ! is_admin() ) {
        return;
    }

    static $instance = null;

    if ( null === $instance ) {
        $instance = new Polanger_Required_Plugins();
    }

    $instance->register( $plugins, $config );
}
} // end function_exists polanger_require_plugins

if ( ! function_exists( 'polanger_require' ) ) {
/**
 * Shorthand function.
 *
 * @param string|array ...$slugs Plugin slugs.
 * @return void
 */
function polanger_require( ...$slugs ) {
    if ( count( $slugs ) === 1 && is_array( $slugs[0] ) ) {
        $slugs = $slugs[0];
    }
    polanger_require_plugins( $slugs );
}
}

if ( ! class_exists( 'Polanger_Required_Plugins' ) ) :
/**
 * Class Polanger_Required_Plugins
 *
 * Minimal plugin dependency manager.
 */
class Polanger_Required_Plugins {

    /**
     * Registered plugins.
     *
     * @var array
     */
    private $plugins = array();

    /**
     * Configuration.
     *
     * @var array
     */
    private $config = array();

    /**
     * Cached installed plugins.
     *
     * @var array|null
     */
    private $installed_plugins = null;

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        // Use admin_init for actions - runs before any output is sent.
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'wp_ajax_polanger_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_plugin_updates' ) );
    }

    /**
     * AJAX handler for dismissing notice.
     *
     * @return void
     */
    public function ajax_dismiss_notice() {
        check_ajax_referer( 'polanger_dismiss_notice', '_wpnonce' );

        $id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';

        if ( empty( $id ) ) {
            wp_send_json_error();
        }

        update_user_meta( get_current_user_id(), 'polanger_dismissed_' . $id, true );

        wp_send_json_success();
    }

    /**
     * Register plugins.
     *
     * @param array $plugins Plugins to register.
     * @param array $config  Configuration.
     * @return void
     */
    public function register( array $plugins, array $config = array() ) {
        // Only set config once to prevent override on multiple calls.
        if ( empty( $this->config ) ) {
            $this->config = wp_parse_args( $config, array(
                'id'              => 'polanger',
                'menu_title'      => __( 'Install Plugins', 'polanger-required-plugins' ),
                'menu_slug'       => 'polanger-install-plugins',
                'parent_slug'     => 'themes.php',
                'capability'      => 'install_plugins',
                'allowed_domains' => array(), // Whitelist for external sources (e.g. ['cdn.example.com'])
            ) );
        }

        foreach ( $plugins as $plugin ) {
            $normalized = $this->normalize( $plugin );
            // Use slug as key to prevent duplicates.
            $this->plugins[ $normalized['slug'] ] = $normalized;
        }
    }

    /**
     * Normalize plugin definition.
     *
     * @param string|array $plugin Plugin slug or config.
     * @return array Normalized plugin config.
     */
    private function normalize( $plugin ) {
        if ( is_string( $plugin ) ) {
            $plugin = array( 'slug' => sanitize_key( $plugin ) );
        }

        $normalized = wp_parse_args( $plugin, array(
            'slug'     => '',
            'name'     => '',
            'source'   => 'wordpress', // wordpress, bundled (file path)
            'required' => true,
            'version'  => '',          // Required version for updates
        ) );

        // Sanitize slug.
        $normalized['slug'] = sanitize_key( $normalized['slug'] );

        return $normalized;
    }

    /**
     * Get plugin status.
     *
     * @param array $plugin Plugin config.
     * @return string Status: active, inactive, not_installed, needs_update.
     */
    private function get_status( array $plugin ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $file = $this->get_plugin_file( $plugin['slug'] );

        if ( ! $file ) {
            return 'not_installed';
        }

        // Check if update is needed.
        if ( $this->needs_update( $plugin ) ) {
            return 'needs_update';
        }

        return is_plugin_active( $file ) ? 'active' : 'inactive';
    }

    /**
     * Check if plugin needs update.
     *
     * @param array $plugin Plugin config.
     * @return bool True if update needed.
     */
    private function needs_update( array $plugin ) {
        if ( empty( $plugin['version'] ) ) {
            return false;
        }

        $installed_version = $this->get_installed_version( $plugin['slug'] );

        if ( ! $installed_version ) {
            return false;
        }

        return version_compare( $installed_version, $plugin['version'], '<' );
    }

    /**
     * Get installed plugin version.
     *
     * @param string $slug Plugin slug.
     * @return string|false Version or false.
     */
    private function get_installed_version( $slug ) {
        $plugins = $this->get_installed_plugins();

        foreach ( $plugins as $file => $data ) {
            $plugin_dir = dirname( $file );
            if ( $plugin_dir === $slug || $file === $slug . '.php' ) {
                return $data['Version'];
            }
        }

        return false;
    }

    /**
     * Get cached installed plugins.
     *
     * @return array Installed plugins.
     */
    private function get_installed_plugins() {
        if ( null === $this->installed_plugins ) {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $this->installed_plugins = get_plugins();
        }
        return $this->installed_plugins;
    }

    /**
     * Clear installed plugins cache.
     *
     * @return void
     */
    private function clear_plugins_cache() {
        $this->installed_plugins = null;
        wp_clean_plugins_cache( true );
    }

    /**
     * Get plugin file path.
     *
     * @param string $slug Plugin slug.
     * @return string|false Plugin file or false.
     */
    private function get_plugin_file( $slug ) {
        $plugins = $this->get_installed_plugins();

        foreach ( $plugins as $file => $data ) {
            $plugin_dir = dirname( $file );

            // Match by directory name or single-file plugin.
            if ( $plugin_dir === $slug || $file === $slug . '.php' ) {
                return $file;
            }

            // Match by TextDomain for plugins with different folder/file names.
            if ( isset( $data['TextDomain'] ) && $data['TextDomain'] === $slug ) {
                return $file;
            }
        }

        return false;
    }

    /**
     * Get plugin name.
     *
     * @param array $plugin Plugin config.
     * @return string Plugin name.
     */
    private function get_name( array $plugin ) {
        if ( ! empty( $plugin['name'] ) ) {
            return $plugin['name'];
        }
        return ucwords( str_replace( array( '-', '_' ), ' ', $plugin['slug'] ) );
    }

    /**
     * Check if source is an external URL.
     *
     * @param string $source Plugin source.
     * @return bool True if external URL.
     */
    private function is_external_source( $source ) {
        return filter_var( $source, FILTER_VALIDATE_URL ) !== false;
    }

    /**
     * Validate external source URL for security.
     *
     * @param string $url External URL.
     * @return true|WP_Error True if valid, WP_Error if not.
     */
    private function validate_external_source( $url ) {
        // 1. HTTPS required.
        if ( strpos( $url, 'https://' ) !== 0 ) {
            return new \WP_Error( 'invalid_protocol', __( 'External sources must use HTTPS.', 'polanger-required-plugins' ) );
        }

        // 2. Must be .zip file.
        $path = parse_url( $url, PHP_URL_PATH );
        if ( ! $path || ! preg_match( '/\.zip$/i', $path ) ) {
            return new \WP_Error( 'invalid_extension', __( 'External source must be a .zip file.', 'polanger-required-plugins' ) );
        }

        // 3. Domain whitelist (if configured).
        if ( ! empty( $this->config['allowed_domains'] ) ) {
            $host = parse_url( $url, PHP_URL_HOST );
            if ( ! in_array( $host, $this->config['allowed_domains'], true ) ) {
                return new \WP_Error( 'invalid_domain', __( 'External source domain is not allowed.', 'polanger-required-plugins' ) );
            }
        }

        return true;
    }

    /**
     * Get source type label for display.
     *
     * @param array $plugin Plugin config.
     * @return string Source label.
     */
    private function get_source_label( array $plugin ) {
        if ( $plugin['source'] === 'wordpress' ) {
            return 'WordPress.org';
        }

        if ( $plugin['source'] === 'license' ) {
            return __( 'License', 'polanger-required-plugins' );
        }

        if ( $this->is_external_source( $plugin['source'] ) ) {
            return __( 'External', 'polanger-required-plugins' );
        }

        return __( 'Bundled', 'polanger-required-plugins' );
    }

    /**
     * Resolve download URL for a plugin.
     * This is the central source resolver - installer doesn't need to know where URL comes from.
     *
     * Supported sources:
     * - 'wordpress' : WordPress.org plugin repository
     * - 'license'   : License-protected download (requires polanger_license_download_url filter)
     * - URL string  : External HTTPS URL (validated for security)
     * - File path   : Local bundled ZIP file
     *
     * @param array $plugin Plugin config.
     * @return string|WP_Error Download URL or WP_Error on failure.
     */
    private function resolve_download_url( array $plugin ) {
        $source = $plugin['source'];

        // WordPress.org - get download link from API.
        if ( $source === 'wordpress' ) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            $api = plugins_api( 'plugin_information', array( 'slug' => $plugin['slug'] ) );
            if ( is_wp_error( $api ) ) {
                return new \WP_Error( 'wporg_api_failed', __( 'WordPress.org API request failed.', 'polanger-required-plugins' ) );
            }
            return $api->download_link;
        }

        // License-protected source - developer provides URL via filter.
        if ( $source === 'license' ) {
            /**
             * Filter to get download URL for license-protected plugins.
             * Developers can hook into this to integrate their own license system.
             *
             * @param string|null $url    Download URL (null by default).
             * @param array       $plugin Plugin configuration array.
             * @return string|WP_Error Download URL or WP_Error.
             */
            $url = apply_filters( 'polanger_license_download_url', null, $plugin );

            if ( is_wp_error( $url ) ) {
                return $url;
            }

            if ( empty( $url ) ) {
                return new \WP_Error( 'license_not_configured', __( 'License download handler not configured. Add a polanger_license_download_url filter.', 'polanger-required-plugins' ) );
            }

            return $url;
        }

        // External URL - validate security first.
        if ( $this->is_external_source( $source ) ) {
            $validation = $this->validate_external_source( $source );
            if ( is_wp_error( $validation ) ) {
                return $validation;
            }
            return $source;
        }

        // Bundled - local file path.
        if ( ! file_exists( $source ) ) {
            return new \WP_Error( 'source_not_found', __( 'Plugin source file not found.', 'polanger-required-plugins' ) );
        }

        return $source;
    }

    /**
     * Get incomplete plugins.
     *
     * @return array Plugins that need action.
     */
    private function get_incomplete() {
        return array_filter( $this->plugins, function( $p ) {
            return $this->get_status( $p ) !== 'active';
        } );
    }

    /**
     * Display admin notice.
     *
     * @return void
     */
    public function admin_notice() {
        if ( ! current_user_can( $this->config['capability'] ) ) {
            return;
        }

        // Categorize plugins by status.
        $not_installed = array();
        $needs_update = array();
        $inactive = array();

        foreach ( $this->plugins as $plugin ) {
            $status = $this->get_status( $plugin );
            if ( $status === 'not_installed' ) {
                $not_installed[] = $plugin;
            } elseif ( $status === 'needs_update' ) {
                $needs_update[] = $plugin;
            } elseif ( $status === 'inactive' ) {
                $inactive[] = $plugin;
            }
        }

        // Separate by required/recommended for not_installed and inactive only.
        // needs_update plugins are already installed, so they're less critical.
        $required_missing = array_filter( array_merge( $not_installed, $inactive ), function( $p ) { return $p['required']; } );
        $recommended_missing = array_filter( array_merge( $not_installed, $inactive ), function( $p ) { return ! $p['required']; } );

        $has_required_missing = ! empty( $required_missing );
        $has_recommended_missing = ! empty( $recommended_missing );
        $has_updates = ! empty( $needs_update );

        // Nothing to show.
        if ( ! $has_required_missing && ! $has_recommended_missing && ! $has_updates ) {
            return;
        }

        // Check if dismissed (only applies when no required missing and no updates).
        if ( ! $has_required_missing && ! $has_updates ) {
            $dismissed = get_user_meta( get_current_user_id(), 'polanger_dismissed_' . $this->config['id'], true );
            if ( $dismissed ) {
                return;
            }
        }

        $url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );

        // Build message parts.
        $message_parts = array();

        if ( $has_required_missing ) {
            $required_names = array_map( array( $this, 'get_name' ), $required_missing );
            $message_parts[] = sprintf(
                '<strong>%s</strong> %s',
                esc_html__( 'Required:', 'polanger-required-plugins' ),
                esc_html( implode( ', ', $required_names ) )
            );
        }

        if ( $has_recommended_missing ) {
            $recommended_names = array_map( array( $this, 'get_name' ), $recommended_missing );
            $message_parts[] = sprintf(
                '<strong>%s</strong> %s',
                esc_html__( 'Recommended:', 'polanger-required-plugins' ),
                esc_html( implode( ', ', $recommended_names ) )
            );
        }

        if ( $has_updates ) {
            $update_names = array_map( array( $this, 'get_name' ), $needs_update );
            $message_parts[] = sprintf(
                '<strong>%s</strong> %s',
                esc_html__( 'Updates available:', 'polanger-required-plugins' ),
                esc_html( implode( ', ', $update_names ) )
            );
        }

        // Determine notice type, intro text, and dismissibility.
        if ( $has_required_missing ) {
            // Required plugins missing - warning, not dismissible.
            $class       = 'notice-warning';
            $dismissible = '';
            $intro       = __( 'This theme needs the following plugins:', 'polanger-required-plugins' );
            $button_text = __( 'Install Plugins', 'polanger-required-plugins' );
        } elseif ( $has_updates && ! $has_recommended_missing ) {
            // Only updates available - info, dismissible.
            $class       = 'notice-info is-dismissible';
            $dismissible = sprintf( ' data-polanger-dismiss="%s"', esc_attr( $this->config['id'] ) );
            $intro       = __( 'Plugin updates are available:', 'polanger-required-plugins' );
            $button_text = __( 'View Updates', 'polanger-required-plugins' );
        } else {
            // Recommended plugins or mix - info, dismissible.
            $class       = 'notice-info is-dismissible';
            $dismissible = sprintf( ' data-polanger-dismiss="%s"', esc_attr( $this->config['id'] ) );
            $intro       = __( 'This theme recommends the following plugins:', 'polanger-required-plugins' );
            $button_text = __( 'Install Plugins', 'polanger-required-plugins' );
        }

        printf(
            '<div class="notice %s"%s><p><strong>%s</strong></p><p>%s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
            esc_attr( $class ),
            $dismissible,
            esc_html( $intro ),
            implode( ' &nbsp;|&nbsp; ', $message_parts ),
            esc_url( $url ),
            esc_html( $button_text )
        );

        // Add dismiss script only if dismissible.
        if ( ! $has_required_missing ) {
            $this->dismiss_notice_script();
        }
    }

    /**
     * Output dismiss notice script.
     * Uses wp_add_inline_script for CSP compatibility.
     *
     * @return void
     */
    private function dismiss_notice_script() {
        static $script_added = false;
        if ( $script_added ) {
            return;
        }
        $script_added = true;

        // Enqueue inline script attached to jQuery for CSP compatibility.
        $nonce = wp_create_nonce( 'polanger_dismiss_notice' );
        $script = "
            jQuery(function($) {
                $(document).on('click', '.notice[data-polanger-dismiss] .notice-dismiss', function() {
                    var id = $(this).closest('.notice').data('polanger-dismiss');
                    $.post(ajaxurl, {
                        action: 'polanger_dismiss_notice',
                        id: id,
                        _wpnonce: '" . esc_js( $nonce ) . "'
                    });
                });
            });
        ";
        wp_add_inline_script( 'jquery', $script );
    }

    /**
     * Add admin menu.
     *
     * @return void
     */
    public function admin_menu() {
        add_submenu_page(
            $this->config['parent_slug'],
            $this->config['menu_title'],
            $this->config['menu_title'],
            $this->config['capability'],
            $this->config['menu_slug'],
            array( $this, 'render_page' )
        );
    }

    /**
     * Render admin page using WP_List_Table style.
     *
     * @return void
     */
    public function render_page() {
        $actionable = $this->get_actionable_slugs();
        $is_processing = ! empty( $_GET['queue'] );
        $form_action = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->config['menu_title'] ); ?></h1>
            <p><?php esc_html_e( 'The following plugins are required or recommended.', 'polanger-required-plugins' ); ?></p>

            <?php if ( $is_processing ) : ?>
            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e( 'Installing plugins...', 'polanger-required-plugins' ); ?></strong></p>
            </div>
            <?php endif; ?>

            <?php
            // Display error message if action failed.
            if ( ! empty( $_GET['prp_error'] ) ) :
                $error_code = sanitize_key( $_GET['prp_error'] );
                $error_plugin = isset( $_GET['prp_plugin'] ) ? sanitize_key( $_GET['prp_plugin'] ) : '';
                $plugin_name = ( $error_plugin && isset( $this->plugins[ $error_plugin ] ) ) 
                    ? $this->get_name( $this->plugins[ $error_plugin ] ) 
                    : $error_plugin;

                // Detailed error messages for developers.
                $error_messages = array(
                    // General errors
                    'install_failed'        => __( 'Plugin installation failed. The download or extraction may have failed.', 'polanger-required-plugins' ),
                    'activate_failed'       => __( 'Plugin activation failed. The plugin may have errors or conflicts.', 'polanger-required-plugins' ),
                    'deactivate_failed'     => __( 'Plugin deactivation failed.', 'polanger-required-plugins' ),
                    'update_failed'         => __( 'Plugin update failed. The download or extraction may have failed.', 'polanger-required-plugins' ),
                    'plugin_not_found'      => __( 'Plugin not found. It may have been deleted or moved.', 'polanger-required-plugins' ),
                    'source_not_found'      => __( 'Plugin source file not found. Check the file path in your theme configuration.', 'polanger-required-plugins' ),
                    'wporg_api_failed'      => __( 'WordPress.org API request failed. The plugin slug may be incorrect or the API is unavailable.', 'polanger-required-plugins' ),
                    // External source security errors
                    'invalid_protocol'      => __( 'External source must use HTTPS. HTTP is not allowed for security reasons.', 'polanger-required-plugins' ),
                    'invalid_extension'     => __( 'External source must be a .zip file.', 'polanger-required-plugins' ),
                    'invalid_domain'        => __( 'External source domain is not in the allowed_domains whitelist. Add the domain to your theme configuration.', 'polanger-required-plugins' ),
                    // License source errors
                    'license_not_configured' => __( 'License download handler not configured. Add a polanger_license_download_url filter in your theme.', 'polanger-required-plugins' ),
                );
                $error_text = $error_messages[ $error_code ] ?? __( 'An unknown error occurred.', 'polanger-required-plugins' );
            ?>
            <div class="notice notice-error inline">
                <p><strong><?php echo esc_html( $plugin_name ); ?>:</strong> <?php echo esc_html( $error_text ); ?></p>
                <p><small><?php printf( esc_html__( 'Error code: %s', 'polanger-required-plugins' ), '<code>' . esc_html( $error_code ) . '</code>' ); ?></small></p>
            </div>
            <?php endif; ?>

            <?php
            // Display bulk operation failed plugins.
            if ( ! empty( $_GET['prp_failed'] ) ) :
                $failed_slugs = array_filter( array_map( 'sanitize_key', explode( ',', $_GET['prp_failed'] ) ) );
                $failed_names = array();
                foreach ( $failed_slugs as $slug ) {
                    if ( isset( $this->plugins[ $slug ] ) ) {
                        $failed_names[] = $this->get_name( $this->plugins[ $slug ] );
                    } else {
                        $failed_names[] = $slug;
                    }
                }
                // Clean URL to prevent message on refresh.
                $clean_url = remove_query_arg( array( 'prp_failed', 'prp_error', 'prp_plugin' ) );
            ?>
            <div class="notice notice-warning inline is-dismissible">
                <p><strong><?php esc_html_e( 'Some plugins failed to install/update:', 'polanger-required-plugins' ); ?></strong> <?php echo esc_html( implode( ', ', $failed_names ) ); ?></p>
            </div>
            <script>if (history.replaceState) { history.replaceState(null, '', '<?php echo esc_js( $clean_url ); ?>'); }</script>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( $form_action ); ?>">
                <?php wp_nonce_field( 'polanger_bulk_install', '_wpnonce' ); ?>
                <input type="hidden" name="polanger_bulk_action" value="install_selected">

                <?php if ( ! empty( $actionable ) && ! $is_processing ) : ?>
                <p>
                    <?php
                    $bulk_url = add_query_arg( array(
                        'queue'    => implode( ',', $actionable ),
                        '_wpnonce' => wp_create_nonce( 'polanger_bulk_install' ),
                    ), $form_action );
                    ?>
                    <a href="<?php echo esc_url( $bulk_url ); ?>" class="button button-primary">
                        <?php
                        printf(
                            esc_html__( 'Install All (%d)', 'polanger-required-plugins' ),
                            count( $actionable )
                        );
                        ?>
                    </a>
                    <button type="submit" class="button" id="polanger-install-selected" disabled>
                        <?php esc_html_e( 'Install Selected', 'polanger-required-plugins' ); ?>
                    </button>
                </p>
                <?php endif; ?>

                <table class="wp-list-table widefat plugins">
                    <thead>
                        <tr>
                            <?php if ( ! empty( $actionable ) && ! $is_processing ) : ?>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="polanger-select-all">
                            </td>
                            <?php endif; ?>
                            <th scope="col"><?php esc_html_e( 'Plugin', 'polanger-required-plugins' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Source', 'polanger-required-plugins' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Type', 'polanger-required-plugins' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'polanger-required-plugins' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Action', 'polanger-required-plugins' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Sort plugins: Required first, then Recommended.
                        $sorted_plugins = $this->plugins;
                        uasort( $sorted_plugins, function( $a, $b ) {
                            return $b['required'] <=> $a['required'];
                        } );
                        
                        foreach ( $sorted_plugins as $plugin ) : 
                            $status = $this->get_status( $plugin );
                            $name   = $this->get_name( $plugin );
                            $is_actionable = $status !== 'active';
                        ?>
                        <tr class="<?php echo $status === 'active' ? 'active' : 'inactive'; ?>">
                            <?php if ( ! empty( $actionable ) && ! $is_processing ) : ?>
                            <th scope="row" class="check-column">
                                <?php if ( $is_actionable ) : ?>
                                <input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $plugin['slug'] ); ?>" class="polanger-plugin-checkbox">
                                <?php endif; ?>
                            </th>
                            <?php endif; ?>
                            <td><strong><?php echo esc_html( $name ); ?></strong></td>
                            <td><?php echo esc_html( $this->get_source_label( $plugin ) ); ?></td>
                            <td><?php echo $plugin['required'] ? esc_html__( 'Required', 'polanger-required-plugins' ) : esc_html__( 'Recommended', 'polanger-required-plugins' ); ?></td>
                            <td>
                                <?php if ( $status === 'active' ) : ?>
                                    <span style="color: #00a32a;">● <?php esc_html_e( 'Active', 'polanger-required-plugins' ); ?></span>
                                <?php elseif ( $status === 'needs_update' ) : 
                                    $installed_ver = $this->get_installed_version( $plugin['slug'] );
                                    $required_ver = $plugin['version'];
                                ?>
                                    <span style="color: #d54e21;">● <?php 
                                        printf(
                                            esc_html__( 'Update Available (%s → %s)', 'polanger-required-plugins' ),
                                            esc_html( $installed_ver ),
                                            esc_html( $required_ver )
                                        );
                                    ?></span>
                                <?php elseif ( $status === 'inactive' ) : ?>
                                    <span style="color: #dba617;">● <?php esc_html_e( 'Installed', 'polanger-required-plugins' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #d63638;">● <?php esc_html_e( 'Not Installed', 'polanger-required-plugins' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $this->get_action_link( $plugin, $status ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php if ( ! empty( $actionable ) && ! $is_processing ) : ?>
            <script>
            (function() {
                var selectAll = document.getElementById('polanger-select-all');
                var checkboxes = document.querySelectorAll('.polanger-plugin-checkbox');
                var installBtn = document.getElementById('polanger-install-selected');

                function updateButton() {
                    var checked = document.querySelectorAll('.polanger-plugin-checkbox:checked').length;
                    installBtn.disabled = checked === 0;
                    installBtn.textContent = checked > 0 
                        ? '<?php echo esc_js( __( 'Install Selected', 'polanger-required-plugins' ) ); ?> (' + checked + ')'
                        : '<?php echo esc_js( __( 'Install Selected', 'polanger-required-plugins' ) ); ?>';
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                        updateButton();
                    });
                }

                checkboxes.forEach(function(cb) {
                    cb.addEventListener('change', updateButton);
                });
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get action link for plugin.
     *
     * @param array  $plugin Plugin config.
     * @param string $status Plugin status.
     * @return string HTML link.
     */
    private function get_action_link( array $plugin, string $status ) {
        $slug = $plugin['slug'];
        $nonce = wp_create_nonce( 'polanger_action_' . $slug );
        $base_url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );
        $links = array();

        switch ( $status ) {
            case 'active':
                // Deactivate link for active plugins.
                $deactivate_url = add_query_arg( array(
                    'action'   => 'deactivate',
                    'plugin'   => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                $links[] = sprintf(
                    '<a href="%s" class="button">%s</a>',
                    esc_url( $deactivate_url ),
                    esc_html__( 'Deactivate', 'polanger-required-plugins' )
                );
                break;

            case 'needs_update':
                // Update button (primary).
                $update_url = add_query_arg( array(
                    'action'   => 'update',
                    'plugin'   => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                $links[] = sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url( $update_url ),
                    esc_html__( 'Update', 'polanger-required-plugins' )
                );
                // Also show activate/deactivate based on current activation state.
                $file = $this->get_plugin_file( $slug );
                if ( $file && is_plugin_active( $file ) ) {
                    $deactivate_url = add_query_arg( array(
                        'action'   => 'deactivate',
                        'plugin'   => $slug,
                        '_wpnonce' => $nonce,
                    ), $base_url );
                    $links[] = sprintf(
                        '<a href="%s" class="button">%s</a>',
                        esc_url( $deactivate_url ),
                        esc_html__( 'Deactivate', 'polanger-required-plugins' )
                    );
                } else {
                    $activate_url = add_query_arg( array(
                        'action'   => 'activate',
                        'plugin'   => $slug,
                        '_wpnonce' => $nonce,
                    ), $base_url );
                    $links[] = sprintf(
                        '<a href="%s" class="button">%s</a>',
                        esc_url( $activate_url ),
                        esc_html__( 'Activate', 'polanger-required-plugins' )
                    );
                }
                break;

            case 'inactive':
                // Activate link for inactive plugins.
                $activate_url = add_query_arg( array(
                    'action'   => 'activate',
                    'plugin'   => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                $links[] = sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url( $activate_url ),
                    esc_html__( 'Activate', 'polanger-required-plugins' )
                );
                break;

            case 'not_installed':
            default:
                // Install link for not installed plugins.
                $install_url = add_query_arg( array(
                    'action'   => 'install',
                    'plugin'   => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                $links[] = sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url( $install_url ),
                    esc_html__( 'Install', 'polanger-required-plugins' )
                );
                break;
        }

        return implode( ' ', $links );
    }

    /**
     * Handle install/activate actions including queue processing.
     *
     * @return void
     */
    public function handle_actions() {
        // Only process on our plugin page to prevent conflicts with other admin pages.
        // Nonce verification inside each handler provides additional security.
        $is_our_page = isset( $_GET['page'] ) && $_GET['page'] === $this->config['menu_slug'];

        if ( ! $is_our_page ) {
            return;
        }

        // Handle queue-based bulk installation (GET).
        if ( ! empty( $_GET['queue'] ) ) {
            $this->handle_queue();
            return;
        }

        // Handle selected plugins form submission (POST).
        if ( ! empty( $_POST['polanger_bulk_action'] ) && $_POST['polanger_bulk_action'] === 'install_selected' ) {
            $this->handle_selected_install();
            return;
        }

        if ( empty( $_GET['action'] ) || empty( $_GET['plugin'] ) ) {
            return;
        }

        $action = sanitize_key( $_GET['action'] );
        $slug   = sanitize_key( $_GET['plugin'] );

        // Whitelist allowed actions.
        if ( ! in_array( $action, array( 'install', 'activate', 'deactivate', 'update' ), true ) ) {
            return;
        }

        // Verify nonce.
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'polanger_action_' . $slug ) ) {
            wp_die( __( 'Security check failed.', 'polanger-required-plugins' ) );
        }

        // Check capability.
        if ( ! current_user_can( $this->config['capability'] ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'polanger-required-plugins' ) );
        }

        // Find plugin config (slug is key).
        if ( ! isset( $this->plugins[ $slug ] ) ) {
            return;
        }

        $plugin = $this->plugins[ $slug ];

        $error_code = '';

        if ( $action === 'install' ) {
            $result = $this->install_plugin( $plugin );
            if ( $result !== true ) {
                $error_code = is_string( $result ) ? $result : 'install_failed';
            }
        } elseif ( $action === 'activate' ) {
            $result = $this->activate_plugin( $plugin );
            if ( ! $result ) {
                $error_code = 'activate_failed';
            }
        } elseif ( $action === 'deactivate' ) {
            $result = $this->deactivate_plugin( $plugin );
            if ( ! $result ) {
                $error_code = 'deactivate_failed';
            }
        } elseif ( $action === 'update' ) {
            $result = $this->update_plugin( $plugin );
            if ( $result !== true ) {
                $error_code = is_string( $result ) ? $result : 'update_failed';
            }
        }

        // Redirect back with error message if failed.
        $redirect_url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );
        if ( ! empty( $error_code ) ) {
            $redirect_url = add_query_arg( 'prp_error', $error_code, $redirect_url );
            $redirect_url = add_query_arg( 'prp_plugin', $slug, $redirect_url );
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle selected plugins form submission.
     * Converts selected plugins to queue and redirects.
     *
     * @return void
     */
    private function handle_selected_install() {
        // Verify nonce.
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'polanger_bulk_install' ) ) {
            wp_die( __( 'Security check failed.', 'polanger-required-plugins' ) );
        }

        // Check capability.
        if ( ! current_user_can( $this->config['capability'] ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'polanger-required-plugins' ) );
        }

        // Get selected plugins.
        $selected = isset( $_POST['plugins'] ) && is_array( $_POST['plugins'] ) 
            ? array_map( 'sanitize_key', $_POST['plugins'] ) 
            : array();

        if ( empty( $selected ) ) {
            wp_safe_redirect( admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] ) );
            exit;
        }

        // Redirect to queue processing.
        $redirect_url = add_query_arg( array(
            'queue'    => implode( ',', $selected ),
            '_wpnonce' => wp_create_nonce( 'polanger_bulk_install' ),
        ), admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] ) );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle queue-based bulk installation.
     * Processes one plugin at a time, then redirects to continue the queue.
     *
     * @return void
     */
    private function handle_queue() {
        // Verify nonce.
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'polanger_bulk_install' ) ) {
            wp_die( __( 'Security check failed.', 'polanger-required-plugins' ) );
        }

        // Check capability.
        if ( ! current_user_can( $this->config['capability'] ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'polanger-required-plugins' ) );
        }

        // Parse queue and failed plugins list.
        $queue = array_filter( array_map( 'sanitize_key', explode( ',', $_GET['queue'] ) ) );
        $failed = isset( $_GET['prp_failed'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', $_GET['prp_failed'] ) ) ) : array();

        if ( empty( $queue ) ) {
            // Queue complete - redirect with failed plugins if any.
            $redirect_url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );
            if ( ! empty( $failed ) ) {
                $redirect_url = add_query_arg( 'prp_failed', implode( ',', $failed ), $redirect_url );
            }
            wp_safe_redirect( $redirect_url );
            exit;
        }

        // Get current plugin to process.
        $current_slug = array_shift( $queue );
        $result = true;

        // Find plugin config.
        if ( isset( $this->plugins[ $current_slug ] ) ) {
            $plugin = $this->plugins[ $current_slug ];
            $status = $this->get_status( $plugin );

            // Install if not installed.
            if ( $status === 'not_installed' ) {
                $result = $this->install_plugin( $plugin );
            } elseif ( $status === 'needs_update' ) {
                // Update if needs update.
                $result = $this->update_plugin( $plugin );
            } elseif ( $status === 'inactive' ) {
                // Activate if installed but inactive.
                $result = $this->activate_plugin( $plugin );
            }

            // Track failed plugins.
            if ( ! $result ) {
                $failed[] = $current_slug;
            }
        }

        // Build redirect URL.
        $redirect_url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );

        // Continue with remaining queue.
        $redirect_args = array(
            '_wpnonce' => wp_create_nonce( 'polanger_bulk_install' ),
        );

        if ( ! empty( $queue ) ) {
            $redirect_args['queue'] = implode( ',', $queue );
        }

        if ( ! empty( $failed ) ) {
            $redirect_args['prp_failed'] = implode( ',', $failed );
        }

        $redirect_url = add_query_arg( $redirect_args, $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Get slugs of plugins that need installation or activation.
     *
     * @return array Array of plugin slugs.
     */
    private function get_actionable_slugs() {
        $slugs = array();
        foreach ( $this->plugins as $slug => $plugin ) {
            $status = $this->get_status( $plugin );
            if ( $status !== 'active' ) {
                $slugs[] = $slug;
            }
        }
        return $slugs;
    }

    /**
     * Install a plugin.
     *
     * @param array $plugin Plugin config.
     * @return true|string True on success, error code string on failure.
     */
    private function install_plugin( array $plugin ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Resolve download URL using central resolver.
        $source = $this->resolve_download_url( $plugin );
        if ( is_wp_error( $source ) ) {
            return $source->get_error_code();
        }

        // Install with quiet skin.
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result = $upgrader->install( $source );

        if ( ! $result ) {
            return 'install_failed';
        }

        $this->clear_plugins_cache();
        // Auto-activate after install.
        $this->activate_plugin( $plugin );

        return true;
    }

    /**
     * Activate a plugin.
     *
     * @param array $plugin Plugin config.
     * @return bool Success.
     */
    private function activate_plugin( array $plugin ) {
        $file = $this->get_plugin_file( $plugin['slug'] );

        if ( ! $file ) {
            return false;
        }

        $result = activate_plugin( $file );

        return ! is_wp_error( $result );
    }

    /**
     * Deactivate a plugin.
     *
     * @param array $plugin Plugin config.
     * @return bool Success.
     */
    private function deactivate_plugin( array $plugin ) {
        $file = $this->get_plugin_file( $plugin['slug'] );

        if ( ! $file ) {
            return false;
        }

        deactivate_plugins( $file );

        return true;
    }

    /**
     * Update a plugin.
     *
     * @param array $plugin Plugin config.
     * @return true|string True on success, error code string on failure.
     */
    private function update_plugin( array $plugin ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );

        // WordPress.org plugins use native upgrade method.
        if ( $plugin['source'] === 'wordpress' ) {
            $file = $this->get_plugin_file( $plugin['slug'] );
            if ( ! $file ) {
                return 'plugin_not_found';
            }
            $result = $upgrader->upgrade( $file );
        } else {
            // For bundled, external, and license sources: delete + reinstall.
            // Resolve download URL using central resolver.
            $source = $this->resolve_download_url( $plugin );
            if ( is_wp_error( $source ) ) {
                return $source->get_error_code();
            }

            // Delete existing plugin first to allow reinstall.
            $file = $this->get_plugin_file( $plugin['slug'] );
            if ( $file ) {
                deactivate_plugins( $file, true );
                delete_plugins( array( $file ) );
                $this->clear_plugins_cache();
            }

            // Install fresh from resolved source.
            $result = $upgrader->install( $source );

            // Re-activate after install.
            if ( $result ) {
                $this->clear_plugins_cache();
                $new_file = $this->get_plugin_file( $plugin['slug'] );
                if ( $new_file ) {
                    activate_plugin( $new_file );
                }
            }
        }

        if ( ! $result ) {
            return 'update_failed';
        }

        $this->clear_plugins_cache();
        return true;
    }

    /**
     * Inject plugin update information into WordPress update transient.
     * This enables native WordPress update notifications for bundled plugins.
     * Note: Only bundled plugins are injected. WordPress.org plugins are handled by WP core.
     *
     * @param object $transient Update transient object.
     * @return object Modified transient.
     */
    public function inject_plugin_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        foreach ( $this->plugins as $plugin ) {
            // Skip plugins without version requirement.
            if ( empty( $plugin['version'] ) ) {
                continue;
            }

            // Skip WordPress.org plugins - they are handled by WP core update system.
            // Only process bundled and external plugins.
            if ( $plugin['source'] === 'wordpress' ) {
                continue;
            }

            $file = $this->get_plugin_file( $plugin['slug'] );

            if ( ! $file ) {
                continue;
            }

            // Get installed version from transient.
            $installed_version = $transient->checked[ $file ] ?? null;

            if ( ! $installed_version ) {
                continue;
            }

            // Check if update is needed.
            if ( version_compare( $installed_version, $plugin['version'], '<' ) ) {
                // Bundled/External plugin needs update - but don't add to WP's response.
                // WP's native update button won't work with local paths or external URLs.
                // Instead, mark as "no_update" so WP doesn't show its update UI.
                // Our own UI handles these plugin updates via update_plugin() method.
                if ( ! isset( $transient->no_update ) ) {
                    $transient->no_update = array();
                }

                $no_update = new \stdClass();
                $no_update->slug        = $plugin['slug'];
                $no_update->plugin      = $file;
                $no_update->new_version = $installed_version;
                $no_update->package     = '';
                $no_update->url         = '';

                $transient->no_update[ $file ] = $no_update;
            }
        }

        return $transient;
    }
}
endif; // class_exists
