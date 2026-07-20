<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Auth {

    /**
     * Verifies the API key via Authorization: Bearer <key> or X-API-Key header.
     */
    public static function verify( WP_REST_Request $request ): bool {
        $api_key = get_option( 'wp_mcp_api_key', '' );
        if ( empty( $api_key ) ) return false;

        // Authorization: Bearer <oauth_token>
        $auth = $request->get_header( 'Authorization' );
        if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
            $token = substr( $auth, 7 );
            // check first whether it's an API key
            if ( hash_equals( $api_key, $token ) ) return true;
            // then check whether it's an OAuth token
            $tokens = get_option( 'wp_mcp_oauth_tokens', [] );
            return in_array( $token, $tokens, true );
        }

        // X-API-Key: <key>
        $key = $request->get_header( 'X-API-Key' );
        if ( $key ) {
            return hash_equals( $api_key, $key );
        }

        // ?api_key=<key> (for connectors that don't support custom headers)
        $query_key = $request->get_param( 'api_key' );
        if ( $query_key ) {
            return hash_equals( $api_key, $query_key );
        }

        return false;
    }
}
