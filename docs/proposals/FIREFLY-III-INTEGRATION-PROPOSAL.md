# Firefly III Personal Finance Integration - Comprehensive Proposal

**Last Updated:** January 29, 2026  
**Status:** 📋 PROPOSAL - Awaiting Review and Approval  
**Recommendation:** Integrate via REST API with OAuth 2.0  
**Estimated Effort:** 2-3 months for complete integration

---

## Executive Summary

Proposal to integrate **Firefly III** personal finance management capabilities into **NV oOS (Open Operator System)** WordPress plugin, enabling AI-powered financial insights, budget tracking, expense analysis, and automated financial reporting through natural language interactions.

**Key Recommendation:** Integrate with self-hosted or cloud-hosted Firefly III instances using their REST API v2 with OAuth 2.0 authentication.

**Value Proposition:** Enable WordPress users to leverage AI assistants for comprehensive personal finance management, automated expense tracking, budget monitoring, financial goal tracking, and intelligent financial insights—all through natural language conversations.

---

## Quick Status

| Approach | Status | Effort | Recommendation |
|----------|--------|--------|----------------|
| **Firefly III API Integration** | ✅ RECOMMENDED | 2-3 months | Proven, secure, feature-rich |
| **Native Finance Plugin** | ❌ NOT RECOMMENDED | 6-12 months | Too complex, regulatory concerns |
| **Third-Party API (Mint/YNAB)** | ⚠️ ALTERNATIVE | 3-4 months | Proprietary, subscription costs |

**Recommendation: Firefly III Integration** ✅

---

## What is Firefly III?

**Firefly III** is a free, open-source personal finance manager that runs on your own server. It helps users track expenses, income, budgets, and bills with powerful reporting and analytics.

### Key Features
- 📊 **Transaction Management**: Track income, expenses, and transfers
- 💰 **Budget Tracking**: Set and monitor budgets by category
- 📈 **Financial Reports**: Detailed analytics and visualizations
- 🏦 **Multi-Account Support**: Bank accounts, credit cards, cash, savings
- 🔄 **Recurring Transactions**: Automate bills and regular income
- 📱 **API Access**: Comprehensive REST API v2
- 🔐 **Self-Hosted**: Full control over financial data
- 🆓 **Open Source**: Free to use and modify

### Why Firefly III?

1. **Privacy-First**: Self-hosted solution means financial data stays under user control
2. **Open Source**: Transparent, auditable codebase (MIT License)
3. **Comprehensive API**: Well-documented REST API with OAuth 2.0
4. **Active Development**: Regular updates and strong community
5. **WordPress Alignment**: Similar PHP ecosystem and self-hosted philosophy
6. **No Vendor Lock-in**: Users own their data
7. **International Support**: Multi-currency and multi-language

---

## Integration Architecture

### High-Level Overview

```
WordPress + NV oOS Plugin
    ↓ (OAuth 2.0)
Firefly III REST API v2
    ↓
Firefly III Server (Self-hosted or Cloud)
    ↓
User's Financial Database (MySQL/PostgreSQL)
```

### Integration Components

```
includes/integrations/
├── class-wp-mcp-ai-firefly-oauth-handler.php      # OAuth 2.0 authentication
├── class-wp-mcp-ai-firefly-client.php             # API client wrapper
└── firefly-integration-init.php                    # Initialization

includes/tools/ (Pro Tools - loaded when Firefly III connected)
├── class-wp-mcp-ai-tool-firefly-get-transactions.php
├── class-wp-mcp-ai-tool-firefly-create-transaction.php
├── class-wp-mcp-ai-tool-firefly-get-budgets.php
├── class-wp-mcp-ai-tool-firefly-get-accounts.php
├── class-wp-mcp-ai-tool-firefly-get-categories.php
├── class-wp-mcp-ai-tool-firefly-get-bills.php
├── class-wp-mcp-ai-tool-firefly-get-reports.php
├── class-wp-mcp-ai-tool-firefly-search-transactions.php
├── class-wp-mcp-ai-tool-firefly-analyze-spending.php
└── class-wp-mcp-ai-tool-firefly-budget-insights.php
```

---

## Firefly III API Capabilities

### Available Endpoints

Firefly III REST API v2 provides comprehensive access:

#### 1. **Transactions** (`/api/v1/transactions`)
- List transactions with filtering (date, type, account, category)
- Create, update, delete transactions
- Attach metadata and tags
- Support for withdrawals, deposits, transfers

#### 2. **Accounts** (`/api/v1/accounts`)
- List all accounts (asset, expense, revenue, liability)
- Get account balance and details
- Track account history
- Multiple currency support

#### 3. **Budgets** (`/api/v1/budgets`)
- List budgets and limits
- Track budget spending vs. limits
- Get budget insights by period
- Auto-budget calculations

#### 4. **Categories** (`/api/v1/categories`)
- List transaction categories
- Get spending by category
- Category-based analytics

#### 5. **Bills** (`/api/v1/bills`)
- Track recurring bills
- Monitor payment status
- Predict upcoming expenses

#### 6. **Reports** (`/api/v1/insight`)
- Income vs. expense reports
- Category breakdowns
- Account balances over time
- Budget performance

#### 7. **Rules & Tags** (`/api/v1/rules`, `/api/v1/tags`)
- Automated transaction categorization
- Tag-based organization
- Custom rule execution

#### 8. **Currencies** (`/api/v1/currencies`)
- Multi-currency support
- Exchange rate handling
- Default currency configuration

---

## Proposed AI Tools (10 Core Tools)

