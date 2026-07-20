# WP MCP Server

WordPress plugin that turns any WordPress site into an MCP (Model Context Protocol) server, connectable to AI assistants like Claude as a custom connector.

## Features
- MCP JSON-RPC 2.0 endpoint
- OAuth 2.0 with PKCE for Claude connector authentication
- WP Core module: posts, pages, CPTs, taxonomies
- WooCommerce module: products, orders, revenue, stock
- ACF module: custom fields

## Requirements
- WordPress 6.0+
- PHP 8.0+
- HTTPS

## Installation
1. Upload the `wp-mcp-server` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Settings → MCP Server
4. Copy the endpoint URL and add it as a connector in Claude
