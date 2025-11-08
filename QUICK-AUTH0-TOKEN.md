# Quick Reference: Generate Auth0 Bearer Token

## TL;DR
Ask your AI assistant to generate an Auth0 token from your client credentials.

## Steps

### 1. Get Your Auth0 Credentials
- Log in to Auth0 Dashboard
- Go to Applications → Machine to Machine
- Copy: Domain, Client ID, Client Secret

### 2. Ask Your AI Assistant
```
Generate an Auth0 token using:
- Domain: [your-domain].auth0.com
- Client ID: [your-client-id]
- Client Secret: [your-client-secret]
```

### 3. Get Your Token
The assistant returns:
```json
{
  "access_token": "eyJhbGci...",
  "expires_in": 86400,
  "expires_at": "2025-11-09T02:48:57+00:00"
}
```

### 4. Use the Token
**Option A: 1-Click Setup**
1. Go to Settings → WP oOS → Auth0 Setup
2. Paste the token
3. Click "Auto-Configure"

**Option B: API Testing**
```bash
curl -H "Authorization: Bearer eyJhbGci..." \
  https://your-site.com/wp-json/mcp-ai/v1/assistants
```

## Requirements
- Administrator access (`manage_options` capability)
- Auth0 Machine-to-Machine application with API access

## Common Issues

| Issue | Solution |
|-------|----------|
| "Invalid credentials" | Check Client ID and Secret are correct |
| "Permission denied" | Need administrator access |
| "Token expired" | Generate a new token (they expire after ~24 hours) |
| "Invalid audience" | Use default or specify correct audience |

## Security Notes
✅ Client secrets are NOT stored
✅ Tokens are NOT cached
✅ Only administrators can use this tool

## Related Docs
- Full guide: [AUTH0-TOKEN-GENERATION.md](AUTH0-TOKEN-GENERATION.md)
- Tool reference: [docs/tool-reference.md](docs/tool-reference.md)
- Auth docs: [docs/mcp-server-authentication.md](docs/mcp-server-authentication.md)
