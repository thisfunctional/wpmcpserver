<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Server {

    private WP_MCP_Router $router;

    public function __construct() {
        $this->router = new WP_MCP_Router();
        $this->load_modules();
    }

    private function load_modules(): void {
        $enabled = get_option( 'wp_mcp_enabled_modules', [ 'wp_core' ] );

        if ( in_array( 'wp_core', $enabled, true ) ) {
            ( new WP_MCP_Module_WP_Core() )->register( $this->router );
        }

        if ( in_array( 'woocommerce', $enabled, true ) && class_exists( 'WooCommerce' ) ) {
            ( new WP_MCP_Module_WooCommerce() )->register( $this->router );
        }

        if ( in_array( 'acf', $enabled, true ) && function_exists( 'get_fields' ) ) {
            ( new WP_MCP_Module_ACF() )->register( $this->router );
        }
    }

    public function register_routes(): void {
        register_rest_route( 'mcp/v1', '/request', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_request' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        add_filter( 'rest_post_dispatch', [ $this, 'add_www_authenticate_header' ], 10, 3 );
    }

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        $raw_body = file_get_contents( 'php://input' );
        $body     = json_decode( $raw_body, true );
        $method   = $body['method'] ?? '';

        // The initialize handshake happens before a client necessarily has a token
        // yet (e.g. while probing the connector), so it's allowed through unauthenticated.
        if ( in_array( $method, [ 'initialize', 'notifications/initialized' ], true ) ) {
            WP_MCP_Logger::log( '[MCP Auth]', [ 'method' => $method, 'bypassed' => true ] );
            return true;
        }

        $auth_header = $request->get_header( 'Authorization' );
        $verified    = WP_MCP_Auth::verify( $request );

        WP_MCP_Logger::log( '[MCP Auth]', [
            'method'   => $method,
            'bypassed' => false,
            'header'   => $auth_header ? substr( $auth_header, 0, 20 ) . '...' : 'none',
            'result'   => $verified ? 'ok' : 'fail',
        ] );

        if ( $verified ) return true;

        return new WP_Error( 'rest_forbidden', __( 'Authentication required', 'wp-mcp-server' ), [ 'status' => 401 ] );
    }

    /**
     * Guarantees 401 responses from our endpoint carry a WWW-Authenticate header,
     * so OAuth clients (e.g. ChatGPT) know to retry the request with a Bearer token.
     *
     * A raw header() call inside check_permission() isn't reliable here: WP_REST_Server
     * only actually emits the headers stored on the WP_REST_Response object (via
     * send_headers()), which happens well after permission_callback runs — so setting
     * the header on the response itself, via rest_post_dispatch, is the mechanism WP
     * guarantees will reach the client.
     */
    public function add_www_authenticate_header( $response, $server, $request ) {
        if ( $response instanceof WP_REST_Response
            && 401 === $response->get_status()
            && '/mcp/v1/request' === $request->get_route()
        ) {
            $response->header( 'WWW-Authenticate', 'Bearer realm="WordPress MCP Server"' );
        }

        return $response;
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        WP_MCP_Logger::log( '[MCP REQUEST START]', [ 'uri' => $_SERVER['REQUEST_URI'] ?? '' ] );

        $body = $request->get_json_params();

        WP_MCP_Logger::log( '[MCP]', [
            'http_method' => $request->get_method(),
            'method'      => $body['method'] ?? 'none',
            'auth'        => $request->get_header( 'Authorization' ) ? 'present' : 'missing',
        ] );

        WP_MCP_Logger::log( '[MCP REQUEST]', [
            'method' => $body['method'] ?? 'MISSING',
            'id'     => $body['id'] ?? null,
        ] );

        if ( empty( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
            return $this->error( null, -32600, __( 'Invalid JSON-RPC request', 'wp-mcp-server' ) );
        }

        $id     = $body['id'] ?? null;
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];

        try {
            $response = match ( $method ) {
                'initialize'        => $this->handle_initialize( $id ),
                'notifications/initialized' => new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => null ], 200 ),
                'tools/list'        => $this->handle_tools_list( $id ),
                'tools/call'        => $this->handle_tools_call( $id, $params ),
                default             => $this->error( $id, -32601, sprintf(
                    /* translators: %s: JSON-RPC method name */
                    __( "Method '%s' not found", 'wp-mcp-server' ),
                    $method
                ) ),
            };
        } catch ( Throwable $e ) {
            WP_MCP_Logger::log( '[MCP FATAL] ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ] );
            return new WP_REST_Response( [
                'jsonrpc' => '2.0',
                'id'      => $body['id'] ?? null,
                'error'   => [ 'code' => -32603, 'message' => 'Internal error: ' . $e->getMessage() ],
            ], 200 );
        }

        WP_MCP_Logger::log( '[MCP RESPONSE]', [
            'data' => substr( wp_json_encode( $response->get_data() ), 0, 500 ),
        ] );

        return $response;
    }

    private function handle_initialize( $id ): WP_REST_Response {
        return $this->ok( $id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => [ 'tools' => new stdClass() ],
            'serverInfo'      => [
                'name'    => 'wp-mcp-server',
                'version' => WP_MCP_VERSION,
            ],
        ] );
    }

    private function handle_tools_list( $id ): WP_REST_Response {
        return $this->ok( $id, [ 'tools' => $this->router->list_tools() ] );
    }

    private function handle_tools_call( $id, array $params ): WP_REST_Response {
        $name      = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        return $this->ok( $id, $this->router->call_tool( $name, $arguments ) );
    }

    private function ok( $id, array $result ): WP_REST_Response {
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );
    }

    private function error( $id, int $code, string $message ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [ 'code' => $code, 'message' => $message ],
        ], 400 );
    }
}
