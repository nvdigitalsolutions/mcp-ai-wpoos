# Open Operator System (NV oOS) - Pro Add-on

Enhanced WordPress plugin with advanced AI toolkits for document generation, image processing, math rendering, calendar management, data visualization, code formatting, email templates, geospatial analysis, and video processing.

## Overview

The Pro Add-on extends the base Open Operator System with 8 specialized toolkits powered by best-in-class NPM packages. All production dependencies are included in the repository for zero-configuration deployment.

## Enhanced Toolkits

### 1. Document Generation Toolkit ✅
**Packages**: `pdfkit`, `docx`, `exceljs`

Generate professional documents directly from WordPress:
- **PDF Generation**: Create reports, invoices, certificates using pdfkit
- **Word Documents**: Generate .docx files with rich formatting using docx
- **Excel Spreadsheets**: Create complex spreadsheets with formulas using exceljs

**Use Cases**: Automated reporting, invoice generation, export functionality

### 2. Media Toolkit ✅
**Package**: `sharp` v0.33.5

High-performance image processing for WordPress media:
- Resize, crop, rotate images with hardware acceleration
- Format conversion (JPEG, PNG, WebP, AVIF, TIFF)
- Color manipulation, effects, and filters
- Batch processing for collections
- Much faster than ImageMagick/GraphicsMagick

**Use Cases**: Batch image optimization, automated thumbnails, image transformations

### 3. Quiz System ✅
**Package**: `katex` v0.16.27

Render mathematical formulas in quizzes:
- LaTeX math equation rendering
- Support for complex mathematical notation
- Fast, server-side rendering
- Perfect for STEM education

**Use Cases**: Math and science quizzes, educational content, academic assessments

### 4. Project Management ✅
**Package**: `ics` v3.8.1

Calendar integration for project timelines:
- Generate .ics calendar files
- Export project events to Google Calendar, Outlook, Apple Calendar
- Share deadlines and milestones with team members

**Use Cases**: Project timeline exports, event sharing, calendar synchronization

### 5. Health & Wellness / Business Analytics ✅
**Package**: `chart.js` v4.5.1

Data visualization for health metrics and business data:
- Line charts for patient vitals tracking
- Bar charts for medication schedules
- Pie charts for health insurance analytics
- Interactive, responsive charts

**Use Cases**: Patient health dashboards, medication tracking, business analytics

### 6. Code Tools ✅
**Package**: `prettier` v3.8.0

Auto-format AI-generated code:
- Format JavaScript, PHP, CSS, HTML, JSON, YAML
- Consistent code style across projects
- Support for WordPress coding standards
- Clean, readable output

**Use Cases**: WPCode snippet formatting, automated code generation, development tools

### 7. Email Tools ✅
**Package**: `mjml` v4.18.0

Professional email template generation:
- Create responsive email layouts
- AI-generated marketing emails
- Transactional email templates
- Cross-client compatibility

**Use Cases**: Newsletter generation, automated email campaigns, notification templates

### 8. Places Management ✅
**Package**: `@turf/turf` v7.3.2

Advanced geospatial calculations:
- Distance calculations between locations
- Point-in-polygon queries
- Area measurements
- Proximity analysis
- Geographic buffering

**Use Cases**: Location-based services, proximity search, geographic analytics

### 9. Social Media / Video Tools ✅
**Package**: `fluent-ffmpeg` v2.1.3

Video processing for social media:
- Video transcoding and compression
- Format conversion
- Thumbnail generation
- Platform-specific optimization (Instagram, TikTok, YouTube)

**Use Cases**: Social media publishing, video optimization, content automation

## Installation

### Production Deployment (Recommended)

The plugin is **production-ready** after cloning. All required dependencies are included in the repository:

```bash
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
# No npm install needed! Dependencies are already included.
```

### Development Setup

For development with additional dev dependencies:

```bash
cd addons/pro
npm install
```

### Production Install (Optional)

To reinstall only production dependencies:

```bash
cd addons/pro
npm install --production
```

