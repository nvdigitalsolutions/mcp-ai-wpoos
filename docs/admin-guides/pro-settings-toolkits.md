# Pro Settings and Toolkits Guide

**NV oOS Pro Features Configuration** - Enable and manage advanced Pro addon toolkits

## Overview

The Pro addon for NV oOS provides 20 specialized toolkits with 300+ additional tools for advanced use cases. Each toolkit can be enabled/disabled independently based on your needs.

**Location**: NV oOS → Tools → Features (subtab)

## Available Pro Toolkits

### 1. 📝 Media Toolkit

**Setting**: `enable_media_toolkit`  
**Tools**: 7 tools  
**Status**: Pro addon required

**Features**:
- Media template creation and management
- Template-based content generation
- Media collection organization
- Automated media workflows
- Template presets library
- Sharp image optimization (NPM-powered)

**Tools Provided**:
- `list_media_templates` - List available media templates
- `apply_media_template` - Apply a template to media items
- `create_media_template` - Create new media templates
- `create_media_collection` - Group media items into collections
- `process_collection` - Process a collection of media items
- `apply_collection_template` - Apply a template to a collection
- `optimize_image_sharp` - Optimize images using Sharp library

**Use Cases**:
- Content marketing teams
- Social media management
- Brand asset management
- Template-based campaigns

**Documentation**: See `docs/tools/pro/media-toolkit.md`

---

### 2. 📄 Document Generation Toolkit

**Setting**: `enable_document_generation_toolkit`  
**Tools**: 15 tools  
**Status**: Pro addon required

**Features**:
- PDF document generation
- Word document creation
- Excel spreadsheet generation
- HTML to PDF conversion
- Template-based documents
- PDF text extraction and OCR
- PDF merging and watermarking
- Invoice PDF generation
- Excel data import/export
- Custom styling and branding

**Tools Provided**:
- `pro_pdf_document` - Advanced PDF generation with full styling
- `pro_word_document` - Advanced Word document generation
- `pro_excel_document` - Advanced Excel spreadsheet generation
- `generate_pdf` - Simplified PDF generation
- `generate_word` - Simplified Word document generation
- `generate_excel` - Simplified Excel generation
- `extract_pdf_text` - Extract text content from PDFs
- `ocr_pdf_text` - OCR text extraction from scanned PDFs
- `pro_document_ocr` - Advanced OCR document processing
- `html_to_pdf` - Convert HTML content to PDF
- `merge_pdfs` - Merge multiple PDF files into one
- `add_watermark_to_pdf` - Add watermarks to PDF files
- `generate_invoice_pdf` - Generate professional invoice PDFs
- `excel_data_import` - Import data from Excel files
- `excel_data_export` - Export data to Excel format

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
- JetEngine CCT synchronization (when JetEngine is active, four CCTs are auto-provisioned — see below)

**JetEngine CCTs** (registered only when `enable_project_management` is on):

| CCT slug | Table | Purpose |
|---|---|---|
| `mcp_task_plans` | `wp_jet_cct_mcp_task_plans` | Markdown task plans for autonomous orchestration |
| `mcp_task_templates` | `wp_jet_cct_mcp_task_templates` | Reusable workflow templates |
| `mcp_autonomous_sessions` | `wp_jet_cct_mcp_autonomous_sessions` | Active agent session state |
| `mcp_execution_history` | `wp_jet_cct_mcp_execution_history` | Per-step tool-execution log |

> **Note**: All four CCTs are visible in JetEngine's admin UI (`wp-admin/admin.php?page=jet-cct-<slug>`) only after enabling Project Management and running the site at least once with JetEngine active.

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
- `get_calendar_view` - Get calendar view of events

**Use Cases**:
- Software development teams
- Marketing campaign management
- Construction projects
- Event planning

**Documentation**: See `docs/tools/pro/project-management.md`

---

### 4. 📍 Places Management

