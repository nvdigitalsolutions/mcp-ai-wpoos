# 5-Minute Quick Start: From Zero to First Chat

**Last Updated:** December 29, 2025  
**Plugin Version:** 1.1.0  
**Time Required:** 5 minutes

---

## What You'll Do

In 5 minutes, you'll:
1. ✅ Install NV oOS
2. ✅ Add OpenAI API key
3. ✅ Create your first assistant
4. ✅ Send your first message
5. ✅ Add chat to a page

---

## Step 1: Install (60 seconds)

1. Go to **Plugins → Add New**
2. Search "NV Digital Open Operator System"
3. Click **Install Now** → **Activate**
4. Look for "NV oOS" in admin menu

---

## Step 2: Add API Key (60 seconds)

1. Get key: https://platform.openai.com/api-keys
2. Go to **Settings → NV oOS**
3. Paste key in "OpenAI API Key" field
4. Look for green ✅ checkmark

---

## Step 3: Create Assistant (90 seconds)

1. Go to **AI Assistants → Add New**
2. Title: "My First Assistant"
3. Keep defaults (gpt-4o-mini, temperature 0.7)
4. Click **Publish**
5. Note the ID in URL (e.g., `post=123`)

---

## Step 4: Test Chat (60 seconds)

1. Find "Test Chat" box (right sidebar)
2. Type: "Hello! Introduce yourself"
3. Press Enter
4. Watch response appear!

---

## Step 5: Add to Page (60 seconds)

1. Go to **Pages → Add New**
2. Add shortcode: `[mcp_ai_chat assistant="123"]`
   (Replace 123 with your assistant ID)
3. Click **Publish** → **View Page**
4. Chat with your assistant live!

---

## 🎉 Done!

You just:
- Installed NV oOS
- Configured OpenAI
- Created an assistant
- Chatted with AI
- Made it live

**Cost:** < $0.01

---

## What's Next?

### Quick Wins (5-10 min each)

**Add More Tools**
- **Settings → NV oOS → Tools**
- Enable image generation, web search
- [See all 159 tools →](../../reference/tools/README.md)

**Use Templates**
- **Professions → Browse**
- 182 pre-built experts
- One-click deploy

**Set Token Limits**
- **Settings → Token Manager**
- Free/Pro/Enterprise tiers
- [Token Guide →](../../features/performance/TOKEN_MANAGEMENT_GUIDE.md)

**Add Gemini**
- **Settings → AI Providers**
- 2M context window
- Free tier available

---

## Common Issues

**Chat not appearing?**
- Check assistant ID in shortcode
- Assistant published (not draft)?
- Clear browser cache

**No response?**
- Test API key: Settings → General → Test Connection
- Check internet connection
- Verify not rate limited at OpenAI

**High costs?**
- Use gpt-4o-mini (10x cheaper)
- Enable token limits
- Set usage alerts

---

## Useful Shortcodes

```
[mcp_ai_chat assistant="123"]

[mcp_ai_chat assistant="123" allow_guests="true"]

[mcp_ai_chat assistant="123" model="gpt-4o"]

[mcp_ai_professional_selector]
```

---

## Learn More

- **[Full Quick Reference](../../QUICK_REFERENCE.md)** - Common tasks
- **[Settings Dashboard Guide](../../guides/admin/SETTINGS_DASHBOARD_GUIDE.md)** - All 9 tabs explained
- **[Token Management Guide](../../features/performance/TOKEN_MANAGEMENT_GUIDE.md)** - Control costs
- **[Documentation Index](../../DOCUMENTATION_INDEX.md)** - 659 guides

---

## Need Help?

- **GitHub:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Docs:** [Complete Index](../../DOCUMENTATION_INDEX.md)
- **Troubleshooting:** [Common Issues](../../troubleshooting/)

---

**Congratulations!** You're now ready to explore NV oOS.

---

**Last Updated:** December 29, 2025  
**Version:** 1.1.0  
**Maintainer:** NV Digital Solutions