## Toolkit Activation

Toolkits are enabled through WordPress admin settings:

**Settings → NV oOS → Pro Features**

- `enable_media_toolkit` - Media image processing
- `enable_quiz_system` - Quiz management with math support
- `enable_project_management` - Project/task/event management
- `enable_health_wellness_management` - Health records system
- `enable_places_management` - Location management
- `enable_document_generation_toolkit` - PDF/Word/Excel generation
- `enable_woocommerce_tools` - E-commerce integration
- `enable_jetengine_tools` - JetEngine CCT integration

## Package Details

All packages are:
- **Actively maintained** (updated in last 6 months)
- **Well-documented** with extensive usage examples
- **MIT or permissive licenses**
- **High download counts** (community trusted)
- **Production-ready** with no critical security vulnerabilities

## Architecture

### Production Dependencies Strategy

The Pro add-on leverages production dependencies from the base plugin's `node_modules/` directory (managed via the root `package.json`). This ensures all Pro addon NPM packages are available without duplicating dependencies:

```json
// Base plugin package.json includes all Pro dependencies:
{
  "dependencies": {
    "@turf/turf": "^7.3.2",
    "sharp": "^0.33.5",
    "katex": "^0.16.11",
    "ics": "^3.8.1",
    "prettier": "^3.4.2",
    "mjml": "^4.18.0",
    "fluent-ffmpeg": "^2.1.3",
    // ... (see package.json for full list)
  }
}
```

This ensures:
- ✅ **Centralized dependency management** - All dependencies in one place
- ✅ **No duplication** - Shared dependencies between base and Pro
- ✅ **Production-ready** - Works immediately in WordPress
- ✅ **Simple updates** - Update versions in one location

### File Size Optimization

Test and documentation files are excluded from tracked packages:
- No test directories
- No example files
- No markdown docs (except README/LICENSE)
- Source files only where needed

## Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 7.4 or higher (8.0+ recommended)
- **Node.js**: 18+ (for development only, not required for production)

### System Requirements for Specific Packages

- **sharp**: Requires libvips (automatically handled by sharp)
- **fluent-ffmpeg**: Requires FFmpeg installed on server (optional)
- **prettier**: No additional requirements
- **katex**: No additional requirements

## Troubleshooting

For detailed troubleshooting guidance, see [TROUBLESHOOTING.md](./TROUBLESHOOTING.md).

### Common Issues

#### JavaScript/CSS Not Loading in Base+Pro Setup
If the Password Generator & Authenticator page displays but buttons don't work, ensure you're running v1.3.0+ which includes the fix for asset URL detection in separate plugin installations. See [TROUBLESHOOTING.md](./TROUBLESHOOTING.md#javascriptcss-not-loading) for details.

#### sharp Installation Issues

If sharp fails to install, ensure build tools are available:

```bash
# Ubuntu/Debian
sudo apt-get install build-essential libvips-dev

# CentOS/RHEL
sudo yum install gcc-c++ vips-devel

# macOS
brew install vips
```

### fluent-ffmpeg Not Working

Install FFmpeg on your server:

```bash
# Ubuntu/Debian
sudo apt-get install ffmpeg

# CentOS/RHEL
sudo yum install ffmpeg

# macOS
brew install ffmpeg
```

## Documentation

- **NPM Package Opportunities**: See `NPM_PACKAGE_OPPORTUNITIES.md` for detailed package analysis
- **Tool Reference**: See `/docs/tool-reference.md` in main repository
- **API Documentation**: See `/docs/rest-api.md` in main repository

## License

This is proprietary software. See LICENSE file for details.

**Patent Pending**: Application #19/410,504 - "System and Method for Dynamic AI Orchestration Layer with Real-Time Capability Gating and Resource Budgeting."

## Support

- **Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation**: See `/docs/` directory in main repository
- **Commercial Support**: Contact NV Digital Solutions

---

**Copyright (c) 2025 NV Digital Solutions**
https://nvdigitalsolutions.com
