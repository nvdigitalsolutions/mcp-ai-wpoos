# Firefly III Integration for NV oOS

**Integration Type**: External API Connection  
**Status**: ✅ Available (Base Plugin)  
**Firefly III Version**: 6.0+ (API v1)

---

## 🔑 Key Point: Firefly III is a Separate Application

**You MUST install Firefly III separately.** This WordPress plugin integration **connects to** your existing Firefly III instance via REST API. It does **NOT** include or bundle the Firefly III application.

```
┌─────────────────────┐   API    ┌──────────────────────┐
│  WordPress + NV oOS │ ◄─────►  │   Firefly III        │
│  (This Plugin)      │  HTTPS   │   (Separate Install) │
└─────────────────────┘          └──────────────────────┘
```

---

## What is Firefly III?

[Firefly III](https://www.firefly-iii.org/) is a free, open-source personal finance manager. It's a Laravel-based web application that helps you:
- Track expenses and income
- Manage budgets and categories
- Monitor savings goals
- Analyze spending patterns
- Import bank transactions

**License**: AGPL-3.0  
**Tech Stack**: Laravel (PHP), MySQL/PostgreSQL

---

## What This Integration Does

### ✅ What's Included

1. **7 Integration Tools**
   - 6 read tools (accounts, transactions, budgets, categories, bills)
   - 1 write tool (create transactions)
   - 1 visualization tool (expense charts)

2. **API Client** (`WP_MCP_AI_Firefly_Client`)
   - Authentication with Personal Access Tokens
   - Pagination support
   - Error handling
   - Settings or Remote Sites configuration

3. **Chart.js Visualizations**
   - Expense breakdown by category
   - Responsive, interactive charts
   - Currency-formatted tooltips

### ❌ What's NOT Included

- Firefly III application itself
- Database for Firefly III
- Web server for Firefly III
- Auto-installer for Firefly III
- Firefly III UI embedded in WordPress

---

## Installation Overview

### Three-Step Process

```
Step 1: Install Firefly III      (Separate, on your server)
   ↓
Step 2: Generate Access Token    (From Firefly III admin)
   ↓  
Step 3: Configure WordPress      (Enter API URL + Token)
```

### Step 1: Install Firefly III

You have several options:

**A. Docker (Easiest)**
```bash
docker run -d \
  --name=firefly_iii \
  -p 8080:8080 \
  -e APP_KEY=$(head /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 32) \
  -e DB_CONNECTION=sqlite \
  -v firefly_iii_data:/var/www/html/storage/database \
  fireflyiii/core:latest
```
Access at: `http://localhost:8080`

**B. Self-Hosted** (LAMP/LEMP Stack)
- Follow: https://docs.firefly-iii.org/how-to/firefly-iii/installation/self-managed/
- Requirements: PHP 8.1+, MySQL/PostgreSQL, Composer, Apache/Nginx

**C. Managed Hosting**
- Some providers offer Firefly III hosting
- Check Firefly III documentation for recommended hosts

### Step 2: Generate Access Token

1. Log into Firefly III
2. Go to **Options → Profile → OAuth**
3. Under "Personal Access Tokens", click **Create New Token**
4. Name it (e.g., "WordPress Integration")
5. Click **Create**
6. **COPY THE TOKEN** (shown only once!)

### Step 3: Configure WordPress

1. In WordPress admin: **Settings → NV oOS**
2. Find **Firefly III Integration** section
3. Enter:
   - **API URL**: `https://your-firefly-instance.com`
   - **Access Token**: (paste token from Step 2)
4. Click **Test Connection**
5. Save Settings

---

## Quick Test

After configuration, test with your AI assistant:

```
User: "Show me all my accounts from Firefly III"

AI Assistant: Uses firefly_get_accounts tool
Returns: List of checking, savings, credit card accounts
```

```
User: "Create a $25 expense for groceries"

AI Assistant: Uses firefly_create_transaction
Returns: "Created withdrawal of $25.00 in Groceries category"
```

---

## Available Tools

| Tool | Purpose | Type |
|------|---------|------|
| `firefly_get_accounts` | List all accounts | Read |
| `firefly_get_transactions` | Query transactions | Read |
| `firefly_get_budgets` | View budget data | Read |
| `firefly_get_categories` | List categories | Read |
| `firefly_get_bills` | View recurring bills | Read |
| `firefly_create_transaction` | Create deposit/withdrawal/transfer | Write |
| `firefly_chart_expenses` | Generate expense breakdown chart | Visualization |

---

## Configuration Options

### Method 1: Settings Page (Simple)

Best for: Single Firefly III instance

Location: **Settings → NV oOS → Firefly III Integration**

Fields:
- API URL
- Personal Access Token

### Method 2: Remote Sites (Advanced)

Best for: Multiple Firefly III instances, per-user configuration

Location: **NV oOS → Remote Sites → Add Connection**

Fields:
- Connection Name
- Connection Type: Firefly III
- API URL
- Access Token (encrypted)
- Enabled/Disabled toggle

Tools can specify `connection_id` parameter to use specific connection.

---

## Security

### Data Privacy
- ✅ No financial data stored in WordPress (queries Firefly III directly)
- ✅ Tokens stored encrypted in WordPress database
- ✅ HTTPS recommended for API calls
- ✅ WordPress capability checks required (`edit_posts` or `manage_options`)

### Access Control
- User-level authentication required
- Per-request capability validation
- Support for multisite with per-site configuration

---

## Documentation

- **[SETUP_GUIDE.md](./SETUP_GUIDE.md)** - Detailed installation instructions
- **[CONFIGURATION.md](./CONFIGURATION.md)** - All configuration options
- **[TROUBLESHOOTING.md](./TROUBLESHOOTING.md)** - Common issues & solutions
- **[EXAMPLES.md](./EXAMPLES.md)** - Usage examples and prompts

---

## Support & Resources

### Plugin Support
- [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- Plugin documentation

### Firefly III Resources
- [Official Website](https://www.firefly-iii.org/)
- [Documentation](https://docs.firefly-iii.org/)
- [API Docs](https://api-docs.firefly-iii.org/)
- [GitHub](https://github.com/firefly-iii/firefly-iii)

---

## FAQ

**Q: Can I use this without installing Firefly III?**  
A: No. Firefly III must be installed separately. This plugin only provides integration.

**Q: Can I run Firefly III on the same server as WordPress?**  
A: Yes, as long as they use different ports/domains or are properly configured.

**Q: Does this work with Firefly III cloud/hosted versions?**  
A: Yes, as long as you have API access and a Personal Access Token.

**Q: Will my financial data be stored in WordPress?**  
A: No. Queries go directly to your Firefly III instance. Only the API token is stored in WordPress.

**Q: Can multiple WordPress users access the same Firefly III?**  
A: Yes, they'll all use the same token. For per-user access, use Remote Sites with user-specific connections.

---

**Last Updated**: January 2026  
**Plugin Version**: Compatible with NV oOS 1.0+  
**Firefly III Version**: Tested with v6.1.x
