# Pro Settings and Toolkits Guide

**NV oOS Pro Features Configuration** - Enable and manage advanced Pro addon toolkits

## Overview

The Pro addon for NV oOS provides 8 specialized toolkits with 200+ additional tools for advanced use cases. Each toolkit can be enabled/disabled independently based on your needs.

**Location**: NV oOS → Tools → Features (subtab)

## Available Pro Toolkits

### 1. 📝 Media Toolkit

**Setting**: `enable_media_toolkit`  
**Tools**: 15+ tools  
**Status**: Pro addon required

**Features**:
- Media template creation and management
- Template-based content generation
- Media collection organization
- Automated media workflows
- Template presets library

**Use Cases**:
- Content marketing teams
- Social media management
- Brand asset management
- Template-based campaigns

**Documentation**: See `docs/tools/pro/media-toolkit.md`

---

### 2. 📄 Document Generation Toolkit

**Setting**: `enable_document_generation_toolkit`  
**Tools**: 10+ tools  
**Status**: Pro addon required

**Features**:
- PDF document generation
- Word document creation
- Excel spreadsheet generation
- HTML to PDF conversion
- Template-based documents
- Custom styling and branding

**Use Cases**:
- Report generation
- Invoice creation
- Contract management
- Documentation automation

**Dependencies**:
- Node.js runtime (for server-side generation)
- PDFKit, docx, ExcelJS libraries

**Documentation**: See `docs/tools/pro/document-generation.md`

---

### 3. 🗂️ Project Management

**Setting**: `enable_project_management`  
**Tools**: 13 tools  
**Status**: Pro addon required

**Features**:
- AI-powered project creation and management
- Task assignment and tracking
- Event scheduling
- Timeline management
- Team collaboration
- JetEngine CCT synchronization

**Tools Provided**:
- `create_project` - Create new projects
- `update_project` - Modify project details
- `list_projects` - View all projects
- `delete_project` - Remove projects
- `create_task` - Add tasks to projects
- `update_task` - Modify task details
- `list_tasks` - View project tasks
- `delete_task` - Remove tasks
- `create_event` - Schedule events
- `update_event` - Modify event details
- `list_events` - View calendar events
- `delete_event` - Cancel events
- `get_project_status` - Project health reports

**Use Cases**:
- Software development teams
- Marketing campaign management
- Construction projects
- Event planning

**Documentation**: See `docs/tools/pro/project-management.md`

---

### 4. 📍 Places Management

**Setting**: `enable_places_management`  
**Tools**: 6+ tools  
**Status**: Pro addon required

**Features**:
- Location database management
- Google Maps integration
- Geocoding and reverse geocoding
- Radius-based search
- Place data enrichment
- Attraction and business listings

**Tools Provided**:
- `create_place` - Add new locations
- `update_place` - Modify place details
- `list_places` - Browse locations
- `delete_place` - Remove places
- `search_places_radius` - Geographic search
- `geocode_address` - Convert addresses to coordinates

**Use Cases**:
- Travel and tourism sites
- Real estate platforms
- Restaurant directories
- Event venues
- Store locators

**Integration**:
- Requires Google Maps API key
- Enhances all geospatial tools
- Works with Google Places API

**Documentation**: See `docs/tools/pro/places-management.md`

---

### 5. 🏫 ECA Pro Toolkit

**Setting**: `enable_eca_management`  
**Tools**: 5+ tools  
**Status**: Pro addon required

**Features**:
- Extra-Curricular Activities management
- Club and society administration
- Sports team management
- Student enrollment tracking
- Schedule coordination
- iSAMS integration

**Tools Provided**:
- `create_eca` - Create activities/clubs
- `update_eca` - Modify ECA details
- `list_ecas` - View all activities
- `enroll_student` - Register students
- `sync_isams_ecas` - Sync with iSAMS

**Use Cases**:
- Schools and universities
- Sports organizations
- Youth programs
- Community centers

**Documentation**: See `docs/tools/pro/eca-management.md`

---

### 6. 🏥 Health & Wellness Pro Toolkit

**Setting**: `enable_health_wellness_management`  
**Tools**: 30+ tools  
**Status**: Pro addon required

**Features**:
- Family member management
- Medical records storage
- Policy and insurance tracking
- Checkup scheduling
- Prescription management
- Allergy and condition tracking
- Pet health management
- Secure health data storage

**Tool Categories**:
- **Members**: Create, update, list family/pet members
- **Records**: Medical history management
- **Policies**: Insurance and coverage tracking
- **Checkups**: Schedule and track appointments
- **Prescriptions**: Medication management
- **Allergies**: Allergy and condition tracking