**Setting**: `enable_places_management`  
**Tools**: 8 tools  
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
- `get_place` - Get place details
- `search_and_save_places` - Search and save places from external sources
- `research_place` - AI-powered research on a place
- `analyze_geospatial` - Turf.js geospatial analysis (distance, buffers, intersections)

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
**Tools**: 14 tools  
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
- `get_eca` - Get ECA details
- `list_ecas` - View all activities
- `delete_eca` - Remove ECA records
- `create_student` - Add student records
- `update_student` - Modify student details
- `get_student` - Get student details
- `list_students` - Browse students
- `delete_student` - Remove student records
- `enroll_student_eca` - Enroll a student in an ECA
- `sync_students_from_isams` - Sync students from iSAMS
- `sync_ecas_from_isams` - Sync ECAs from iSAMS
- `research_eca` - AI-powered research on an ECA

**Use Cases**:
- Schools and universities
- Sports organizations
- Youth programs
- Community centers

**Documentation**: See `docs/tools/pro/eca-management.md`

---

### 6. 🏥 Health & Wellness Pro Toolkit

**Setting**: `enable_health_wellness_management`  
**Tools**: 48 tools  
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
- JetEngine CCT for vitals log (when JetEngine is active — see below)

**JetEngine CCT** (registered only when `enable_health_wellness_management` is on):

| CCT slug | Table | Purpose |
|---|---|---|
| `vitals_log` | `wp_jet_cct_vitals_log` | Time-series vital signs log (blood pressure, glucose, weight, CBC panel, LFT, electrolytes) |

> **Note**: The `vitals_log` CCT is used by the `log_vital_signs` and `import_vitals` tools. It will not appear in JetEngine's admin UI unless Health & Wellness is enabled.

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
**Tools**: 2 tools  
**Status**: Pro addon required

**Features**:
- AI assistant on post/page edit screens
- Integration with WordPress admin
- Direct content creation/editing
- Custom post type support
- AI-powered research and analysis

**Tools Provided**:
- `research_post` - AI-powered research on a post, fetches and analyzes content
- `research_page` - AI-powered research on a page, fetches and analyzes content

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

### 9. 🛠️ AI Tool Builder Toolkit

**Setting**: `enable_ai_tool_builder_toolkit`  
**Tools**: 10 professional meta-tools  
**Status**: Pro addon required (Phase 2.9)

**Features**:
- Meta-toolkit for creating custom AI tools
- AI-powered code generation from natural language
- Automated test and documentation generation
- Security vulnerability scanning
- WordPress Coding Standards compliance checking
- Performance benchmarking and optimization
- Tool scaffold generation
- Parameter schema validation

**Tool Categories**:
- **Code Generation**: Tool scaffolds, parameters, logic, refactoring (4 tools)
- **Testing & Documentation**: Test suites, documentation, schema validation (3 tools)
- **Quality Assurance**: Security analysis, compliance checking, performance benchmarking (3 tools)

**Use Cases**:
- Tool development workflows
- Custom business logic creation
- Plugin extension development
- Quality assurance automation
- Developer productivity enhancement

**Security Note**: 
- **Development environments only**
- Requires `manage_options` capability
- Review all generated code before deployment
- Never expose to untrusted users

**Documentation**: See `addons/pro/includes/tools/ai-tool-builder/README.md`

---

### 10. 🏗️ Architectural Design Toolkit

**Setting**: `enable_architectural_design_toolkit`  
**Tools**: 16 professional tools  
**Status**: Pro addon required (Phase 2.10)

**Features**:
- AI-powered floor plan generation
- 3D building model creation
- Photorealistic rendering
- Construction document generation
- Building code compliance checking
- Structural feasibility analysis
- LEED sustainability scoring
- Material schedules and cost estimation
- Construction timeline planning

**Tool Categories**:
- **Floor Planning**: Floor plans, space optimization, variations, sketch conversion (4 tools)
- **3D Modeling**: 3D models, renderings, walkthrough animations (3 tools)
- **Documentation**: Construction drawings, detail sheets, document export (3 tools)
- **Analysis**: Building codes, structural analysis, sustainability metrics (3 tools)
- **Estimation**: Material schedules, cost estimation, construction timelines (3 tools)

