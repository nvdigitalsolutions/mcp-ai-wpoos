# Asset Inventory and Classification
## Open Operator System (NV oOS) WordPress Plugin

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-05  
**Review Date:** 2026-04-05  
**Document Owner:** Operations Team

---

## 1. Purpose

This document provides a comprehensive inventory of information assets for the NV oOS WordPress plugin and defines their classification, ownership, and security requirements in accordance with ISO/IEC 27001:2022 controls A.5.9 and A.5.12.

## 2. Asset Categories

### 2.1 Information Assets

#### Source Code
| Asset ID | Asset Name | Classification | Owner | Location | Security Controls |
|----------|------------|----------------|-------|----------|-------------------|
| INFO-001 | PHP Source Code | Confidential | Dev Team | GitHub Private Repo | Access control, version control, code review |
| INFO-002 | JavaScript Source | Confidential | Dev Team | GitHub Private Repo | Access control, version control |
| INFO-003 | CSS/Assets | Internal | Dev Team | GitHub Private Repo | Version control |

#### Documentation
| Asset ID | Asset Name | Classification | Owner | Location | Security Controls |
|----------|------------|----------------|-------|----------|-------------------|
| INFO-004 | ISMS Documentation | Internal | CISO | GitHub + docs/compliance/ | Version control, access control |
| INFO-005 | API Documentation | Internal | Dev Team | GitHub + docs/ | Version control |
| INFO-006 | User Documentation | Public | Support | GitHub + docs/ | Version control |
| INFO-007 | Technical Specs | Confidential | Dev Team | GitHub + docs/ | Access control |

#### Configuration Data
| Asset ID | Asset Name | Classification | Owner | Location | Security Controls |
|----------|------------|----------------|-------|----------|-------------------|
| INFO-008 | Plugin Settings | Confidential | Ops Team | WordPress Database | Encryption, access control |
| INFO-009 | API Keys (OpenAI) | Restricted | Admin Users | WordPress DB (encrypted) | AES-256 encryption, access control |
| INFO-010 | API Keys (Gemini) | Restricted | Admin Users | WordPress DB (encrypted) | AES-256 encryption, access control |
| INFO-011 | Master Encryption Key | Restricted | CISO | Secure key storage | Encrypted, limited access |
| INFO-012 | Root Security Key | Restricted | Admin | Secure storage | Encrypted, emergency access only |

#### User Data
| Asset ID | Asset Name | Classification | Owner | Location | Security Controls |
|----------|------------|----------------|-------|----------|-------------------|
| INFO-013 | Chat Transcripts | Confidential | Users | WordPress DB / JetEngine CCT | Encryption, access control, retention policy |
| INFO-014 | Uploaded Files | Confidential | Users | wp-content/uploads/mcp-ai/ | Access control, virus scanning |
| INFO-015 | User Credentials | Restricted | Users | WordPress DB | bcrypt hashing, TLS |
| INFO-016 | Audit Logs | Confidential | Ops Team | WordPress DB | Access control, 12-month retention |

#### Development Assets
| Asset ID | Asset Name | Classification | Owner | Location | Security Controls |
|----------|------------|----------------|-------|----------|-------------------|
| INFO-017 | Test Data | Internal | Dev Team | Development environments | Sanitized, no production data |
| INFO-018 | Build Artifacts | Internal | Dev Team | GitHub Actions / CI/CD | Access control, temporary storage |
| INFO-019 | Dependencies | Public/Internal | Dev Team | composer.json, package.json | Vulnerability scanning |

### 2.2 Technology Assets

#### Development Tools
| Asset ID | Asset Name | Classification | Owner | Security Controls |
|----------|------------|----------------|-------|-------------------|
| TECH-001 | Git Version Control | Internal | Dev Team | SSH keys, 2FA, branch protection |
| TECH-002 | GitHub Repository | Confidential | Dev Team | 2FA, access control, audit logs |
| TECH-003 | Composer (PHP) | Public | Dev Team | Lock file integrity, vulnerability scanning |
| TECH-004 | NPM (JavaScript) | Public | Dev Team | Lock file integrity, vulnerability scanning |
| TECH-005 | CodeQL Scanner | Internal | Security Team | Automated scanning, integrated in CI/CD |
| TECH-006 | Dependabot | Internal | Security Team | Automated vulnerability detection |

#### Third-Party APIs
| Asset ID | Asset Name | Classification | Owner | Security Controls |
|----------|------------|----------------|-------|-------------------|
| TECH-007 | OpenAI API | External | OpenAI | API key authentication, rate limiting, TLS |
| TECH-008 | Google Gemini API | External | Google | API key authentication, rate limiting, TLS |
| TECH-009 | Ollama (Local AI) | Internal/External | User-deployed | Local network, user-managed |

