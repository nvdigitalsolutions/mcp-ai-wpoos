# Backup and Recovery Procedure
## Open Operator System (NV oOS) WordPress Plugin

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-05  
**Review Date:** 2026-04-05  
**Document Owner:** Operations Team

---

## 1. Purpose

This procedure defines backup and recovery processes for the NV oOS WordPress plugin to ensure business continuity and data availability in the event of system failures, disasters, or data loss incidents.

## 2. Scope

This procedure covers:
- Plugin configuration data
- Database tables and content
- Uploaded files and media
- Chat transcripts and user data
- Audit logs and security events
- API credentials and encryption keys

## 3. Backup Strategy

### 3.1 Backup Types

#### Full Backup
- Complete copy of all plugin data
- Frequency: Weekly
- Retention: 4 weeks (rolling) + 12 months (yearly snapshot)

#### Incremental Backup
- Changes since last backup
- Frequency: Daily
- Retention: 30 days

#### Differential Backup
- Changes since last full backup
- Frequency: Not used (incremental preferred)

### 3.2 Backup Scope

**Included in Backups:**

```
WordPress Database:
├── wp_posts (mcp_ai_assistant posts)
├── wp_postmeta (assistant configuration)
├── wp_options (plugin settings)
│   ├── wp_mcp_ai_*
│   ├── wp_mcp_ai_credentials_*
│   └── wp_mcp_ai_settings_*
├── Custom Tables (if using JetEngine CCT)
│   ├── wp_jet_cct_mcp_ai_transcripts
│   └── wp_jet_cct_mcp_ai_assistants
└── wp_nvoos_access_log (if implemented)

File System:
├── /wp-content/uploads/mcp-ai/
│   ├── user-uploads/
│   ├── generated-images/
│   ├── generated-audio/
│   └── generated-videos/
└── /wp-content/plugins/mcp-ai-wpoos/
    └── (source code - version controlled via Git)
```

**Excluded from Backups:**
- Temporary files (`/tmp/`)
- Cache files
- Third-party plugin/theme files (unless modified)
- WordPress core files (unless modified)

### 3.3 Backup Schedule

| Backup Type | Frequency | Time (UTC) | Retention |
|-------------|-----------|------------|-----------|
| Full | Weekly | Sunday 02:00 | 4 weeks + yearly |
| Incremental | Daily | 03:00 | 30 days |
| On-demand | Manual | As needed | 7 days |

## 4. Backup Methods

### 4.1 Database Backup

**WordPress Database Export:**
```bash
# Using WP-CLI
wp db export /path/to/backup/nvoos-db-$(date +%Y%m%d).sql --tables=$(wp db tables 'wp_*' --format=csv)

# With compression
wp db export - | gzip > /path/to/backup/nvoos-db-$(date +%Y%m%d).sql.gz
```

**MySQL/MariaDB Direct:**
```bash
mysqldump -u username -p database_name \
  --tables wp_posts wp_postmeta wp_options \
  --where="post_type='mcp_ai_assistant' OR option_name LIKE 'wp_mcp_ai_%'" \
  > nvoos-backup-$(date +%Y%m%d).sql
```

### 4.2 File System Backup

**Using rsync:**
```bash
rsync -avz --delete \
  /var/www/html/wp-content/uploads/mcp-ai/ \
  /backup/location/nvoos-files-$(date +%Y%m%d)/
```

**Using tar:**
```bash
tar -czf /backup/nvoos-files-$(date +%Y%m%d).tar.gz \
  /var/www/html/wp-content/uploads/mcp-ai/
```

### 4.3 Credentials Backup

**Encrypted Backup:**
```bash
# Export encrypted credentials
wp option get wp_mcp_ai_credentials_master > credentials.enc

# Encrypt with GPG
gpg --encrypt --recipient security@nvdigitalsolutions.com credentials.enc

# Store in secure location
mv credentials.enc.gpg /secure/backup/location/
```

**Important:** Credentials are already encrypted in database. Backup includes encrypted data.

### 4.4 Automated Backup

**Using WordPress Plugins:**
- UpdraftPlus (recommended)
- BackupBuddy
- Duplicator
- BackWPup

**Recommended Configuration:**
```
Backup Schedule: Daily
Backup Type: Full (database + files)
Retention: 30 days local, 90 days remote
Remote Storage: AWS S3, Google Drive, Dropbox
Encryption: Enabled
Notifications: Email on success/failure
```

## 5. Backup Storage

### 5.1 Storage Locations

**Primary Storage:**
- Local server backup directory
- Separate partition or disk
- Minimum 30 days retention

