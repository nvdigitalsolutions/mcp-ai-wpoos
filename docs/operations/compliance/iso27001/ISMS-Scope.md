# ISMS Scope Document
## Open Operator System (NV oOS) WordPress Plugin

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-05  
**Review Date:** 2026-07-05  
**Document Owner:** Chief Information Security Officer (CISO)

---

## 1. Purpose

This document defines the scope and boundaries of the Information Security Management System (ISMS) for the Open Operator System (NV oOS) WordPress plugin, in accordance with ISO/IEC 27001:2022 requirements.

## 2. Organization Context

### 2.1 Organization Profile
- **Organization:** NV Digital Solutions
- **Product:** Open Operator System (NV oOS)
- **Type:** WordPress Plugin - AI Assistant Framework
- **License:** GPL v3
- **Primary Function:** AI-powered assistant framework integrating OpenAI, Gemini, Ollama, and MCP tools

### 2.2 Business Context
The NV oOS plugin provides:
- Multi-provider AI integration (OpenAI GPT, Google Gemini, Ollama)
- Model Context Protocol (MCP) tool execution framework
- 519 built-in tools (165 base + 348 pro + 6 core/memory) for WordPress operations
- Custom assistant creation and management
- Chat interface for end-users
- Integration with WordPress, WooCommerce, JetEngine, Elementor

## 3. ISMS Scope Definition

### 3.1 In Scope

#### 3.1.1 Products and Services
- **Core Plugin:** Base WordPress plugin with 165 base tools
- **Full Version:** Complete plugin with 519 tools (165 base + 348 pro + 6 core/memory) and integrations
- **Documentation:** All technical and user documentation
- **Support Services:** Plugin support and maintenance

#### 3.1.2 Information Assets
- **Source Code:** PHP, JavaScript, CSS files
- **Documentation:** User guides, API documentation
- **Configuration Data:** Settings, API keys, credentials
- **User Data:** Chat transcripts, uploaded files, usage metrics
- **Development Assets:** Git repositories, CI/CD pipelines
- **Infrastructure:** Hosting environments, databases, file storage

#### 3.1.3 Technology
- **Development:**
  - PHP 7.4+ (WordPress backend)
  - JavaScript/Node.js (Frontend)
  - Composer (dependency management)
  - NPM (JavaScript dependencies)
  
- **Platforms:**
  - WordPress 6.0+
  - MySQL/MariaDB databases
  - Web servers (Apache/Nginx)
  
- **Third-Party APIs:**
  - OpenAI API (GPT models, DALL-E, Whisper)
  - Google Gemini API (Gemini models, Imagen)
  - Ollama (local AI models)
  - WordPress REST API
  - Optional: WooCommerce, JetEngine, Elementor APIs