#### WordPress Platform
| Asset ID | Asset Name | Classification | Owner | Security Controls |
|----------|------------|----------------|-------|-------------------|
| TECH-010 | WordPress Core | Public | WP Community | Regular updates, security patches |
| TECH-011 | WordPress Database | Confidential | Ops Team | Access control, backups, encryption at rest |
| TECH-012 | WordPress REST API | Internal | Dev Team | Authentication, nonces, capability checks |

#### Infrastructure
| Asset ID | Asset Name | Classification | Owner | Security Controls |
|----------|------------|----------------|-------|-------------------|
| TECH-013 | Web Server | Internal | Hosting Provider | Firewall, security patches, monitoring |
| TECH-014 | Database Server | Confidential | Hosting Provider | Firewall, encryption, access control |
| TECH-015 | File Storage | Confidential | Hosting Provider | Encryption at rest, access control |
| TECH-016 | Backup Storage | Confidential | Hosting Provider | Encryption, geographic redundancy |

### 2.3 People Assets

#### Development Team
| Asset ID | Role | Classification | Responsibilities | Access Level |
|----------|------|----------------|------------------|--------------|
| PPL-001 | Lead Developer | Internal | Code architecture, reviews | Full repository access |
| PPL-002 | Backend Developer | Internal | PHP development, security | Repository read/write |
| PPL-003 | Frontend Developer | Internal | JavaScript, UI/UX | Repository read/write |

#### Operations Team
| Asset ID | Role | Classification | Responsibilities | Access Level |
|----------|------|----------------|------------------|--------------|
| PPL-004 | Operations Manager | Internal | Infrastructure, monitoring | Admin access to systems |
| PPL-005 | DevOps Engineer | Internal | CI/CD, deployments | Admin access, deployment keys |

#### Security Team
| Asset ID | Role | Classification | Responsibilities | Access Level |
|----------|------|----------------|------------------|--------------|
| PPL-006 | CISO | Internal | ISMS oversight, audits | Full access, security oversight |
| PPL-007 | Security Analyst | Internal | Monitoring, incident response | Security tools, logs access |

## 3. Asset Classification

### 3.1 Classification Levels

#### Public
**Definition:** Information that can be freely shared with anyone  
**Examples:** Public documentation, open-source code (GPL v3), marketing materials  
**Handling:** Basic integrity protection, version control  
**Labeling:** "Public" header in documents

#### Internal
**Definition:** Information for internal use only  
**Examples:** Internal documentation, development plans, non-sensitive configs  
**Handling:** Access restricted to authorized personnel  
**Labeling:** "Internal" header in documents

#### Confidential
**Definition:** Sensitive information requiring protection  
**Examples:** API keys, user data, chat transcripts, business information  
**Handling:** Encryption, strict access control, audit logging  
**Labeling:** "Confidential" header in documents

#### Restricted
**Definition:** Highly sensitive information with severe impact if disclosed  
**Examples:** Master encryption keys, security vulnerability details (pre-patch), legal documents  
**Handling:** Highest protection level, need-to-know basis only  
**Labeling:** "Restricted" header in documents

### 3.2 Classification Criteria

**Confidentiality Impact:**
- Public: No impact
- Internal: Minor impact (operational inefficiency)
- Confidential: Significant impact (data breach, reputation damage)
- Restricted: Severe impact (system compromise, legal liability)

**Integrity Impact:**
- Public: Low (correctable)
- Internal: Medium (operational issues)
- Confidential: High (security implications)
- Restricted: Critical (system-wide compromise)

**Availability Impact:**
- Public: Low (inconvenience)
- Internal: Medium (operational delays)
- Confidential: High (business disruption)
- Restricted: Critical (security failure)

## 4. Asset Ownership

### 4.1 Owner Responsibilities

**Asset Owners Must:**
- Classify assets appropriately
- Define access requirements
- Ensure proper protection
- Review access regularly
- Update inventory
- Report incidents

### 4.2 Ownership Assignment

| Asset Category | Primary Owner | Backup Owner |
|---------------|---------------|--------------|
| Source Code | Development Team Lead | CTO |
| Documentation | Technical Writer | Development Team Lead |
| Configuration | Operations Manager | DevOps Engineer |
| User Data | Product Owner | Operations Manager |
| Security Assets | CISO | Security Team Lead |
| Infrastructure | Operations Manager | DevOps Engineer |

## 5. Asset Lifecycle

### 5.1 Creation
- Asset created and classified
- Owner assigned
- Added to inventory
- Security controls applied
- Access granted as needed

### 5.2 Usage
- Used according to classification
- Access monitored and logged
- Regular reviews conducted
- Updates tracked

