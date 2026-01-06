# Endpoint and Network Security Procedures
## ISO 27001 Controls A.8.1, A.8.7, A.7.8, A.8.6, A.8.22

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-06  
**Review Date:** 2026-07-06  
**Document Owner:** Chief Information Security Officer (CISO) & IT Operations  
**ISO 27001:2022 Controls:** A.8.1, A.8.7, A.7.8, A.8.6, A.8.22

---

## Part 1: User Endpoint Devices (A.8.1)

### 1.1 Purpose
Establish security requirements for all user endpoint devices accessing organizational systems.

### 1.2 Endpoint Security Requirements

**Mandatory Security Software:**

1. **Antivirus/Anti-Malware:**
   - Enterprise-grade solution (Windows Defender ATP, CrowdStrike, SentinelOne)
   - Real-time protection enabled
   - Daily definition updates
   - Full system scans weekly
   - Automatic quarantine of threats

2. **Endpoint Detection and Response (EDR):**
   - Behavioral analysis
   - Threat hunting capabilities
   - Incident response automation
   - Integration with SIEM

3. **Personal Firewall:**
   - Host-based firewall enabled
   - Inbound connections blocked by default
   - Outbound monitoring for data exfiltration
   - Logging enabled

4. **Patch Management:**
   - Operating system patches: Within 30 days of release
   - Critical security patches: Within 7 days
   - Application updates: Within 30 days
   - Firmware updates: Within 60 days

**Configuration Standards:**

```
Windows Endpoints:
- Windows 10/11 Pro or Enterprise
- BitLocker encryption enabled
- Windows Defender ATP or equivalent
- Windows Firewall enabled
- Automatic Updates enabled
- User Account Control (UAC) enabled
- PowerShell execution policy: Restricted

macOS Endpoints:
- macOS 11.0 (Big Sur) or later
- FileVault encryption enabled
- XProtect and Gatekeeper enabled
- macOS Firewall enabled
- Automatic Updates enabled
- System Integrity Protection (SIP) enabled

Linux Endpoints:
- Ubuntu 20.04 LTS or equivalent
- LUKS disk encryption
- SELinux or AppArmor enabled
- UFW or iptables firewall configured
- Unattended-upgrades enabled
```

### 1.3 Endpoint Management

**Centralized Management:**
- Configuration management (Intune, Jamf, Ansible)
- Software deployment automation
- Policy enforcement
- Compliance monitoring
- Asset inventory integration

**Required Capabilities:**
- Remote monitoring
- Remote patch deployment
- Remote software installation
- Remote troubleshooting
- Compliance reporting

---

## Part 2: Protection Against Malware (A.8.7)

### 2.1 Multi-Layered Malware Protection

**Layer 1: Prevention**
- Email scanning (gateway-level)
- Web filtering and URL reputation
- File download scanning
- Executable blocking (AppLocker/Gatekeeper)
- Application whitelisting

**Layer 2: Detection**
- Signature-based antivirus
- Behavioral analysis
- Heuristic detection
- Machine learning detection
- Sandboxing suspicious files

**Layer 3: Response**
- Automatic quarantine
- Alert generation
- Incident response automation
- System isolation if compromised
- Forensic data collection

### 2.2 CI/CD Malware Scanning

**Automated Scanning in Pipeline:**

```yaml
# .github/workflows/security-scan.yml
name: Security Scan

on:
  pull_request:
    branches: [ main, develop ]
  push:
    branches: [ main ]

jobs:
  malware-scan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: ClamAV Scan
        run: |
          sudo apt-get update
          sudo apt-get install -y clamav clamav-daemon
          sudo freshclam
          clamscan -r --infected --remove=yes .
      
      - name: Dependency Vulnerability Scan
        run: |
          composer audit
          npm audit --audit-level=high
      
      - name: Secret Scanning
        uses: trufflesecurity/trufflehog@main
        with:
          path: ./
          base: ${{ github.event.repository.default_branch }}
          head: HEAD
```

**Code Repository Protection:**
- Pre-commit hooks for malware scanning
- Branch protection with status checks
- Dependency scanning (Dependabot, Snyk)
- Container image scanning
- Infrastructure as Code scanning

### 2.3 User Training

**Malware Awareness:**
- Recognizing phishing emails
- Safe browsing practices
- Software download safety
- USB drive risks
- Social engineering tactics
- Reporting suspicious activity

**Training Frequency:** Quarterly with phishing simulations

---

## Part 3: Equipment Siting and Protection (A.7.8)

