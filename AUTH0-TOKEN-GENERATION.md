# Auth0 Bearer Token Generation - Usage Guide

## Overview

This PR adds the `generate_auth0_token` tool that enables administrators to generate Auth0 bearer tokens programmatically using OAuth 2.0 client credentials flow.

## Problem Solved

Previously, to use the Auth0 1-click setup wizard, users needed to:
1. Manually go through OAuth flow to get a bearer token
2. Copy the token from browser developer tools
3. Paste it into the wizard

Now, users can:
1. Use their Auth0 client ID and secret
2. Ask the AI assistant to generate a token
3. Use that token with the 1-click wizard or for API testing

## How to Use

### With AI Assistant

Simply ask your AI assistant:

```
Generate an Auth0 token using:
- Domain: example.us.auth0.com
- Client ID: YOUR_CLIENT_ID
- Client Secret: YOUR_CLIENT_SECRET
```

The assistant will use the `generate_auth0_token` tool and return the bearer token.

### Expected Response

```json
{
  "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 86400,
  "expires_at": "2025-11-09T02:48:57+00:00",
  "scope": "read:users read:user_idp_tokens"
}
```

### Use Cases

1. **1-Click Auth0 Setup**
   - Generate token with your credentials
   - Go to Settings → WP oOS → Auth0 Setup
   - Paste the token to auto-configure domain and audience

2. **API Testing**
   - Generate tokens for testing Auth0-protected endpoints
   - No need for manual OAuth flow during development

3. **Automated Workflows**
   - Script token generation in CI/CD pipelines
   - Programmatic access to Auth0 Management API

## Security Features

- ✅ Requires `manage_options` capability (administrator only)
- ✅ Client secrets are never stored
- ✅ Tokens are not cached by the plugin
- ✅ Input sanitization and validation
- ✅ Proper error handling with actionable messages
- ✅ Comprehensive test coverage

## Parameters

### Required
- `auth0_domain` - Your Auth0 tenant domain (e.g., `example.us.auth0.com`)
- `client_id` - Auth0 Management API Client ID
- `client_secret` - Auth0 Management API Client Secret

### Optional
- `audience` - API audience (defaults to `https://DOMAIN/api/v2/` for Management API)

## Where to Get Auth0 Credentials

1. Log in to your Auth0 dashboard
2. Go to Applications → Applications
3. Select or create a Machine-to-Machine application
4. Navigate to the Settings tab
5. Copy the **Domain**, **Client ID**, and **Client Secret**
6. Under APIs, authorize the application for the Management API
7. Grant required scopes (e.g., `read:users`, `read:user_idp_tokens`)

## Implementation Details

### Files Added
- `includes/tools/class-wp-mcp-ai-tool-generate-auth0-token.php` - Core tool implementation
- `tests/test-generate-auth0-token-tool.php` - Comprehensive test suite

### Files Modified
- `includes/class-wp-mcp-ai-tool-registry.php` - Tool registration
- `docs/tool-reference.md` - Tool documentation
- `docs/mcp-server-authentication.md` - Usage guide

### Code Quality
- ✅ Zero PHP syntax errors
- ✅ Follows WordPress coding standards
- ✅ Proper sanitization and validation
- ✅ Comprehensive inline documentation
- ✅ Test coverage for all scenarios (success, errors, edge cases)

## Testing

The test suite covers:
- Tool availability and metadata
- Authentication requirements
- Permission checks (manage_options capability)
- Parameter validation
- HTTP error handling
- Auth0 API rejection scenarios
- Successful token generation
- Custom audience parameter
- Default audience fallback

Run tests with:
```bash
vendor/bin/phpunit tests/test-generate-auth0-token-tool.php
```

## Related Documentation

- [Tool Reference](../docs/tool-reference.md#generate-auth0-token)
- [MCP Server Authentication](../docs/mcp-server-authentication.md#generating-auth0-bearer-tokens)
- [Auth0 1-Click Setup Summary](../AUTH0-1CLICK-SUMMARY.md)

## Example Workflow

1. **User asks assistant**: "I have Auth0 credentials, can you generate a bearer token for me?"
2. **User provides**: Domain, Client ID, Client Secret
3. **Assistant calls**: `generate_auth0_token` tool
4. **User receives**: Bearer token with expiration info
5. **User uses token**: For 1-click setup or API testing

## Error Handling

The tool provides clear error messages for common issues:
- Missing parameters
- Invalid credentials
- Network failures
- Auth0 API errors
- Expired tokens
- Invalid audience

All errors include remediation guidance to help users resolve issues quickly.
