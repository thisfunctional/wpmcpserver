<?php
/**
 * Plugin Name:       WP MCP Server
 * Plugin URI:        https://thisfunctional.pt
 * Description:       Turns WordPress into an MCP server for AI assistants like Claude.
 * Version:           1.1.9
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            this.functional
 * Author URI:        https://thisfunctional.pt
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-server
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_MCP_VERSION', '1.1.9' );
define( 'WP_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_MCP_DIR . 'includes/class-mcp-logger.php';
require_once WP_MCP_DIR . 'includes/class-mcp-auth.php';
require_once WP_MCP_DIR . 'includes/class-mcp-router.php';
require_once WP_MCP_DIR . 'includes/class-mcp-server.php';
require_once WP_MCP_DIR . 'includes/modules/class-module-wp-core.php';
require_once WP_MCP_DIR . 'includes/modules/class-module-woocommerce.php';
require_once WP_MCP_DIR . 'includes/modules/class-module-acf.php';
require_once WP_MCP_DIR . 'admin/class-admin-settings.php';

require_once WP_MCP_DIR . 'includes/oauth/class-oauth-server.php';

// The well-known endpoint must be registered HERE, at the top — not inside another hook.
add_action( 'init', function () {
    $uri = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    if ( $uri === '/.well-known/oauth-authorization-server' ) {
        header( 'Content-Type: application/json' );
        echo wp_json_encode( [
            'issuer'                           => home_url(),
            'authorization_endpoint'           => home_url( '/authorize' ),
            'token_endpoint'                   => rest_url( 'mcp/v1/oauth/token' ),
            'registration_endpoint'            => rest_url( 'mcp/v1/oauth/register' ),
            'response_types_supported'         => [ 'code' ],
            'grant_types_supported'            => [ 'authorization_code' ],
            'code_challenge_methods_supported' => [ 'S256' ],
        ] );
        exit;
    }

    if ( $uri === '/authorize' ) {
        $client_id             = sanitize_text_field( $_REQUEST['client_id'] ?? '' );
        $redirect_uri          = esc_url_raw( $_REQUEST['redirect_uri'] ?? '' );
        $state                 = sanitize_text_field( $_REQUEST['state'] ?? '' );
        $code_challenge        = sanitize_text_field( $_REQUEST['code_challenge'] ?? '' );
        $code_challenge_method = sanitize_text_field( $_REQUEST['code_challenge_method'] ?? '' );

        $clients = get_option( 'wp_mcp_oauth_clients', [] );
        if ( ! isset( $clients[ $client_id ] ) ) {
            wp_die( esc_html__( 'Invalid client_id.', 'wp-mcp-server' ), esc_html__( 'OAuth Error', 'wp-mcp-server' ), [ 'response' => 400 ] );
        }
        $client = $clients[ $client_id ];

        if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
            wp_die( esc_html__( 'Invalid redirect_uri.', 'wp-mcp-server' ), esc_html__( 'OAuth Error', 'wp-mcp-server' ), [ 'response' => 400 ] );
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            // redirect_to points to /authorize (not the REST URL) — avoids the loop
            $return_to = home_url( '/authorize?' . ( $_SERVER['QUERY_STRING'] ?? '' ) );
            wp_safe_redirect( wp_login_url( $return_to ) );
            exit;
        }

        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wp_mcp_oauth_authorize' ) ) {
                wp_die( esc_html__( 'Invalid nonce.', 'wp-mcp-server' ), esc_html__( 'OAuth Error', 'wp-mcp-server' ), [ 'response' => 400 ] );
            }

            if ( empty( $_POST['approved'] ) ) {
                wp_safe_redirect( add_query_arg( [ 'error' => 'access_denied', 'state' => $state ], $redirect_uri ) );
                exit;
            }

            $auth_code           = wp_generate_password( 32, false );
            $codes               = get_option( 'wp_mcp_oauth_codes', [] );
            $codes[ $auth_code ] = [
                'client_id'      => $client_id,
                'redirect_uri'   => $redirect_uri,
                'code_challenge' => $code_challenge,
                'user_id'        => get_current_user_id(),
                'expires'        => time() + 600,
            ];
            update_option( 'wp_mcp_oauth_codes', $codes );

            wp_redirect( add_query_arg( [ 'code' => $auth_code, 'state' => $state ], $redirect_uri ) );
            exit;
        }

        // GET — show the authorization form
        $nonce = wp_create_nonce( 'wp_mcp_oauth_authorize' );
        $user  = wp_get_current_user();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">
            <title>' . esc_html__( 'Authorize access — WP MCP Server', 'wp-mcp-server' ) . '</title>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            </head><body style="font-family:-apple-system,sans-serif;max-width:480px;margin:80px auto;text-align:center;">
            <h1>' . esc_html__( 'Authorize access', 'wp-mcp-server' ) . '</h1>
            <p>' . sprintf(
                /* translators: 1: OAuth client name, 2: site host, 3: WordPress username */
                esc_html__( '%1$s wants to connect to %2$s via MCP, on behalf of %3$s.', 'wp-mcp-server' ),
                '<strong>' . esc_html( $client['client_name'] ) . '</strong>',
                '<strong>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</strong>',
                '<strong>' . esc_html( $user->user_login ) . '</strong>'
            ) . '</p>
            <form method="post" action="' . esc_url( home_url( '/authorize' ) ) . '">
            <input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">
            <input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">
            <input type="hidden" name="redirect_uri" value="' . esc_attr( $redirect_uri ) . '">
            <input type="hidden" name="state" value="' . esc_attr( $state ) . '">
            <input type="hidden" name="code_challenge" value="' . esc_attr( $code_challenge ) . '">
            <input type="hidden" name="code_challenge_method" value="' . esc_attr( $code_challenge_method ) . '">
            <input type="hidden" name="approved" value="1">
            <button type="submit" style="padding:10px 20px;font-size:14px;">' . esc_html__( 'Authorize access to WordPress', 'wp-mcp-server' ) . '</button>
            </form></body></html>';
        exit;
    }
}, 1 );