### Tool 1: `firefly_get_transactions`
**Description:** Retrieve transactions with filtering and pagination

**Parameters:**
```json
{
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "account_id": 123,
  "category": "Groceries",
  "type": "withdrawal|deposit|transfer",
  "limit": 50
}
```

**Returns:** Array of transactions with amount, description, category, date, account

**Use Case:** "Show me all grocery expenses in January"

---

### Tool 2: `firefly_create_transaction`
**Description:** Create new transaction (expense, income, or transfer)

**Parameters:**
```json
{
  "type": "withdrawal",
  "date": "2026-01-29",
  "amount": "45.99",
  "description": "Grocery shopping at Safeway",
  "source_account_id": 1,
  "destination_account_id": 2,
  "category": "Groceries",
  "tags": ["weekly shopping", "food"],
  "notes": "Weekly grocery run"
}
```

**Returns:** Created transaction details with ID

**Use Case:** "Add an expense of $45.99 for groceries today"

---

### Tool 3: `firefly_get_budgets`
**Description:** Get budget information and spending analysis

**Parameters:**
```json
{
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "budget_id": 5
}
```

**Returns:** Budget limits, spent amounts, remaining, percentage used

**Use Case:** "How much of my grocery budget is left this month?"

---

### Tool 4: `firefly_get_accounts`
**Description:** List all accounts with balances

**Parameters:**
```json
{
  "type": "asset|expense|revenue|liability",
  "include_balance": true
}
```

**Returns:** Array of accounts with current balances

**Use Case:** "What's my current bank account balance?"

---

### Tool 5: `firefly_get_categories`
**Description:** List categories with spending totals

**Parameters:**
```json
{
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "include_spending": true
}
```

**Returns:** Categories with total spending per category

**Use Case:** "What categories did I spend the most on this month?"

---

### Tool 6: `firefly_get_bills`
**Description:** Get recurring bills and payment status

**Parameters:**
```json
{
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "status": "paid|unpaid|all"
}
```

**Returns:** Bills with amounts, due dates, payment status

**Use Case:** "What bills are due this month?"

---

### Tool 7: `firefly_get_reports`
**Description:** Generate financial reports and insights

**Parameters:**
```json
{
  "report_type": "income-expense|budget|category|account",
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "accounts": [1, 2, 3]
}
```

**Returns:** Comprehensive report data with summaries

**Use Case:** "Give me a summary of my finances for January"

---

### Tool 8: `firefly_search_transactions`
**Description:** Search transactions by keyword or criteria

**Parameters:**
```json
{
  "query": "amazon",
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "limit": 20
}
```

**Returns:** Matching transactions

**Use Case:** "Find all Amazon purchases this year"

---

### Tool 9: `firefly_analyze_spending`
**Description:** AI-powered spending pattern analysis

**Parameters:**
```json
{
  "start_date": "2026-01-01",
  "end_date": "2026-01-31",
  "analysis_type": "trends|anomalies|recommendations"
}
```

**Returns:** Analyzed spending patterns, insights, recommendations

**Use Case:** "Analyze my spending patterns and suggest where I can save"

---

### Tool 10: `firefly_budget_insights`
**Description:** Get budget performance and recommendations

**Parameters:**
```json
{
  "period": "current_month|current_year",
  "budget_ids": [1, 2, 3]
}
```

**Returns:** Budget health, overspending alerts, savings opportunities

**Use Case:** "Am I on track with my budgets this month?"

---

## Implementation Plan

### Phase 1: OAuth & API Client (2-3 weeks)

**Tasks:**
1. Create Firefly III OAuth 2.0 handler class
2. Implement Personal Access Token (PAT) support as alternative
3. Build REST API client wrapper with error handling
4. Add connection testing and validation
5. Create settings page in WordPress admin
6. Implement secure token storage

**Deliverables:**
- `WP_MCP_AI_Firefly_OAuth_Handler` class
- `WP_MCP_AI_Firefly_Client` class
- Admin settings UI
- Connection test utility

**Acceptance Criteria:**
- ✅ User can connect WordPress to Firefly III instance
- ✅ OAuth flow works for cloud and self-hosted instances
- ✅ PAT authentication works as fallback
- ✅ Tokens stored securely with encryption
- ✅ Connection status visible in admin

---

### Phase 2: Core Transaction Tools (2-3 weeks)

**Tasks:**
1. Implement `firefly_get_transactions` tool
2. Implement `firefly_create_transaction` tool
3. Implement `firefly_search_transactions` tool
4. Add transaction validation and error handling
5. Create transaction formatting utilities
6. Add audit logging for financial operations

**Deliverables:**
- 3 transaction management tools
- Transaction data validation
- Comprehensive error messages
- Audit trail logging

**Acceptance Criteria:**
- ✅ AI can retrieve user transactions with filters
- ✅ AI can create new transactions on user request
- ✅ AI can search transaction history
- ✅ All financial operations logged for audit
- ✅ Error messages are user-friendly

---

### Phase 3: Budget & Account Tools (2-3 weeks)

**Tasks:**
1. Implement `firefly_get_budgets` tool
2. Implement `firefly_get_accounts` tool
3. Implement `firefly_get_categories` tool
4. Implement `firefly_get_bills` tool
5. Add budget analysis utilities
6. Create multi-currency support

**Deliverables:**
- 4 financial tracking tools
- Budget calculation utilities
- Multi-currency handling
- Account balance formatting

**Acceptance Criteria:**
- ✅ AI can retrieve budget information
- ✅ AI can check account balances
- ✅ AI can analyze spending by category
- ✅ AI can track bill payments
- ✅ Multi-currency transactions handled correctly

