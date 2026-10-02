=== WP MCP Server ===
Contributors: limpinho
Donate link: https://thisfunctional.pt
Tags: mcp, ai, claude, rest-api, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns your WordPress site into an MCP (Model Context Protocol) server, connectable to AI assistants like Claude as a custom connector.

== Description ==

WP MCP Server exposes your WordPress site as an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server, so it can be connected to AI assistants such as Claude as a custom connector. Once connected, the assistant can query and act on your site's content, WooCommerce store data, and ACF custom fields through a small set of well-defined tools — all guarded behind OAuth 2.0 authentication.

**Features**

* MCP JSON-RPC 2.0 endpoint exposed over the WordPress REST API
* OAuth 2.0 with PKCE for secure, per-user authorization of AI assistant connectors (plus Dynamic Client Registration and an optional static API key for direct/advanced access)
* WP Core module — search and read posts, pages, and custom post types; list taxonomies and site info
* WooCommerce module (optional) — products, orders, revenue summaries, low-stock alerts
* ACF module (optional) — read Advanced Custom Fields data and field group definitions
* Settings page in wp-admin to enable/disable modules, view the connector endpoint, and manage the API key
* Modules automatically disable themselves if their dependency (WooCommerce/ACF) is not active

= Available tools =

**WP Core** (always available): `search_posts`, `get_post`, `list_post_types`, `list_terms`, `get_site_info`

**WooCommerce** (requires WooCommerce active): `woo_get_products`, `woo_get_orders`, `woo_get_revenue`, `woo_low_stock`

**ACF** (requires Advanced Custom Fields active): `acf_get_fields`, `acf_list_field_groups`, `acf_search_by_field`

== Installation ==

1. Upload the `wp-mcp-server` folder to `/wp-content/plugins/`
2. Activate the plugin through the "Plugins" screen in wp-admin
3. Go to Settings → MCP Server
4. Enable the modules you want (WP Core is on by default; WooCommerce/ACF only appear once those plugins are active)
5. Copy the endpoint URL and add it as a connector in Claude

== Frequently Asked Questions ==

= How do I connect this site to Claude? =

In wp-admin, go to Settings → MCP Server and copy the endpoint URL. In Claude, go to Settings → Connectors → Add custom connector, paste the URL, and click Connect. You'll be asked to log in and approve access on behalf of your WordPress user — this uses OAuth 2.0 with PKCE, so no client secret needs to be typed in by hand.

= Claude shows a connection error when adding the connector =

Use the manual fallback on the settings page: in Claude, click "Add an OAuth Client ID" and paste the Client ID/Client Secret shown under Settings → MCP Server.

= Does this work without WooCommerce or ACF? =

Yes. The WP Core module works out of the box. The WooCommerce and ACF modules only appear and activate when their respective plugin is installed and active; if you deactivate one later, it's automatically removed from the enabled modules list.

= Is HTTPS required? =

Yes. HTTPS is required for OAuth redirects and for Claude to trust the connector.

== Screenshots ==

1. Settings page showing the connector endpoint and OAuth setup instructions
2. Module selection (WP Core, WooCommerce, ACF)

== Changelog ==

= 1.3.0 =
* Added: `woo_get_orders` can filter by `product_id` and return line items with their options/add-ons (`include_items`); `status` accepts several values or `any`.
* Fixed: `date_from` + `date_to` together now form a range.

= 1.2.1 =
* Changed: OAuth discovery now works without the web server serving `/.well-known/` (issuer and metadata live under `/wp-json/mcp/v1/oauth`).

= 1.2.0 =
* Added: OAuth Protected Resource Metadata (RFC 9728) at `/.well-known/oauth-protected-resource`, and `resource_metadata` in the 401 `WWW-Authenticate` header, as required by current MCP clients.
* Added: refresh tokens (rotating, 30 days) so connections no longer drop after one hour; `ping` method.
* Changed: protocol version negotiation on `initialize`; notifications answered with an empty `202`; GET/DELETE on the endpoint return `405`; discovery documents also served at path-aware URLs.

