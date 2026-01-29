# Firefly III Integration: Setup Requirements - Visual Guide

## 🔴 IMPORTANT: Firefly III is NOT Built-In

This WordPress plugin **DOES NOT** include Firefly III. You must install it separately.

---

## What You're Getting

### ✅ THIS PLUGIN INCLUDES:

```
┌─────────────────────────────────────┐
│   WordPress NV oOS Plugin           │
│   (What you're installing now)      │
├─────────────────────────────────────┤
│                                     │
│  ✓ API Client                       │
│  ✓ 7 Integration Tools              │
│  ✓ Chart.js Visualizations          │
│  ✓ Settings Configuration           │
│                                     │
└─────────────────────────────────────┘
```

### ❌ THIS PLUGIN DOES NOT INCLUDE:

```
┌─────────────────────────────────────┐
│   Firefly III Application           │
│   (You must install separately)     │
├─────────────────────────────────────┤
│                                     │
│  ✗ Firefly III app itself           │
│  ✗ Database for Firefly III         │
│  ✗ Web server for Firefly III       │
│  ✗ Laravel framework                │
│  ✗ Transaction storage              │
│                                     │
└─────────────────────────────────────┘
```

---

## How They Work Together

```
┌──────────────────────────┐          ┌──────────────────────────┐
│   YOUR WORDPRESS SITE    │          │   YOUR FIREFLY III       │
│                          │          │   (Separate Server/Port) │
├──────────────────────────┤          ├──────────────────────────┤
│                          │          │                          │
│  • NV oOS Plugin         │          │  • Laravel App           │
│  • Firefly API Client    │◄────────►│  • MySQL Database        │
│  • Integration Tools     │  HTTPS   │  • Transactions          │
│  • Chart Visualizations  │  Token   │  • Accounts & Budgets    │
│                          │   Auth   │  • Categories & Bills    │
│                          │          │                          │
└──────────────────────────┘          └──────────────────────────┘
     Your WP Server                      Your Finance Server
    (This plugin talks to)              (You must install this)
```

---

## Installation Checklist

### □ Step 1: Install WordPress Plugin
**What**: NV oOS plugin with Firefly III integration  
**Where**: Your WordPress site  
**How**: Standard WordPress plugin installation  
**Time**: 5 minutes  

### □ Step 2: Install Firefly III (SEPARATE!)
**What**: Firefly III personal finance application  
**Where**: Separate server, Docker, or hosting  
**How**: Docker, self-hosted, or managed hosting  
**Time**: 30-45 minutes  

**Choose ONE option:**

**Option A: Docker** (Easiest)
```bash
docker run -d \
  --name firefly_iii \
  -p 8080:8080 \
  fireflyiii/core:latest
```
Access: `http://localhost:8080`

**Option B: Self-Hosted** (Full Control)
- Install PHP 8.1+, MySQL, web server
- Clone Firefly III repo
- Configure & deploy
- Access: `https://firefly.yourdomain.com`

**Option C: Managed Hosting** (No Setup)
- Find provider offering Firefly III
- Sign up & activate
- Access: Provided by host

### □ Step 3: Get API Token
**What**: Personal Access Token from Firefly III  
**Where**: Firefly III admin panel  
**How**: Options → Profile → OAuth → Create Token  
**Save**: Copy token immediately (shown only once!)

### □ Step 4: Connect WordPress to Firefly III
**What**: Enter API URL and token in WordPress  
**Where**: WordPress → Settings → NV oOS  
**How**: Paste API URL and token, save settings  
**Test**: Use AI assistant to query accounts

---

## Real-World Setup Scenarios

### Scenario 1: Same Server, Different Ports

```
Server: myserver.com

WordPress:    https://myserver.com          (Port 443)
Firefly III:  https://myserver.com:8080     (Port 8080)

Configuration in WordPress:
API URL: https://myserver.com:8080
```

### Scenario 2: Different Servers

```
Server 1: WordPress
  URL: https://myblog.com

Server 2: Firefly III  
  URL: https://finance.myserver.com

Configuration in WordPress:
API URL: https://finance.myserver.com
```

### Scenario 3: Docker on Same Server

```
Server: myserver.com

WordPress:    https://myserver.com          (normal install)
Firefly III:  http://localhost:8080         (Docker container)

Configuration in WordPress:
API URL: http://myserver.com:8080
or
API URL: http://localhost:8080
```

---

## Cost Comparison