**Security**:
- Proper access controls
- HIPAA compliance considerations
- GDPR data protection
- Encrypted storage options

**Use Cases**:
- Personal health tracking
- Family health management
- Pet healthcare
- Clinic patient management (with proper compliance)

**⚠️ Important**: Always ensure HIPAA/GDPR compliance for healthcare deployments.

**Documentation**: See `docs/tools/pro/health-wellness.md`

---

### 7. ☁️ Cloudways Pro Toolkit

**Setting**: `enable_cloudways_toolkit`  
**Tools**: 58+ tools  
**Status**: Pro addon required

**Features**:
- Server management and monitoring
- Application deployment
- Database operations
- SSL certificate management
- Backup automation
- Performance optimization
- Security hardening
- Multi-server orchestration

**Tool Categories**:
- **Servers**: Create, manage, monitor servers
- **Applications**: Deploy, update, manage apps
- **Databases**: MySQL/PostgreSQL management
- **SSL**: Certificate installation and renewal
- **Backups**: Automated backup scheduling
- **Monitoring**: Performance and uptime tracking
- **Security**: Firewall, IP whitelisting
- **DNS**: Domain and DNS management

**Use Cases**:
- Hosting providers
- Development agencies
- Multi-site networks
- Enterprise deployments

**Requirements**:
- Active Cloudways account
- API credentials configured
- Server and app IDs

**Documentation**: See `docs/tools/pro/cloudways-toolkit.md`

---

### 8. 🖥️ AI CPT Management

**Setting**: `enable_ai_cpt_management`  
**Tools**: Integration metabox  
**Status**: Pro addon required

**Features**:
- AI assistant on post/page edit screens
- Integration with WordPress admin
- Direct content creation/editing
- Custom post type support
- Product management (WooCommerce)
- Term and taxonomy management

**Supported Post Types**:
- Posts
- Pages
- Products (WooCommerce)
- Custom post types
- Terms and taxonomies

**Use Cases**:
- Content creation assistance
- Product description writing
- SEO optimization
- Bulk content updates

**Documentation**: See `docs/tools/pro/cpt-management.md`

---

## How to Enable Pro Toolkits

### Step 1: Install Pro Addon

**Option A: Combined Version**
1. Upload `mcp-ai-wpoos-1.1.0.zip` (includes base + pro)
2. Activate the plugin
3. Pro features automatically available

**Option B: Separate Installation**
1. Install and activate `mcp-ai-wpoos-base-1.1.0.zip`
2. Install and activate `mcp-ai-wpoos-pro-1.1.0.zip`
3. Pro features added to base installation

### Step 2: Navigate to Settings

1. Go to **NV oOS → Tools**
2. Click **Features** subtab
3. Scroll to Pro Toolkits section

### Step 3: Enable Desired Toolkits

Each toolkit has an independent checkbox:

```
☐ Enable Media Toolkit
☐ Enable Document Generation Toolkit  
☐ Enable Project Management
☐ Enable Places Management
☐ Enable ECA Pro Toolkit
☐ Enable Health & Wellness Pro Toolkit
☐ Enable Cloudways Pro Toolkit
☐ Enable AI CPT Management
```

**Check the boxes** for toolkits you want to use.

### Step 4: Configure Toolkit Settings

Some toolkits require additional configuration:

**Places Management**:
- Navigate to: Integrations → Google Maps
- Add Google Maps API key

**Cloudways Toolkit**:
- Navigate to: Integrations → Cloudways
- Add email and API key
- Fetch server and app data

**Health & Wellness**:
- Review compliance requirements
- Configure access controls
- Enable HIPAA mode if needed

### Step 5: Save Settings

Click **Save Settings** at the bottom of the page.

### Step 6: Verify Tools Available

1. Go to **NV oOS → Tools → Tools Manager**
2. Verify toolkit tools appear in the list
3. Check tool counts match expected numbers

---

## Toolkit Dependencies

### Required PHP Extensions
- **Document Generation**: `zip`, `xml`, `gd`
- **Health & Wellness**: `openssl` (for encryption)
- **All**: Standard WordPress requirements

### Optional Integrations
- **JetEngine**: Enhanced data storage for Projects, Places, ECAs
- **WooCommerce**: Product management with AI CPT
- **Google Maps**: Required for Places Management
- **Cloudways**: Required for Cloudways Toolkit

### External Services
- **Document Generation**: Node.js runtime (recommended)
- **Places**: Google Maps API
- **Cloudways**: Cloudways hosting account

