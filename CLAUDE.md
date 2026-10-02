# WP MCP Server

## What this is

A WordPress plugin that exposes a **MCP (Model Context Protocol) server** over WordPress's HTTP/REST API, so AI assistants and MCP clients — Claude.ai, ChatGPT, and others — can connect to a WordPress site and query/act on its content, WooCommerce store data, and ACF custom fields through a small set of well-defined "tools". Access is gated behind OAuth 2.0 (with PKCE) or a static API key.

## Stack

- PHP 8.0+, WordPress 6.0+ (no external dependencies/Composer packages)
- WordPress REST API (`register_rest_route`) as the transport for both the MCP endpoint and the OAuth endpoints
- OAuth 2.0 Authorization Code flow with PKCE (S256), plus Dynamic Client Registration (RFC 7591) and HTTP Basic Authentication support (RFC 6749 §2.3.1)
- JSON-RPC 2.0 as the message format for the MCP endpoint itself
- No build step — plain PHP, no JS bundling, no Composer autoload (each file is `require_once`'d explicitly from the main plugin file)

## File structure

- **`wp-mcp-server.php`** — plugin bootstrap: header, `WP_MCP_VERSION`/`WP_MCP_DIR` constants, all `require_once` calls (in dependency order), and every top-level `add_action`/`add_filter`/`register_activation_hook` call. Also contains the OAuth metadata helpers (`wp_mcp_issuer()`, `wp_mcp_protected_resource_metadata()`, `wp_mcp_authorization_server_metadata()`), the optional root `/.well-known/oauth-*` handlers and a legacy inline `/authorize` handler, all registered on a priority-1 `init` hook (see "OAuth flow" below for why `/authorize` lives here and not as a REST route).
- **`includes/class-mcp-logger.php`** — `WP_MCP_Logger`, the internal logging system. Loaded **first**, before every other include, since other classes call it unconditionally.
- **`includes/class-mcp-auth.php`** — `WP_MCP_Auth::verify()`, validates incoming MCP requests via `Authorization: Bearer <api_key_or_oauth_token>`, `X-API-Key`, or `?api_key=`.
- **`includes/class-mcp-router.php`** — `WP_MCP_Router`, an in-memory tool registry. Modules call `register_tool($name, $description, $inputSchema, $handler)` on it; it exposes `list_tools()` (for `tools/list`) and `call_tool()` (for `tools/call`), wrapping handler execution in `try/catch (Throwable)`.
- **`includes/class-mcp-server.php`** — `WP_MCP_Server`, the actual MCP JSON-RPC endpoint (`POST /wp-json/mcp/v1/request`). Instantiates the router, loads whichever modules are enabled in `wp_mcp_enabled_modules`, and dispatches `initialize` (with protocol version negotiation) / `ping` / `tools/list` / `tools/call`. JSON-RPC notifications get an empty `202`; `GET`/`DELETE` get `405`.
- **`includes/modules/`** — one class per integration (`class-module-wp-core.php`, `class-module-woocommerce.php`, `class-module-acf.php`), each registering its own tools onto the router. See "Conventions" below for the dependency-guard pattern every module follows.
- **`includes/oauth/class-oauth-server.php`** — `WP_MCP_OAuth_Server`, the REST-based half of the OAuth flow: Dynamic Client Registration (`/oauth/register`), the authorize screen (`/oauth/authorize`, GET+POST), and token exchange (`/oauth/token`).
- **`admin/class-admin-settings.php`** — `WP_MCP_Admin_Settings`, the wp-admin settings page (Settings → MCP Server): module toggles, API key display/regeneration, OAuth client fallback credentials, and the Logs section (debug-logging toggle, last-200 viewer, Clear logs action).

## MCP flow

Everything goes through a single REST route: `POST /wp-json/mcp/v1/request`, JSON-RPC 2.0 over the body. Sequence a client follows:

1. `initialize` — client announces itself, server replies with `protocolVersion`, `capabilities`, `serverInfo` (name/version).
2. `notifications/initialized` — client confirms; server answers `202 Accepted` with an empty body (any `notifications/*` message is handled this way).
3. `tools/list` — server returns every tool registered by the currently-enabled modules (`WP_MCP_Router::list_tools()`).
4. `tools/call` — client invokes a specific tool by name with arguments; server dispatches to the module's handler via the router and returns its result (or an `isError` payload on failure).

Auth (`WP_MCP_Auth::verify()`) runs as the REST route's `permission_callback`, checked on every request regardless of JSON-RPC method.

## OAuth flow

1. **Discovery** (must not depend on the web server serving `/.well-known/`, which many hosts reserve for AutoSSL): the 401 from the MCP endpoint carries `WWW-Authenticate: Bearer ... resource_metadata="<rest_url>/mcp/v1/oauth/protected-resource"` (RFC 9728). That document points at the issuer `<rest_url>/mcp/v1/oauth`, and because the issuer has a path, clients also request `{issuer}/.well-known/openid-configuration`, which is a normal REST route in `class-oauth-server.php`. The root `/.well-known/oauth-*` handlers in `wp-mcp-server.php` remain as an optional extra when the host allows them.
2. **Dynamic Client Registration**: `POST /wp-json/mcp/v1/oauth/register` — client self-registers, gets back a `client_id`/`client_secret` pair stored in the `wp_mcp_oauth_clients` option.
3. **Authorize**: `/authorize` — **this is not a REST route**. It's handled by a plain `add_action('init', ..., 1)` closure in `wp-mcp-server.php`, matched by parsing `$_SERVER['REQUEST_URI']` directly. (There's also a REST-based twin at `/wp-json/mcp/v1/oauth/authorize` in `class-oauth-server.php` used by some clients — both write to the same `wp_mcp_oauth_codes` option.) Requires an authenticated WordPress user with `manage_options`; shows a consent screen; on approval, generates an auth code bound to `client_id` + `redirect_uri` + `code_challenge`, stored in `wp_mcp_oauth_codes` (10-minute TTL), then redirects back to the client's `redirect_uri` with `?code=...`.
4. **Token exchange**: `POST /wp-json/mcp/v1/oauth/token` (`WP_MCP_OAuth_Server::handle_token()`) — exchanges the auth code for an access token and a rotating refresh token (`grant_type=refresh_token`, 30-day lifetime, stored in `wp_mcp_oauth_refresh`). Validates PKCE (`SHA256(code_verifier)` base64url must match the stored `code_challenge`), single-use codes, expiry, and client/redirect_uri binding. Resolves `client_id` from up to three sources, in order: body param → HTTP Basic Auth header (RFC 6749 §2.3.1, e.g. ChatGPT) → the stored authorization code's own `client_id` (for clients that send neither, also ChatGPT). See "Known bugs" below.