### 3.1 Physical Equipment Protection

**Home Office Requirements:**
- Dedicated workspace away from high-traffic areas
- Surge protector for power
- Backup power (UPS) for desktops
- Adequate ventilation
- Cable management (trip hazard prevention)
- Locking desk drawer for documents

**Environmental Controls:**
- Temperature: 15-25°C (59-77°F)
- Humidity: 20-80% non-condensing
- Protection from direct sunlight
- Protection from liquids and food
- Dust-free environment

**Physical Access Control:**
- Equipment in locked room when unattended
- Cable locks for laptops in shared spaces
- Kensington lock slots utilized
- Security cameras optional for high-value equipment

### 3.2 Equipment Protection Standards

**Laptop Protection:**
- Hard shell case for transport
- Padded laptop bag/backpack
- Cable lock when in public
- Privacy screen installed
- Spill-resistant keyboard cover (optional)

**Desktop Protection:**
- Under-desk mounting or secure placement
- Cable locks for monitors
- Locked server racks for servers
- BIOS password protection
- Disable USB ports if not needed

**Power Protection:**
- Surge protectors (minimum 2000 joules)
- UPS for desktops (15-minute runtime minimum)
- Generator or battery backup for critical systems
- Proper cable management

---

## Part 4: Capacity Management (A.8.6)

### 4.1 Capacity Planning

**Monitored Resources:**

1. **Server Capacity:**
   - CPU utilization (alert at 80%)
   - Memory usage (alert at 85%)
   - Disk space (alert at 80%)
   - Network bandwidth
   - Database connections

2. **Application Performance:**
   - API response times (target < 200ms)
   - Database query performance
   - Page load times
   - Concurrent users supported
   - Transaction throughput

3. **Storage Capacity:**
   - Database growth rate
   - Backup storage usage
   - Log file accumulation
   - Media uploads
   - Archive storage

**Capacity Monitoring Tools:**
- New Relic, Datadog, or Prometheus
- WordPress-specific: Query Monitor, Debug Bar
- Server monitoring: Nagios, Zabbix, or cloud provider tools
- Database monitoring: MySQL/MariaDB slow query log

### 4.2 Capacity Thresholds

**Alert Levels:**

| Resource | Warning (%) | Critical (%) | Action Required |
|----------|-------------|--------------|-----------------|
| CPU | 70% | 85% | Scale up or optimize |
| Memory | 75% | 90% | Increase RAM or optimize |
| Disk | 70% | 85% | Add storage or cleanup |
| Bandwidth | 60% | 80% | Increase bandwidth |
| API Rate Limit | 70% | 90% | Request increase or throttle |

**Rate Limiting Implementation:**

```php
/**
 * Implement rate limiting for API requests
 */
function wp_mcp_ai_check_rate_limit( $user_id, $endpoint ) {
    $limits = array(
        'chat' => array( 'requests' => 100, 'period' => 3600 ), // 100/hour
        'tools' => array( 'requests' => 500, 'period' => 3600 ), // 500/hour
        'admin' => array( 'requests' => 1000, 'period' => 3600 ), // 1000/hour
    );
    
    $limit = $limits[ $endpoint ] ?? $limits['tools'];
    $key = "rate_limit_{$user_id}_{$endpoint}";
    $count = get_transient( $key );
    
    if ( false === $count ) {
        set_transient( $key, 1, $limit['period'] );
        return true;
    }
    
    if ( $count >= $limit['requests'] ) {
        return new WP_Error( 'rate_limit_exceeded', 'Rate limit exceeded. Try again later.' );
    }
    
    set_transient( $key, $count + 1, $limit['period'] );
    return true;
}
```

### 4.3 Capacity Review Process

**Monthly Review:**
- Resource utilization trends
- Performance metrics
- Capacity forecasting (3-6 months)
- Bottleneck identification

**Quarterly Planning:**
- Infrastructure scaling needs
- Budget allocation for upgrades
- Performance optimization priorities
- Disaster recovery capacity

**Annual Assessment:**
- Long-term capacity planning (1-3 years)
- Technology refresh requirements
- Architecture changes needed
- Cost optimization opportunities

---

## Part 5: Network Segregation (A.8.22)

### 5.1 Environment Segregation

**Mandatory Separation:**

