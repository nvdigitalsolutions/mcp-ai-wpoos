# ICT Readiness for Business Continuity

**Control:** A.5.30  
**Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-06  
**Owner:** Chief Information Security Officer (CISO)

---

## 1. Overview

This document defines the ICT (Information and Communication Technology) continuity procedures for the NV oOS WordPress plugin, ensuring availability and resilience of critical services in the event of disruptions.

---

## 2. Scope

This procedure covers:
- **Recovery Time Objectives (RTO)** - Maximum acceptable downtime
- **Recovery Point Objectives (RPO)** - Maximum acceptable data loss
- **Failover procedures** for critical components
- **Backup and recovery processes**
- **Multi-provider redundancy strategy**

---

## 3. Recovery Objectives

### 3.1 Recovery Time Objectives (RTO)

| Component | RTO | Justification |
|-----------|-----|---------------|
| **AI Provider APIs** | 5 minutes | Automatic failover between OpenAI, Gemini, Ollama |
| **WordPress Plugin Core** | 1 hour | Standard plugin reactivation/reinstall |
| **Database (WordPress)** | 4 hours | WordPress database backup restoration |
| **Configuration Settings** | 30 minutes | Stored in WordPress options, quick restore |
| **Custom Post Types** | 4 hours | Database restoration required |
| **File Storage** | 2 hours | WordPress uploads backup restoration |

### 3.2 Recovery Point Objectives (RPO)

| Data Type | RPO | Backup Frequency | Justification |
|-----------|-----|------------------|---------------|
| **Configuration Data** | 1 hour | Continuous (WordPress options) | No scheduled backups needed, options persist |
| **Assistant Definitions** | 24 hours | Daily backup recommended | Low change frequency |
| **Chat Transcripts** | 1 hour | Real-time (if JetEngine CCT enabled) | User data protection |
| **Training Completions** | 24 hours | Daily backup recommended | Low change frequency |
| **Security Logs** | 1 hour | Real-time to external SIEM (optional) | Compliance requirement |
| **API Keys/Credentials** | 24 hours | Daily encrypted backup | Sensitive data |

---

## 4. Redundancy Architecture

### 4.1 AI Provider Redundancy

The plugin implements multi-provider redundancy for AI services:

**Primary Providers:**
1. **OpenAI GPT** (gpt-4o, gpt-4-turbo, gpt-3.5-turbo)
2. **Google Gemini** (gemini-2.0-flash-exp, gemini-1.5-pro)
3. **Ollama** (Local deployment: llama3.3, mistral, etc.)

**Failover Logic:**
```php
// Automatic failover implementation
if ( ! $openai_available ) {
    // Try Gemini
    if ( ! $gemini_available ) {
        // Try Ollama (local)
        if ( ! $ollama_available ) {
            // Log error and return graceful failure
        }
    }
}
```

**Failover Time:** < 5 seconds per request

### 4.2 Storage Redundancy

- **WordPress Database:** Relies on hosting provider database redundancy
- **File Storage:** WordPress uploads directory with hosting backup
- **Options Table:** WordPress core persistence mechanism
- **Custom Tables:** JetEngine CCT (optional) for chat transcripts

**Recommendations:**
- Use managed WordPress hosting with automated daily backups
- Enable JetEngine CCT for persistent chat transcript storage
- Configure external backup service (e.g., UpdraftPlus, BackWPup)

---

## 5. Backup Procedures

### 5.1 Configuration Backup

**Frequency:** On-demand  
**Method:** WordPress export or WP-CLI

```bash
# Export plugin settings
wp option get wp_mcp_ai_settings --format=json > wp-mcp-ai-settings-backup.json

# Export assistants
wp post list --post_type=mcp_ai_assistant --format=json > assistants-backup.json
```

### 5.2 Database Backup

**Frequency:** Daily (recommended)  
**Method:** WordPress database backup plugin or hosting provider

**Automated Backup (UpdraftPlus example):**
- Schedule: Daily at 2:00 AM server time
- Retention: 30 days
- Remote storage: AWS S3, Google Drive, or Dropbox

### 5.3 File Backup

**Frequency:** Weekly (recommended)  
**Method:** Full site backup including plugins directory

**Critical Files:**
- `/wp-content/plugins/mcp-ai-wpoos/` - Plugin files
- `/wp-content/uploads/` - User uploads (if applicable)

---

## 6. Disaster Recovery Procedures

### 6.1 Scenario 1: AI Provider Outage

**Trigger:** Primary AI provider (OpenAI) returns 503 Service Unavailable

**Response:**
1. **Automatic failover** to secondary provider (Gemini) - No manual action required
2. **Monitor** - Check status page: https://status.openai.com
3. **Log incident** - Record in incident management system
4. **Notify users** (optional) - Display banner if extended outage

**Recovery Steps:**
- No manual recovery needed - automatic failback when primary recovers
- Verify functionality after recovery: test assistant responses