**Use Cases**:
- Architecture firms
- Construction companies
- Real estate developers
- Interior designers
- Building contractors

**Requirements**:
- Vision-capable AI model (for sketch conversion)
- OpenAI API key
- Regional building code databases (optional)

**Documentation**: See `addons/pro/includes/tools/architectural-design/README.md`

---

### 11. 📅 Calendar Booking Toolkit

**Setting**: `enable_calendar_booking_toolkit`  
**Tools**: 15 professional tools  
**Status**: Pro addon required (Phase 2.6)

**Features**:
- Complete appointment management system
- Google Calendar and Outlook sync
- Automated booking confirmations and reminders
- Business hours and availability management
- Time slot blocking and conflict detection
- AI-powered schedule optimization
- Public booking link generation
- Payment tracking integration

**Tool Categories**:
- **Appointments**: Create, update, cancel, reschedule, get details (5 tools)
- **Availability**: Check availability, set rules, get slots, block times, optimize (5 tools)
- **Integration**: Google/Outlook sync, confirmations, reminders, booking links (5 tools)

**Use Cases**:
- Service professionals (consultants, coaches, therapists)
- Medical and dental practices
- Salons and spas
- Legal and financial advisors
- Educational tutoring

**Data Storage**:
- Custom post types: `mcp_appointment`, `mcp_blocked_time`, `mcp_booking_link`
- WordPress options for business hours

**Documentation**: See `addons/pro/includes/tools/calendar-booking/README.md`

---

### 12. 🎵 DJ Management Toolkit

**Setting**: `enable_dj_management_toolkit`  
**Tools**: 18 professional tools  
**Status**: Pro addon required (Phase 2.7)

**Features**:
- Complete DJ business management system
- Equipment inventory and maintenance tracking
- Music library with BPM and key analysis
- AI-powered playlist generation
- Harmonic mixing with Camelot Wheel
- Event booking and timeline generation
- Client relationship management
- Contract and invoice generation
- Payment tracking

**Tool Categories**:
- **Equipment**: Inventory, maintenance, reports, reservations (4 tools)
- **Music Library**: Playlists, library management, BPM analysis, AI generation, transitions (5 tools)
- **Event Management**: Bookings, updates, timelines, confirmations, payments (5 tools)
- **Client Management**: Profiles, contracts, invoices, communication logs (4 tools)

**Use Cases**:
- Professional DJs
- DJ agencies
- Mobile entertainment companies
- Event production companies
- Wedding DJs

**Data Storage**:
- Custom post types: `dj_equipment`, `dj_track`, `dj_playlist`, `dj_booking`, `dj_client`

**Documentation**: See `addons/pro/includes/tools/dj-management/README.md`

---

### 13. 🛒 E-commerce Toolkit

**Setting**: `enable_ecommerce_toolkit`  
**Tools**: 20 professional tools  
**Status**: Pro addon required (100% complete)

**Features**:
- Advanced WooCommerce product management
- Bulk product operations
- CSV/Excel product import
- Order workflow automation
- Professional invoice generation
- Customer segmentation and CLV analysis
- Inventory forecasting and alerts
- Abandoned cart recovery
- AI-powered upsell recommendations
- Sales performance analytics

**Tool Categories**:
- **Product Management**: Advanced creation, bulk updates, CSV import, export, inventory sync (5 tools)
- **Order Management**: Workflow processing, invoices, bulk updates, refunds, analytics (5 tools)
- **Customer Management**: Segmentation, lifetime value, GDPR export (3 tools)
- **Inventory**: Movement tracking, low stock alerts, forecasting (3 tools)
- **Marketing**: Discount campaigns, cart recovery, upsells, sales dashboard (4 tools)

**Use Cases**:
- Online stores
- E-commerce agencies
- Wholesale businesses
- Subscription services
- Multi-vendor marketplaces

**Requirements**:
- WooCommerce plugin active
- Stripe integration (for payment features)

