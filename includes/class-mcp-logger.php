<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_MCP_Logger {

    private const OPTION_ENABLED = 'wp_mcp_debug_enabled';
    private const OPTION_LOGS    = 'wp_mcp_logs';
    private const MAX_ENTRIES    = 500;

    public static function log( string $message, array $context = [] ): void {
        if ( ! get_option( self::OPTION_ENABLED, false ) ) {
            return;
        }

        $logs   = self::get_logs();
        $logs[] = [
            'timestamp' => current_time( 'mysql' ),
            'message'   => $message,
            'context'   => wp_json_encode( $context ),
        ];

        if ( count( $logs ) > self::MAX_ENTRIES ) {
            $logs = array_slice( $logs, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_LOGS, $logs, false );
    }

    public static function get_logs(): array {
        $logs = get_option( self::OPTION_LOGS, [] );
        return is_array( $logs ) ? $logs : [];
    }

    public static function clear(): void {
        delete_option( self::OPTION_LOGS );
    }
}