---

### Phase 4: Reports & Analytics (2-3 weeks)

**Tasks:**
1. Implement `firefly_get_reports` tool
2. Implement `firefly_analyze_spending` tool
3. Implement `firefly_budget_insights` tool
4. Create data visualization helpers
5. Add trend analysis algorithms
6. Build recommendation engine

**Deliverables:**
- 3 analytics and reporting tools
- Spending pattern analysis
- Budget health scoring
- Financial recommendations

**Acceptance Criteria:**
- ✅ AI can generate comprehensive financial reports
- ✅ AI can identify spending trends and anomalies
- ✅ AI can provide budget recommendations
- ✅ Reports include visual data summaries
- ✅ Insights are actionable and relevant

---

### Phase 5: Documentation & Testing (1-2 weeks)

**Tasks:**
1. Write user documentation
2. Create setup and configuration guide
3. Document all tool parameters and examples
4. Write security best practices guide
5. Create troubleshooting guide
6. Develop pre-built assistant templates
7. Comprehensive testing (unit, integration, security)

**Deliverables:**
- User documentation
- Admin/setup guide
- Security guidelines
- Assistant templates
- Test suite

**Acceptance Criteria:**
- ✅ Complete documentation for users and admins
- ✅ Pre-built "Personal Finance Assistant" template
- ✅ All security requirements documented
- ✅ 90%+ test coverage for critical paths
- ✅ Troubleshooting guide covers common issues

---

## Security Considerations

### Authentication & Authorization

1. **OAuth 2.0 Flow** ✅
   - Standard authorization code flow
   - PKCE (Proof Key for Code Exchange) for enhanced security
   - State parameter for CSRF protection
   - Token refresh handling

2. **Personal Access Tokens** ✅
   - Alternative to OAuth for self-hosted instances
   - Encrypted storage in WordPress database
   - Token rotation capabilities
   - Expiration monitoring

3. **WordPress Capabilities** ✅
   - `manage_own_finances` - View own financial data
   - `manage_finances` - Manage all financial operations (admin)
   - `edit_transactions` - Create/edit transactions
   - Capability checks on every tool execution

### Data Protection

1. **Encryption at Rest** ✅
   - All tokens encrypted before database storage
   - Use WordPress's encryption utilities
   - Separate encryption keys per environment

2. **Encryption in Transit** ✅
   - HTTPS required for all API communications
   - SSL/TLS certificate validation
   - No financial data in logs or error messages

3. **Data Minimization** ✅
   - WordPress stores only connection tokens
   - No transaction data cached in WordPress
   - Temporary data cleared after AI response
   - No financial data in browser localStorage

### Access Control

1. **User Isolation** ✅
   - Each WordPress user connects own Firefly III account
   - No cross-user data access
   - Admin cannot view other users' financial data by default
   - Audit logs for all financial operations

2. **API Rate Limiting** ✅
   - Respect Firefly III rate limits
   - Implement exponential backoff
   - Cache appropriate data (budgets, categories)
   - Alert on quota exhaustion

3. **Input Validation** ✅
   - Validate all transaction amounts and dates
   - Sanitize user inputs before API calls
   - Check account IDs and category existence
   - Prevent SQL injection and XSS

### Compliance & Privacy

1. **GDPR Compliance** ✅
   - Data processing agreement requirements
   - Right to data deletion (disconnect Firefly III)
   - Data portability via Firefly III exports
   - Privacy policy updates

2. **Financial Regulations** ⚠️
   - NOT a financial institution (no PCI DSS required)
   - No payment processing (read/organize only)
   - Users responsible for their own compliance
   - Disclaimer in documentation

3. **Audit Logging** ✅
   - Log all financial tool usage
   - Track transaction creations and modifications
   - Monitor API errors and failures
   - Retention policy (90 days default)

### Best Practices

1. **Secure by Default**
   - HTTPS required (enforced at connection)
   - Tokens never logged or displayed
   - Error messages don't leak sensitive data
   - Secure defaults in configuration

2. **Principle of Least Privilege**
   - Request minimum OAuth scopes needed
   - Read-only access by default
   - Write permissions explicitly granted
   - Time-limited token access

3. **Monitoring & Alerts**
   - Connection failure notifications
   - Unusual spending pattern alerts
   - API error rate monitoring
   - Security event logging

---

## Use Cases & Examples

### Use Case 1: Daily Expense Tracking
**User:** "Add today's grocery shopping expense of $87.43 to my checking account"

**AI Workflow:**
1. Uses `firefly_get_accounts` to find checking account
2. Uses `firefly_create_transaction` with:
   - Type: withdrawal
   - Amount: $87.43
   - Category: Groceries (auto-detected or prompted)
   - Date: Today
   - Source: Checking account
3. Confirms transaction created

**Response:** "I've added a $87.43 grocery expense to your checking account for today. Your remaining grocery budget for January is $112.57."

---

### Use Case 2: Budget Check
**User:** "Am I staying within my budget this month?"

**AI Workflow:**
1. Uses `firefly_get_budgets` for current month
2. Uses `firefly_budget_insights` for analysis
3. Compares spent vs. limits across categories

**Response:** "You're doing well! Here's your budget status for January:
- Groceries: $387/500 (77%) ✅
- Entertainment: $250/200 (125%) ⚠️ Over by $50
- Transportation: $145/300 (48%) ✅
- Dining Out: $180/150 (120%) ⚠️ Over by $30

Overall, you're slightly over budget by $80. Consider reducing entertainment and dining out spending."

---

