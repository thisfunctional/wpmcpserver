=== WP MCP Server ===
Contributors: limpinho
Donate link: https://thisfunctional.pt
Tags: mcp, ai, claude, rest-api, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.2
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

= 1.1.2 =
Fixes OAuth token exchange for clients (e.g. ChatGPT) that omit client_id entirely.

= 1.1.1 =
Fixes OAuth token exchange for clients (e.g. ChatGPT) that send credentials via HTTP Basic Authentication.

= 1.1.0 =
Adds an internal debug logging system with a settings page toggle and log viewer.

= 1.0.0 =
Initial release.
