<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Module_ACF {

    private function is_available(): bool {
        return function_exists( 'get_field' ) || class_exists( 'ACF' );
    }

    public function register( WP_MCP_Router $router ): void {
        if ( ! $this->is_available() ) {
            return;
        }

        $router->register_tool(
            'acf_get_fields',
            __( 'Gets all ACF fields for a specific post.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'required'   => [ 'post_id' ],
                'properties' => [
                    'post_id' => [ 'type' => 'integer', 'description' => __( 'Post ID', 'wp-mcp-server' ) ],
                ],
            ],
            [ $this, 'get_fields' ]
        );

        $router->register_tool(
            'acf_list_field_groups',
            __( 'Lists all ACF field groups and their associated post types.', 'wp-mcp-server' ),
            [ 'type' => 'object', 'properties' => [] ],
            [ $this, 'list_field_groups' ]
        );

        $router->register_tool(
            'acf_search_by_field',
            __( 'Searches posts by an ACF field value.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'required'   => [ 'field_key', 'value' ],
                'properties' => [
                    'field_key' => [ 'type' => 'string', 'description' => __( 'ACF field name (meta_key)', 'wp-mcp-server' ) ],
                    'value'     => [ 'type' => 'string', 'description' => __( 'Value to search for', 'wp-mcp-server' ) ],
                    'post_type' => [ 'type' => 'string', 'description' => __( 'Post type to search (default: any)', 'wp-mcp-server' ), 'default' => 'any' ],
                    'limit'     => [ 'type' => 'integer', 'description' => __( 'Maximum number of results (default: 10)', 'wp-mcp-server' ), 'default' => 10 ],
                ],
            ],
            [ $this, 'search_by_field' ]
        );
    }

    public function get_fields( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'ACF is not installed or active.', 'wp-mcp-server' ) );
        }

        $post_id = (int) $args['post_id'];
        $fields  = get_fields( $post_id );

        return [
            'post_id' => $post_id,
            'fields'  => $fields ? $this->sanitize_acf_value( $fields ) : [],
        ];
    }

    public function list_field_groups( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'ACF is not installed or active.', 'wp-mcp-server' ) );
        }

        $groups = acf_get_field_groups();
        $result = [];

        foreach ( $groups as $group ) {
            $fields = acf_get_fields( $group['key'] );
            $result[] = [
                'title'    => $group['title'],
                'key'      => $group['key'],
                'location' => $group['location'] ?? [],
                'fields'   => $fields ? array_map( fn( $f ) => [
                    'label' => $f['label'],
                    'name'  => $f['name'],
                    'type'  => $f['type'],
                ], $fields ) : [],
            ];
        }

        return [ 'field_groups' => $result ];
    }

    public function search_by_field( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'ACF is not installed or active.', 'wp-mcp-server' ) );
        }

        $query = new WP_Query( [
            'post_type'      => sanitize_text_field( $args['post_type'] ?? 'any' ),
            'post_status'    => 'publish',
            'posts_per_page' => min( (int) ( $args['limit'] ?? 10 ), 50 ),
            'meta_query'     => [ [
                'key'     => sanitize_text_field( $args['field_key'] ),
                'value'   => sanitize_text_field( $args['value'] ),
                'compare' => 'LIKE',
            ] ],
        ] );

        $results = [];
        foreach ( $query->posts as $post ) {
            $results[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'type'  => $post->post_type,
                'url'   => get_permalink( $post->ID ),
            ];
        }

        return [ 'posts' => $results, 'total' => $query->found_posts ];
    }

    /**
     * Recursively sanitizes ACF values for safe JSON serialization.
     * Converts WordPress objects (WP_Post, WP_Term, etc.) into plain arrays.
     */
    private function sanitize_acf_value( mixed $value ): mixed {
        if ( is_array( $value ) ) {
            return array_map( [ $this, 'sanitize_acf_value' ], $value );
        }

        if ( $value instanceof WP_Post ) {
            return [ 'id' => $value->ID, 'title' => $value->post_title, 'type' => $value->post_type ];
        }

        if ( $value instanceof WP_Term ) {
            return [ 'id' => $value->term_id, 'name' => $value->name, 'slug' => $value->slug ];
        }

        if ( is_object( $value ) ) {
            return '[object]';
        }

        return $value;
    }
}