### Use Case 3: Spending Analysis
**User:** "Where did most of my money go last month?"

**AI Workflow:**
1. Uses `firefly_get_categories` for previous month
2. Uses `firefly_analyze_spending` for insights
3. Generates ranked list and percentage breakdown

**Response:** "In December, your top spending categories were:
1. Rent: $1,800 (45%)
2. Groceries: $520 (13%)
3. Transportation: $380 (9.5%)
4. Utilities: $320 (8%)
5. Entertainment: $280 (7%)

Compared to November, you spent 15% more on groceries and 23% less on entertainment. Your fixed costs (rent + utilities) represent 53% of total spending."

---

### Use Case 4: Bill Reminder
**User:** "What bills are due this week?"

**AI Workflow:**
1. Uses `firefly_get_bills` filtered for next 7 days
2. Checks payment status
3. Sorts by due date

**Response:** "You have 3 bills due this week:
- Electric Bill: $142.50 due Feb 1st (tomorrow) ⚠️ Unpaid
- Internet: $79.99 due Feb 3rd ✅ Paid
- Credit Card: $387.24 due Feb 5th ⚠️ Unpaid

Total unpaid: $529.74. Would you like me to mark these as paid once you've processed them?"

---

### Use Case 5: Financial Goal Tracking
**User:** "How much have I saved this year toward my vacation fund?"

**AI Workflow:**
1. Uses `firefly_get_accounts` to find vacation savings account
2. Uses `firefly_get_transactions` to calculate deposits
3. Compares to goal (if set in Firefly III)

**Response:** "Your vacation fund currently has $2,847.50. You've saved $1,420 so far this year through 8 deposits. At your current pace of ~$237/month, you'll reach your $5,000 goal by October. To hit the goal by July (vacation time), you'd need to save $539/month."

---

### Use Case 6: Transaction Search
**User:** "Find all Starbucks purchases in the last 3 months"

**AI Workflow:**
1. Uses `firefly_search_transactions` with query "Starbucks"
2. Filters last 90 days
3. Calculates total and frequency

**Response:** "You made 23 Starbucks purchases in the last 3 months totaling $127.85:
- January: 8 purchases ($46.20)
- December: 9 purchases ($52.15)
- November: 6 purchases ($29.50)

Average per visit: $5.56. That's roughly 2 visits per week."

---

### Use Case 7: Income vs. Expense Summary
**User:** "Give me a financial summary for January"

**AI Workflow:**
1. Uses `firefly_get_reports` with type "income-expense"
2. Calculates net income
3. Identifies trends

**Response:** "January 2026 Financial Summary:

**Income:** $4,200
- Salary: $3,800
- Freelance: $400

**Expenses:** $3,847
- Fixed: $2,420 (rent, utilities, insurance)
- Variable: $1,427 (groceries, dining, entertainment)

**Net Savings:** +$353 (8.4% savings rate)

This is better than December (+$124) but below your 15% savings goal. Consider reducing variable expenses by $280/month to hit your target."

---

### Use Case 8: Anomaly Detection
**User:** "Have I had any unusual expenses lately?"

**AI Workflow:**
1. Uses `firefly_analyze_spending` with type "anomalies"
2. Compares to historical patterns
3. Flags outliers

**Response:** "I found 2 unusual expenses in the last 30 days:
1. $450 car repair on Jan 15th (Transportation) - 3x your normal
2. $225 at electronics store on Jan 22nd (Shopping) - Unusual category for you

Both were one-time expenses. Excluding these, your spending is typical for January."

---

### Use Case 9: Category Insights
**User:** "Am I spending too much on eating out?"

**AI Workflow:**
1. Uses `firefly_get_categories` for "Dining Out"
2. Compares to budget and historical data
3. Calculates trends

**Response:** "Your dining out spending:
- This month: $285
- Budget: $200
- Over budget by: $85 (42%)

Compared to last 6 months:
- Average: $215/month
- This month is 32% higher than average
- You're eating out 18 times this month vs. 12 usually

Recommendation: Reduce dining out by 6 meals to stay within budget. That would save ~$90."

---

### Use Case 10: Multi-Account Overview
**User:** "What's my total net worth?"

**AI Workflow:**
1. Uses `firefly_get_accounts` for all account types
2. Calculates total assets
3. Subtracts total liabilities
4. Handles multiple currencies

**Response:** "Net Worth Summary (as of Jan 29, 2026):

**Assets:**
- Checking: $3,247.50
- Savings: $12,450.00
- Vacation Fund: $2,847.50
- Investment Account: $8,234.75
**Total Assets:** $26,779.75

**Liabilities:**
- Credit Card: $1,287.42
- Car Loan: $8,450.00
**Total Liabilities:** $9,737.42

**Net Worth:** $17,042.33 (+$1,127 since last month)"

---

## WordPress Admin Configuration

### Settings Page: Settings → NV oOS → Integrations → Firefly III

#### Connection Settings
```
☐ Enable Firefly III Integration

Firefly III Instance URL: _________________________________
Example: https://firefly.example.com

Authentication Method:
○ OAuth 2.0 (Recommended)
○ Personal Access Token

[If OAuth selected:]
OAuth Client ID: _________________________________
OAuth Client Secret: _________________________________

[If PAT selected:]
Personal Access Token: _________________________________
[Generate Token in Firefly III Settings → Profile → OAuth]

Connection Status: ○ Not Connected / ✅ Connected
[Test Connection] [Disconnect]

Last Sync: Jan 29, 2026 at 3:45 PM
API Version: v2.0.14
```