**RTO:** 5 minutes  
**RPO:** 0 (no data loss)

### 6.2 Scenario 2: Plugin Corruption

**Trigger:** Plugin fails to load, fatal errors, or unexpected behavior

**Response:**
1. **Deactivate plugin** via WordPress admin or WP-CLI
   ```bash
   wp plugin deactivate mcp-ai-wpoos
   ```
2. **Reinstall plugin**
   ```bash
   wp plugin install mcp-ai-wpoos --activate --force
   ```
3. **Restore settings** from backup
   ```bash
   wp option update wp_mcp_ai_settings "$(cat wp-mcp-ai-settings-backup.json)"
   ```
4. **Verify functionality** - Test key features

**RTO:** 1 hour  
**RPO:** 24 hours (settings)

### 6.3 Scenario 3: Database Corruption

**Trigger:** Database errors, data loss, or WordPress inaccessible

**Response:**
1. **Access database** via phpMyAdmin or command line
2. **Restore from backup**
   ```bash
   mysql -u username -p database_name < wordpress-backup.sql
   ```
3. **Verify WordPress** is accessible
4. **Check plugin status** - Ensure all data restored
5. **Test critical functions** - Verify assistants, settings, users

**RTO:** 4 hours  
**RPO:** 24 hours (daily backups)

### 6.4 Scenario 4: Complete Site Loss

**Trigger:** Hosting provider failure, server crash, or data center disaster

**Response:**
1. **Provision new hosting** environment
2. **Install WordPress** (same version)
3. **Restore database** from backup
4. **Restore files** from backup
5. **Reinstall and activate plugin**
6. **Reconfigure external services** (API keys if not in backup)
7. **Verify all functionality**

**RTO:** 8-24 hours  
**RPO:** 24 hours

---

## 7. Failover Testing

### 7.1 AI Provider Failover Test

**Frequency:** Quarterly  
**Procedure:**
1. Temporarily disable OpenAI API key in settings
2. Send test chat message to assistant
3. Verify automatic failover to Gemini
4. Check logs for failover event
5. Re-enable OpenAI API key
6. Verify failback to primary provider

**Success Criteria:**
- Failover completes within 5 seconds
- No user-facing errors
- Chat response generated successfully

### 7.2 Backup Restoration Test

**Frequency:** Semi-annually  
**Procedure:**
1. Create test WordPress installation
2. Restore most recent backup to test environment
3. Verify all plugin functionality
4. Verify all assistants and settings intact
5. Document any issues

**Success Criteria:**
- Restoration completes within defined RTO
- All data intact (within RPO)
- Plugin fully functional

---

## 8. Monitoring and Alerts

### 8.1 Uptime Monitoring

**Tool:** UptimeRobot, Pingdom, or StatusCake  
**Frequency:** Every 5 minutes  
**Endpoints:**
- Main WordPress site
- REST API endpoint: `/wp-json/mcp-ai/v1/assistants`

### 8.2 AI Provider Monitoring

**Method:** Automated health checks  
**Frequency:** Every request (passive)  
**Alerts:**
- Email to admin if failover occurs > 5 times in 1 hour
- Log all provider unavailability events

### 8.3 Backup Monitoring

**Method:** Backup plugin notifications  
**Alerts:**
- Email notification on successful backup
- Immediate alert on backup failure
- Weekly summary report

---

## 9. Contact Information

### 9.1 Emergency Contacts

| Role | Contact | Responsibility |
|------|---------|----------------|
| **CISO** | security@nvdigitalsolutions.com | Overall incident response |
| **Technical Lead** | support@nvdigitalsolutions.com | Technical recovery |
| **Hosting Support** | [Provider contact] | Infrastructure issues |

### 9.2 Vendor Contacts

| Vendor | Status Page | Support Contact |
|--------|-------------|-----------------|
| **OpenAI** | https://status.openai.com | https://help.openai.com |
| **Google (Gemini)** | https://status.cloud.google.com | Google Cloud Support |
| **WordPress.org** | https://wordpress.org/support | Community Forums |

---

## 10. Documentation and Training

### 10.1 Required Documentation
- ✅ This ICT Continuity Procedures document
- ✅ Business Continuity Plan (Business-Continuity-Plan.md)
- ✅ Incident Response Procedures
- ✅ Backup and Recovery Procedures

### 10.2 Training Requirements
- Annual DR drill participation (all technical staff)
- Quarterly failover test execution (CISO, Technical Lead)
- New team member onboarding includes DR procedures review

---

## 11. Review and Updates

**Review Frequency:** Semi-annually or after any major incident  
**Next Review Date:** 2026-07-06  
**Approval Required:** CISO

**Change Log:**
| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | GitHub Copilot | Initial document creation |

---

**Status:** ✅ Implemented  
**Control:** ISO/IEC 27001:2022 A.5.30 - ICT Readiness for Business Continuity  
**Classification:** Internal