**Documentation**: See `addons/pro/includes/tools/ecommerce/README.md`

---

### 14. 💰 Financial Planning Toolkit

**Setting**: `enable_financial_planner_toolkit`  
**Tools**: 32 professional tools  
**Status**: Pro addon required (✅ Fully Implemented)

**Features**:
- Retirement planning and savings calculators
- IRA and Roth IRA comparison analysis
- Withdrawal strategy planning
- Social security optimization
- Pension analysis
- Budget planning and expense tracking
- Net worth calculation and tracking
- Cash flow analysis and forecasting
- Bank account synchronization via Plaid
- Investment portfolio visualization (educational)
- Asset allocation planning
- Investment return calculations
- Portfolio rebalancing analysis (educational)
- Tax-loss harvesting tracking (educational)
- Debt payoff strategy calculators
- Mortgage calculations and refinancing
- Credit score tracking
- Savings goal planning
- Emergency fund calculations
- Financial health scoring
- Tax estimation
- College savings (529 plan) calculator
- Insurance needs analysis
- Real-time financial news aggregation from multiple sources
- Stock ticker search and OHLCV data retrieval via YFinance
- Market sentiment analysis with keyword scoring (-1.0 to +1.0)
- Time-series market forecasting (linear regression, moving average, exponential smoothing)
- Investment signal tracking and evolution evaluation
- Financial logic chain visualization (Mermaid diagrams)
- Professional financial report generation (6 report types)
- Specialized multi-source financial web search

**Tool Categories**:
- **Retirement Planning**: Calculators, IRA/Roth, withdrawal strategies, social security, pensions (5 tools)
- **Budget & Expenses**: Budget planner, expense tracker, net worth, cash flow, bank sync (5 tools)
- **Investment**: Portfolio visualization, asset allocation, returns, rebalancing, tax-loss harvesting (5 tools - educational)
- **Debt Management**: Debt payoff, mortgage calculator, credit score tracking (3 tools)
- **Goal Planning**: Savings goals, emergency fund (2 tools)
- **Financial Literacy**: Health score, tax estimator, college savings, insurance (4 tools)
- **Market Analysis & Research**: News aggregation, stock data, sentiment analysis, forecasting, signal tracking, logic visualization, report generation, financial search (8 tools)

**Use Cases**:
- Financial advisors (educational purposes)
- Personal finance websites
- Banking portals
- Financial planning services
- Financial literacy education
- Budget tracking applications

**Important**: Investment tools are for educational purposes only. Not financial advice.

**Documentation**: See `addons/pro/includes/tools/financial-planning/README.md`

---

### 15. 🎨 Image Production Toolkit

**Setting**: `enable_image_production_toolkit`  
**Tools**: 15 professional tools  
**Status**: Pro addon required (Phase 2.8)

**Features**:
- AI image generation (DALL-E, Stable Diffusion)
- Image variation generation
- AI inpainting and editing
- Background removal
- AI upscaling (2x, 4x, 8x)
- Image quality enhancement
- Artistic style transfer
- Black & white colorization
- Smart image compression
- Format conversion (WebP, AVIF)
- Content-aware resizing
- Batch image processing
- Responsive image generation

**Tool Categories**:
- **AI Generation**: Text-to-image, variations, inpainting, prompt optimization (4 tools)
- **Editing & Enhancement**: Background removal, upscaling, enhancement, style transfer, colorization (5 tools)
- **Optimization**: Compression, format conversion, smart resize, batch processing, responsive images, web optimization (6 tools)

**Use Cases**:
- Content creators
- Marketing teams
- E-commerce stores
- Design agencies
- Photography studios

**Requirements**:
- OpenAI API key (for DALL-E)
- Stability AI API key (for Stable Diffusion)
- Optional: remove.bg API key
- Python 3 with AI libraries (optional for local processing)
- GPU access (recommended for heavy operations)

**Documentation**: See `addons/pro/includes/tools/image-production/README.md`

---