#### Tool Settings
```
☑ Enable Transaction Creation (Allow AI to create transactions)
☑ Enable Transaction Search
☑ Enable Budget Analysis
☑ Enable Spending Insights
☐ Enable Automatic Expense Categorization (Pro Feature)

Cache Duration: [15 minutes ▼]
Rate Limit: [60 requests/minute ▼]

☑ Enable Audit Logging
Audit Log Retention: [90 days ▼]
```

#### Privacy & Security
```
☑ Encrypt tokens at rest
☑ Require HTTPS for API communication
☑ Enable two-factor confirmation for transaction creation
☑ Log all financial tool usage

Data Retention:
○ Don't cache financial data (Most Secure)
○ Cache for performance (15 minutes)
○ Custom: [_____] minutes

User Permissions:
☑ Allow users to connect their own Firefly III accounts
☐ Admin can view all users' financial summaries
☑ Require WordPress capability: [manage_own_finances ▼]
```

---

## Pre-Built Assistant Templates

### Template 1: Personal Finance Assistant
**Name:** "My Finance Manager"  
**Description:** "Your personal AI financial advisor for budget tracking, expense analysis, and financial insights"

**System Prompt:**
```
You are a helpful personal finance assistant with access to the user's 
Firefly III financial data. Help them track expenses, monitor budgets, 
analyze spending patterns, and achieve their financial goals. Always:
- Be encouraging and non-judgmental about spending habits
- Provide actionable recommendations
- Use clear, simple language for financial concepts
- Ask for confirmation before creating transactions
- Respect privacy and never share financial details
- Present data visually when possible (tables, percentages)
```

**Tools Enabled:**
- firefly_get_transactions
- firefly_create_transaction
- firefly_get_budgets
- firefly_get_accounts
- firefly_get_categories
- firefly_get_bills
- firefly_analyze_spending
- firefly_budget_insights

---

### Template 2: Budget Coach
**Name:** "Budget Accountability Coach"  
**Description:** "Strict but supportive AI coach to help you stay within budget"

**System Prompt:**
```
You are a direct, honest budget coach. Your role is to help users 
stay on track with their financial goals through accountability. Be:
- Firm but supportive
- Data-driven in your feedback
- Proactive in identifying overspending
- Creative in suggesting cost-cutting measures
- Celebratory when goals are met
Challenge users constructively when they overspend and celebrate 
their financial wins.
```

**Tools Enabled:**
- firefly_get_budgets
- firefly_budget_insights
- firefly_get_categories
- firefly_analyze_spending

---

### Template 3: Expense Tracker
**Name:** "Quick Expense Logger"  
**Description:** "Fast expense entry through conversation"

**System Prompt:**
```
You are a quick expense logging assistant. Users will tell you about 
purchases, and you'll log them in Firefly III. Be:
- Fast and efficient
- Intelligent about categorization
- Helpful in clarifying details (date, amount, category)
- Proactive in confirming before saving
Keep conversations brief and focused on capturing expenses accurately.
```

**Tools Enabled:**
- firefly_create_transaction
- firefly_get_accounts
- firefly_get_categories

---

## API Reference

### Firefly III REST API v2 Endpoints

Base URL: `https://[firefly-instance]/api/v2/`

#### Authentication Headers
```http
Authorization: Bearer {access_token}
Accept: application/json
Content-Type: application/json
```

