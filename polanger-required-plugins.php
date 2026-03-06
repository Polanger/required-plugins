<?php
/**
 * Polanger Required Plugins
 *
 * A minimal, modern library for WordPress theme developers to require plugins.
 * Clean replacement for TGMPA in ~350 lines.
 *
 * @package Polanger_Required_Plugins
 * @version 1.0.0
 * @author  Polanger
 * @license GPL-2.0-or-later
 * @link    https://github.com/polanger/required-plugins
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
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'wp_ajax_polanger_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
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
        $this->config = wp_parse_args( $config, array(
            'id'          => 'polanger',
            'menu_title'  => __( 'Install Plugins', 'polanger-required-plugins' ),
            'menu_slug'   => 'polanger-install-plugins',
            'parent_slug' => 'themes.php',
            'capability'  => 'install_plugins',
        ) );

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
        ) );

        // Sanitize slug.
        $normalized['slug'] = sanitize_key( $normalized['slug'] );

        return $normalized;
    }

    /**
     * Get plugin status.
     *
     * @param array $plugin Plugin config.
     * @return string Status: active, inactive, not_installed.
     */
    private function get_status( array $plugin ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $file = $this->get_plugin_file( $plugin['slug'] );

        if ( ! $file ) {
            return 'not_installed';
        }

        return is_plugin_active( $file ) ? 'active' : 'inactive';
    }

    /**
     * Get plugin file path.
     *
     * @param string $slug Plugin slug.
     * @return string|false Plugin file or false.
     */
    private function get_plugin_file( $slug ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ( get_plugins() as $file => $data ) {
            $plugin_dir = dirname( $file );
            if ( $plugin_dir === $slug || $file === $slug . '.php' ) {
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

        $incomplete = $this->get_incomplete();
        if ( empty( $incomplete ) ) {
            return;
        }

        // Check for incomplete required plugins.
        $required_incomplete = array_filter( $incomplete, function( $p ) { return $p['required']; } );
        $has_required_incomplete = ! empty( $required_incomplete );

        // If only recommended plugins are incomplete, check if dismissed.
        if ( ! $has_required_incomplete ) {
            $dismissed = get_user_meta( get_current_user_id(), 'polanger_dismissed_' . $this->config['id'], true );
            if ( $dismissed ) {
                return;
            }
        }

        $names = array_map( array( $this, 'get_name' ), $incomplete );
        $url   = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );

        // Determine notice type and dismissibility.
        if ( $has_required_incomplete ) {
            $class       = 'notice-warning';
            $dismissible = '';
            $message     = __( 'This theme requires the following plugins:', 'polanger-required-plugins' );
        } else {
            $class       = 'notice-info is-dismissible';
            $dismissible = sprintf(
                ' data-polanger-dismiss="%s"',
                esc_attr( $this->config['id'] )
            );
            $message     = __( 'This theme recommends the following plugins:', 'polanger-required-plugins' );
        }

        printf(
            '<div class="notice %s"%s><p><strong>%s</strong> %s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
            esc_attr( $class ),
            $dismissible,
            esc_html( $message ),
            esc_html( implode( ', ', $names ) ),
            esc_url( $url ),
            esc_html__( 'Install Plugins', 'polanger-required-plugins' )
        );

        // Add dismiss script only if dismissible.
        if ( ! $has_required_incomplete ) {
            $this->dismiss_notice_script();
        }
    }

    /**
     * Output dismiss notice script.
     *
     * @return void
     */
    private function dismiss_notice_script() {
        static $script_added = false;
        if ( $script_added ) {
            return;
        }
        $script_added = true;
        ?>
        <script>
        jQuery(function($) {
            $(document).on('click', '.notice[data-polanger-dismiss] .notice-dismiss', function() {
                var id = $(this).closest('.notice').data('polanger-dismiss');
                $.post(ajaxurl, {
                    action: 'polanger_dismiss_notice',
                    id: id,
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'polanger_dismiss_notice' ) ); ?>'
                });
            });
        });
        </script>
        <?php
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->config['menu_title'] ); ?></h1>
            <p><?php esc_html_e( 'The following plugins are required or recommended.', 'polanger-required-plugins' ); ?></p>

            <table class="wp-list-table widefat plugins">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Plugin', 'polanger-required-plugins' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Source', 'polanger-required-plugins' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Type', 'polanger-required-plugins' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Status', 'polanger-required-plugins' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Action', 'polanger-required-plugins' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $this->plugins as $plugin ) : 
                        $status = $this->get_status( $plugin );
                        $name   = $this->get_name( $plugin );
                    ?>
                    <tr class="<?php echo $status === 'active' ? 'active' : 'inactive'; ?>">
                        <td><strong><?php echo esc_html( $name ); ?></strong></td>
                        <td><?php echo $plugin['source'] === 'wordpress' ? 'WordPress.org' : esc_html__( 'Bundled', 'polanger-required-plugins' ); ?></td>
                        <td><?php echo $plugin['required'] ? esc_html__( 'Required', 'polanger-required-plugins' ) : esc_html__( 'Recommended', 'polanger-required-plugins' ); ?></td>
                        <td>
                            <?php if ( $status === 'active' ) : ?>
                                <span style="color: #00a32a;">● <?php esc_html_e( 'Active', 'polanger-required-plugins' ); ?></span>
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

        switch ( $status ) {
            case 'active':
                return '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>';

            case 'inactive':
                $url = add_query_arg( array(
                    'action' => 'activate',
                    'plugin' => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                return sprintf(
                    '<a href="%s" class="button">%s</a>',
                    esc_url( $url ),
                    esc_html__( 'Activate', 'polanger-required-plugins' )
                );

            case 'not_installed':
            default:
                $url = add_query_arg( array(
                    'action' => 'install',
                    'plugin' => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                return sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url( $url ),
                    esc_html__( 'Install', 'polanger-required-plugins' )
                );
        }
    }

    /**
     * Handle install/activate actions.
     *
     * @return void
     */
    public function handle_actions() {
        if ( empty( $_GET['action'] ) || empty( $_GET['plugin'] ) ) {
            return;
        }

        $action = sanitize_key( $_GET['action'] );
        $slug   = sanitize_key( $_GET['plugin'] );

        // Whitelist allowed actions.
        if ( ! in_array( $action, array( 'install', 'activate' ), true ) ) {
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

        if ( $action === 'install' ) {
            $this->install_plugin( $plugin );
        } elseif ( $action === 'activate' ) {
            $this->activate_plugin( $plugin );
        }

        // Redirect back.
        wp_safe_redirect( admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] ) );
        exit;
    }

    /**
     * Install a plugin.
     *
     * @param array $plugin Plugin config.
     * @return bool Success.
     */
    private function install_plugin( array $plugin ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        // Get download URL.
        if ( $plugin['source'] === 'wordpress' ) {
            $api = plugins_api( 'plugin_information', array( 'slug' => $plugin['slug'] ) );
            if ( is_wp_error( $api ) ) {
                return false;
            }
            $source = $api->download_link;
        } else {
            // Bundled - source is file path.
            $source = $plugin['source'];
            if ( ! file_exists( $source ) ) {
                return false;
            }
        }

        // Install with quiet skin.
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result = $upgrader->install( $source );

        if ( $result ) {
            wp_clean_plugins_cache( true );
            // Auto-activate after install.
            $this->activate_plugin( $plugin );
        }

        return (bool) $result;
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
}
endif; // class_exists