**Secondary Storage (Offsite):**
- Cloud storage (AWS S3, Google Cloud Storage, Azure Blob)
- Geographic redundancy enabled
- Minimum 90 days retention

**Tertiary Storage (Archive):**
- Long-term archival storage
- Yearly snapshots
- 7+ years retention

### 5.2 Storage Security

**Access Controls:**
- Encrypted at rest (AES-256)
- Encrypted in transit (TLS 1.2+)
- Access restricted to authorized personnel
- MFA required for access

**Backup Encryption:**
```bash
# Encrypt backup before storage
openssl enc -aes-256-cbc -salt \
  -in backup.sql \
  -out backup.sql.enc \
  -pass file:/secure/key/location

# Or use GPG
gpg --encrypt --recipient backup@nvdigitalsolutions.com backup.sql
```

### 5.3 Storage Monitoring

**Monitoring Metrics:**
- Backup success rate
- Backup size and growth
- Storage capacity utilization
- Backup age (last successful backup)
- Failed backup alerts

## 6. Recovery Procedures

### 6.1 Recovery Objectives

**RTO (Recovery Time Objective):** 4 hours
- Time to restore service after incident
- Critical data restored within 1 hour
- Full service restored within 4 hours

**RPO (Recovery Point Objective):** 24 hours
- Maximum acceptable data loss
- Daily backups ensure ≤24 hours data loss
- Critical data: ≤1 hour (if using continuous backup)

### 6.2 Recovery Scenarios

#### Scenario 1: Single Assistant Deletion

**Steps:**
1. Identify deleted assistant ID
2. Locate most recent backup containing assistant
3. Extract assistant post and meta from backup
4. Import into current database
5. Verify assistant functionality
6. Update audit log

**Expected Time:** 15 minutes

#### Scenario 2: Configuration Data Loss

**Steps:**
1. Stop WordPress (maintenance mode)
2. Restore `wp_options` table from backup
3. Filter for `wp_mcp_ai_%` options
4. Import restored options
5. Verify plugin settings
6. Exit maintenance mode
7. Test plugin functionality

**Expected Time:** 30 minutes

#### Scenario 3: Complete Database Loss

**Steps:**
1. Stop WordPress
2. Create new empty database
3. Import latest full backup
4. Run WordPress database upgrade
5. Verify data integrity
6. Test plugin functionality
7. Resume service
8. Notify users if necessary

**Expected Time:** 2-4 hours

#### Scenario 4: File System Data Loss

**Steps:**
1. Identify affected files/directories
2. Locate latest backup
3. Restore files from backup
4. Set correct permissions
5. Verify file integrity
6. Test file access
7. Clear caches

**Expected Time:** 1-2 hours

### 6.3 Recovery Commands

**Database Restore:**
```bash
# Using WP-CLI
wp db import /path/to/backup/nvoos-db-20260105.sql

# MySQL direct
mysql -u username -p database_name < backup.sql

# Restore specific tables
mysql -u username -p database_name < backup.sql --one-database
```

**File Restore:**
```bash
# Extract from tar archive
tar -xzf nvoos-files-20260105.tar.gz -C /var/www/html/

# Restore specific directory
rsync -avz /backup/nvoos-files-20260105/user-uploads/ \
  /var/www/html/wp-content/uploads/mcp-ai/user-uploads/
```

**Credentials Restore:**
```bash
# Decrypt backup
gpg --decrypt credentials.enc.gpg > credentials.enc

# Import to WordPress
wp option update wp_mcp_ai_credentials_master --format=json < credentials.enc
```

## 7. Testing and Validation

### 7.1 Backup Testing Schedule

**Monthly:** Restore test (development environment)
**Quarterly:** Full disaster recovery drill
**Annually:** Comprehensive recovery test

### 7.2 Backup Validation

**Automated Checks:**
```bash
# Verify backup file exists
test -f /backup/nvoos-db-$(date +%Y%m%d).sql && echo "Backup exists"

# Verify backup size (should be > 100KB)
size=$(stat -f%z /backup/nvoos-db-$(date +%Y%m%d).sql)
[ $size -gt 102400 ] && echo "Backup size OK"

# Verify backup can be read
mysql -u username -p database_name < backup.sql --execute="SELECT 1" 2>&1 | grep -q "1"
```

**Manual Checks:**
- Backup completion notification received
- Backup file size reasonable
- Backup age < 24 hours
- No error messages in logs

### 7.3 Recovery Testing

**Test Procedure:**
1. Set up isolated test environment
2. Perform recovery from backup
3. Verify data integrity
4. Test plugin functionality
5. Document test results
6. Identify and address issues