### 16. 📱 Social Media Toolkit

**Setting**: `enable_social_media_toolkit`  
**Tools**: 15 professional tools  
**Status**: Pro addon required (100% complete)

**Features**:
- Cross-platform content publishing
- Automated post scheduling
- Bulk CSV scheduling
- Platform-specific image optimization
- Social video format generation
- Mention and reply monitoring
- AI-powered auto-responses
- Comment moderation
- Unified analytics dashboard
- Hashtag performance tracking
- Competitor analysis
- Influencer identification
- Content calendar creation
- AI content suggestions
- Social listening and trend tracking

**Tool Categories**:
- **Publishing**: Multi-platform posting, scheduling, bulk scheduling, image optimization, video creation (5 tools)
- **Engagement**: Mention monitoring, auto-responses, comment moderation (3 tools)
- **Analytics**: Cross-platform analytics, hashtag tracking, competitor analysis, influencer identification (4 tools)
- **Content Management**: Content calendar, AI post ideas, trend listening (3 tools)

**Use Cases**:
- Social media managers
- Marketing agencies
- Brand management teams
- Influencers
- Community managers

**Requirements**:
- Platform API keys (Twitter, Facebook, LinkedIn, Instagram)
- OAuth connections configured

**Documentation**: See `addons/pro/includes/tools/social-media/README.md`

---

### 17. 🌍 Multilingual Toolkit

**Setting**: `enable_multilingual_toolkit`  
**Tools**: 10 professional tools  
**Status**: Pro addon required (✅ Fully Implemented)

**Features**:
- AI-powered content translation
- WooCommerce product translation
- Translation memory and reuse
- XLIFF/PO file import/export
- Automatic language detection
- Date and currency localization
- RTL (right-to-left) content optimization
- Translation quality checking
- Untranslated string detection
- Multilingual SEO auditing

**Tools Provided**:
1. `auto_translate_content` - AI translation of posts/pages with multiple language support
2. `translate_woocommerce_products` - Translate product catalogs including descriptions and meta
3. `translation_memory_search` - Reuse previous translations for consistency
4. `export_import_translations` - XLIFF/PO file import/export for professional workflows
5. `detect_content_language` - Automatically detect content language
6. `localize_dates_currencies` - Format dates and currencies by locale
7. `rtl_content_optimization` - Optimize content for right-to-left languages
8. `translation_quality_check` - Validate translation completeness and accuracy
9. `find_untranslated_strings` - Scan for missing translations across site
10. `multilingual_seo_audit` - SEO optimization for multilingual content

**Use Cases**:
- International e-commerce sites
- Multi-language websites
- Global content publishers
- Translation agencies
- Localization services
- Multilingual blogs

**Requirements**:
- Translation API keys (Google Translate, DeepL, or AWS)
- Optional: WPML or Polylang integration

**Documentation**: See `addons/pro/includes/tools/multilingual/README.md`

---

### 18. 🎬 Video Production Toolkit

**Setting**: `enable_video_production_toolkit`  
**Tools**: 12 professional tools  
**Status**: Pro addon required (✅ Fully Implemented)

**Features**:
- Video creation from images (slideshows)
- Watermark addition and branding
- Automated caption generation
- Video merging and concatenation
- Video trimming and cutting
- Resolution resizing
- Speed adjustment (slow motion/timelapse)
- Video compression with quality preservation
- Format conversion (MP4, WebM, AVI, etc.)
- Platform-specific optimization (YouTube, Instagram, TikTok)
- Metadata extraction and analysis
- Thumbnail generation

**Tools Provided**:
1. `create_video_from_images` - Create slideshow videos from image sequences
2. `add_watermark_to_video` - Brand videos with watermarks and logos
3. `generate_video_captions` - Auto-generate subtitles and closed captions
4. `merge_videos` - Combine multiple videos into one
5. `trim_video` - Cut video sections with precision
6. `resize_video_resolution` - Change video dimensions and aspect ratios
7. `adjust_video_speed` - Speed up or slow down video playback
8. `compress_video` - Reduce file size while maintaining quality
9. `convert_video_format` - Convert between video formats
10. `optimize_for_platform` - Platform-specific optimization (social media)
11. `extract_video_metadata` - Get video information and technical details
12. `generate_video_thumbnails` - Create thumbnail images from video frames

