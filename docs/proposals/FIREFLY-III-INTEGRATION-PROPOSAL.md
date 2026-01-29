# Firefly III Personal Finance Integration - Proposal

**Status**: Proposal  
**Type**: External API Integration  
**Tier**: Base Plugin (with Pro Enhancement Path)  
**Estimated Effort**: Phase 1: 12-17 hours | Full Implementation: 40-60 hours

---

## Executive Summary

This proposal outlines the integration of [Firefly III](https://www.firefly-iii.org/), a free and open-source personal finance manager, with the NV oOS WordPress plugin. The integration will provide AI assistants with the ability to access financial data, create transactions, generate visualizations, and provide financial insights directly within WordPress.

**Key Points**:
- ✅ External API integration (Firefly III runs separately)
- ✅ 7 basic tools initially (expandable to 100+)
- ✅ Chart.js visualizations for financial data
- ✅ Future: Full integration with Financial Planner Toolkit (Pro)
- ✅ JetEngine CCT caching for performance (Pro)

---

## Background

### What is Firefly III?

Firefly III is a self-hosted personal finance manager built with Laravel (PHP). It provides:
- Multi-currency transaction tracking
- Budget management and categories
- Savings goals (piggy banks)
- Recurring transactions and bills
- Rules and automation
- Import from banks (CSV, API)
- Detailed financial reports

**License**: AGPL-3.0 (compatible with GPLv3)  
**Tech Stack**: Laravel 10+, PHP 8.1+, MySQL/PostgreSQL  
**API**: RESTful JSON:API v1.0

### Why Integrate with WordPress?

**User Benefits**:
1. **Unified Platform**: Manage finances alongside content management
2. **AI Assistant Access**: Query financial data conversationally
3. **Automated Insights**: AI-generated financial analysis
4. **Visual Dashboards**: Chart.js visualizations in WordPress
5. **Workflow Integration**: Connect finances with project management, e-commerce

**Technical Benefits**:
1. Proven external API integration pattern (follows Flowhub model)
2. No data duplication (WordPress queries Firefly III directly)
3. Scalable architecture (optional CCT caching in Pro)
4. Clean separation of concerns

---

## Proposed Architecture

### System Architecture

```
┌────────────────────────────────────────────────────────────┐
│                  WordPress + NV oOS Plugin                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Base Plugin                                         │  │
│  │  • WP_MCP_AI_Firefly_Client (API wrapper)          │  │
│  │  • 7 Integration Tools                              │  │
│  │  • Settings configuration                           │  │
│  │  • Remote Sites support                             │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                            │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Pro Addon (Future Enhancement)                      │  │
│  │  • 13 total tools (7 existing + 6 sync bridge)      │  │
│  │  • JetEngine CCT caching                            │  │
│  │  • Financial Planner Toolkit integration            │  │
│  │  • Advanced visualizations                          │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────┘
                          │
                          │ HTTPS REST API
                          │ Bearer Token Auth
                          ▼
┌────────────────────────────────────────────────────────────┐
│              Firefly III (Self-Hosted)                     │
│  • Laravel Application                                     │
│  • MySQL/PostgreSQL Database                               │
│  • Transaction & Account Data                              │
│  • Budget & Category Management                            │
└────────────────────────────────────────────────────────────┘
```

### Data Flow

**Read Operations** (Accounts, Transactions, Budgets):
```
User → AI Assistant → WordPress Tool → Firefly Client → HTTPS API Call
                                           ↓
User ← AI Assistant ← WordPress ← JSON Response ← Firefly III API
```

**Write Operations** (Create Transaction):
```
User → AI Assistant → WordPress Tool → Validation → Firefly Client
                                           ↓
                                    HTTPS POST Request
                                           ↓
                                    Firefly III API
                                           ↓
                                    Database Update
                                           ↓
User ← AI Assistant ← Confirmation ← JSON Response
```

**Chart Generation**:
```
User → AI Assistant → Chart Tool → Fetch Transactions → Aggregate Data
                                           ↓
                                    Generate Chart.js Config
                                           ↓
User ← AI Assistant ← HTML + Embedded Chart.js Visualization
```

---

## Proposed Features

### Phase 1: Core Integration (Base Plugin)

**1.1 API Client** - `WP_MCP_AI_Firefly_Client`
- HTTP wrapper for Firefly III REST API
- Bearer token authentication
- Pagination support (up to 100 records per call)
- Error handling and sanitization
- Support for both settings-based and Remote Sites configuration

**1.2 Financial Data Access Tools (6 Read Tools)**

| Tool Slug | Purpose | API Endpoint | Parameters |
|-----------|---------|--------------|------------|
| `firefly_get_accounts` | List accounts | `/api/v1/accounts` | `type`, `page`, `limit` |
| `firefly_get_transactions` | Query transactions | `/api/v1/transactions` | `type`, `start`, `end`, `page`, `limit` |
| `firefly_get_budgets` | View budgets | `/api/v1/budgets` | `start`, `end` |
| `firefly_get_categories` | List categories | `/api/v1/categories` | `page`, `limit` |
| `firefly_get_bills` | View bills | `/api/v1/bills` | `page`, `limit` |

**1.3 Transaction Management (1 Write Tool)**

| Tool Slug | Purpose | API Endpoint | Parameters |
|-----------|---------|--------------|------------|
| `firefly_create_transaction` | Create deposit/withdrawal/transfer | `/api/v1/transactions` | `type`, `amount`, `source`, `destination`, `category`, `budget`, `description` |

**1.4 Data Visualization (1 Chart Tool)**

| Tool Slug | Purpose | Output |
|-----------|---------|--------|
| `firefly_chart_expenses` | Expense breakdown by category | Chart.js pie/doughnut chart (HTML + JS) |

**Features**:
- Aggregates transactions by category
- Vibrant HSL color generation
- Currency-formatted tooltips
- Responsive canvas sizing
- Configurable date ranges
- Chart type options (pie/doughnut)

**1.5 Configuration**

**Settings Page** (`Settings → NV oOS`):
- API URL field
- Personal Access Token field (encrypted storage)
- Connection test button
- Enable/disable toggle

**Remote Sites** (Advanced):
- Multiple Firefly III instances
- Per-user connections
- Connection management UI

**1.6 Security & Capabilities**

- WordPress capability checks: `edit_posts` or `manage_options`
- Per-request authentication validation
- No sensitive data stored in WordPress (only API token)
- HTTPS recommended for API calls
- Multisite support with per-site configuration

### Phase 2: Pro Enhancement (Future)

**2.1 Tool Migration & Enhancement**

Move 7 base tools to Pro addon with enhancements:
- Prefix all tool slugs with `pro_`
- Add more parameters and filters
- Implement advanced error handling
- Add logging and analytics

**2.2 JetEngine CCT Storage Layer (4 CCTs)**

| CCT Slug | Purpose | Fields |
|----------|---------|--------|
| `firefly_transactions` | Cache transaction data | transaction_id, date, amount, type, source, destination, category, budget, tags, synced_at |
| `firefly_accounts` | Cache account data | account_id, name, type, balance, currency, iban, active, synced_at |
| `firefly_budgets` | Cache budget data | budget_id, name, amount, spent, start, end, synced_at |
| `firefly_categories` | Cache category data | category_id, name, spent, earned, synced_at |

**Benefits**:
- Local queryable cache (reduces API calls)
- JetEngine relations and queries
- WordPress search integration
- Performance optimization
- Offline access to recent data

**Sync Strategy**:
- Scheduled hourly sync via WP-Cron
- Manual sync trigger
- Delta sync (only new/updated records)
- One-way sync: Firefly III → WordPress (read-only cache)

**2.3 Sync Bridge Tools (6 New Tools)**

| Tool Slug | Purpose | Target |
|-----------|---------|--------|
| `pro_firefly_sync_to_budget` | Sync transactions to Budget Planner | Financial Planner Toolkit |
| `pro_firefly_sync_to_expenses` | Feed Expense Tracker | Financial Planner Toolkit |
| `pro_firefly_sync_to_networth` | Update Net Worth Calculator | Financial Planner Toolkit |
| `pro_firefly_sync_to_cashflow` | Feed Cash Flow Analyzer | Financial Planner Toolkit |
| `pro_firefly_import_categories` | Import as budget categories | Financial Planner Toolkit |
| `pro_firefly_auto_sync` | Scheduled automatic sync | WP-Cron hourly job |

**2.4 Financial Planner Toolkit Integration**

Combine Firefly III real data with Financial Planner calculators:
- Budget Planner uses actual transactions
- Expense Tracker auto-populated
- Net Worth Calculator auto-updated from accounts
- Cash Flow Analyzer fed with real data

**Integration Points**:
```
Financial Planner Toolkit (24 tools)
├── Budget Planner ◄────── firefly_sync_to_budget
├── Expense Tracker ◄────── firefly_sync_to_expenses
├── Net Worth Calculator ◄── firefly_sync_to_networth
└── Cash Flow Analyzer ◄─── firefly_sync_to_cashflow
```

**2.5 Enhanced Visualizations (4 Additional Chart Tools)**

| Tool Slug | Purpose | Chart Type |
|-----------|---------|------------|
| `pro_firefly_chart_budget_vs_actual` | Compare budget allocations vs actual spending | Bar chart (grouped) |
| `pro_firefly_chart_networth_trend` | Net worth over time | Line chart (time-series) |
| `pro_firefly_chart_income_expense` | Income vs expenses comparison | Bar chart (stacked) |
| `pro_firefly_chart_category_trends` | Spending trends by category | Line chart (multi-line) |

**2.6 WordPress Shortcodes**

```php
[firefly_expense_chart date_range="last_30_days" width="600" height="400"]
[firefly_budget_progress budget_id="123"]
[firefly_networth_trend months="12"]
[firefly_account_balance account="Checking"]
[firefly_recent_transactions limit="10" category="Groceries"]
```

**2.7 Elementor Widgets**

- Firefly Expense Chart Widget
- Firefly Budget Dashboard Widget
- Firefly Account Summary Widget
- Firefly Transaction List Widget

### Phase 3: Comprehensive Tool Coverage (Optional)

Expand to 50-100+ tools covering complete Firefly III API:

**Account Management** (8 tools):
- `pro_firefly_create_account`
- `pro_firefly_update_account`
- `pro_firefly_delete_account`
- `pro_firefly_get_account_transactions`
- `pro_firefly_get_account_piggybanks`
- `pro_firefly_get_account_attachments`

**Transaction Management** (10 tools):
- `pro_firefly_update_transaction`
- `pro_firefly_delete_transaction`
- `pro_firefly_attach_file`
- `pro_firefly_get_transaction_links`
- `pro_firefly_split_transaction`

**Budget Management** (6 tools):
- `pro_firefly_create_budget`
- `pro_firefly_update_budget`
- `pro_firefly_delete_budget`
- `pro_firefly_get_budget_limits`
- `pro_firefly_set_budget_limit`

**Category Management** (6 tools):
- `pro_firefly_create_category`
- `pro_firefly_update_category`
- `pro_firefly_delete_category`

**Piggy Banks** (8 tools):
- `pro_firefly_get_piggybanks`
- `pro_firefly_create_piggybank`
- `pro_firefly_update_piggybank`
- `pro_firefly_delete_piggybank`
- `pro_firefly_add_to_piggybank`
- `pro_firefly_remove_from_piggybank`

**Rules & Automation** (10 tools):
- `pro_firefly_get_rules`
- `pro_firefly_create_rule`
- `pro_firefly_update_rule`
- `pro_firefly_delete_rule`
- `pro_firefly_trigger_rule`
- `pro_firefly_test_rule`

**Reports & Analytics** (12 tools):
- `pro_firefly_get_insight_expense`
- `pro_firefly_get_insight_income`
- `pro_firefly_get_insight_transfers`
- `pro_firefly_get_insight_categories`
- `pro_firefly_get_insight_budgets`
- `pro_firefly_get_insight_tags`
- `pro_firefly_generate_report`

**Total**: 50-70 additional tools

---

## Implementation Plan

### Phase 1: Core Integration (Base Plugin)

**Duration**: 12-17 hours

**Tasks**:

1. **API Client Development** (3-4 hours)
   - [x] Create `WP_MCP_AI_Firefly_Client` class
   - [x] Implement authentication (Bearer token)
   - [x] Add pagination support
   - [x] Error handling and logging
   - [x] Settings and Remote Sites integration

2. **Tool Implementation** (6-8 hours)
   - [x] Implement 6 read tools
   - [x] Implement 1 write tool
   - [x] Implement 1 chart tool
   - [x] Add to tool registry
   - [x] Capability flags and security checks

3. **Settings Integration** (1-2 hours)
   - [ ] Add settings page UI
   - [ ] API URL and token fields
   - [ ] Connection test functionality
   - [ ] Enable/disable toggle

4. **Documentation** (2-3 hours)
   - [x] Integration overview (README.md)
   - [x] Setup guide (SETUP_GUIDE.md)
   - [x] Visual guide (VISUAL_SETUP_GUIDE.md)
   - [x] Cloudways guides (2 files)
   - [ ] API client documentation
   - [ ] Tool reference updates

5. **Testing** (1-2 hours)
   - [ ] Unit tests for API client
   - [ ] Integration tests for tools
   - [ ] Test with real Firefly III instance
   - [ ] Security audit

**Deliverables**:
- ✅ 7 functional tools in base plugin
- ✅ API client with full authentication
- ✅ Comprehensive setup documentation
- ⏳ Settings page integration
- ⏳ Test coverage

### Phase 2: Pro Enhancement

**Duration**: 18-24 hours

**Tasks**:

1. **Tool Migration** (4-6 hours)
   - [ ] Move 7 tools to Pro addon
   - [ ] Rename with `pro_` prefix
   - [ ] Update tool registry
   - [ ] Add Pro availability checks

2. **CCT Implementation** (6-8 hours)
   - [ ] Create 4 CCT classes
   - [ ] Define schemas and fields
   - [ ] Implement sync logic
   - [ ] Add cron jobs

3. **Sync Bridge Tools** (4-6 hours)
   - [ ] Implement 6 sync tools
   - [ ] Financial Planner integration
   - [ ] Test data flow

4. **Enhanced Visualizations** (4-6 hours)
   - [ ] 4 additional chart tools
   - [ ] Shortcode implementation
   - [ ] Elementor widgets

5. **Testing & Documentation** (2-3 hours)
   - [ ] Pro feature testing
   - [ ] Update documentation
   - [ ] Migration guide

**Deliverables**:
- 13 total Pro tools
- 4 CCTs with sync
- Financial Planner integration
- Enhanced visualizations

### Phase 3: Comprehensive Coverage (Optional)

**Duration**: 40-60 hours

**Tasks**:
- Implement 50-70 additional tools
- Cover all Firefly III API endpoints
- Advanced error handling
- Webhook support
- Extensive test coverage

---

## Technical Specifications

### API Client Specification

**Class**: `WP_MCP_AI_Firefly_Client`

**Methods**:
```php
public function __construct( $connection_id = null );
public function get_accounts( array $options = array() );
public function get_transactions( array $options = array() );
public function get_budgets( array $options = array() );
public function get_categories( array $options = array() );
public function get_bills( array $options = array() );
public function create_transaction( array $data );
protected function request( $method, $endpoint, $data = null, $options = array() );
protected function get_auth_header();
protected function handle_error( $response );
```

**Authentication**:
- Bearer token via `Authorization` header
- Token from settings or Remote Sites
- Encrypted storage in WordPress database

**Error Handling**:
- WP_Error returns for all failures
- HTTP status code validation
- JSON parsing error detection
- Sanitized error messages (no sensitive data exposure)

### Tool Specification

**Base Class**: Implements `WP_MCP_AI_Tool_Interface`

**Required Methods**:
```php
public function get_slug();
public function get_name();
public function get_description();
public function get_parameters_schema();
public function execute( array $arguments, array $context );
```

**Capability Flags**:
```php
public function get_capability_flags() {
    return array(
        'pro',                  // Pro tier (future)
        'external-api',         // Makes external API calls
        'requires-credentials', // Requires API token
        'requires-capability',  // Requires WordPress capabilities
        'read-only',            // Or 'write' for create_transaction
        'rate-limited',         // Subject to Firefly III rate limits
        'pii-data',             // Contains financial/personal data
        'cacheable',            // Results can be cached (read tools)
    );
}
```

### CCT Schema Specification (Pro)

**Transactions CCT**:
```php
array(
    'transaction_id' => 'text',      // Firefly UUID
    'date' => 'date',
    'amount' => 'number',
    'type' => 'select',              // withdrawal/deposit/transfer
    'source_account' => 'text',
    'destination_account' => 'text',
    'category_name' => 'text',
    'budget_name' => 'text',
    'description' => 'textarea',
    'tags' => 'text',                // JSON array
    'synced_at' => 'datetime',
)
```

---

## Installation & Setup

### Prerequisites

1. **Firefly III Installation** (separate from WordPress)
   - Docker, self-hosted, or managed hosting
   - Firefly III v6.0 or higher
   - Personal Access Token generated

2. **WordPress Requirements**
   - WordPress 6.0+
   - NV oOS plugin installed
   - PHP 7.4+
   - HTTPS recommended

### Installation Options

**Option 1: Connect to External Firefly III** (Recommended)
- Install Firefly III on separate VPS/Docker ($5-10/mo)
- Generate Personal Access Token
- Configure API URL + token in WordPress
- 30-60 minute setup time

**Option 2: Install on Cloudways**
- Create Custom PHP application
- Manually install Laravel app via SSH
- Configure MySQL and environment
- 60-90 minute setup time
- See: `CLOUDWAYS_LARAVEL_INSTALL.md`

**Option 3: Docker on Same Server**
- Run Firefly III container alongside WordPress
- Use different ports (8080 for Firefly)
- Configure reverse proxy if needed
- 30-45 minute setup time

### Configuration

**WordPress Settings** (`Settings → NV oOS`):
```
Firefly III Integration
├─ API URL: https://firefly.yourdomain.com
├─ Personal Access Token: ******************************
├─ Connection Test: [Test Connection Button]
└─ Enable Integration: [✓]
```

**Remote Sites** (Advanced):
- Multiple Firefly III instances
- Per-user connections
- Encrypted credential storage

---

## Security Considerations

### Data Privacy

- ✅ No financial data stored in WordPress (queries Firefly III directly)
- ✅ Only API token stored (encrypted)
- ✅ HTTPS recommended for all API calls
- ✅ No transaction data in WordPress database (unless Pro CCT cache enabled)

### Access Control

- WordPress capability checks: `edit_posts` or `manage_options`
- Per-request authentication validation
- Token-based API authentication
- Multisite per-site configuration

### Compliance

- **GDPR Compatible**: Financial data stays in user's Firefly III instance
- **PII Data**: Tools flagged with `pii-data` capability
- **Data Residency**: User controls where Firefly III is hosted
- **No Third-Party**: Direct connection, no intermediary services

### Security Best Practices

1. **Use HTTPS**: Both WordPress and Firefly III should use SSL
2. **Token Rotation**: Regenerate tokens every 90 days
3. **Firewall Rules**: Restrict Firefly III access to WordPress server IP
4. **Capability Checks**: Always verify user permissions
5. **Input Validation**: Sanitize all inputs before API calls
6. **Output Escaping**: Escape all outputs for display
7. **Rate Limiting**: Respect Firefly III API rate limits

---

## Performance Considerations

### Without CCT Caching (Base)

**Latency**:
- API call: 100-500ms (depends on network)
- Chart generation: +200-300ms (data aggregation)
- Total: 300-800ms per request

**Rate Limits**:
- Firefly III default: 60 requests/minute
- Mitigation: Implement request queuing in Pro

### With CCT Caching (Pro)

**Latency**:
- CCT query: 10-50ms (local database)
- Chart generation: +50-100ms (cached data)
- Total: 60-150ms per request

**Sync Overhead**:
- Hourly cron job
- Delta sync: Only new/updated records
- 1-5 minute sync time for typical datasets

---

## Testing Strategy

### Unit Tests

```php
// Test API client
class Test_Firefly_Client extends WP_UnitTestCase {
    public function test_authentication();
    public function test_get_accounts();
    public function test_error_handling();
    public function test_pagination();
}

// Test tools
class Test_Firefly_Tools extends WP_UnitTestCase {
    public function test_get_accounts_tool();
    public function test_create_transaction_tool();
    public function test_chart_expenses_tool();
    public function test_capability_checks();
}
```

### Integration Tests

- Test with real Firefly III instance (Docker test environment)
- Test all 7 tools end-to-end
- Test chart generation and rendering
- Test error scenarios (invalid token, network failures)

### Security Tests

- Test capability enforcement
- Test input sanitization
- Test output escaping
- Test token encryption
- Test HTTPS validation

---

## Documentation Deliverables

### User Documentation

- [x] **README.md** - Integration overview, tool inventory, FAQ
- [x] **SETUP_GUIDE.md** - Step-by-step installation (Docker/self-hosted/managed)
- [x] **VISUAL_SETUP_GUIDE.md** - Architecture diagrams, decision trees
- [x] **CLOUDWAYS_SETUP_GUIDE.md** - Cloudways connection instructions
- [x] **CLOUDWAYS_LARAVEL_INSTALL.md** - Installing Firefly III on Cloudways
- [ ] **TROUBLESHOOTING.md** - Common issues and solutions
- [ ] **CONFIGURATION.md** - All configuration options
- [ ] **EXAMPLES.md** - Usage examples and AI assistant prompts

### Developer Documentation

- [ ] **API_CLIENT.md** - API client developer reference
- [ ] **TOOL_DEVELOPMENT.md** - Creating new Firefly III tools
- [ ] **CCT_SCHEMA.md** - CCT field definitions and relations (Pro)
- [ ] **SYNC_ARCHITECTURE.md** - Sync system design (Pro)

### Integration Documentation

- [ ] Update main tool reference with Firefly III tools
- [ ] Add to external integrations documentation
- [ ] Update Pro addon feature list (Phase 2)

---

## Risks & Mitigation

### Technical Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Firefly III API changes | High | Low | Version pinning, API version checks |
| Rate limiting issues | Medium | Medium | Request queuing, CCT caching (Pro) |
| Network latency | Medium | Medium | Local caching, async requests |
| Token expiration | Low | Medium | Token refresh reminders, error handling |

### User Experience Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Complex setup process | Medium | High | Comprehensive documentation, Docker quick-start |
| Firefly III unavailable | High | Low | Clear error messages, offline mode (cached data in Pro) |
| Data sync delays | Low | Medium | Real-time option (direct API) vs cached (Pro) |

### Security Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Token exposure | Critical | Low | Encrypted storage, no logging |
| MITM attacks | High | Low | HTTPS enforcement, certificate validation |
| Unauthorized access | High | Low | Capability checks, per-request validation |

---

## Success Metrics

### Phase 1 Success Criteria

- [ ] All 7 tools functional and tested
- [ ] API client handles pagination and errors
- [ ] Chart generation works with various datasets
- [ ] Documentation complete and reviewed
- [ ] Security audit passed
- [ ] Performance within acceptable ranges (<1s per request)

### Phase 2 Success Criteria

- [ ] Pro tools migrated successfully
- [ ] CCT sync working reliably
- [ ] Financial Planner integration tested
- [ ] Enhanced visualizations rendering correctly
- [ ] Sync performance optimized (<5 min for typical datasets)

### User Adoption Metrics

- Number of active Firefly III connections
- Daily API call volume
- Chart generation requests
- User feedback and support requests
- Feature request trends

---

## Future Enhancements

### Beyond Phase 3

1. **Bi-Directional Sync**: WordPress → Firefly III (create transactions from WordPress)
2. **Advanced Reports**: Custom report builder with Chart.js
3. **Mobile App Support**: Native mobile integration
4. **Multi-Instance Management**: Manage multiple Firefly III instances
5. **Automated Categorization**: AI-powered transaction categorization
6. **Budget Recommendations**: AI-generated budget suggestions
7. **Financial Forecasting**: Predictive analytics for cash flow
8. **Webhook Support**: Real-time sync via webhooks
9. **Import/Export**: Bulk data operations
10. **Third-Party Integrations**: Bank APIs, crypto wallets, investment platforms

---

## Competitive Analysis

### Similar Solutions

**Firefly III Mobile Apps**:
- Native iOS/Android apps
- Direct access to Firefly III
- Not integrated with WordPress

**WordPress Finance Plugins**:
- WP ERP, WP Project Manager (invoicing/accounting)
- Not personal finance focused
- No Firefly III integration

**Our Advantage**:
- ✅ AI assistant integration (unique)
- ✅ Combines content + finance in one platform
- ✅ Open-source + self-hosted (privacy)
- ✅ Extensible architecture (100+ potential tools)

---

## Conclusion

This proposal outlines a comprehensive integration between NV oOS and Firefly III that provides:

1. **Immediate Value** (Phase 1): 7 functional tools enabling AI assistants to access and manage financial data
2. **Enhanced Value** (Phase 2): Pro addon with caching, sync, and Financial Planner integration
3. **Complete Coverage** (Phase 3): 100+ tools covering entire Firefly III API

**Recommended Approach**: Implement Phase 1 in base plugin, then evaluate user demand for Phase 2/3 enhancements.

**Total Investment**:
- Phase 1: 12-17 hours (core integration)
- Phase 2: 18-24 hours (Pro enhancement)
- Phase 3: 40-60 hours (comprehensive coverage)

**Next Steps**:
1. Approve proposal
2. Finalize Phase 1 implementation (settings UI, testing)
3. Deploy to production
4. Gather user feedback
5. Evaluate Phase 2 timeline

---

**Proposal Author**: @copilot  
**Date**: January 2026  
**Version**: 1.0  
**Status**: Awaiting Approval
