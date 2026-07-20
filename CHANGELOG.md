# Changelog

All notable changes to this project are documented in this file.

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