## Known bugs (fixed)

- **`properties: []` vs `new stdClass()`** — tools with no input parameters were declared with `'properties' => []` in their `inputSchema`. PHP serializes an empty array as a JSON array (`[]`), but JSON Schema requires `properties` to be an *object* (`{}`) — this caused some MCP clients to reject the whole `tools/list` response. Fix: use `'properties' => new stdClass()` for any tool with no parameters (see `list_post_types`, `get_site_info` in the WP Core module, `acf_list_field_groups` in the ACF module).
- **`client_id` arriving as `null` from ChatGPT during token exchange** — ChatGPT's token request doesn't always include `client_id` in the POST body. Fixed with a layered fallback in `handle_token()`: body param, then HTTP Basic Auth (`Authorization: Basic base64(client_id:client_secret)`), then — because ChatGPT sometimes sends *neither* — recovery from the `client_id` already bound to the authorization code in `wp_mcp_oauth_codes` (that binding was set when `/authorize` originally issued the code, so it's trustworthy).
- **"Clear logs" button leaving a blank page at `admin-post.php`** — `add_action('admin_post_wp_mcp_clear_logs', ...)` was registered inside `WP_MCP_Admin_Settings::register()`, which itself only runs via the `admin_menu` hook. `admin_menu` **never fires** on `admin-post.php` requests (that endpoint only bootstraps enough of wp-admin to fire `admin_init`), so the handler was never actually attached when the form posted — `do_action()` found no listener and the request ended with an empty body. Fix: register `admin_post_wp_mcp_clear_logs` unconditionally at plugin bootstrap in `wp-mcp-server.php`, not nested inside an `admin_menu` callback. **General rule for this codebase: any `admin_post_*` / `admin_post_nopriv_*` hook must be registered at top-level bootstrap, never inside a callback that's itself gated behind `admin_menu`.**

## Conventions

- **Logging**: always use `WP_MCP_Logger::log($message, $context = [])` — never call `error_log()` directly. The logger is a no-op unless the `wp_mcp_debug_enabled` option is truthy, and entries are stored (FIFO, max 500) in the `wp_mcp_logs` option, viewable/clearable from the settings page.
- **Module dependency guards**: every integration module (`class-module-woocommerce.php`, `class-module-acf.php`) has a private `is_available()` check (`class_exists(...)` / `function_exists(...)`) called at the top of `register()` (returns early, registering zero tools, if unavailable) and at the top of every public tool-handler method (throws an `Exception` if unavailable, which the router turns into an `isError` tool result). `wp_mcp_enabled_modules` is also self-healing: an `init` hook strips a module from that option if its dependency plugin gets deactivated later.
- **Empty `inputSchema.properties`**: always `new stdClass()`, never `[]` — see "Known bugs" above.
- **i18n**: every user-facing string (admin UI, wp_die messages, tool/parameter descriptions) is wrapped in `__()`/`esc_html__()`/`esc_html_e()`/`esc_attr__()` with the `wp-mcp-server` text domain. Regenerate `languages/wp-mcp-server.pot` after adding/changing strings via `wp i18n make-pot . languages/wp-mcp-server.pot --domain=wp-mcp-server`.
- **Versioning**: bump `Version` in the `wp-mcp-server.php` header, `WP_MCP_VERSION`, `Stable tag` in `readme.txt`, and add entries to both `CHANGELOG.md` and `readme.txt`'s `== Changelog ==`/`== Upgrade Notice ==` on every release-worthy change.

## Author

Limpinho / [thisfunctional.pt](https://thisfunctional.pt)
