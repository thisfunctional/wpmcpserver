# Changelog

All notable changes to this project are documented in this file.

## [1.1.6]

### Fixed
- `check_permission()` now reads the JSON-RPC method via `$request->get_json_params()` instead of a second, independent `file_get_contents('php://input')` call. WordPress's REST server already reads and caches the raw body internally, and re-reading `php://input` a second time is not guaranteed to work on every SAPI/hosting configuration — using the already-parsed body makes the 1.1.5 initialize-handshake bypass reliable everywhere.

### Changed
- The `[MCP Auth]` log entry is now emitted for every request (not just non-bypassed ones) and includes `http_method` (`$request->get_method()`) alongside `rpc_method`, `bypassed`, `header`, and `result` — needed to diagnose clients (e.g. ChatGPT) that arrive without auth and without a body.

## [1.1.5]

### Changed
- `check_permission()` in `WP_MCP_Server` now reads the raw JSON-RPC method from the request body (`file_get_contents('php://input')` + `json_decode`) before enforcing authentication, and lets `initialize` and `notifications/initialized` through without a valid token — allowing clients to complete the MCP handshake before they necessarily have one. Every other method (`tools/list`, `tools/call`, etc.) is unaffected and still requires authentication. A `[MCP Auth]` log entry now records the method and whether the bypass was taken.

## [1.1.4]

### Fixed
- The "Clear logs" button on the settings page left a blank page at `admin-post.php` instead of redirecting back. `add_action('admin_post_wp_mcp_clear_logs', ...)` was registered inside a method only ever called from the `admin_menu` hook, which never fires on `admin-post.php` requests — so the handler was never actually attached. It's now registered unconditionally at plugin bootstrap. The success redirect's query arg is now `cleared` (was `logs_cleared`).
- OAuth token responses now return `token_type: "Bearer"` (was lowercase `"bearer"`) and a new `expires_in: 3600` field. Access tokens are now stored with an actual expiry timestamp (`wp_mcp_oauth_tokens` changed from a flat list of token strings to a `token => expires_at` map) and `WP_MCP_Auth::verify()` rejects expired tokens — previously issued tokens never expired at all.
- 401 responses from the MCP request endpoint now reliably carry a `WWW-Authenticate: Bearer realm="WordPress MCP Server"` header. The header was previously set via a raw `header()` call inside `check_permission()`, which runs before the actual `WP_REST_Response` object exists; it's now attached via the `rest_post_dispatch` filter, which is the mechanism WordPress guarantees will reach the client.

## [1.1.3]

### Added
- Diagnostic logging in the OAuth token endpoint: a `[MCP OAuth Token] success` entry on successful token issuance (`client_id`, token prefix) and a `[MCP OAuth Token] client_id from code fallback` entry when the client_id-from-authorization-code fallback (added in 1.1.2) is actually exercised. Both are gated behind `wp_mcp_debug_enabled` like all other logger output.

## [1.1.2]

### Fixed
- The OAuth token endpoint now recovers `client_id` from the stored authorization code record when a client (e.g. ChatGPT) sends neither `client_id` in the body nor via HTTP Basic Authentication — only `code` and `code_verifier`. Since the authorization code was already bound to a specific `client_id` when `/authorize` issued it, that binding is used as the final fallback.

## [1.1.1]

### Fixed
- The OAuth token endpoint (`/wp-json/mcp/v1/oauth/token`) now also accepts `client_id`/`client_secret` sent via HTTP Basic Authentication (RFC 6749 §2.3.1), which some clients (e.g. ChatGPT) use instead of putting credentials in the request body. Body-supplied `client_id` (as sent by Claude.ai) still takes precedence when present.

## [1.1.0]

### Added
- Internal debug logging system (`WP_MCP_Logger`): a "Logs" section on the settings page with a debug-logging toggle (`wp_mcp_debug_enabled`), a scrollable viewer for the last 200 log entries, and a "Clear logs" action
- Logs are stored in the `wp_mcp_logs` option as a JSON array (timestamp, message, context), capped at 500 entries FIFO, and only recorded while debug logging is enabled

## [1.0.0]

Initial production release.

### Added
- MCP (Model Context Protocol) JSON-RPC 2.0 server exposed over the WordPress REST API (`/wp-json/mcp/v1/request`), supporting `initialize`, `tools/list`, and `tools/call`
- OAuth 2.0 authorization with PKCE for Claude connector authentication, including Dynamic Client Registration (`/wp-json/mcp/v1/oauth/register`), an authorization/consent screen (`/authorize`), token exchange (`/wp-json/mcp/v1/oauth/token`), and an OAuth Authorization Server discovery document (`/.well-known/oauth-authorization-server`)
- Static API key as an alternative authentication method for direct/advanced API access
- WP Core module: `search_posts`, `get_post`, `list_post_types`, `list_terms`, `get_site_info`
- WooCommerce module (auto-enabled only when WooCommerce is active): `woo_get_products`, `woo_get_orders`, `woo_get_revenue`, `woo_low_stock`
- ACF module (auto-enabled only when Advanced Custom Fields is active): `acf_get_fields`, `acf_list_field_groups`, `acf_search_by_field`
- Settings page under **Settings → MCP Server** to enable/disable modules, view the connector endpoint URL, manage the API key, and get manual OAuth client credentials as a fallback
- Automatic migration that removes a module from the enabled list if its dependency plugin is later deactivated
- Full internationalization support (`wp-mcp-server` text domain, `.pot` translation template)
