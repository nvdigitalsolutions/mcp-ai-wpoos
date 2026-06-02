# Asset Inventory System - Implementation Guide

**Control:** ISO 27001:2022 A.5.9 - Inventory of Information and Other Associated Assets  
**Status:** ✅ Implemented  
**Date:** 2026-01-06  
**Version:** 1.0.0

---

## Overview

The Asset Inventory System provides automated discovery and classification of all information and associated assets within the NV oOS plugin, implementing ISO 27001:2022 Control A.5.9.

### Key Features

- **Automated Discovery**: Weekly cron job discovers all plugin assets
- **Classification System**: Four-level classification (Public, Internal, Confidential, Restricted)
- **Comprehensive Coverage**: Code, configuration, data, integrations, and documentation
- **REST API**: Full API access for programmatic management
- **Admin Dashboard**: User-friendly interface for viewing and filtering assets
- **Asset Tracking**: Monitors asset ownership, location, and last modification date

---

## Architecture

### Components

1. **WP_MCP_AI_Asset_Inventory** (`includes/class-wp-mcp-ai-asset-inventory.php`)
   - Core inventory management class
   - Singleton pattern
   - Asset discovery methods
   - Classification and statistics

2. **WP_MCP_AI_Asset_Inventory_REST** (`includes/rest/class-wp-mcp-ai-asset-inventory-rest.php`)
   - REST API endpoints
   - `/mcp-ai/v1/assets/inventory` - Get full inventory
   - `/mcp-ai/v1/assets/discover` - Trigger discovery
   - `/mcp-ai/v1/assets/statistics` - Get statistics
   - `/mcp-ai/v1/assets/classification/{level}` - Filter by classification
   - `/mcp-ai/v1/assets/type/{type}` - Filter by type

3. **WP_MCP_AI_Asset_Inventory_Admin** (`includes/admin/class-wp-mcp-ai-asset-inventory-admin.php`)
   - Admin dashboard page
   - Asset visualization
   - Filtering interface
   - Statistics display

4. **Frontend Assets**
   - `assets/css/asset-inventory.css` - Dashboard styling
   - `assets/js/asset-inventory.js` - Dashboard interactions

---

## Asset Classification Levels

Per ISO 27001:2022 and ISMS Policy Section 8:

| Level | Description | Examples | Protection Requirements |
|-------|-------------|----------|------------------------|
| **Public** | Information intended for public disclosure | README.md, public documentation | Basic integrity protection |
| **Internal** | Information for internal use only | Development docs, team info | Access control, basic encryption |
| **Confidential** | Sensitive business information | Source code, user data, chat transcripts | Strong encryption, strict access control |
| **Restricted** | Highly sensitive information | API keys, encryption keys, credentials | Maximum security, audit logging |

---

## Asset Types

The system categorizes assets into eight types:

1. **API Key/Credential** - Authentication credentials and API keys
2. **User Data** - User information and metadata
3. **Chat Transcript** - User conversation data
4. **Source Code** - Plugin source code files
5. **Configuration** - WordPress options and settings
6. **Database** - Database tables and stored data
7. **Third-Party Integration** - External API connections
8. **Documentation** - Technical and compliance documentation

---

## Automated Discovery Process

### Discovery Scope

The system automatically discovers:

#### 1. Code Assets
- `includes/` - Core plugin classes
- `assets/` - JavaScript, CSS, images
- `core/` - Core functionality
- `shared/` - Shared utilities
- `addons/` - Add-on components

**Classification:** Confidential  
**Owner:** Development Team

#### 2. Configuration Assets
- OpenAI API key
- Google Gemini API key
- Ollama configuration
- Plugin settings
- Encryption keys

**Classification:** Restricted (API keys), Internal (settings)  
**Owner:** Security Team

#### 3. Third-Party Integrations
- OpenAI GPT API
- Google Gemini API
- Ollama Local AI
- Hugging Face API
- WordPress Core
- JetEngine
- WooCommerce
- Elementor

**Classification:** Confidential (external APIs), Internal (WordPress plugins)  
**Owner:** Development Team

#### 4. Data Assets
- Custom Post Types (mcp_ai_assistant, mcp_ai_team, mcp_ai_profession)
- User metadata
- Chat transcripts (localStorage + JetEngine CCT)

**Classification:** Confidential  
**Owner:** Data Management Team

#### 5. Documentation Assets
- README.md (Public)
- SECURITY.md (Public)
- CHANGELOG.md (Public)
- CONTRIBUTING.md (Public)
- ISO 27001 compliance documentation (Confidential)

