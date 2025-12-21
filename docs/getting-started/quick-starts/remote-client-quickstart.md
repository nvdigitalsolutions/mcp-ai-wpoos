# Quick Start: Connect Claude Desktop to WordPress WP oOS

Get your WordPress WP oOS server connected to Claude Desktop in 5 minutes.

## Prerequisites

- WordPress site with WP oOS plugin installed and activated
- At least one published AI Assistant
- Claude Desktop installed on your computer

## Step 1: Generate Credential (2 minutes)

1. Log in to your WordPress admin
2. Go to **AI Assistants** → Select an assistant
3. Find **API Credentials** meta box (scroll down)
4. Click **Generate Credential**
5. **IMPORTANT**: Copy the token immediately - it looks like:
   ```
   cred_abc123.a1b2c3d4e5f6g7h8i9j0...
   ```
   You'll only see this once!

## Step 2: Configure Claude Desktop (2 minutes)

1. Find your Claude Desktop config file:
   - **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
   - **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
   - **Linux**: `~/.config/Claude/claude_desktop_config.json`

2. Open it in a text editor and add:

```json
{
  "mcpServers": {
    "my-wordpress": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "sse": true
    }
  }
}
```

3. Replace:
   - `your-site.com` with your actual WordPress site domain
   - `cred_xxxxx.SECRET` with the token you copied in Step 1

4. Save the file

## Step 3: Restart Claude Desktop (1 minute)

1. Quit Claude Desktop completely
2. Reopen Claude Desktop
3. Start a new conversation

## Step 4: Verify Connection

In Claude, type:
```
What WordPress assistants are available?
```

If connected successfully, Claude will:
- List your WordPress assistants
- Show available tools
- Be ready to interact with your WordPress data

## Common Issues

### "Server not found" or "Connection refused"

**Fix**: Check your URL
- Must be HTTPS (not HTTP)
- Must end with `/wp-json/mcp-ai/v1`
- Test in browser first: visit `https://your-site.com/wp-json/mcp-ai/v1/assistants`

### "Invalid token" or "401 Unauthorized"

**Fix**: Generate a new credential
- The token might have been copied incorrectly
- Include the entire token including `cred_` prefix
- Make sure there are no spaces or line breaks

### "Assistant not responding"

**Fix**: Check your WordPress
- Visit **Settings → WP oOS** in WordPress
- Verify OpenAI API key is configured
- Ensure the assistant is published (not draft)

## What's Next?

### Access More WordPress Features

Claude can now:
- Search your WordPress content
- Create and update posts
- Access WooCommerce data (if installed)
- Generate images and audio
- Execute custom tools

### Connect Multiple Assistants

Generate credentials for each assistant and add them to your config:

```json
{
  "mcpServers": {
    "editorial-assistant": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_editorial.SECRET1"
      },
      "sse": true
    },
    "support-assistant": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_support.SECRET2"
      },
      "sse": true
    }
  }
}
```

### Test Your Connection

Use the built-in test script to verify everything works:

```bash
./bin/test-remote-connection.sh \
  -u https://your-site.com/wp-json/mcp-ai/v1 \
  -t cred_xxxxx.SECRET
```

## Need More Help?

- **Full setup guide**: [docs/remote-client-setup.md](../installation-setup/remote-client-setup.md)
- **LM Studio setup**: See the [LM Studio section](../installation-setup/remote-client-setup.md#lm-studio-setup)
- **ChatGPT connector**: See the [ChatGPT section](../installation-setup/remote-client-setup.md#chatgpt-connector-setup) (requires Auth0)
- **Troubleshooting**: [Detailed troubleshooting guide](../installation-setup/remote-client-setup.md#troubleshooting)

## Security Reminders

- Each credential only works for the assistant it was generated from
- Revoke credentials you're not using (WordPress admin → Edit Assistant → API Credentials)
- Don't share credentials in public channels or version control
- Generate new credentials for each client/user for better security

---

**Ready to explore?** Start a conversation in Claude Desktop and ask it to help you manage your WordPress site!