#### Key Endpoints Used

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/transactions` | GET | List transactions |
| `/transactions` | POST | Create transaction |
| `/transactions/{id}` | GET | Get transaction details |
| `/transactions/{id}` | PUT | Update transaction |
| `/transactions/{id}` | DELETE | Delete transaction |
| `/accounts` | GET | List accounts |
| `/accounts/{id}` | GET | Get account balance |
| `/budgets` | GET | List budgets |
| `/budgets/{id}` | GET | Get budget details |
| `/categories` | GET | List categories |
| `/bills` | GET | List bills |
| `/insight/income/revenue` | GET | Income report |
| `/insight/expense/expense` | GET | Expense report |
| `/search/transactions` | GET | Search transactions |

---

## Cost & Effort Analysis

### Firefly III Integration (Recommended) ✅
- **Development:** 2-3 months × 2 developers = $40k-$75k
- **Firefly III Hosting:** 
  - Self-hosted: $5-$20/month (VPS)
  - Managed: $10-$50/month
- **Maintenance:** $25k-$45k/year
- **Total First Year:** $45k-$95k

### Native Finance Plugin (Not Recommended) ❌
- **Development:** 6-12 months × 3-4 developers = $300k-$600k
- **Compliance:** $100k-$250k (legal, audits)
- **Infrastructure:** $500-$2000/month
- **Maintenance:** $150k-$300k/year
- **Total First Year:** $450k-$1M+
- **Regulatory Risk:** High

### Third-Party API (YNAB/Mint) ⚠️
- **Development:** 3-4 months × 2 developers = $50k-$100k
- **API Subscription:** $50-$100/month per user
- **Maintenance:** $30k-$50k/year
- **Total First Year:** $80k-$150k
- **Ongoing per-user costs:** High
- **Data Privacy:** Lower (third-party storage)

**Recommendation: Firefly III Integration** - Best balance of cost, features, privacy, and control

---

## Comparison: Firefly III vs. Alternatives

| Feature | Firefly III ✅ | QuickBooks | Mint | YNAB |
|---------|---------------|------------|------|------|
| **Cost** | Free (OSS) | $30-$180/mo | Free | $14.99/mo |
| **Self-Hosted** | Yes | No | No | No |
| **API Access** | Full REST API | Limited | No public API | REST API |
| **Data Privacy** | Full control | Cloud | Cloud | Cloud |
| **Personal Finance** | Excellent | Business-focused | Good | Excellent |
| **Multi-Currency** | Yes | Yes | Limited | Limited |
| **Open Source** | Yes (MIT) | No | No | No |
| **Setup Complexity** | Medium | Low | Low | Low |
| **WordPress Fit** | Excellent | Good | Poor | Good |

**Verdict:** Firefly III is the best fit for NV oOS due to privacy, cost, API quality, and philosophical alignment.

---

## Technical Requirements

### Minimum Requirements

#### Firefly III Instance
- Firefly III v6.0.0 or higher
- HTTPS enabled (required)
- API access enabled
- OAuth 2.0 configured (or PAT available)

#### WordPress Environment
- WordPress 6.0+
- PHP 7.4+
- NV oOS Plugin 1.1.0+
- SSL certificate (HTTPS)
- MySQL 5.7+ or PostgreSQL 10+

#### Server Requirements
- PHP extensions: curl, json, openssl
- WordPress REST API enabled
- Outbound HTTPS connections allowed
- Adequate PHP memory (256MB+ recommended)

### Recommended Setup

#### Production Environment
- Firefly III on separate server/subdomain
- Dedicated database for Firefly III
- Regular backups (daily recommended)
- Monitoring and alerting
- Rate limiting configured
- Security headers enabled

---

## Limitations & Considerations

### Known Limitations

1. **Self-Hosted Requirement**
   - Users must host their own Firefly III instance OR use hosted service
   - Requires technical knowledge for self-hosting
   - Alternative: Provide Firefly III hosting recommendations

2. **API Rate Limits**
   - Default: 60 requests/minute per token
   - Heavy usage may hit limits
   - Mitigation: Intelligent caching, request batching

3. **No Real-Time Bank Sync**
   - Firefly III doesn't auto-sync with banks (by design, for privacy)
   - Users must manually import transactions or use third-party tools
   - Not a limitation for our integration (Firefly III API limitation)

4. **Single User per Connection**
   - Each WordPress user connects to one Firefly III instance
   - No shared family/household financial management by default
   - Workaround: Multiple WordPress users can connect to same Firefly III

5. **No Historical Data Migration**
   - Integration doesn't migrate existing financial data into Firefly III
   - Users responsible for importing historical data if needed
   - Firefly III has import tools (CSV, bank exports)

### Considerations

1. **Setup Complexity**
   - Requires users to have Firefly III already set up
   - May be barrier for non-technical users
   - Solution: Detailed setup guide, video tutorials, recommended hosts

2. **Multi-Currency Edge Cases**
   - Complex currency conversion scenarios
   - Exchange rate updates
   - Solution: Rely on Firefly III's currency handling

3. **Backup Responsibility**
   - Financial data backup is user's responsibility
   - No backup service in WordPress plugin
   - Recommendation: Document backup best practices

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| API breaking changes | Medium | High | Version pinning, monitoring, rapid updates |
| Security breach | Low | Critical | Encryption, audit logs, security reviews |
| User adoption low | Medium | Medium | Marketing, tutorials, pre-built templates |
| Performance issues | Low | Medium | Caching, rate limiting, optimization |
| Data loss | Low | Critical | User backup education, connection testing |
| Regulatory compliance | Low | High | Clear disclaimers, privacy policy updates |

---

## Testing Strategy

### Unit Tests
- OAuth flow components
- API client methods
- Tool parameter validation
- Error handling
- Token encryption/decryption
- Data formatting utilities

### Integration Tests
- End-to-end OAuth flow
- API communication
- Tool execution with mock Firefly III
- Error scenarios (API down, invalid tokens)
- Multi-user isolation
- Rate limiting

### Security Tests
- SQL injection attempts
- XSS attempts
- CSRF protection
- Token leakage checks
- Capability bypass attempts
- Audit log verification

### User Acceptance Tests
- Setup wizard flow
- Transaction creation via AI
- Budget checking workflow
- Report generation
- Error message clarity
- Assistant template functionality

### Performance Tests
- Response time under load
- Cache effectiveness
- API rate limit handling
- Large dataset handling (1000+ transactions)
- Concurrent user requests

---

## Documentation Deliverables

### User Documentation
1. **Getting Started Guide** (`docs/integrations/firefly-iii-setup.md`)
   - Firefly III installation options
   - OAuth setup walkthrough
   - First assistant configuration
   - Example conversations

2. **Tool Reference** (`docs/tools/firefly-iii-tools.md`)
   - All 10 tools documented
   - Parameter details
   - Example requests/responses
   - Error handling

3. **Use Cases & Examples** (`docs/examples/firefly-iii-use-cases.md`)
   - 20+ real-world scenarios
   - Complete conversation flows
   - Assistant configuration tips
   - Advanced usage patterns

4. **Troubleshooting Guide** (`docs/troubleshooting/firefly-iii.md`)
   - Common connection issues
   - API error codes explained
   - Permission problems
   - Performance optimization

### Admin Documentation
1. **Installation & Configuration** (`docs/admin/firefly-iii-admin.md`)
   - Server requirements
   - WordPress configuration
   - Security hardening
   - Monitoring setup

2. **Security Best Practices** (`docs/security/firefly-iii-security.md`)
   - Token management
   - User permissions
   - Audit logging
   - Compliance considerations

3. **Backup & Recovery** (`docs/admin/firefly-iii-backup.md`)
   - Backup strategies
   - Disaster recovery
   - Token rotation
   - Connection migration

### Developer Documentation
1. **Integration Architecture** (this document)
2. **API Client Reference** (`docs/development/firefly-iii-api.md`)
3. **Creating Custom Financial Tools** (`docs/development/custom-finance-tools.md`)

---

## Roadmap & Future Enhancements

### Phase 6: Advanced Features (Future)
- [ ] Automatic expense categorization using AI
- [ ] Receipt OCR and automatic expense entry
- [ ] Financial goal progress tracking
- [ ] Predictive budget recommendations
- [ ] Integration with bank import services
- [ ] Custom financial dashboard widgets
- [ ] Multi-user household finance management
- [ ] Investment tracking integration
- [ ] Tax document preparation assistance
- [ ] Financial literacy educational content

### Phase 7: Ecosystem Integration (Future)
- [ ] WooCommerce sales → Firefly III income automation
- [ ] Gravity Forms → Expense logging integration
- [ ] Elementor widgets for financial summaries
- [ ] BuddyPress/PeepSo finance accountability groups
- [ ] MemberPress subscription → Recurring transaction sync
- [ ] WP Job Manager → Freelance income tracking

---

## Stakeholder Decision Points

### Decision Required: Approve Firefly III Integration?

**Option 1: YES - Proceed with Full Integration** ✅
- Timeline: 2-3 months
- Resources: 2 developers
- Budget: $40k-$75k development
- Deliverables: 10 tools, complete documentation, 3 assistant templates
- Market Differentiator: Yes - unique offering

**Option 2: PILOT - Limited Integration First** ⚠️
- Timeline: 4-6 weeks
- Resources: 1 developer
- Deliverables: 4 core tools (transactions, accounts, budgets, basic reports)
- Gather user feedback before full commitment
- Budget: $15k-$25k

**Option 3: NO - Defer for Now** ⏸️
- Revisit in Q3 2026
- Monitor user requests for financial features
- Research alternative approaches
- Focus resources elsewhere

**Option 4: ALTERNATIVE - Use QuickBooks Instead** 🔄
- Leverage existing QuickBooks OAuth integration
- More business-focused, less personal finance
- Higher per-user costs
- Different user demographic

---

## Success Metrics

### User Adoption
- **Target:** 25% of NV oOS Pro users connect Firefly III within 6 months
- **Measure:** Active Firefly III connections
- **Tracking:** Analytics on connection attempts, successful setups

### Tool Usage
- **Target:** Average 50 financial tool calls per user per month
- **Measure:** Tool execution logs
- **Tracking:** Most used tools, common workflows

### User Satisfaction
- **Target:** 4.5+ star rating for finance features
- **Measure:** User surveys, support ticket sentiment
- **Tracking:** NPS score, feature request volume

### Technical Performance
- **Target:** 95% uptime, <2s average response time
- **Measure:** Error rates, API latency
- **Tracking:** Application monitoring, error logs

### Business Impact
- **Target:** 15% increase in Pro subscriptions attributed to finance features
- **Measure:** Conversion tracking, user surveys
- **Tracking:** Subscription analytics, feature usage correlation

---

## FAQ

**Q: Why Firefly III instead of Mint or YNAB?**  
A: Firefly III is open-source, self-hosted, and free. It gives users full control over their financial data and has a comprehensive API. Mint has no public API, and YNAB requires subscriptions for every user.

**Q: Do users need to install Firefly III themselves?**  
A: Yes, or they can use a hosted Firefly III service. We'll provide setup guides and recommendations for Firefly III hosting providers.

**Q: What if Firefly III changes their API?**  
A: We'll monitor their releases and maintain compatibility. Firefly III has a stable v2 API with backward compatibility commitments.

**Q: Can multiple WordPress users share one Firefly III account?**  
A: Yes, technically possible but not recommended. Each user should have their own Firefly III instance for privacy and data isolation.

**Q: Is this PCI DSS compliant?**  
A: PCI DSS applies to payment processing. This integration doesn't process payments—it organizes financial data. Users are responsible for their own compliance needs.

**Q: What about mobile access?**  
A: WordPress is responsive, so users can access AI finance features on mobile. Firefly III also has its own mobile app for direct access.

**Q: Can AI automatically categorize expenses?**  
A: Yes, as a future enhancement. Initial version requires user confirmation for categorization. Future AI-powered auto-categorization is planned.

**Q: What happens if Firefly III server goes down?**  
A: Finance-related AI features would be unavailable until connection is restored. Other NV oOS features continue working normally.

**Q: Can this integrate with bank accounts directly?**  
A: No. Firefly III doesn't directly connect to banks (by design, for security/privacy). Users import transactions via Firefly III's own import tools.

**Q: Is this a Pro feature only?**  
A: Recommended as Pro feature due to complexity and target audience. Could offer limited version (3-4 tools) in base plugin.

---

## Conclusion & Recommendation

### Executive Summary

**Recommendation: ✅ PROCEED WITH FIREFLY III INTEGRATION**

The Firefly III integration represents a unique market opportunity for NV oOS:

### Why Firefly III?
- ✅ **Privacy-First:** Self-hosted, user controls data
- ✅ **Cost-Effective:** Open-source, no per-user licensing
- ✅ **Feature-Rich:** Comprehensive personal finance management
- ✅ **Strong API:** Well-documented REST API v2
- ✅ **WordPress Alignment:** Similar philosophy (open-source, self-hosted)
- ✅ **Market Gap:** Few WordPress plugins offer AI-powered finance insights
- ✅ **Differentiator:** Unique offering vs. competitors

### Business Value
- **Market Differentiation:** Only WordPress AI plugin with personal finance
- **User Retention:** High-value feature increases stickiness
- **Pro Upsell:** Strong incentive for Pro subscriptions
- **Use Case Expansion:** Opens B2C market segment
- **Competitive Advantage:** First-mover in AI + personal finance space

### Technical Feasibility
- **API Quality:** ★★★★★ (5/5) - Excellent documentation
- **Integration Complexity:** ★★★☆☆ (3/5) - Moderate
- **Maintenance Burden:** ★★☆☆☆ (2/5) - Low
- **Security Risk:** ★★☆☆☆ (2/5) - Manageable with proper implementation
- **Timeline:** ★★★★☆ (4/5) - Achievable in 2-3 months

### ROI Projection
- **Development Investment:** $40k-$75k
- **Ongoing Maintenance:** $25k-$45k/year
- **Expected Pro Subscription Increase:** 15-20%
- **Break-Even:** 6-9 months
- **5-Year Value:** $250k-$500k (based on subscription growth)

### Next Steps

1. **Immediate (Week 1)**
   - ✅ Review and approve this proposal
   - ✅ Allocate development resources (2 developers)
   - ✅ Set up project tracking and milestones

2. **Short-Term (Weeks 2-4)**
   - ⏳ Set up Firefly III test instances
   - ⏳ Research OAuth implementation patterns
   - ⏳ Begin Phase 1: OAuth & API Client

3. **Medium-Term (Months 2-3)**
   - ⏳ Complete Phases 2-4: Tools implementation
   - ⏳ Beta testing with select users
   - ⏳ Documentation and assistant templates

4. **Launch (Month 3)**
   - ⏳ Public release as Pro feature
   - ⏳ Marketing campaign
   - ⏳ User education and support

---

**Document Version:** 1.0  
**Last Updated:** January 29, 2026  
**Author:** NV Digital Solutions  
**Status:** 📋 Proposal - Ready for Review

**Approval Signature Lines:**
- [ ] Product Owner: _________________________ Date: _______
- [ ] Technical Lead: ________________________ Date: _______
- [ ] Security Officer: ______________________ Date: _______
- [ ] Finance/Budget: ________________________ Date: _______

---

**Next Action:** Schedule stakeholder review meeting to discuss and approve proposal.

**Contact:** For questions about this proposal, contact the NV Digital Solutions development team.

**Related Documents:**
- [Bitwarden/Vaultwarden Integration Proposal](BITWARDEN-VAULTWARDEN-INTEGRATION-PROPOSAL.md) - Similar OAuth pattern
- [QuickBooks Integration](../integrations/quickbooks-integration-init.php) - Financial integration example
- [Google Site Kit Integration](../integrations/google-site-kit-integration.md) - Third-party API integration pattern

---

## Appendix A: Firefly III API Examples

### Example: List Transactions
```http
GET /api/v1/transactions?start=2026-01-01&end=2026-01-31&type=withdrawal
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": [
    {
      "type": "transactions",
      "id": "1",
      "attributes": {
        "created_at": "2026-01-15T10:30:00+00:00",
        "updated_at": "2026-01-15T10:30:00+00:00",
        "description": "Grocery shopping at Safeway",
        "date": "2026-01-15T00:00:00+00:00",
        "type": "withdrawal",
        "amount": "87.43",
        "currency_code": "USD",
        "source_name": "Checking Account",
        "destination_name": "Safeway",
        "category_name": "Groceries",
        "tags": ["weekly shopping", "food"]
      }
    }
  ]
}
```

### Example: Create Transaction
```http
POST /api/v1/transactions
Authorization: Bearer {token}
Content-Type: application/json

