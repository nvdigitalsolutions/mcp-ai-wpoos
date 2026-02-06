# 🚨 URGENT: Fix NPM Publishing EOTP Error

## Quick Fix (5 minutes)

If you're seeing this error when publishing to NPM:

```
npm error code EOTP
npm error This operation requires a one-time password from your authenticator.
```

**Follow these steps:**

### Step 1: Generate Automation Token (2 min)

1. Go to **https://www.npmjs.com/settings/tokens**
2. Click **"Generate New Token"**
3. **Select "Automation"** (NOT "Publish" or "Granular Access Token")
4. Name it: `GitHub Actions - mcp-ai-wpoos`
5. Click **"Generate Token"**
6. **COPY THE TOKEN** (shown only once!)

### Step 2: Update GitHub Secret (2 min)

1. Go to **https://github.com/nvdigitalsolutions/mcp-ai-wpoos/settings/secrets/actions**
2. Find `NPM_TOKEN` and click the **pencil icon** to edit
3. **Paste the new token**
4. Click **"Update secret"**

### Step 3: Re-run Workflow (1 min)

1. Go to **https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions**
2. Click the failed workflow run
3. Click **"Re-run failed jobs"**

## Why This Happens

Your NPM account has 2FA enabled (good!), but you're using a **"Publish" token** instead of an **"Automation" token**.

| Token Type | Requires OTP? | Works in CI/CD? |
|------------|---------------|-----------------|
| Automation | ❌ No         | ✅ Yes          |
| Publish    | ✅ Yes        | ❌ No           |

**Automation tokens** are designed for CI/CD and bypass OTP requirements safely.

## Need More Help?

See the full troubleshooting guide: [docs/npm-publishing-troubleshooting.md](docs/npm-publishing-troubleshooting.md)

---

**Still stuck?** Open an issue: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