= 1.1.9 =
* Fixed: `woo_get_orders` could fatal when `wc_get_orders()` returned a `WC_Order_Refund` object (which doesn't implement the billing/customer methods used to build each row). The loop now skips any result that isn't an instance of `WC_Order`.

= 1.1.8 =
* Reverted: the unauthenticated auth bypass for `initialize`/`notifications/initialized` (introduced in 1.1.5) has been removed — every JSON-RPC method on the MCP endpoint now requires authentication again, with no exceptions.
* Reverted: the temporary `[MCP Headers Debug]` full-header dump on auth failure (1.1.7) has been removed.
* Reverted: the `[MCP Auth]` log entry is back to its original simple shape (`header`, `result`), dropping the `http_method`/`rpc_method`/`bypassed` fields added during the ChatGPT debugging work.
* All other OAuth/logging fixes from 1.1.1–1.1.4 (Basic Auth client credentials, client_id-from-code fallback, token expiry, the WWW-Authenticate header, and the Clear logs fix) are unaffected and remain in place.

= 1.1.7 =
* Added: temporary diagnostic logging that dumps every HTTP request header when authentication fails, to help identify exactly what unauthenticated MCP clients (e.g. ChatGPT) are sending. Only logs on auth failure, so normal request logs stay unaffected.

= 1.1.6 =
* Fixed: the MCP endpoint's auth check now reads the JSON-RPC method via the request's already-parsed body instead of re-reading the raw input stream, so the initialize-handshake bypass added in 1.1.5 reliably detects the method instead of silently failing to on some server configurations.
* Changed: the internal auth log now also records the HTTP method (GET/POST/OPTIONS/etc.) of every request, to help diagnose clients that arrive without a body or without credentials.

= 1.1.5 =
* Changed: the MCP endpoint no longer requires authentication for the `initialize` and `notifications/initialized` JSON-RPC methods, so clients can complete the protocol handshake before a token is available. All other methods (including `tools/list` and `tools/call`) still require authentication as before.

= 1.1.4 =
* Fixed: the "Clear logs" button left a blank page at admin-post.php instead of redirecting back to the settings page. The action hook was only being registered on admin_menu, which never fires for admin-post.php requests; it's now registered unconditionally at plugin bootstrap. The redirect query param is now `cleared` instead of `logs_cleared`.
* Fixed: OAuth token responses now return `token_type: "Bearer"` (capitalized) and a new `expires_in: 3600` field; issued access tokens now actually expire after 1 hour (previously they never expired) and expiry is enforced on every authenticated request.
* Fixed: 401 responses from the MCP endpoint now reliably include a `WWW-Authenticate: Bearer realm="WordPress MCP Server"` header (previously set via a raw header() call that could be sent before the real response object was finalized), so OAuth clients like ChatGPT know to retry with a Bearer token.

= 1.1.3 =
* Added diagnostic logging around OAuth token issuance: a log entry on successful token issuance (client_id, token prefix) and a log entry when the client_id-from-authorization-code fallback is used, to confirm that path is exercised as expected.

= 1.1.2 =
* Fixed: the OAuth token endpoint now also recovers client_id from the stored authorization code when a client (e.g. ChatGPT) sends neither client_id in the body nor via HTTP Basic Authentication, only code and code_verifier.

= 1.1.1 =
* Fixed: the OAuth token endpoint now also accepts client credentials sent via HTTP Basic Authentication (RFC 6749 §2.3.1), which some clients (e.g. ChatGPT) use instead of the request body. Body-supplied client_id (as sent by Claude.ai) still takes precedence.

= 1.1.0 =
* Added an internal debug logging system: a "Logs" section on the settings page with an on/off toggle, a viewer for the last 200 log entries, and a "Clear logs" action
* Logs are stored in the wp_mcp_logs option (max 500 entries, FIFO) and only recorded when debug logging is enabled

= 1.0.0 =
* MCP (Model Context Protocol) JSON-RPC 2.0 server exposed over the WordPress REST API
* OAuth 2.0 authorization with PKCE for Claude connector authentication, including Dynamic Client Registration and a discovery document
* Static API key as an alternative authentication method for direct/advanced API access
* WP Core module: search_posts, get_post, list_post_types, list_terms, get_site_info
* WooCommerce module (auto-enabled only when WooCommerce is active): woo_get_products, woo_get_orders, woo_get_revenue, woo_low_stock
* ACF module (auto-enabled only when Advanced Custom Fields is active): acf_get_fields, acf_list_field_groups, acf_search_by_field
* Settings page under Settings → MCP Server
* Automatic migration that removes a module from the enabled list if its dependency plugin is later deactivated
* Full internationalization support (wp-mcp-server text domain, .pot translation template)

== Upgrade Notice ==

= 1.3.0 =
Orders can now be listed per product with their add-on options. Customer names and emails are returned with more detail.

= 1.2.1 =
Fixes connector setup on hosts that block or reserve `/.well-known/`. Remove and re-add the connector after updating.

= 1.2.0 =
Required for current Claude connectors: adds OAuth protected-resource discovery, refresh tokens and MCP protocol negotiation. After updating, remove and re-add the connector.

= 1.1.9 =
Fixes a potential fatal in woo_get_orders when a WC_Order_Refund object appears in the results.

= 1.1.8 =
Reverts the initialize-handshake auth bypass and ChatGPT debug logging (1.1.5-1.1.7) — authentication is required on every method again.

= 1.1.7 =
Adds temporary header-dump diagnostics on auth failure to help debug unauthenticated MCP client requests.

= 1.1.6 =
Makes the initialize-handshake auth bypass (1.1.5) reliable and adds HTTP method to the internal auth log.

= 1.1.5 =
The MCP protocol handshake (initialize / notifications/initialized) no longer requires a token; all other methods still do.

= 1.1.4 =
Fixes the Clear logs button, adds real OAuth token expiry (1 hour), and ensures the WWW-Authenticate header is always sent on 401 responses.

= 1.1.3 =
Adds diagnostic logging for OAuth token issuance and the client_id fallback path (requires debug logging enabled).

= 1.1.2 =
Fixes OAuth token exchange for clients (e.g. ChatGPT) that omit client_id entirely.

= 1.1.1 =
Fixes OAuth token exchange for clients (e.g. ChatGPT) that send credentials via HTTP Basic Authentication.

= 1.1.0 =
Adds an internal debug logging system with a settings page toggle and log viewer.

= 1.0.0 =
Initial release.
