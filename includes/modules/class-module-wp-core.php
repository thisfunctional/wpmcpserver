<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Module_WP_Core {

    public function register( WP_MCP_Router $router ): void {

        $router->register_tool(
            'search_posts',
            __( 'Searches posts, pages, or custom post types by keyword.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'properties' => [
                    'query'     => [ 'type' => 'string', 'description' => __( 'Keyword to search for', 'wp-mcp-server' ) ],
                    'post_type' => [ 'type' => 'string', 'description' => __( 'Post type (default: any)', 'wp-mcp-server' ), 'default' => 'any' ],
                    'status'    => [ 'type' => 'string', 'description' => __( 'Post status (default: publish)', 'wp-mcp-server' ), 'default' => 'publish' ],
                    'limit'     => [ 'type' => 'integer', 'description' => __( 'Maximum number of results (default: 10)', 'wp-mcp-server' ), 'default' => 10 ],
                ],
            ],
            [ $this, 'search_posts' ]
        );

        $router->register_tool(
            'get_post',
            __( 'Gets the full content of a post or page by ID.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'required'   => [ 'id' ],
                'properties' => [
                    'id' => [ 'type' => 'integer', 'description' => __( 'Post ID', 'wp-mcp-server' ) ],
                ],
            ],
            [ $this, 'get_post' ]
        );

        $router->register_tool(
            'list_post_types',
            __( 'Lists all public post types available on this site.', 'wp-mcp-server' ),
            [ 'type' => 'object', 'properties' => [] ],
            [ $this, 'list_post_types' ]
        );

        $router->register_tool(
            'list_terms',
            __( 'Lists terms of a taxonomy (categories, tags, or custom taxonomies).', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [ 'type' => 'string', 'description' => __( 'Taxonomy slug (default: category)', 'wp-mcp-server' ), 'default' => 'category' ],
                ],
            ],
            [ $this, 'list_terms' ]
        );

        $router->register_tool(
            'get_site_info',
            __( 'Returns basic information about this WordPress site.', 'wp-mcp-server' ),
            [ 'type' => 'object', 'properties' => [] ],
            [ $this, 'get_site_info' ]
        );
    }

    public function search_posts( array $args ): array {
        $query = new WP_Query( [
            's'              => $args['query'] ?? '',
            'post_type'      => $args['post_type'] ?? 'any',
            'post_status'    => $args['status'] ?? 'publish',
            'posts_per_page' => min( (int) ( $args['limit'] ?? 10 ), 50 ),
        ] );

        $results = [];
        foreach ( $query->posts as $post ) {
            $results[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'type'    => $post->post_type,
                'status'  => $post->post_status,
                'date'    => $post->post_date,
                'url'     => get_permalink( $post->ID ),
                'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
            ];
        }

        return [ 'posts' => $results, 'total' => $query->found_posts ];
    }

    public function get_post( array $args ): array {
        $post = get_post( (int) $args['id'] );
        if ( ! $post ) throw new Exception( __( 'Post not found.', 'wp-mcp-server' ) );

        return [
            'id'      => $post->ID,
            'title'   => $post->post_title,
            'content' => wp_strip_all_tags( $post->post_content ),
            'type'    => $post->post_type,
            'status'  => $post->post_status,
            'date'    => $post->post_date,
            'author'  => get_the_author_meta( 'display_name', $post->post_author ),
            'url'     => get_permalink( $post->ID ),
        ];
    }

    public function list_post_types( array $args ): array {
        $types = get_post_types( [ 'public' => true ], 'objects' );
        return [
            'post_types' => array_values( array_map( fn( $t ) => [
                'slug'  => $t->name,
                'label' => $t->label,
            ], $types ) ),
        ];
    }

    public function list_terms( array $args ): array {
        $terms = get_terms( [
            'taxonomy'   => $args['taxonomy'] ?? 'category',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $terms ) ) throw new Exception( $terms->get_error_message() );

        return [
            'terms' => array_map( fn( $t ) => [
                'id'    => $t->term_id,
                'name'  => $t->name,
                'slug'  => $t->slug,
                'count' => $t->count,
            ], $terms ),
        ];
    }

    public function get_site_info( array $args ): array {
        return [
            'name'        => get_bloginfo( 'name' ),
            'description' => get_bloginfo( 'description' ),
            'url'         => get_bloginfo( 'url' ),
            'wp_version'  => get_bloginfo( 'version' ),
            'language'    => get_bloginfo( 'language' ),
            'timezone'    => wp_timezone_string(),
        ];
    }
}