---

## Performance Considerations

### Memory Usage

Each toolkit adds to memory footprint:
- **Media Toolkit**: ~5-10 MB
- **Document Generation**: ~50 MB (Node.js bundles)
- **Project Management**: ~3-5 MB
- **Places**: ~2-3 MB
- **ECA**: ~2-3 MB
- **Health & Wellness**: ~5-8 MB
- **Cloudways**: ~10-15 MB
- **AI CPT**: ~2-3 MB

**Total if all enabled**: ~80-110 MB additional memory

**Recommendation**: Only enable toolkits you actively use.

### Database Impact

Toolkits that create custom post types:
- **Project Management**: Projects, Tasks, Events CPTs
- **Places**: Places CPT
- **ECA**: ECAs CPT
- **Health & Wellness**: Multiple CPTs for health data
- **Media Toolkit**: Media Templates, Collections CPTs

**With JetEngine**: Data stored in CCTs instead of CPTs (more efficient)

---

## Troubleshooting

### Toolkit Not Appearing After Enable

**Solutions**:
1. Clear all caches (Advanced → Settings Management → Clear Cache)
2. Deactivate and reactivate Pro addon
3. Check PHP error logs for conflicts
4. Verify Pro addon is latest version

### Tools Not Showing in Tools Manager

**Check**:
1. Toolkit is enabled in Tools → Features
2. Required dependencies are met
3. No PHP errors during initialization
4. Tool limits allow toolkit tools

### Performance Issues

**Solutions**:
1. Disable unused toolkits
2. Increase PHP memory limit (recommend 256M minimum)
3. Enable object caching (Redis/Memcached)
4. Use JetEngine for efficient data storage

### Document Generation Errors

**Check**:
1. Node.js installed (if using server-side generation)
2. Bundled scripts have execute permissions
3. Temporary directory is writable
4. Required PHP extensions loaded

---

## Best Practices

### Production Deployments

1. **Enable Selectively**: Only activate toolkits you need
2. **Test on Staging**: Test toolkit features before production
3. **Monitor Performance**: Watch memory and CPU usage
4. **Regular Backups**: Especially for health/project data
5. **Compliance**: Review data protection requirements

### Development Workflow

1. **Start Small**: Enable one toolkit at a time
2. **Review Tools**: Check Tools Manager for new tools
3. **Test Capabilities**: Try each tool's functionality
4. **Configure Properly**: Set up required integrations
5. **Document Usage**: Note which toolkits are essential

### Security Considerations

1. **Access Control**: Limit who can enable/disable toolkits
2. **Health Data**: Extra precautions for health toolkit
3. **Cloudways**: Protect API credentials
4. **Project Data**: Consider data sensitivity
5. **Regular Audits**: Review enabled toolkits quarterly

---

## Toolkit Comparison

| Toolkit | Tools | Memory | Dependencies | Use Case |
|---------|-------|--------|--------------|----------|
| Media | 15+ | 5-10MB | None | Marketing |
| Documents | 10+ | 50MB | Node.js | Reports |
| Projects | 13 | 3-5MB | Optional: JetEngine | Teams |
| Places | 6+ | 2-3MB | Google Maps API | Location |
| ECA | 5+ | 2-3MB | Optional: iSAMS | Schools |
| Health | 30+ | 5-8MB | None | Healthcare |
| Cloudways | 58+ | 10-15MB | Cloudways account | Hosting |
| AI CPT | Metabox | 2-3MB | None | Content |

---

## Future Roadmap

**Planned Toolkits** (under consideration):
- E-commerce Pro Toolkit
- Social Media Management Toolkit
- Advanced Analytics Toolkit
- Multi-language Content Toolkit
- Video Production Toolkit

**Community Requests**: Submit toolkit ideas via GitHub issues

---

## Support

### Documentation
- Individual toolkit guides: `docs/tools/pro/`
- Integration guides: `docs/integrations/`
- Troubleshooting: `docs/troubleshooting/pro-toolkits.md`

### Getting Help
1. Check documentation for specific toolkit
2. Review Tools Manager for tool details
3. Run Settings Health Check
4. Check GitHub issues for known problems
5. Contact support with toolkit name and error details

---

## Related Documentation

- [Base Tools Reference](../tools/tool-reference.md)
- [Settings Dashboard](../settings/README.md)
- [Pro Addon Overview](../../addons/pro/README.md)
- [JetEngine Integration](../../integrations/jetengine.md)

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-20  
**Applies to**: NV oOS Pro v1.1.0+