**Test Metrics:**
- Recovery time (actual vs RTO)
- Data loss (actual vs RPO)
- Success rate
- Issues encountered

## 8. Disaster Recovery

### 8.1 Disaster Scenarios

**Site-Level Disaster:**
- Complete server failure
- Data center outage
- Ransomware attack
- Catastrophic data corruption

**Recovery Strategy:**
1. Activate alternate hosting environment
2. Restore from offsite backup
3. Update DNS records
4. Verify functionality
5. Communicate with users

**Expected Time:** 4-8 hours

### 8.2 Failover Procedures

**Automatic Failover:**
- Load balancer health checks
- Automatic traffic redirection
- Standby server activation

**Manual Failover:**
1. Declare disaster
2. Activate DR site
3. Restore latest backup
4. Update DNS/routing
5. Verify functionality
6. Notify stakeholders

### 8.3 Communication Plan

**Internal:**
- Notify operations team immediately
- Update incident ticket
- Regular status updates

**External:**
- Notify affected users
- Status page updates
- ETA for restoration
- Post-incident report

## 9. Backup Monitoring and Alerting

### 9.1 Monitoring

**Monitored Metrics:**
- Backup completion status
- Backup duration
- Backup size
- Storage utilization
- Failed backup count

**Alerting Thresholds:**
- Backup failure: Immediate alert
- Backup not completed in 24h: Warning
- Storage >80% full: Warning
- Storage >95% full: Critical

### 9.2 Notifications

**Success Notification:**
```
Subject: NV oOS Backup Successful - [DATE]
Body: Backup completed successfully
  - Start: [TIME]
  - End: [TIME]
  - Duration: [MINUTES]
  - Size: [MB]
  - Location: [PATH]
```

**Failure Notification:**
```
Subject: NV oOS Backup FAILED - [DATE]
Body: Backup failed
  - Error: [ERROR_MESSAGE]
  - Time: [TIME]
  - Action Required: Investigate and retry
```

## 10. Roles and Responsibilities

### 10.1 Operations Team

- Execute scheduled backups
- Monitor backup success
- Maintain backup storage
- Perform routine restores
- Update backup procedures

### 10.2 Security Team

- Encrypt sensitive backups
- Manage backup encryption keys
- Audit backup access
- Verify backup security

### 10.3 Development Team

- Test backup restore procedures
- Verify data integrity
- Assist with complex recoveries
- Update recovery scripts

### 10.4 Management

- Approve backup strategy
- Allocate backup resources
- Review backup reports
- Approve DR exercises

## 11. Documentation

### 11.1 Backup Logs

**Log Contents:**
- Backup timestamp
- Backup type (full/incremental)
- Backup size
- Duration
- Success/failure status
- Error messages (if any)
- Storage location

### 11.2 Recovery Logs

**Log Contents:**
- Recovery timestamp
- Reason for recovery
- Backup used (date/time)
- Recovery steps taken
- Duration
- Success/failure
- Data loss (if any)
- Lessons learned

### 11.3 Documentation Retention

- Backup logs: 12 months
- Recovery logs: 7 years
- Test reports: 3 years
- Disaster recovery plans: Current + 3 years

## 12. Compliance

### 12.1 Regulatory Requirements

**GDPR:**
- Data availability maintained
- User data can be restored
- Backup retention aligns with data retention policies
- Right to erasure extends to backups

**ISO 27001:**
- Control A.8.13: Information backup
- Control A.8.14: Redundancy of information processing
- Regular backup testing required

### 12.2 Audit Trail

- All backup operations logged
- All recovery operations logged
- Regular audit of backup procedures
- Annual review of backup strategy

## 13. Continuous Improvement

### 13.1 Review Process

**Monthly:**
- Review backup success rate
- Review storage utilization
- Identify failed backups

**Quarterly:**
- Review backup strategy
- Update RTO/RPO targets
- Test disaster recovery
- Update documentation

**Annually:**
- Comprehensive backup audit
- Disaster recovery drill
- Update backup technology
- Benchmark against industry standards

### 13.2 Metrics and KPIs

- Backup Success Rate: >99%
- Mean Time to Restore (MTTR): <4 hours
- Recovery Success Rate: 100%
- Backup Test Success Rate: >95%
- Storage Cost per GB: Tracked

## 14. References

- [ISMS Policy](../ISMS-Policy.md)
- [Incident Management Procedure](./Incident-Management.md)
- [Risk Assessment](../Risk-Assessment.md)
- [Business Continuity Plan](#) (to be created)

## 15. Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-05 | GitHub Copilot | Initial backup and recovery procedure |

---

**Next Review:** 2026-04-05 (Quarterly)