**Use Cases**:
- Video content creators
- Marketing teams
- Educational content producers
- Social media managers
- Video production studios
- E-learning platforms

**Requirements**:
- FFmpeg binary configured and accessible
- Node.js runtime (optional for advanced features)
- NPM packages: ffmpeg-static, fluent-ffmpeg

**Documentation**: See `addons/pro/includes/tools/video-production/README.md`

---

### 19. 📊 Analytics Toolkit

**Setting**: `enable_advanced_analytics_toolkit`  
**Tools**: 12 professional tools  
**Status**: Pro addon required (✅ Fully Implemented)

**Features**:
- Custom metrics collection and tracking
- Data warehouse synchronization (BigQuery, Snowflake, Redshift)
- Real-time event tracking and monitoring
- Executive-level analytics dashboards
- User cohort behavior analysis
- Conversion funnel tracking with drop-off analysis
- Multi-touch attribution modeling
- Machine learning-based churn prediction
- AI-powered customer segmentation
- Revenue forecasting and projections
- Custom report generation
- Analytics data export via REST API

**Tool Categories**:
- **Data Collection**: Custom metrics, warehouse sync, real-time events (3 tools)
- **Analytics & Reporting**: Executive dashboard, cohort analysis, funnel analysis, attribution modeling (4 tools)
- **Predictive Analytics**: Churn prediction, customer segmentation, revenue forecast (3 tools)
- **Export & Integration**: Custom reports, API export (2 tools)

**Use Cases**:
- Data-driven businesses with advanced analytics needs
- SaaS platforms tracking subscriber metrics
- E-commerce analytics and forecasting
- Marketing analytics and campaign attribution
- Business intelligence dashboards
- Customer behavior analysis

**Requirements**:
- NPM packages: d3, mathjs, regression, fast-csv (for advanced visualizations)
- Optional: Google Analytics 4 API integration
- Optional: WooCommerce (for e-commerce analytics)

**Documentation**: See `addons/pro/includes/tools/analytics/README.md`

---

### 20. 🤝 CRM Toolkit

**Setting**: `enable_crm_toolkit`  
**Tools**: 7 tools  
**Status**: Pro addon required

**Features**:
- Contact management with full CRUD operations (create, read, update, delete, list, search)
- Company records with industry, size, location, and target status
- AI-powered company research using web search
- Email-based lead search with scoring, MQL staging, and WP Cron scheduling
- Email-based correspondence search with response-time analytics and routing suggestions
- Email-based accounting search with invoice/payment tracking and QuickBooks/Xero integration hints
- Cached results (WP_MCP_AI_Cache_Helper) with scheduled auto-refresh via WP Cron

**Tools Provided**:
- `manage_crm_contact` - Create, read, update, delete, list, and search CRM contacts (supports CCT/CPT storage)
- `create_company` - Create company records with industry, size, location, and target status
- `get_companies` - List and search companies by industry, size, target status, or location
- `research_company` - AI-powered company research using web search for industry insights and target fit
- `crm_email_search_leads` - Search new leads by email criteria with lead scoring, MQL staging, and scheduling
- `crm_email_search_correspondence` - Search customer correspondence with response-time analytics and routing
- `crm_email_search_accounting` - Search accounting emails (invoices, payments, quotes) with billing status and fiscal filtering

**Use Cases**:
- Lead tracking and qualification pipeline
- Customer database management
- Automated lead discovery and scoring
- Accounts receivable and invoice follow-up
- Customer support correspondence management
- Company prospecting and target analysis

**Documentation**: See `addons/pro/includes/tools/crm/`

---

### 21. 💬 Chat Channels Inbox

**Setting**: `enable_chat_channels_toolkit`  
**Status**: Pro addon required