**Classification:** Public or Confidential (depending on content)  
**Owner:** Documentation Team

### Discovery Schedule

- **Automatic:** Weekly via WordPress cron (`wp_mcp_ai_asset_discovery` hook)
- **Manual:** Via "Discover Assets" button in admin dashboard
- **API:** POST request to `/wp-json/mcp-ai/v1/assets/discover`

---

## REST API Documentation

### Base URL
```
/wp-json/mcp-ai/v1/assets
```

### Authentication
All endpoints require `manage_options` capability (WordPress administrator).

### Endpoints

#### GET `/inventory`
Get complete asset inventory.

**Response:**
```json
{
  "success": true,
  "inventory": {
    "assets": [
      {
        "id": "code_includes",
        "name": "Includes Directory",
        "type": "code",
        "classification": "confidential",
        "location": "/path/to/includes",
        "owner": "Development Team",
        "description": "Plugin source code in includes directory",
        "last_modified": "2026-01-06 00:00:00"
      }
    ],
    "generated_at": "2026-01-06 00:00:00",
    "total_count": 50
  }
}
```

#### POST `/discover`
Trigger asset discovery process.

**Response:**
```json
{
  "success": true,
  "message": "Asset discovery completed successfully.",
  "count": 50,
  "assets": [...]
}
```

#### GET `/statistics`
Get asset statistics.

**Response:**
```json
{
  "success": true,
  "statistics": {
    "total": 50,
    "by_type": {
      "code": 5,
      "configuration": 5,
      "third_party": 8,
      "database": 4,
      "documentation": 5
    },
    "by_classification": {
      "restricted": 5,
      "confidential": 20,
      "internal": 15,
      "public": 10
    },
    "generated_at": "2026-01-06 00:00:00"
  }
}
```

#### GET `/classification/{level}`
Get assets by classification level.

**Parameters:**
- `level` (required): public|internal|confidential|restricted

**Response:**
```json
{
  "success": true,
  "classification": "restricted",
  "count": 5,
  "assets": [...]
}
```

#### GET `/type/{type}`
Get assets by type.

**Parameters:**
- `type` (required): api_key|user_data|chat_transcript|code|configuration|database|third_party|documentation

**Response:**
```json
{
  "success": true,
  "type": "code",
  "count": 5,
  "assets": [...]
}
```

---

## Admin Dashboard Usage

### Access
Navigate to: **WP Admin → NV oOS Pro → Asset Inventory**

### Features

#### 1. Asset Statistics Cards
- Total Assets count
- Count by classification level (color-coded)
- Last updated timestamp

#### 2. Discover Assets Button
Manually trigger asset discovery. Page reloads automatically after completion.

#### 3. Filter Controls
- **Classification Filter**: Filter by Public, Internal, Confidential, or Restricted
- **Type Filter**: Filter by asset type (Code, Configuration, etc.)

#### 4. Asset Table
Displays all assets with:
- Asset name and description
- Type (user-friendly label)
- Classification (color-coded badge)
- Owner
- Location (file path or database table)
- Last modified date

#### 5. Information Panel
Explains the asset inventory system and ISO 27001 compliance.

---

## Development Usage

### Get Asset Inventory Programmatically

```php
// Get inventory instance.
$inventory = WP_MCP_AI_Asset_Inventory::get_instance();

// Discover all assets.
$assets = $inventory->discover_assets();

// Get stored inventory.
$stored = $inventory->get_asset_inventory();

// Get assets by classification.
$restricted = $inventory->get_assets_by_classification( 'restricted' );
$confidential = $inventory->get_assets_by_classification( 'confidential' );

// Get assets by type.
$code = $inventory->get_assets_by_type( 'code' );
$config = $inventory->get_assets_by_type( 'configuration' );

// Get statistics.
$stats = $inventory->get_asset_statistics();
echo "Total assets: " . $stats['total'];
echo "Restricted: " . $stats['by_classification']['restricted'];
```

### Add Custom Discovery Logic

You can extend the discovery process:

```php
add_filter( 'wp_mcp_ai_discovered_assets', function( $assets ) {
    // Add custom asset.
    $assets[] = array(
        'id'             => 'custom_asset_1',
        'name'           => 'Custom Asset',
        'type'           => 'configuration',
        'classification' => 'internal',
        'location'       => 'Custom location',
        'owner'          => 'Custom Team',
        'description'    => 'Custom asset description',
        'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
    );
    
    return $assets;
}, 10, 1 );
```