{
  "transactions": [
    {
      "type": "withdrawal",
      "date": "2026-01-29",
      "amount": "45.99",
      "description": "Coffee beans",
      "source_id": "1",
      "destination_name": "Local Coffee Shop",
      "category_name": "Groceries",
      "tags": ["coffee", "beverages"]
    }
  ]
}
```

---

## Appendix B: WordPress Capability Definitions

```php
// Custom capabilities for financial management
$capabilities = array(
    'manage_own_finances' => array(
        'description' => 'Can view and manage own financial data',
        'default_roles' => array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ),
    ),
    'manage_all_finances' => array(
        'description' => 'Can view all users financial data (admin only)',
        'default_roles' => array( 'administrator' ),
    ),
    'create_transactions' => array(
        'description' => 'Can create new financial transactions via AI',
        'default_roles' => array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ),
    ),
    'delete_transactions' => array(
        'description' => 'Can delete financial transactions (requires confirmation)',
        'default_roles' => array( 'author', 'editor', 'administrator' ),
    ),
);
```

---

## Appendix C: Error Code Reference

| Code | Error | Cause | Solution |
|------|-------|-------|----------|
| `firefly_001` | Connection Failed | Cannot reach Firefly III instance | Check URL, SSL certificate, firewall |
| `firefly_002` | Authentication Failed | Invalid token or expired | Reconnect OAuth or regenerate PAT |
| `firefly_003` | Invalid Account | Account ID doesn't exist | Refresh account list |
| `firefly_004` | Budget Not Found | Budget ID invalid | Check budget configuration |
| `firefly_005` | Rate Limit Exceeded | Too many API requests | Wait and retry with exponential backoff |
| `firefly_006` | Invalid Amount | Amount format incorrect | Use decimal format (e.g., 45.99) |
| `firefly_007` | Missing Category | Category required but not provided | Specify category or create new one |
| `firefly_008` | Permission Denied | User lacks WordPress capability | Check user role and capabilities |

---

**End of Proposal**
