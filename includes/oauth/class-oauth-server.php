<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_OAuth_Server {

    private const CODE_TTL = 600; // 10 minutes
    private const NONCE_ACTION = 'wp_mcp_oauth_authorize';

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'mcp/v1', '/oauth/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_register' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'mcp/v1', '/oauth/authorize', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_authorize' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'mcp/v1', '/oauth/token', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_token' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * POST /wp-json/mcp/v1/oauth/register — Dynamic Client Registration.
     */
    public function handle_register( WP_REST_Request $request ): WP_REST_Response {
        $client_name   = sanitize_text_field( (string) $request->get_param( 'client_name' ) );
        $redirect_uris = $request->get_param( 'redirect_uris' );

        if ( ! is_array( $redirect_uris ) ) {
            $redirect_uris = $redirect_uris ? [ $redirect_uris ] : [];
        }
        $redirect_uris = array_values( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) );

        if ( empty( $redirect_uris ) ) {
            return new WP_REST_Response( [
                'error'             => 'invalid_client_metadata',
                'error_description' => __( 'redirect_uris is required.', 'wp-mcp-server' ),
            ], 400 );
        }

        $client_id     = wp_generate_uuid4();
        $client_secret = wp_generate_password( 32, false );

        $clients               = get_option( 'wp_mcp_oauth_clients', [] );
        $clients[ $client_id ] = [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'client_name'   => $client_name ?: 'MCP Client',
            'redirect_uris' => $redirect_uris,
        ];
        update_option( 'wp_mcp_oauth_clients', $clients );

        return new WP_REST_Response( [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'client_name'   => $clients[ $client_id ]['client_name'],
            'redirect_uris' => $redirect_uris,
        ], 201 );
    }

    /**
     * GET/POST /wp-json/mcp/v1/oauth/authorize
     */
    public function handle_authorize( WP_REST_Request $request ) {
        $client_id              = (string) $request->get_param( 'client_id' );
        $redirect_uri           = (string) $request->get_param( 'redirect_uri' );
        $state                  = (string) $request->get_param( 'state' );
        $code_challenge         = (string) $request->get_param( 'code_challenge' );
        $code_challenge_method  = (string) $request->get_param( 'code_challenge_method' );

        $clients = get_option( 'wp_mcp_oauth_clients', [] );
        if ( '' === $client_id || ! isset( $clients[ $client_id ] ) ) {
            return new WP_REST_Response( [ 'error' => 'invalid_client' ], 400 );
        }
        $client = $clients[ $client_id ];

        if ( '' === $redirect_uri || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
            return new WP_REST_Response( [ 'error' => 'invalid_redirect_uri' ], 400 );
        }

        // Only an authenticated WordPress user (with admin permissions) can
        // grant access to the MCP server — without this, any anonymous
        // visitor could generate an authorization code and then an access token.
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            $return_to = home_url( add_query_arg( null, null, wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
            wp_safe_redirect( wp_login_url( $return_to ) );
            exit;
        }

        if ( 'POST' === $request->get_method() ) {
            if ( ! wp_verify_nonce( (string) $request->get_param( '_wpnonce' ), self::NONCE_ACTION ) ) {
                return new WP_REST_Response( [ 'error' => 'invalid_request', 'error_description' => __( 'Invalid nonce.', 'wp-mcp-server' ) ], 400 );
            }

            if ( ! $request->get_param( 'approved' ) ) {
                $denied = add_query_arg( [ 'error' => 'access_denied', 'state' => $state ], $redirect_uri );
                wp_safe_redirect( $denied );
                exit;
            }

            $auth_code           = wp_generate_password( 32, false );
            $codes                = get_option( 'wp_mcp_oauth_codes', [] );
            $codes[ $auth_code ] = [
                'client_id'      => $client_id,
                'redirect_uri'   => $redirect_uri,
                'code_challenge' => $code_challenge,
                'user_id'        => get_current_user_id(),
                'expires'        => time() + self::CODE_TTL,
            ];
            update_option( 'wp_mcp_oauth_codes', $codes );

            $redirect = add_query_arg( [ 'code' => $auth_code, 'state' => $state ], $redirect_uri );
            wp_safe_redirect( $redirect );
            exit;
        }

        $this->render_authorize_page( $client, [
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'state'                 => $state,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
        ] );
        exit;
    }

    private function render_authorize_page( array $client, array $fields ): void {
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title><?php esc_html_e( 'Authorize access — WP MCP Server', 'wp-mcp-server' ); ?></title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
        </head>
        <body style="font-family: -apple-system, sans-serif; max-width: 480px; margin: 80px auto; text-align: center;">
            <h1><?php esc_html_e( 'Authorize access', 'wp-mcp-server' ); ?></h1>
            <p>
                <?php
                printf(
                    /* translators: 1: OAuth client name, 2: site host, 3: WordPress username */
                    esc_html__( '%1$s wants to connect to %2$s via MCP, on behalf of %3$s.', 'wp-mcp-server' ),
                    '<strong>' . esc_html( $client['client_name'] ) . '</strong>',
                    '<strong>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</strong>',
                    '<strong>' . esc_html( wp_get_current_user()->user_login ) . '</strong>'
                );
                ?>
            </p>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, '_wpnonce', false ); ?>
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $fields['client_id'] ); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $fields['redirect_uri'] ); ?>">
                <input type="hidden" name="state" value="<?php echo esc_attr( $fields['state'] ); ?>">
                <input type="hidden" name="code_challenge" value="<?php echo esc_attr( $fields['code_challenge'] ); ?>">
                <input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $fields['code_challenge_method'] ); ?>">
                <input type="hidden" name="approved" value="1">
                <button type="submit" style="padding: 10px 20px; font-size: 14px;"><?php esc_html_e( 'Authorize access to WordPress', 'wp-mcp-server' ); ?></button>
            </form>
        </body>
        </html>
        <?php
    }

    /**
     * POST /wp-json/mcp/v1/oauth/token
     */
    public function handle_token( WP_REST_Request $request ): WP_REST_Response {
        WP_MCP_Logger::log( '[MCP OAuth Token] params', [
            'grant_type'    => $request->get_param( 'grant_type' ),
            'client_id'     => $request->get_param( 'client_id' ),
            'code'          => substr( (string) $request->get_param( 'code' ), 0, 8 ) . '...', // only the first 8 chars
            'redirect_uri'  => $request->get_param( 'redirect_uri' ),
            'code_verifier' => substr( (string) $request->get_param( 'code_verifier' ), 0, 8 ) . '...',
            'client_secret' => $request->get_param( 'client_secret' ) ? '(present)' : '(absent)',
        ] );

        if ( 'authorization_code' !== $request->get_param( 'grant_type' ) ) {
            WP_MCP_Logger::log( '[MCP OAuth Token] error: unsupported_grant_type' );
            return new WP_REST_Response( [ 'error' => 'unsupported_grant_type' ], 400 );
        }

        $code          = (string) $request->get_param( 'code' );
        $client_id     = (string) $request->get_param( 'client_id' );
        $client_secret = (string) $request->get_param( 'client_secret' );
        $redirect_uri  = (string) $request->get_param( 'redirect_uri' );
        $code_verifier = (string) $request->get_param( 'code_verifier' );

        // RFC 6749 §2.3.1: some clients (e.g. ChatGPT) send client credentials via
        // HTTP Basic Authentication instead of the request body. Claude.ai sends
        // client_id in the body, so that always takes precedence when present.
        if ( '' === $client_id ) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if ( '' === $auth_header && function_exists( 'apache_request_headers' ) ) {
                $headers     = apache_request_headers();
                $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }

            if ( str_starts_with( $auth_header, 'Basic ' ) ) {
                $decoded = base64_decode( substr( $auth_header, 6 ), true );
                if ( false !== $decoded && str_contains( $decoded, ':' ) ) {
                    [ $client_id, $client_secret ] = explode( ':', $decoded, 2 );
                }
            }
        }

        $codes = get_option( 'wp_mcp_oauth_codes', [] );

        // Some clients (e.g. ChatGPT) send neither client_id in the body nor via
        // Basic Auth — only code and code_verifier. The authorization code was
        // already bound to a client_id when /authorize issued it, so recover it
        // from the stored code record.
        if ( '' === $client_id && isset( $codes[ $code ]['client_id'] ) ) {
            $client_id = $codes[ $code ]['client_id'];
        }

        $clients = get_option( 'wp_mcp_oauth_clients', [] );
        if ( ! isset( $clients[ $client_id ] ) ) {
            WP_MCP_Logger::log( '[MCP OAuth Token] error: invalid_client' );
            return new WP_REST_Response( [ 'error' => 'invalid_client' ], 401 );
        }
        // client_secret is optional for public clients using PKCE
        if ( '' !== $client_secret && ! hash_equals( $clients[ $client_id ]['client_secret'], $client_secret ) ) {
            WP_MCP_Logger::log( '[MCP OAuth Token] error: invalid_client' );
            return new WP_REST_Response( [ 'error' => 'invalid_client' ], 401 );
        }

        if ( ! isset( $codes[ $code ] ) ) {
            WP_MCP_Logger::log( '[MCP OAuth Token] error: invalid_grant' );
            return new WP_REST_Response( [ 'error' => 'invalid_grant' ], 400 );
        }
        $code_data = $codes[ $code ];

        // The code can only be used once, even if validation fails afterwards.
        unset( $codes[ $code ] );
        update_option( 'wp_mcp_oauth_codes', $codes );

        if ( $code_data['expires'] < time()
            || ! hash_equals( $code_data['client_id'], $client_id )
            || ! hash_equals( $code_data['redirect_uri'], $redirect_uri )
        ) {
            WP_MCP_Logger::log( '[MCP OAuth Token] error: invalid_grant' );
            return new WP_REST_Response( [ 'error' => 'invalid_grant' ], 400 );
        }

        // PKCE: SHA256( code_verifier ) in base64url must match the stored code_challenge.
        $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
        if ( empty( $code_data['code_challenge'] ) || ! hash_equals( $code_data['code_challenge'], $challenge ) ) {
            WP_MCP_Logger::log( '[MCP OAuth Token] error: invalid_grant' );
            return new WP_REST_Response( [ 'error' => 'invalid_grant', 'error_description' => __( 'Invalid PKCE.', 'wp-mcp-server' ) ], 400 );
        }

        $access_token = wp_generate_password( 40, false );
        $tokens       = get_option( 'wp_mcp_oauth_tokens', [] );
        $tokens[]     = $access_token;
        update_option( 'wp_mcp_oauth_tokens', $tokens );

        return new WP_REST_Response( [
            'access_token' => $access_token,
            'token_type'   => 'bearer',
        ], 200 );
    }
}
