# WP MCP Server

WP MCP Server turns any WordPress site into an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server, so it can be connected to AI assistants such as Claude as a custom connector. Once connected, the assistant can query and act on your site's content, WooCommerce store data, and ACF custom fields through a small set of well-defined tools — all guarded behind OAuth 2.0 authentication.

## Features

- **MCP JSON-RPC 2.0 endpoint** exposed over the WordPress REST API (`/wp-json/mcp/v1/request`)
- **OAuth 2.0 with PKCE** for secure, per-user authorization of AI assistant connectors (plus Dynamic Client Registration and an optional static API key for direct/advanced access)
- **WP Core module** — search and read posts, pages, and custom post types; list taxonomies and site info
- **WooCommerce module** (optional) — products, orders, revenue summaries, low-stock alerts
- **ACF module** (optional) — read Advanced Custom Fields data and field group definitions
- **Settings page** in wp-admin to enable/disable modules, view the connector endpoint, and manage the API key
- Modules automatically disable themselves if their dependency (WooCommerce/ACF) is not active, and a background check keeps the enabled-modules list in sync if a dependency is later deactivated

## Requirements

- WordPress 6.0+
- PHP 8.0+
- HTTPS (required for OAuth redirects and for Claude to trust the connector)
- WooCommerce (optional — only needed for the WooCommerce module)
- Advanced Custom Fields (optional — only needed for the ACF module)

## Installation

1. Upload the `wp-mcp-server` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen in wp-admin
3. Go to **Settings → MCP Server**
4. Enable the modules you want (WP Core is on by default; WooCommerce/ACF only appear once those plugins are active)
5. Copy the endpoint URL and add it as a connector in Claude (see below)

## Connecting to Claude (OAuth flow)

1. In wp-admin, go to **Settings → MCP Server** and copy the endpoint URL shown at the top of the page.
2. In Claude, go to **Settings → Connectors → Add custom connector** and paste the URL.
3. Click **Connect**. Claude will open your site's login screen (if you're not already logged in) and then an authorization page asking you to approve access on behalf of your WordPress user.
4. Approve access. Claude exchanges the authorization code for an access token via PKCE — no client secret ever needs to be typed in by hand.

This flow relies on WordPress's [OAuth 2.0 Dynamic Client Registration](https://www.rfc-editor.org/rfc/rfc7591), backed by:

- `/.well-known/oauth-authorization-server` — discovery document
- `/wp-json/mcp/v1/oauth/register` — dynamic client registration
- `/authorize` — the authorization/consent screen (must be logged in as a user with `manage_options`)
- `/wp-json/mcp/v1/oauth/token` — token exchange (authorization_code + PKCE)

**If Claude reports a connection error**, the settings page shows a fallback: a pre-generated **Client ID** and **Client Secret** you can paste manually into Claude's "Add an OAuth Client ID" option.

**Advanced / direct API access**: the settings page also exposes a static **API Key** for calling the endpoint directly (e.g. `Authorization: Bearer <key>`, `X-API-Key: <key>`, or `?api_key=<key>`) without going through the OAuth flow. This is meant for scripts/testing, not for the Claude connector itself.

## Available tools

### WP Core (always available)

| Tool | Description |
|---|---|
| `search_posts` | Search posts, pages, or custom post types by keyword |
| `get_post` | Get the full content of a post or page by ID |
| `list_post_types` | List all public post types available on the site |
| `list_terms` | List terms of a taxonomy (categories, tags, or custom taxonomies) |
| `get_site_info` | Return basic information about the site (name, URL, WP version, timezone, etc.) |

### WooCommerce (requires WooCommerce active)

| Tool | Description |
|---|---|
| `woo_get_products` | List or search products |
| `woo_get_orders` | List orders with optional status/date filters |
| `woo_get_revenue` | Revenue summary for a date range |
| `woo_low_stock` | Products with low or no stock |

### ACF (requires Advanced Custom Fields active)

| Tool | Description |
|---|---|
| `acf_get_fields` | Get all ACF fields for a specific post |
| `acf_list_field_groups` | List all ACF field groups and their associated post types |
| `acf_search_by_field` | Search posts by an ACF field value |

## Troubleshooting

**Claude shows "connection error" when adding the connector**
Use the manual fallback on the settings page: in Claude, click "Add an OAuth Client ID" and paste the Client ID/Client Secret shown under **Settings → MCP Server**.

**"invalid_client" or "invalid_redirect_uri" errors during authorization**
The client registered with your site doesn't match what Claude is sending. Try removing and re-adding the connector in Claude so it re-registers a fresh client, or use the manual Client ID/Secret fallback.

**Redirected to login in a loop**
Authorization requires an active WordPress session for a user with the `manage_options` capability. Make sure you're logging in as an administrator, and that your site is served over HTTPS (mixed HTTP/HTTPS can break the login redirect).

**A module (WooCommerce/ACF) doesn't show up on the settings page**
The module only appears once its dependency plugin is installed and active. If you deactivate WooCommerce or ACF after enabling their module, WP MCP Server automatically removes them from the enabled list on the next request.

**Tool calls fail or return unexpected errors**
Each tool call is wrapped in error handling: a failure returns a tool result with `isError: true` and the underlying exception message, instead of crashing the request.

**Endpoint returns 401 Unauthorized**
Confirm the connector is still authorized in Claude (tokens aren't revoked automatically, but regenerating the API key on the settings page invalidates the old key immediately for direct API access).