### 5.3 Modification
- Changes tracked in version control
- Security reassessed if needed
- Reclassification if required
- Documentation updated

### 5.4 Archival
- Assets moved to archive storage
- Access restricted further
- Retention policy applied
- Encrypted if sensitive

### 5.5 Disposal
- Secure deletion methods
- Crypto-shredding for encrypted data
- Physical media destruction if applicable
- Disposal documented

## 6. Security Controls by Classification

### 6.1 Public Assets
- Version control
- Integrity checks
- No access restrictions

### 6.2 Internal Assets
- Access control (authenticated users)
- Version control
- Audit logging of access

### 6.3 Confidential Assets
- Strict access control (role-based)
- Encryption at rest (AES-256)
- Encryption in transit (TLS 1.2+)
- Comprehensive audit logging
- Regular access reviews

### 6.4 Restricted Assets
- Maximum protection
- Need-to-know access only
- Encryption at rest and in transit
- Hardware security modules (HSM) where applicable
- Detailed audit logging
- Quarterly access reviews
- MFA for access

## 7. Asset Review and Maintenance

### 7.1 Review Schedule

**Quarterly Review:**
- Asset inventory completeness
- Classification accuracy
- Owner assignments
- Access rights

**Annual Review:**
- Comprehensive asset audit
- Security control effectiveness
- Disposal of obsolete assets
- Update asset register

### 7.2 Review Process

1. Generate asset report
2. Verify asset existence and details
3. Confirm classification
4. Validate owner assignments
5. Review access rights
6. Update inventory
7. Document changes

## 8. Asset Valuation

### 8.1 Valuation Criteria

**Financial Value:**
- Development cost
- Replacement cost
- Revenue impact

**Business Value:**
- Strategic importance
- Competitive advantage
- Legal/regulatory requirements

**Security Impact:**
- Confidentiality impact
- Integrity impact
- Availability impact

### 8.2 High-Value Assets

| Asset | Value | Criticality | Protection Level |
|-------|-------|-------------|------------------|
| Master Encryption Key | Critical | Highest | Restricted |
| User Database | High | High | Confidential |
| API Keys | High | High | Restricted |
| Source Code | High | High | Confidential |
| Chat Transcripts | Medium | Medium | Confidential |

## 9. Asset Dependencies

### 9.1 Critical Dependencies

**Source Code depends on:**
- Development tools (Git, Composer, NPM)
- Third-party libraries
- Documentation

**User Data depends on:**
- WordPress database
- Encryption keys
- Backup systems

**API Keys depend on:**
- Encryption keys
- Access control system
- Audit logging

## 10. Backup and Recovery

### 10.1 Backup Requirements by Classification

| Classification | Backup Frequency | Retention | Location |
|---------------|------------------|-----------|----------|
| Public | Weekly | 4 weeks | Local |
| Internal | Daily | 30 days | Local + Cloud |
| Confidential | Daily | 90 days | Encrypted cloud |
| Restricted | Real-time | 1 year | Encrypted, geographic redundancy |

### 10.2 Recovery Priority

**Priority 1 (Critical):**
- Master encryption keys
- User authentication database
- API keys

**Priority 2 (High):**
- Source code
- Configuration data
- User data

**Priority 3 (Medium):**
- Documentation
- Chat transcripts
- Logs

## 11. Compliance Requirements

### 11.1 Legal and Regulatory

**GDPR Requirements:**
- User data classification
- Retention policies
- Right to erasure capability
- Data protection impact assessments

**GPL v3:**
- Source code availability
- License file inclusion
- Derivative works compliance

### 11.2 Contractual

**OpenAI Terms:**
- API key protection
- Usage data handling
- Rate limit compliance

**Google Gemini Terms:**
- API key protection
- Data processing agreements

## 12. Asset Inventory Tools

### 12.1 Tracking Methods

**Version Control:**
- Git for source code and documentation
- Commit history as change log

**Database:**
- WordPress database for runtime assets
- Structured asset register (spreadsheet/database)

**Automated Discovery:**
- Dependency scanning (Composer, NPM)
- File system monitoring

### 12.2 Inventory Reports

**Monthly Report:**
- New assets added
- Assets modified
- Assets removed
- Classification changes

**Quarterly Report:**
- Complete asset inventory
- Access review results
- Security control status
- Recommendations

## 13. References

- [ISMS Policy](../ISMS-Policy.md)
- [Statement of Applicability](../Statement-of-Applicability.md)
- [Access Control Procedure](../procedures/Access-Control.md)
- [Risk Assessment](../Risk-Assessment.md)

## 14. Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-05 | GitHub Copilot | Initial asset inventory and classification |

---

**Next Review:** 2026-04-05 (Quarterly)
