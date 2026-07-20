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
    }

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( WP_MCP_Auth::verify( $request ) ) return true;

        header( 'WWW-Authenticate: Bearer realm="' . rest_url( 'mcp/v1' ) . '", error="invalid_token"' );
        return new WP_Error( 'rest_forbidden', __( 'Authentication required', 'wp-mcp-server' ), [ 'status' => 401 ] );
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        if ( empty( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
            return $this->error( null, -32600, __( 'Invalid JSON-RPC request', 'wp-mcp-server' ) );
        }

        $id     = $body['id'] ?? null;
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];

        return match ( $method ) {
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