```
Production Environment:
- Domain: app.nvoos.com
- Database: prod-db.internal
- No direct access from public internet
- Behind Web Application Firewall (WAF)
- Strict access controls (2FA required)
- Logging and monitoring active
- Automated backups (daily)

Staging Environment:
- Domain: staging.nvoos.com
- Database: staging-db.internal
- Isolated from production
- Test with production-like configuration
- Anonymized data only
- Access restricted to dev team

Development Environment:
- Domain: dev.nvoos.local or localhost
- Database: dev-db.local
- Local development only
- Synthetic test data
- No production credentials
- No production data

Testing Environment:
- Automated test execution
- Ephemeral (created/destroyed per test run)
- Synthetic data only
- Isolated from all other environments
```

**Network Segregation:**

```
Firewall Rules:
- Production → Staging: DENY
- Production → Development: DENY
- Staging → Production: READ-ONLY (for data refresh, anonymized)
- Development → Production: DENY
- Development → Staging: DENY

Exceptions:
- Deployment pipeline from CI/CD to Production (authenticated, audited)
- Monitoring system from dedicated network
- Backup system from dedicated network
```

### 5.2 Access Control by Environment

| Environment | Who | Access Method | MFA Required |
|-------------|-----|---------------|--------------|
| **Production** | Ops team only | Bastion host + VPN | Yes |
| **Staging** | Dev team, QA | VPN | Yes |
| **Development** | All developers | Local or VPN | No |
| **Testing** | Automated only | CI/CD pipeline | N/A |

**Deployment Pipeline Security:**

```yaml
# Deployment restricted to specific users/roles
# No manual deployments to production
# All changes via GitOps (audited, reviewed)
# Automated tests must pass before deployment
# Rollback capability available
```

### 5.3 Data Flow Controls

**Allowed Data Flows:**

1. **Development → Staging:** Code deployment (code review required)
2. **Staging → Production:** Code deployment (approval required, tests passed)
3. **Production → Development (data):** NEVER (use anonymized copies only)
4. **Production → Staging (data):** Anonymized/sanitized only, one-way
5. **External APIs → Production:** Through API gateway, rate-limited
6. **Users → Production:** Through CDN/WAF, authenticated

**Prohibited Flows:**
- Direct database connections to production from dev environments
- Production credentials in non-production environments
- Production API keys in version control
- Customer data in development/test environments

### 5.4 Network Isolation Verification

**Quarterly Verification:**

```bash
# Test network segregation
# From Development environment:
ping prod-db.internal # Should FAIL
telnet prod-db.internal 3306 # Should TIMEOUT

# From Staging environment:
ping prod-db.internal # Should FAIL
# READ access to anonymized production data backup only

# From Production environment:
# No outbound except to approved external services
# No inbound except through load balancer/WAF
```

---

## 6. Compliance and Audit

### 6.1 Compliance Monitoring

**Automated Checks:**
- Endpoint security compliance (daily)
- Malware signature updates (hourly)
- System patch status (daily)
- Capacity utilization (hourly)
- Network segregation rules (weekly)

**Manual Reviews:**
- Equipment physical security (quarterly)
- Endpoint configuration audits (quarterly)
- Network segregation testing (semi-annually)
- Capacity planning review (monthly)
- Malware incident review (after each incident)

### 6.2 Audit Procedures

**Evidence Collection:**
- Endpoint security reports (MDM/EDR)
- Malware scan logs and quarantine reports
- Capacity monitoring dashboards
- Network diagrams and firewall rules
- Incident response records

**Audit Frequency:**
- Internal audits: Quarterly
- External audits: Annual
- Compliance spot-checks: Monthly

---

## 7. Related Documents

- [Mobile Device Management](./Mobile-Device-Management.md) - A.7.9
- [Equipment Disposal](./Equipment-Disposal.md) - A.7.14
- [Data Masking](./Data-Masking.md) - A.8.11
- [Incident Management](./Incident-Management.md) - Security incident response
- [Technology Controls](./Technology-Controls.md) - Technical security measures

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | GitHub Copilot | Initial Endpoint and Network Security Procedures (ISO 27001 A.8.1, A.8.7, A.7.8, A.8.6, A.8.22) |

---

## Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| CISO | [Name] | [Digital Signature] | 2026-01-06 |
| IT Manager | [Name] | [Digital Signature] | 2026-01-06 |
| Lead Developer | [Name] | [Digital Signature] | 2026-01-06 |
| Operations Manager | [Name] | [Digital Signature] | 2026-01-06 |

---

**Next Review Date:** 2026-07-06 (6 months)  
**Review Frequency:** Annually or when technology/threat landscape changes significantly
