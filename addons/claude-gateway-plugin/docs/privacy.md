# Privacy Policy — NV oOS Gateway Claude Code Plugin

*Last updated: 2026-11-04*

The NV oOS Gateway plugin for Claude Code ("the plugin") is published by
NV Digital Solutions and connects Claude Code to the public NV oOS MCP
Gateway at `https://mcp.nvoos.pro`.

## What the plugin collects

The plugin itself collects **nothing**. It consists of a manifest, two
Markdown files, and one image. It runs no code on your machine and performs
no tracking, analytics, or telemetry of its own.

## What is transmitted

- When you configure a Gateway API key, it is stored in your platform's
  secure credential store and sent only to `https://mcp.nvoos.pro` as an
  `Authorization` header, for the purpose of authenticating tool calls.
- Tool calls you ask Claude to make are sent to the gateway and forwarded
  to the bound NV oOS WordPress site. The gateway operator sees request
  metadata (key, tool, timestamps) for rate limiting and operational
  purposes, as described in the gateway's own documentation.
- If you choose the OAuth route instead, your OAuth tokens are managed by
  Claude Code and the authorization server (Auth0, `nvoos.us.auth0.com`).

## Third parties

- Auth0 (`nvoos.us.auth0.com`) issues and validates OAuth tokens when you
  use the OAuth flow.
- No other third-party service is contacted by the plugin.

## Changes

Updates to this policy are published in this repository with the plugin
release they accompany.

## Contact

Issues: https://github.com/nvdigitalsolutions/nvoos-claude-gateway-plugin/issues
