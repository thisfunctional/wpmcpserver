<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Module_WooCommerce {

    private function is_available(): bool {
        return class_exists( 'WooCommerce' );
    }

    public function register( WP_MCP_Router $router ): void {
        if ( ! $this->is_available() ) {
            return;
        }

        $router->register_tool(
            'woo_get_products',
            __( 'Lists or searches WooCommerce products.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'properties' => [
                    'search' => [ 'type' => 'string', 'description' => __( 'Search term', 'wp-mcp-server' ) ],
                    'status' => [ 'type' => 'string', 'description' => __( 'Product status (default: publish)', 'wp-mcp-server' ), 'default' => 'publish' ],
                    'stock'  => [ 'type' => 'string', 'description' => __( 'Stock: instock, outofstock, onbackorder', 'wp-mcp-server' ) ],
                    'limit'  => [ 'type' => 'integer', 'description' => __( 'Maximum number of results (default: 20)', 'wp-mcp-server' ), 'default' => 20 ],
                ],
            ],
            [ $this, 'get_products' ]
        );

        $router->register_tool(
            'woo_get_orders',
            __( 'Lists WooCommerce orders with optional filters.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'properties' => [
                    'status'    => [ 'type' => 'string', 'description' => __( 'Status: pending, processing, completed, cancelled, refunded', 'wp-mcp-server' ) ],
                    'date_from' => [ 'type' => 'string', 'description' => __( 'Start date (YYYY-MM-DD)', 'wp-mcp-server' ) ],
                    'date_to'   => [ 'type' => 'string', 'description' => __( 'End date (YYYY-MM-DD)', 'wp-mcp-server' ) ],
                    'limit'     => [ 'type' => 'integer', 'description' => __( 'Maximum number of results (default: 20)', 'wp-mcp-server' ), 'default' => 20 ],
                ],
            ],
            [ $this, 'get_orders' ]
        );

        $router->register_tool(
            'woo_get_revenue',
            __( 'Revenue summary for a date range.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'properties' => [
                    'date_from' => [ 'type' => 'string', 'description' => __( 'Start date (YYYY-MM-DD)', 'wp-mcp-server' ) ],
                    'date_to'   => [ 'type' => 'string', 'description' => __( 'End date (YYYY-MM-DD)', 'wp-mcp-server' ) ],
                ],
            ],
            [ $this, 'get_revenue' ]
        );

        $router->register_tool(
            'woo_low_stock',
            __( 'Products with low or no stock.', 'wp-mcp-server' ),
            [
                'type'       => 'object',
                'properties' => [
                    'threshold' => [ 'type' => 'integer', 'description' => __( 'Minimum stock quantity (default: 5)', 'wp-mcp-server' ), 'default' => 5 ],
                ],
            ],
            [ $this, 'get_low_stock' ]
        );
    }

    public function get_products( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'WooCommerce is not installed or active.', 'wp-mcp-server' ) );
        }

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => $args['status'] ?? 'publish',
            'posts_per_page' => min( (int) ( $args['limit'] ?? 20 ), 100 ),
            's'              => $args['search'] ?? '',
        ];

        if ( ! empty( $args['stock'] ) ) {
            $query_args['meta_query'] = [ [
                'key'   => '_stock_status',
                'value' => sanitize_text_field( $args['stock'] ),
            ] ];
        }

        $query    = new WP_Query( $query_args );
        $products = [];

        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;

            $products[] = [
                'id'           => $product->get_id(),
                'name'         => $product->get_name(),
                'price'        => $product->get_price(),
                'stock_status' => $product->get_stock_status(),
                'stock_qty'    => $product->get_stock_quantity(),
                'sku'          => $product->get_sku(),
                'url'          => $product->get_permalink(),
            ];
        }

        return [ 'products' => $products, 'total' => $query->found_posts ];
    }

    public function get_orders( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'WooCommerce is not installed or active.', 'wp-mcp-server' ) );
        }

        $query_args = [
            'limit'   => min( (int) ( $args['limit'] ?? 20 ), 100 ),
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        if ( ! empty( $args['status'] ) ) {
            $query_args['status'] = 'wc-' . sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['date_from'] ) ) {
            $query_args['date_created'] = '>=' . sanitize_text_field( $args['date_from'] );
        }

        if ( ! empty( $args['date_to'] ) ) {
            $query_args['date_created'] = '<=' . sanitize_text_field( $args['date_to'] );
        }

        $orders = wc_get_orders( $query_args );
        $result = [];

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) continue;

            $result[] = [
                'id'       => $order->get_id(),
                'status'   => $order->get_status(),
                'total'    => $order->get_total(),
                'currency' => $order->get_currency(),
                'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'email'    => $order->get_billing_email(),
                'date'     => $order->get_date_created()?->date( 'Y-m-d H:i:s' ),
                'items'    => count( $order->get_items() ),
            ];
        }

        return [ 'orders' => $result ];
    }

    public function get_revenue( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'WooCommerce is not installed or active.', 'wp-mcp-server' ) );
        }

        global $wpdb;

        $date_from = sanitize_text_field( $args['date_from'] ?? date( 'Y-m-01' ) );
        $date_to   = sanitize_text_field( $args['date_to'] ?? date( 'Y-m-d' ) );

        // HPOS (High-Performance Order Storage) support, plus legacy tables
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) as order_count, SUM(total_amount) as total_revenue
                 FROM {$wpdb->prefix}wc_orders
                 WHERE type = 'shop_order'
                   AND status IN ('wc-completed', 'wc-processing')
                   AND date_created_gmt BETWEEN %s AND %s",
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59'
            ) );

        } else {

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) as order_count, SUM(pm.meta_value) as total_revenue
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed', 'wc-processing')
                   AND p.post_date BETWEEN %s AND %s",
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59'
            ) );
        }

        return [
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'order_count'   => (int) $row->order_count,
            'total_revenue' => round( (float) $row->total_revenue, 2 ),
        ];
    }

    public function get_low_stock( array $args ): array {
        if ( ! $this->is_available() ) {
            throw new Exception( __( 'WooCommerce is not installed or active.', 'wp-mcp-server' ) );
        }

        $threshold = (int) ( $args['threshold'] ?? 5 );

        $query = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_manage_stock', 'value' => 'yes' ],
                [ 'key' => '_stock', 'value' => $threshold, 'compare' => '<=', 'type' => 'NUMERIC' ],
            ],
        ] );

        $products = [];
        foreach ( $query->posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $products[] = [
                'id'        => $product->get_id(),
                'name'      => $product->get_name(),
                'sku'       => $product->get_sku(),
                'stock_qty' => $product->get_stock_quantity(),
            ];
        }

        usort( $products, fn( $a, $b ) => $a['stock_qty'] <=> $b['stock_qty'] );

        return [ 'products' => $products, 'threshold' => $threshold, 'count' => count( $products ) ];
    }
}
