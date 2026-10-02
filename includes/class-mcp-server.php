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
            'methods'             => [ 'GET', 'POST', 'DELETE' ],
            'callback'            => [ $this, 'handle_request' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        add_filter( 'rest_post_dispatch', [ $this, 'add_www_authenticate_header' ], 10, 3 );
        add_filter( 'rest_pre_serve_request', [ $this, 'serve_empty_202' ], 10, 4 );
    }

    /**
     * JSON-RPC notifications get "202 Accepted" with NO body (MCP Streamable HTTP).
     * WordPress would otherwise serialise the empty payload as the literal "null".
     */
    public function serve_empty_202( $served, $result, $request, $server ) {
        if ( '/mcp/v1/request' === $request->get_route()
            && $result instanceof WP_REST_Response
            && 202 === $result->get_status()
        ) {
            return true;
        }
        return $served;
    }

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        $auth_header = $request->get_header( 'Authorization' );
        $verified    = WP_MCP_Auth::verify( $request );

        WP_MCP_Logger::log( '[MCP Auth]', [
            'header' => $auth_header ? substr( $auth_header, 0, 20 ) . '...' : 'none',
            'result' => $verified ? 'ok' : 'fail',
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
            $response->header(
                'WWW-Authenticate',
                sprintf(
                    'Bearer realm="WordPress MCP Server", resource_metadata="%s"',
                    esc_url_raw( wp_mcp_resource_metadata_url() )
                )
            );
        }

        return $response;
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        WP_MCP_Logger::log( '[MCP REQUEST START]', [
            'uri'         => $_SERVER['REQUEST_URI'] ?? '',
            'http_method' => $request->get_method(),
            'protocol'    => $request->get_header( 'MCP-Protocol-Version' ) ?: 'none',
            'accept'      => $request->get_header( 'Accept' ) ?: 'none',
        ] );

        // This server is stateless and never opens an SSE stream, so GET (stream)
        // and DELETE (session termination) are answered with 405, per the spec.
        if ( 'POST' !== $request->get_method() ) {
            $resp = new WP_REST_Response( null, 405 );
            $resp->header( 'Allow', 'POST' );
            return $resp;
        }

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

        // Notifications (no "id") and client responses never get a JSON-RPC reply.
        if ( ! array_key_exists( 'id', $body ) && str_starts_with( (string) $method, 'notifications/' ) ) {
            return new WP_REST_Response( null, 202 );
        }

        try {
            $response = match ( $method ) {
                'initialize'        => $this->handle_initialize( $id, $params ),
                'ping'              => $this->ok( $id, new stdClass() ),
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

    private const SUPPORTED_PROTOCOLS = [ '2025-06-18', '2025-03-26', '2024-11-05' ];

    private function handle_initialize( $id, array $params = [] ): WP_REST_Response {
        // Version negotiation: echo the client's version if we support it,
        // otherwise answer with the newest one we do support.
        $requested = (string) ( $params['protocolVersion'] ?? '' );
        $version   = in_array( $requested, self::SUPPORTED_PROTOCOLS, true )
            ? $requested
            : self::SUPPORTED_PROTOCOLS[0];

        return $this->ok( $id, [
            'protocolVersion' => $version,
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

    private function ok( $id, array|object $result ): WP_REST_Response {
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );
    }

    private function error( $id, int $code, string $message ): WP_REST_Response {
        return new WP_REST_Response( [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [ 'code' => $code, 'message' => $message ],
        ], -32600 === $code ? 400 : 200 );
    }
}
