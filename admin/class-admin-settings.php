<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Admin_Settings {

    public function register(): void {
        add_options_page(
            esc_html__( 'WP MCP Server', 'wp-mcp-server' ),
            esc_html__( 'MCP Server', 'wp-mcp-server' ),
            'manage_options',
            'wp-mcp-server',
            [ $this, 'render_page' ]
        );

        add_action( 'admin_post_wp_mcp_clear_logs', [ $this, 'handle_clear_logs' ] );
    }

    public function handle_clear_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'wp-mcp-server' ) );
        }

        check_admin_referer( 'wp_mcp_clear_logs' );

        WP_MCP_Logger::clear();

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'wp-mcp-server', 'logs_cleared' => '1' ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['wp_mcp_save'] ) && check_admin_referer( 'wp_mcp_settings' ) ) {
            $modules = array_map( 'sanitize_text_field', $_POST['wp_mcp_modules'] ?? [] );
            update_option( 'wp_mcp_enabled_modules', $modules );
            update_option( 'wp_mcp_debug_enabled', ! empty( $_POST['wp_mcp_debug_enabled'] ) );

            if ( ! empty( $_POST['wp_mcp_regenerate_key'] ) ) {
                update_option( 'wp_mcp_api_key', wp_generate_password( 32, false ) );
            }

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-mcp-server' ) . '</p></div>';
        }

        if ( isset( $_GET['logs_cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Logs cleared.', 'wp-mcp-server' ) . '</p></div>';
        }

        $api_key       = get_option( 'wp_mcp_api_key', '' );
        $enabled       = get_option( 'wp_mcp_enabled_modules', [ 'wp_core' ] );
        $endpoint      = rest_url( 'mcp/v1/request' );
        $has_woo       = class_exists( 'WooCommerce' );
        $has_acf       = function_exists( 'get_fields' );
        $debug_enabled = get_option( 'wp_mcp_debug_enabled', false );
        $recent_logs   = array_reverse( array_slice( WP_MCP_Logger::get_logs(), -200 ) );

        $default_client = get_option( 'wp_mcp_default_oauth_client', [] );
        if ( empty( $default_client ) ) {
            $client_id      = wp_generate_uuid4();
            $client_secret  = wp_generate_password( 32, false );
            $default_client = [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'client_name'   => 'Claude (default)',
                'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
            ];
            add_option( 'wp_mcp_default_oauth_client', $default_client );

            $clients               = get_option( 'wp_mcp_oauth_clients', [] );
            $clients[ $client_id ] = $default_client;
            update_option( 'wp_mcp_oauth_clients', $clients );
        }

        ?>
        <div class="wrap">
            <h1>WP MCP Server</h1>
            <p><?php esc_html_e( 'Connect this site as an MCP connector to AI assistants like Claude.', 'wp-mcp-server' ); ?></p>

            <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 16px 20px; margin: 20px 0; max-width: 720px;">
                <h2 style="margin-top: 0;"><?php esc_html_e( 'How to connect this site to Claude', 'wp-mcp-server' ); ?></h2>

                <p><strong><?php esc_html_e( 'Step 1.', 'wp-mcp-server' ); ?></strong> <?php esc_html_e( 'Copy the endpoint URL below.', 'wp-mcp-server' ); ?></p>
                <p>
                    <code><?php echo esc_html( $endpoint ); ?></code>
                    <button type="button" class="button button-small"
                        onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint ); ?>')">
                        <?php esc_html_e( 'Copy', 'wp-mcp-server' ); ?>
                    </button>
                </p>

                <p><strong><?php esc_html_e( 'Step 2.', 'wp-mcp-server' ); ?></strong> <?php esc_html_e( 'In Claude, go to Settings → Connectors → Add custom connector and paste the URL.', 'wp-mcp-server' ); ?></p>

                <p><strong><?php esc_html_e( 'Step 3.', 'wp-mcp-server' ); ?></strong> <?php esc_html_e( 'Click Connect and approve access when the browser opens.', 'wp-mcp-server' ); ?></p>

                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;color:#666;"><?php esc_html_e( 'If Claude shows a connection error, expand here', 'wp-mcp-server' ); ?></summary>
                    <p><?php esc_html_e( 'Some environments require manual configuration. Go back to the "Add custom connector" screen in Claude, click Add an OAuth Client ID and fill in:', 'wp-mcp-server' ); ?></p>
                    <table class="form-table" style="margin:0;">
                        <tr><th>Client ID</th><td><code><?php echo esc_html( $default_client['client_id'] ); ?></code> <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $default_client['client_id'] ); ?>')"><?php esc_html_e( 'Copy', 'wp-mcp-server' ); ?></button></td></tr>
                        <tr><th>Client Secret</th><td><code><?php echo esc_html( $default_client['client_secret'] ); ?></code> <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $default_client['client_secret'] ); ?>')"><?php esc_html_e( 'Copy', 'wp-mcp-server' ); ?></button></td></tr>
                    </table>
                </details>

                <p class="description"><?php esc_html_e( 'Authentication happens automatically via OAuth — you don\'t need an API key.', 'wp-mcp-server' ); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'wp_mcp_settings' ); ?>

                <h2><?php esc_html_e( 'Modules', 'wp-mcp-server' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">WP Core</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_mcp_modules[]" value="wp_core"
                                    <?php checked( in_array( 'wp_core', $enabled, true ) ); ?>>
                                <?php esc_html_e( 'Posts, pages, CPTs, taxonomies, media', 'wp-mcp-server' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WooCommerce</th>
                        <td>
                            <?php if ( $has_woo ) : ?>
                                <label>
                                    <input type="checkbox" name="wp_mcp_modules[]" value="woocommerce"
                                        <?php checked( in_array( 'woocommerce', $enabled, true ) ); ?>>
                                    <?php esc_html_e( 'Products, orders, customers, revenue', 'wp-mcp-server' ); ?>
                                </label>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e( 'WooCommerce is not installed.', 'wp-mcp-server' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ACF</th>
                        <td>
                            <?php if ( $has_acf ) : ?>
                                <label>
                                    <input type="checkbox" name="wp_mcp_modules[]" value="acf"
                                        <?php checked( in_array( 'acf', $enabled, true ) ); ?>>
                                    <?php esc_html_e( 'Custom fields (Advanced Custom Fields)', 'wp-mcp-server' ); ?>
                                </label>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e( 'ACF is not installed.', 'wp-mcp-server' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <details style="max-width: 720px; margin: 20px 0;">
                    <summary style="cursor: pointer; font-weight: 600; padding: 8px 0;"><?php esc_html_e( 'API access (advanced)', 'wp-mcp-server' ); ?></summary>
                    <div style="padding: 8px 0 0 4px;">
                        <p class="description"><?php esc_html_e( 'For those who prefer to access the API directly, without going through the OAuth flow.', 'wp-mcp-server' ); ?></p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">API Key</th>
                                <td>
                                    <code><?php echo esc_html( $api_key ); ?></code>
                                    <button type="button" class="button button-small"
                                        onclick="navigator.clipboard.writeText('<?php echo esc_js( $api_key ); ?>')">
                                        <?php esc_html_e( 'Copy', 'wp-mcp-server' ); ?>
                                    </button>
                                    <p class="description">
                                        <?php
                                        printf(
                                            /* translators: %s: API key */
                                            esc_html__( 'Use in header: %s', 'wp-mcp-server' ),
                                            '<code>Authorization: Bearer ' . esc_html( $api_key ) . '</code>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Regenerate API Key', 'wp-mcp-server' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wp_mcp_regenerate_key" value="1">
                                        <?php esc_html_e( 'Generate a new API key (invalidates the current one)', 'wp-mcp-server' ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>
                </details>

                <h2><?php esc_html_e( 'Logs', 'wp-mcp-server' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Debug logging', 'wp-mcp-server' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_mcp_debug_enabled" value="1"
                                    <?php checked( $debug_enabled ); ?>>
                                <?php esc_html_e( 'Enable debug logs', 'wp-mcp-server' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save settings', 'wp-mcp-server' ), 'primary', 'wp_mcp_save' ); ?>
            </form>

            <h3><?php esc_html_e( 'Recent logs (last 200)', 'wp-mcp-server' ); ?></h3>
            <div style="max-width: 900px; max-height: 400px; overflow-y: auto; border: 1px solid #c3c4c7; background: #fff;">
                <table class="widefat striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 160px;"><?php esc_html_e( 'Time', 'wp-mcp-server' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'wp-mcp-server' ); ?></th>
                            <th><?php esc_html_e( 'Context', 'wp-mcp-server' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $recent_logs ) ) : ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e( 'No logs recorded yet.', 'wp-mcp-server' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $recent_logs as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
                                    <td><code><?php echo esc_html( $entry['context'] ?? '' ); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
                <?php wp_nonce_field( 'wp_mcp_clear_logs' ); ?>
                <input type="hidden" name="action" value="wp_mcp_clear_logs">
                <?php submit_button( __( 'Clear logs', 'wp-mcp-server' ), 'delete', 'wp_mcp_clear_logs_submit', false ); ?>
            </form>
        </div>
        <?php
    }
}