**Features**:
- Unified inbox for Slack, Teams, Discord, Telegram, WhatsApp, and Google Chat
- AI-powered auto-reply to incoming messages
- Conversation threading and history
- JetEngine CCT storage for contacts and messages (when JetEngine is active — see below)

**JetEngine CCTs** (registered only when `enable_chat_channels_toolkit` is on):

| Class | CCT slug | Table | Purpose |
|---|---|---|---|
| `WP_MCP_AI_Channel_Contacts_CCT` | `channel_contacts` | `wp_jet_cct_channel_contacts` | Contact/conversation index per platform |
| `WP_MCP_AI_Channel_Messages_CCT` | `channel_messages` | `wp_jet_cct_channel_messages` | Individual messages per conversation |

> **Note**: When JetEngine is inactive, the toolkit falls back to CPT storage (`mcp_chan_contact` / `mcp_chan_message`). The CCT admin pages will not appear unless the toolkit is enabled.

**Documentation**: See `addons/pro/includes/chat-channels-toolkit-init.php`

---

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
☐ Enable AI Tool Builder Toolkit
☐ Enable Architectural Design Toolkit
☐ Enable Calendar Booking Toolkit
☐ Enable DJ Management Toolkit
☐ Enable E-commerce Toolkit
☐ Enable Financial Planning Toolkit
☐ Enable Image Production Toolkit
☐ Enable Social Media Toolkit
☐ Enable Multilingual Toolkit
☐ Enable Video Production Toolkit
☐ Enable Analytics Toolkit
☐ Enable CRM Toolkit
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

**Calendar Booking**:
- Configure business hours
- Set up Google Calendar or Outlook integration (optional)
- Configure email templates for confirmations

**Social Media Toolkit**:
- Add API keys for platforms (Twitter, Facebook, LinkedIn)
- Configure OAuth connections

**Image Production**:
- Add OpenAI API key (for DALL-E)
- Add Stability AI API key (for Stable Diffusion)
- Optional: Configure remove.bg API key

**Multilingual**:
- Add translation API keys (Google Translate, DeepL, or AWS)
- Configure default languages

**Video Production**:
- Ensure FFmpeg is installed and configured
- Verify temporary directory is writable

**E-commerce**:
- Ensure WooCommerce is installed and active
- Configure Stripe (for payment features)

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
- **Image Production**: `gd` or `imagick`
- **Video Production**: FFmpeg binary
- **All**: Standard WordPress requirements

### Optional Integrations
- **JetEngine**: Enhanced data storage for Projects, Places, ECAs, Calendar, DJ Management
- **WooCommerce**: Required for E-commerce Toolkit
- **Google Maps**: Required for Places Management
- **Cloudways**: Required for Cloudways Toolkit
- **WPML/Polylang**: Enhanced multilingual features

### External Services
- **Document Generation**: Node.js runtime (recommended)
- **Places**: Google Maps API
- **Cloudways**: Cloudways hosting account
- **Calendar Booking**: Google Calendar API, Outlook API (optional)
- **Social Media**: Twitter, Facebook, LinkedIn, Instagram APIs
- **Image Production**: OpenAI API, Stability AI API, remove.bg API
- **Multilingual**: Google Translate, DeepL, or AWS Translation APIs
- **Architectural Design**: OpenAI API with vision models
- **Analytics**: Google Analytics 4 API (optional)

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
- **AI Tool Builder**: ~5-8 MB
- **Architectural Design**: ~8-12 MB
- **Calendar Booking**: ~4-6 MB
- **DJ Management**: ~6-9 MB
- **E-commerce**: ~8-12 MB (requires WooCommerce)
- **Financial Planning**: ~7-10 MB
- **Image Production**: ~10-15 MB (with AI libraries)
- **Social Media**: ~6-9 MB
- **Multilingual**: ~4-6 MB
- **Video Production**: ~15-20 MB (FFmpeg dependencies)
- **Analytics**: ~5-8 MB
- **CRM**: ~1-2 MB