### Option 1: Self-Hosted (Free but requires time)
- ✅ $0 - Both WordPress and Firefly III are free
- ⏱️ Time investment: 1-2 hours initial setup
- 🛠️ Maintenance: You handle updates, backups

### Option 2: Shared Server ($5-20/month)
- 💵 $5-20/month - Single VPS for both apps
- ⏱️ Time: 1-2 hours setup
- 🛠️ Medium maintenance

### Option 3: Managed WordPress + Docker Firefly ($10-30/month)
- 💵 $10-30/month - Managed WP + VPS for Firefly
- ⏱️ Time: 30 minutes setup
- 🛠️ Low maintenance

### Option 4: Both Managed ($20-50+/month)
- 💵 $20-50+/month - Managed hosting for both
- ⏱️ Time: 15 minutes setup
- 🛠️ Minimal maintenance

---

## Common Misunderstandings - CLARIFIED

### ❌ WRONG: "I'll install the WordPress plugin and have Firefly III"
### ✅ RIGHT: "I need to install Firefly III separately, then connect it"

### ❌ WRONG: "My transactions will be stored in WordPress"
### ✅ RIGHT: "Transactions stay in Firefly III, WordPress just queries them"

### ❌ WRONG: "I can use this without Firefly III"
### ✅ RIGHT: "This is an integration - Firefly III is required"

### ❌ WRONG: "The plugin will auto-install Firefly III"
### ✅ RIGHT: "I must manually install Firefly III first"

---

## Quick Decision Tree

```
Do you have Firefly III installed?
│
├─ NO → Install Firefly III first
│        │
│        ├─ Have Docker? → Use Docker installation (30 min)
│        ├─ Have VPS?    → Use self-hosted (60 min)
│        └─ Want easy?   → Use managed hosting (15 min)
│        
│        Then come back and configure WordPress
│
└─ YES → Great! Just configure WordPress
         │
         └─ Get token from Firefly III (5 min)
         └─ Enter in WordPress settings (2 min)
         └─ Test with AI assistant (3 min)
         └─ DONE! ✓
```

---

## What Happens After Setup

### When You Ask Your AI Assistant:

**Your Command**: "Show me my checking account balance"

**Behind the Scenes**:
1. AI assistant receives request
2. Calls `firefly_get_accounts` tool
3. WordPress sends HTTPS request to Firefly III API
4. Firefly III authenticates token
5. Firefly III returns account data (JSON)
6. WordPress formats for display
7. AI assistant shows you the balance

**Data Flow**:
```
You → AI Assistant → WordPress Plugin → Firefly III API → Database
                                          ↓
You ← AI Assistant ← WordPress Plugin ← JSON Response
```

---

## Getting Started Today

### 30-Minute Quick Start (Docker)

**Minute 0-15: Install Firefly III**
```bash
docker run -d --name firefly_iii -p 8080:8080 fireflyiii/core:latest
# Open http://localhost:8080 and complete setup wizard
```

**Minute 15-20: Configure Firefly III**
- Create admin account
- Add a test checking account
- Add 2-3 test transactions
- Generate Personal Access Token

**Minute 20-25: Configure WordPress**
- Go to Settings → NV oOS
- Enter API URL: `http://localhost:8080`
- Paste access token
- Save settings

**Minute 25-30: Test Integration**
- Ask AI: "Show me my Firefly III accounts"
- Ask AI: "What transactions do I have?"
- Ask AI: "Show me an expense chart"

**DONE!** You're up and running! 🎉

---

## Need Help?

**Setup Issues**: See [SETUP_GUIDE.md](./SETUP_GUIDE.md)  
**Connection Problems**: See [TROUBLESHOOTING.md](./TROUBLESHOOTING.md)  
**Usage Questions**: See [README.md](./README.md)  

**External Resources**:
- [Firefly III Docs](https://docs.firefly-iii.org/)
- [Docker Guide](https://docs.docker.com/get-started/)
- [Plugin Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)

---

## TL;DR - The Bottom Line

### Question: "Will Firefly III be built in?"

### Answer: **NO - You must install it separately**

**What you're getting**: WordPress plugin that **connects to** Firefly III  
**What you're NOT getting**: Firefly III application itself  

**Think of it like**: 
- WordPress = Your phone
- This plugin = Instagram app  
- Firefly III = Instagram's servers

The app (plugin) connects your phone (WordPress) to the servers (Firefly III), but you need all three parts!

---

**Last Updated**: January 2026  
**For**: NV oOS WordPress Plugin  
**Integration Type**: External API Connection