---

## Testing

### Unit Tests

Run the asset inventory test suite:

```bash
composer run test -- tests/test-asset-inventory.php
```

### Test Coverage

The test suite covers:
- Singleton instance
- Asset discovery
- Inventory storage
- Classification filtering
- Type filtering
- Statistics generation
- Code asset discovery
- Integration discovery
- Documentation discovery

### Manual Testing

1. **Trigger Discovery:**
   ```bash
   wp eval "WP_MCP_AI_Asset_Inventory::get_instance()->discover_assets();"
   ```

2. **Check Stored Inventory:**
   ```bash
   wp option get wp_mcp_ai_asset_inventory --format=json
   ```

3. **Test REST API:**
   ```bash
   curl -X POST "https://yoursite.com/wp-json/mcp-ai/v1/assets/discover" \
     -H "X-WP-Nonce: YOUR_NONCE"
   ```

---

## Security Considerations

### Access Control
- All REST API endpoints require `manage_options` capability
- Admin dashboard restricted to administrators only
- Sensitive data (API keys, credentials) properly classified as "Restricted"

### Data Protection
- No sensitive data exposed in API responses
- Asset locations use absolute paths (not exposed to frontend)
- Classification enforces appropriate protection measures

### Audit Logging
- Discovery events logged via WP_MCP_AI_Logger
- Includes timestamp and asset count
- Viewable in plugin logs

---

## Compliance Documentation

### ISO 27001:2022 Control A.5.9 Requirements

✅ **Requirement:** Identify all assets relevant to information security  
**Implementation:** Automated discovery of all plugin assets

✅ **Requirement:** Document and maintain an inventory  
**Implementation:** Stored in WordPress options, updated weekly

✅ **Requirement:** Assign ownership to assets  
**Implementation:** Each asset has documented owner (Development Team, Security Team, Data Management Team, Documentation Team)

✅ **Requirement:** Classify assets based on importance  
**Implementation:** Four-level classification system (Public, Internal, Confidential, Restricted)

✅ **Requirement:** Establish acceptable use rules  
**Implementation:** Classification level determines handling requirements per ISMS Policy

✅ **Requirement:** Review and update inventory  
**Implementation:** Weekly automated discovery via cron, manual trigger available

### Evidence for Auditors

1. **Implementation Evidence:**
   - Source code: `includes/class-wp-mcp-ai-asset-inventory.php`
   - Admin interface: Screenshots in `docs/compliance/iso27001/evidence/`
   - Test results: `composer run test` output

2. **Operational Evidence:**
   - WordPress option: `wp_mcp_ai_asset_inventory`
   - Cron schedule: `wp cron event list | grep asset_discovery`
   - Activity logs: Via WP_MCP_AI_Logger

3. **Documentation:**
   - This implementation guide
   - Updated Statement of Applicability (A.5.9)
   - ISMS Policy Section 8 (Information Classification)

---

## Maintenance

### Regular Tasks

**Weekly (Automated):**
- Asset discovery runs via cron
- Inventory updated with latest assets

**Monthly (Manual):**
- Review asset counts for anomalies
- Verify classification accuracy
- Update ownership if team changes

**Quarterly (Manual):**
- Full inventory audit
- Update documentation
- Review and optimize discovery logic

### Troubleshooting

**Issue:** Cron not running  
**Solution:** Check `wp cron event list`, manually trigger with `wp cron event run wp_mcp_ai_asset_discovery`

**Issue:** Missing assets  
**Solution:** Review discovery methods in asset inventory class, add custom logic if needed

**Issue:** Incorrect classification  
**Solution:** Update classification in discovery methods or use filter hook

---

## Future Enhancements

### Planned Features
- [ ] Asset lifecycle tracking (created, modified, retired)
- [ ] Asset dependencies mapping
- [ ] Automated compliance report generation
- [ ] Integration with vulnerability scanners
- [ ] Asset risk assessment scoring
- [ ] Change detection and alerting
- [ ] Export to CSV/PDF for auditors
- [ ] Asset tagging system

### Integration Opportunities
- GRC platforms (RSA Archer, ServiceNow)
- SIEM tools (Splunk, ELK Stack)
- Configuration management databases (CMDB)
- Vulnerability management systems

---

## Support

For issues or questions:

- **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation:** `docs/compliance/iso27001/`
- **Security Contact:** security@nvdigitalsolutions.com

---

**Document Control**

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | GitHub Copilot | Initial implementation guide |

**Next Review:** 2026-02-06