add_action( 'init', function () { ( new WP_MCP_OAuth_Server() )->init(); } );

// Drop modules whose dependency plugin is no longer active (e.g. WooCommerce/ACF deactivated).
add_action( 'init', function () {
    $enabled  = get_option( 'wp_mcp_enabled_modules', [ 'wp_core' ] );
    $filtered = array_values( array_filter( $enabled, function ( $module ) {
        return match ( $module ) {
            'woocommerce' => class_exists( 'WooCommerce' ),
            'acf'         => function_exists( 'get_field' ) || class_exists( 'ACF' ),
            default       => true,
        };
    } ) );

    if ( $filtered !== $enabled ) {
        update_option( 'wp_mcp_enabled_modules', $filtered );
    }
} );

add_action( 'rest_api_init', function () {
    ( new WP_MCP_Server() )->register_routes();
} );

add_action( 'admin_menu', function () {
    ( new WP_MCP_Admin_Settings() )->register();
} );

// Registered here (not inside admin_menu) because admin_menu never fires on
// admin-post.php requests — only admin_init does.
add_action( 'admin_post_wp_mcp_clear_logs', function () {
    ( new WP_MCP_Admin_Settings() )->handle_clear_logs();
} );

register_activation_hook( __FILE__, function () {
    add_option( 'wp_mcp_api_key', wp_generate_password( 32, false ) );
    add_option( 'wp_mcp_enabled_modules', [ 'wp_core' ] );

    // Pre-generated OAuth client for manual fallback
    $client_id     = wp_generate_uuid4();
    $client_secret = wp_generate_password( 32, false );
    add_option( 'wp_mcp_default_oauth_client', [
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'client_name'   => 'Claude (default)',
        'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
    ] );
    // Also register it in the OAuth clients list
    $clients = get_option( 'wp_mcp_oauth_clients', [] );
    $clients[ $client_id ] = [
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'client_name'   => 'Claude (default)',
        'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
    ];
    update_option( 'wp_mcp_oauth_clients', $clients );
} );

add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! empty( $result ) ) return $result;
    if ( ! WP_MCP_Auth::verify( new WP_REST_Request( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
        return null; // let WP handle it
    }
    return $result;
} );
