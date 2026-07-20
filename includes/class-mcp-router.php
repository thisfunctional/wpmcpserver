<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Router {

    private array $tools = [];

    public function register_tool( string $name, string $description, array $input_schema, callable $handler ): void {
        $this->tools[ $name ] = [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => $input_schema,
            'handler'     => $handler,
        ];
    }

    public function list_tools(): array {
        return array_values( array_map( fn( $t ) => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'inputSchema' => $t['inputSchema'],
        ], $this->tools ) );
    }

    public function call_tool( string $name, array $arguments ): array {
        if ( ! isset( $this->tools[ $name ] ) ) {
            return [
                'isError' => true,
                'content' => [ [ 'type' => 'text', 'text' => sprintf(
                    /* translators: %s: tool name */
                    __( "Tool '%s' not found.", 'wp-mcp-server' ),
                    $name
                ) ] ],
            ];
        }

        try {
            $result = call_user_func( $this->tools[ $name ]['handler'], $arguments );
            return [
                'content' => [ [
                    'type' => 'text',
                    'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
                ] ],
            ];
        } catch ( Exception $e ) {
            return [
                'isError' => true,
                'content' => [ [ 'type' => 'text', 'text' => $e->getMessage() ] ],
            ];
        }
    }
}