#### 3.1.4 Locations
- **Development:** Remote development team (distributed)
- **Production:** Customer WordPress installations (global)
- **Hosting:** Cloud-based infrastructure (varies by deployment)
- **Repository:** GitHub (https://github.com/nvdigitalsolutions/mcp-ai-wpoos)

#### 3.1.5 Personnel
- Development team members
- Security team members
- Support staff
- Administrative personnel
- Third-party contractors (as applicable)

#### 3.1.6 Processes
- Software development lifecycle (SDLC)
- Code review and quality assurance
- Security vulnerability management
- Incident response and handling
- Backup and recovery
- Access control and authentication
- Change management
- Vendor management

### 3.2 Out of Scope

#### 3.2.1 Exclusions
The following are **explicitly excluded** from the ISMS scope:

1. **End-User WordPress Installations:**
   - Customer WordPress sites where plugin is installed
   - Customer hosting infrastructure and management
   - Customer data security (except plugin-specific data handling)
   - Customer network security

2. **Third-Party Services (Not Under Our Control):**
   - OpenAI infrastructure and data processing
   - Google Gemini infrastructure and data processing
   - Ollama local installations by end-users
   - WordPress.org infrastructure
   - Third-party plugin/theme security

3. **Hardware:**
   - End-user computers and devices
   - Customer server hardware
   - Physical data center facilities (managed by hosting providers)

4. **Non-Technical Business Functions:**
   - Human resources management
   - Financial accounting systems
   - Marketing systems (not directly integrated with plugin)
   - General office IT infrastructure

#### 3.2.2 Rationale for Exclusions
- **Customer Environments:** Customers are responsible for their own WordPress installations, hosting security, and infrastructure
- **Third-Party Services:** These are covered by their own security certifications (OpenAI, Google)
- **Physical Infrastructure:** Cloud hosting providers maintain ISO 27001 certification for their facilities
- **Non-Technical Functions:** Not directly related to plugin security or information assets

### 3.3 Scope Boundaries

#### 3.3.1 Logical Boundaries
- **Application Layer:** Plugin code and functionality
- **Data Layer:** Plugin-specific data storage (settings, credentials, logs)
- **Integration Layer:** APIs and interfaces with third-party services
- **User Interface:** Admin dashboard and chat interfaces

#### 3.3.2 Network Boundaries
- **Development Network:** Development team access to repositories and CI/CD
- **Production Network:** Plugin communication with AI providers and WordPress APIs
- **Administrative Network:** Access to plugin settings and management interfaces

#### 3.3.3 Organizational Boundaries
- **Internal:** NV Digital Solutions development and support teams
- **External:** End-users (WordPress administrators and site visitors)
- **Third-Party:** AI service providers, WordPress ecosystem partners

## 4. Interfaces and Dependencies

### 4.1 Internal Interfaces
- Development repository (GitHub)
- CI/CD pipeline (GitHub Actions)
- Documentation platform
- Issue tracking system

### 4.2 External Interfaces
- **AI Providers:**
  - OpenAI API (authentication, model access, data processing)
  - Google Gemini API (authentication, model access, data processing)
  - Ollama (local deployment, self-hosted)
  
- **WordPress Ecosystem:**
  - WordPress Core (hooks, filters, APIs)
  - WordPress.org Plugin Directory
  - Optional: WooCommerce, JetEngine, Elementor, Rank Math
  
- **Infrastructure:**
  - Web servers (Apache/Nginx)
  - Database servers (MySQL/MariaDB)
  - File storage systems

### 4.3 Dependencies
- PHP runtime environment (7.4+)
- WordPress framework (6.0+)
- Composer packages (see composer.json)
- NPM packages (see package.json)
- OpenSSL for encryption
- TLS/SSL certificates for HTTPS

## 5. Information Security Requirements

### 5.1 Confidentiality Requirements
- **High:** API keys, authentication tokens, master encryption keys
- **Medium:** User data, chat transcripts, uploaded files
- **Low:** Plugin configuration, public documentation

### 5.2 Integrity Requirements
- **High:** Source code, authentication mechanisms, audit logs
- **Medium:** User data, configuration settings
- **Low:** Public documentation, UI assets

### 5.3 Availability Requirements
- **High:** Core plugin functionality, API access
- **Medium:** Admin dashboard, logging systems
- **Low:** Documentation, optional features

## 6. Legal and Regulatory Requirements

### 6.1 Data Protection
- **GDPR:** General Data Protection Regulation (EU)
- **CCPA:** California Consumer Privacy Act (USA)
- **Similar Laws:** Other regional data protection laws

### 6.2 Industry Standards
- **ISO/IEC 27001:2022:** Information Security Management
- **ISO/IEC 27002:2022:** Information Security Controls
- **OWASP Top 10:** Web Application Security Risks
- **WordPress Security Standards:** Platform-specific guidelines

### 6.3 Licensing and IP
- **GPL v3:** GNU General Public License (plugin code)
- **Copyright:** NV Digital Solutions
- **Third-Party Licenses:** Dependencies and integrations

### 6.4 AI Provider Terms
- OpenAI Terms of Service and Usage Policies
- Google Gemini Terms of Service
- Responsible AI usage guidelines

## 7. Risk Context

### 7.1 Internal Issues
- Development resource constraints
- Code complexity and maintainability
- Dependency management
- Team skill levels and training

### 7.2 External Issues
- Third-party API changes and deprecations
- WordPress core updates and compatibility
- Evolving threat landscape
- Regulatory changes
- AI technology evolution

### 7.3 Interested Parties

#### 7.3.1 Internal Stakeholders
- Development team
- Management
- Support team

#### 7.3.2 External Stakeholders
- End-users (WordPress administrators)
- Site visitors (chat users)
- AI service providers
- WordPress community
- Regulatory authorities

## 8. ISMS Scope Changes

### 8.1 Change Process
Changes to the ISMS scope require:
1. Formal change request
2. Security impact assessment
3. Management approval
4. Documentation update
5. Stakeholder communication

### 8.2 Triggers for Scope Review
- Significant product changes
- New integrations or services
- Organizational changes
- Regulatory requirement changes
- Security incident findings
- Annual ISMS review

### 8.3 Scope Review Schedule
- **Regular Review:** Annually (minimum)
- **Ad-hoc Review:** As needed based on triggers
- **Next Scheduled Review:** 2026-07-05

## 9. Scope Statement Summary

> The ISMS covers the development, deployment, maintenance, and support of the Open Operator System (NV oOS) WordPress plugin, including all information assets, processes, personnel, and technology directly under the control of NV Digital Solutions. The scope encompasses the plugin's core functionality, integrations with AI providers, security controls, and data handling processes. Customer WordPress installations, third-party service infrastructure, and physical facilities managed by hosting providers are outside the ISMS scope.

## 10. Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Management | [To be completed] | [Digital signature] | 2026-01-05 |
| CISO | [To be completed] | [Digital signature] | 2026-01-05 |

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-05 | GitHub Copilot | Initial ISMS Scope document |

---

**Next Scheduled Review:** 2026-07-05