**Total if all enabled**: ~180-250 MB additional memory

**Recommendation**: Only enable toolkits you actively use.

### Database Impact

Toolkits that create custom post types:
- **Project Management**: Projects, Tasks, Events CPTs
- **Places**: Places CPT
- **ECA**: ECAs CPT
- **Health & Wellness**: Multiple CPTs for health data
- **Media Toolkit**: Media Templates, Collections CPTs
- **Calendar Booking**: Appointments, Blocked Times, Booking Links CPTs
- **DJ Management**: Equipment, Tracks, Playlists, Bookings, Clients CPTs
- **E-commerce**: Uses WooCommerce CPTs (Products, Orders)

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

### Image/Video Production Errors

**Check**:
1. FFmpeg installed and accessible (for video)
2. GD or Imagick extension enabled (for images)
3. API keys configured (OpenAI, Stability AI)
4. Temporary directory is writable
5. Memory limit sufficient for large files

### Social Media/Calendar Integration Errors

**Check**:
1. API credentials are valid
2. OAuth tokens not expired
3. Platform-specific permissions granted
4. Rate limits not exceeded

### E-commerce Toolkit Errors

**Check**:
1. WooCommerce is installed and active
2. WooCommerce version is compatible
3. Required permissions for product/order management

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
| Media | 7 | 5-10MB | None | Marketing |
| Documents | 15 | 50MB | Node.js | Reports |
| Projects | 13 | 3-5MB | Optional: JetEngine | Teams |
| Places | 8 | 2-3MB | Optional: Google Maps | Location |
| ECA | 14 | 2-3MB | Optional: iSAMS | Schools |
| Health | 48 | 5-8MB | None | Healthcare |
| Cloudways | 58+ | 10-15MB | Cloudways account | Hosting |
| AI CPT | 2 | 2-3MB | None | Content |
| AI Tool Builder | 10 | 5-8MB | None | Development |
| Architectural Design | 16 | 8-12MB | Vision AI, OpenAI | Architecture |
| Calendar Booking | 15 | 4-6MB | Optional: Google/Outlook | Appointments |
| DJ Management | 18 | 6-9MB | None | DJ Business |
| E-commerce | 20 | 8-12MB | WooCommerce | Online Stores |
| Financial Planner | 24 | 7-10MB | None | Finance |
| Image Production | 15 | 10-15MB | OpenAI, Stability AI | Images |
| Social Media | 15 | 6-9MB | Platform APIs | Social |
| Multilingual | 10 | 4-6MB | Translation APIs | Translation |
| Video Production | 12 | 15-20MB | FFmpeg | Video |
| Analytics | 12 | 5-8MB | Optional: GA4 | Analytics |
| CRM | 7 | 1-2MB | None | Contacts, Companies |

---

## Future Roadmap

**Recently Implemented** (now available):
- ✅ E-commerce Pro Toolkit (20 tools)
- ✅ Social Media Management Toolkit (15 tools)
- ✅ Image Production Toolkit (15 tools)
- ✅ AI Tool Builder Toolkit (10 tools)
- ✅ Architectural Design Toolkit (16 tools)
- ✅ Calendar Booking Toolkit (15 tools)
- ✅ DJ Management Toolkit (18 tools)
- ✅ Financial Planning Toolkit (32 tools - fully implemented)
- ✅ Video Production Toolkit (12 tools)
- ✅ Multilingual Content Toolkit (10 tools)
- ✅ Advanced Analytics Toolkit (12 tools - fully implemented)

**All Core Toolkits Complete**: All 20 planned Pro toolkits are now fully implemented with 300+ professional tools!

**Under Consideration**:
- Marketing Automation Toolkit
- Legal Document Toolkit
- Medical Practice Management
- Real Estate Pro Toolkit
- Restaurant Management Toolkit
- HR & Recruitment Toolkit
- Event Management Pro

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

**Version**: 2.0.0  
**Last Updated**: 2025-01-22  
**Applies to**: NV oOS Pro v1.1.0+
