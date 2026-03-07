<?php
/**
 * Polanger Required Plugins
 *
 * A minimal, modern library for WordPress theme developers to require plugins.
 * Clean replacement for TGMPA in ~350 lines.
 *
 * @package Polanger_Required_Plugins
 * @version 3.0.0
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
        add_action( 'current_screen', array( $this, 'handle_actions' ) );
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

        // Separate required and recommended plugins.
        $required_incomplete = array_filter( $incomplete, function( $p ) { return $p['required']; } );
        $recommended_incomplete = array_filter( $incomplete, function( $p ) { return ! $p['required']; } );

        $has_required = ! empty( $required_incomplete );
        $has_recommended = ! empty( $recommended_incomplete );

        // If only recommended plugins are incomplete, check if dismissed.
        if ( ! $has_required ) {
            $dismissed = get_user_meta( get_current_user_id(), 'polanger_dismissed_' . $this->config['id'], true );
            if ( $dismissed ) {
                return;
            }
        }

        $url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );

        // Build message parts.
        $message_parts = array();

        if ( $has_required ) {
            $required_names = array_map( array( $this, 'get_name' ), $required_incomplete );
            $message_parts[] = sprintf(
                '<strong>%s</strong> %s',
                esc_html__( 'Required:', 'polanger-required-plugins' ),
                esc_html( implode( ', ', $required_names ) )
            );
        }

        if ( $has_recommended ) {
            $recommended_names = array_map( array( $this, 'get_name' ), $recommended_incomplete );
            $message_parts[] = sprintf(
                '<strong>%s</strong> %s',
                esc_html__( 'Recommended:', 'polanger-required-plugins' ),
                esc_html( implode( ', ', $recommended_names ) )
            );
        }

        // Determine notice type and dismissibility.
        if ( $has_required ) {
            $class       = 'notice-warning';
            $dismissible = '';
            $intro       = __( 'This theme needs the following plugins:', 'polanger-required-plugins' );
        } else {
            $class       = 'notice-info is-dismissible';
            $dismissible = sprintf(
                ' data-polanger-dismiss="%s"',
                esc_attr( $this->config['id'] )
            );
            $intro       = __( 'This theme recommends the following plugins:', 'polanger-required-plugins' );
        }

        printf(
            '<div class="notice %s"%s><p><strong>%s</strong></p><p>%s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
            esc_attr( $class ),
            $dismissible,
            esc_html( $intro ),
            implode( ' &nbsp;|&nbsp; ', $message_parts ),
            esc_url( $url ),
            esc_html__( 'Install Plugins', 'polanger-required-plugins' )
        );

        // Add dismiss script only if dismissible.
        if ( ! $has_required ) {
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
                        <?php foreach ( $this->plugins as $plugin ) : 
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
                            <td><?php echo $plugin['source'] === 'wordpress' ? 'WordPress.org' : esc_html__( 'Bundled', 'polanger-required-plugins' ); ?></td>
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

        switch ( $status ) {
            case 'active':
                return '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>';

            case 'needs_update':
                $url = add_query_arg( array(
                    'action' => 'update',
                    'plugin' => $slug,
                    '_wpnonce' => $nonce,
                ), $base_url );
                return sprintf(
                    '<a href="%s" class="button button-primary">%s</a>',
                    esc_url( $url ),
                    esc_html__( 'Update', 'polanger-required-plugins' )
                );

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
     * Handle install/activate actions including queue processing.
     *
     * @return void
     */
    public function handle_actions() {
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
        if ( ! in_array( $action, array( 'install', 'activate', 'update' ), true ) ) {
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
        } elseif ( $action === 'update' ) {
            $this->update_plugin( $plugin );
        }

        // Redirect back.
        wp_safe_redirect( admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] ) );
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

        // Parse queue.
        $queue = array_filter( array_map( 'sanitize_key', explode( ',', $_GET['queue'] ) ) );

        if ( empty( $queue ) ) {
            wp_safe_redirect( admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] ) );
            exit;
        }

        // Get current plugin to process.
        $current_slug = array_shift( $queue );

        // Find plugin config.
        if ( isset( $this->plugins[ $current_slug ] ) ) {
            $plugin = $this->plugins[ $current_slug ];
            $status = $this->get_status( $plugin );

            // Install if not installed.
            if ( $status === 'not_installed' ) {
                $this->install_plugin( $plugin );
            } elseif ( $status === 'needs_update' ) {
                // Update if needs update.
                $this->update_plugin( $plugin );
            } elseif ( $status === 'inactive' ) {
                // Activate if installed but inactive.
                $this->activate_plugin( $plugin );
            }
        }

        // Build redirect URL.
        $redirect_url = admin_url( $this->config['parent_slug'] . '?page=' . $this->config['menu_slug'] );

        // If more plugins in queue, continue processing.
        if ( ! empty( $queue ) ) {
            $redirect_url = add_query_arg( array(
                'queue'    => implode( ',', $queue ),
                '_wpnonce' => wp_create_nonce( 'polanger_bulk_install' ),
            ), $redirect_url );
        }

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
            $this->clear_plugins_cache();
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

    /**
     * Update a plugin.
     *
     * @param array $plugin Plugin config.
     * @return bool Success.
     */
    private function update_plugin( array $plugin ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );

        if ( $plugin['source'] === 'wordpress' ) {
            // WordPress.org plugin - use upgrade method.
            $file = $this->get_plugin_file( $plugin['slug'] );
            if ( ! $file ) {
                return false;
            }
            $result = $upgrader->upgrade( $file );
        } else {
            // Bundled plugin - reinstall from source.
            $source = $plugin['source'];
            if ( ! file_exists( $source ) ) {
                return false;
            }
            // Delete existing and reinstall.
            $result = $upgrader->install( $source, array( 'overwrite_package' => true ) );
        }

        if ( $result ) {
            $this->clear_plugins_cache();
        }

        return (bool) $result;
    }

    /**
     * Inject plugin update information into WordPress update transient.
     * This enables native WordPress update notifications for bundled plugins.
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
                $update = new \stdClass();

                $update->slug        = $plugin['slug'];
                $update->plugin      = $file;
                $update->new_version = $plugin['version'];
                $update->package     = $plugin['source'] === 'wordpress' ? '' : $plugin['source'];
                $update->url         = $plugin['source'] === 'wordpress' 
                    ? 'https://wordpress.org/plugins/' . $plugin['slug'] . '/'
                    : '';

                $transient->response[ $file ] = $update;
            }
        }

        return $transient;
    }
}
endif; // class_exists
