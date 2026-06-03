# Mobile Device Management and Off-Premises Security Policy
## ISO 27001 Controls A.7.9 - Security of Assets Off-Premises

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-06  
**Review Date:** 2026-07-06  
**Document Owner:** Chief Information Security Officer (CISO) & IT Operations  
**ISO 27001:2022 Control:** A.7.9

---

## 1. Purpose

This policy establishes security requirements for organization-owned and personal devices used to access organizational information while off-premises. The policy ensures:

- Protection of organizational data on mobile and portable devices
- Secure remote access to organizational systems
- Prevention of unauthorized access to devices and data
- Compliance with data protection regulations (GDPR, CCPA)
- Meet ISO/IEC 27001:2022 Control A.7.9 requirements
- Support for Bring Your Own Device (BYOD) scenarios

---

## 2. Scope

### 2.1 Covered Devices

**Mobile Devices:**
- Smartphones (iOS, Android)
- Tablets (iPad, Android tablets)
- Wearables (smartwatches with data access)

**Portable Computers:**
- Laptops and notebooks
- 2-in-1 devices (tablet/laptop hybrids)
- Portable workstations

**Portable Storage:**
- USB flash drives
- External hard drives
- SD cards and memory cards

**Other Portable Equipment:**
- Portable hotspots/MiFi devices
- External battery packs with storage
- IoT devices accessing organizational data

### 2.2 Applicable Scenarios

- **Remote Work:** Home office, co-working spaces
- **Business Travel:** Domestic and international
- **Public Spaces:** Cafes, airports, hotels
- **Customer Sites:** Client premises, partner locations
- **Personal Devices:** BYOD accessing organizational data

---

## 3. Device Security Requirements

### 3.1 Mandatory Security Controls

**All Devices Must Have:**

1. **Authentication:**
   - Strong password/PIN (minimum 8 characters for PCs, 6 for mobile)
   - Biometric authentication recommended (fingerprint, Face ID)
   - Auto-lock after 5 minutes (PCs) or 2 minutes (mobile)
   - Failed attempt lockout (10 attempts = device lock)

2. **Encryption:**
   - Full disk encryption (BitLocker, FileVault, LUKS)
   - Mobile device encryption (enabled by default on modern iOS/Android)
   - Encrypted storage for sensitive files
   - Encrypted backups

3. **Security Software:**
   - Up-to-date antivirus/anti-malware
   - Personal firewall enabled
   - Operating system patches current (within 30 days)
   - Security updates auto-install where possible

4. **Remote Management:**
   - Mobile Device Management (MDM) enrollment (for organization-owned)
   - Remote wipe capability enabled
   - Location tracking enabled (for loss recovery)
   - Remote lock capability

5. **Secure Communications:**
   - VPN required for accessing organizational systems
   - Encrypted email (TLS/SSL)
   - Secure messaging for business communications
   - No sensitive data over public Wi-Fi without VPN

### 3.2 Device Configuration Standards

**Laptops/Desktops:**

```
Operating System:
- Windows 10/11 Pro or Enterprise
- macOS 11.0 (Big Sur) or later
- Linux (Ubuntu 20.04 LTS or equivalent)

Security Features Required:
- TPM 2.0 (Windows) or T2/M1 chip (macOS)
- Secure Boot enabled
- Full disk encryption (BitLocker/FileVault)
- Firewall enabled
- Automatic updates enabled

Prohibited:
- Jailbroken/rooted devices
- Unsupported operating systems
- Pirated software
- Disabled security features
```

**Mobile Devices:**

```
Operating System:
- iOS 14.0 or later
- Android 10.0 or later

Security Features Required:
- Device encryption enabled
- Screen lock with PIN/biometric
- Find My Device / Find My iPhone enabled
- Auto-update enabled
- App installation from official stores only

Prohibited:
- Jailbroken iOS devices
- Rooted Android devices
- Sideloaded apps from unknown sources
- Debug mode enabled
```

---

## 4. Mobile Device Management (MDM)

### 4.1 MDM Enrollment

**Organization-Owned Devices:**
- **Mandatory:** All organization-owned mobile devices MUST be enrolled in MDM
- **Timing:** Before device is provided to user
- **Process:**
  1. IT provisions device
  2. Enroll in MDM system
  3. Apply security policies
  4. Install required apps
  5. Verify compliance
  6. Provide to user

**Personal Devices (BYOD):**
- **Optional:** MDM enrollment for personal devices
- **Container Approach:** Work profile separated from personal
- **User Consent:** Required before enrollment
- **Privacy:** Personal data not accessible by organization
- **Alternative:** If MDM not accepted, no organizational data access

### 4.2 MDM Policies

**Enforced via MDM:**

1. **Password Policies:**
   - Minimum password length
   - Password complexity requirements
   - Password expiration
   - Failed attempt limits

2. **Encryption:**
   - Require device encryption
   - Encrypt backups
   - Enforce encrypted email

3. **Network:**
   - Require VPN for corporate access
   - Block untrusted Wi-Fi
   - Certificate-based authentication

4. **Applications:**
   - Whitelist approved apps
   - Blacklist prohibited apps
   - Auto-install required security apps
   - Prevent app installation from unknown sources

5. **Data Protection:**
   - Prevent screenshots of sensitive data
   - Block copy/paste of corporate data to personal apps
   - Enforce data loss prevention (DLP) policies
   - Container data separate from personal

6. **Device Restrictions:**
   - Disable camera in secure areas (location-based)
   - Prevent USB debugging
   - Block sideloading
   - Disable Bluetooth in high-security areas

### 4.3 MDM Monitoring

**Continuous Compliance Monitoring:**

- Device compliance status (real-time)
- OS version and patch level
- Installed applications
- Security policy violations
- Device location (for lost device recovery)
- Data usage and bandwidth
- Last check-in time

**Alerts:**
- Non-compliant devices (immediate)
- Failed security policy checks
- Unusual data transfer patterns
- Devices not checking in (7 days)
- Jailbreak/root detection
- MDM unenrollment attempts

### 4.4 Recommended MDM Solutions

**Enterprise MDM Platforms:**
- Microsoft Intune (Microsoft 365 integration)
- Jamf Pro (Apple-focused)
- VMware Workspace ONE
- MobileIron / Ivanti
- Cisco Meraki Systems Manager

**For Small Teams:**
- Google Workspace (for Android)
- Apple Business Manager + MDM
- Open-source: MicroMDM, Headwind MDM

---

## 5. Remote Access Security

### 5.1 VPN Requirements

**Mandatory VPN Use:**

VPN MUST be used when:
- Accessing organizational systems remotely
- Using public Wi-Fi or untrusted networks
- Accessing sensitive data from off-premises
- Connecting to development/staging/production environments

**VPN Configuration:**

```
Protocol: WireGuard, OpenVPN, or IPSec IKEv2
Encryption: AES-256
Authentication: Certificate-based + MFA
DNS: Use corporate DNS while connected
Split Tunneling: Disabled for organizational traffic
Automatic Connection: Required when accessing corporate resources
```

**VPN Setup:**

1. **Install VPN Client:**
   - Organization-approved VPN client
   - Auto-configure using profile
   - Test connectivity

2. **Certificate Installation:**
   - User certificate provisioned
   - Device certificate (for device-based auth)
   - CA certificates installed

3. **Configuration Verification:**
   - Test connection to corporate resources
   - Verify DNS resolution
   - Check for IP leaks
   - Ensure kill switch works

### 5.2 Network Security

**Trusted Networks:**
- Home network with WPA2/WPA3 encryption
- Office networks
- Organization-provided hotspots

**Untrusted Networks:**
- Public Wi-Fi (cafes, airports, hotels)
- Open networks (no encryption)
- Guest networks

**Security Measures for Untrusted Networks:**

1. **Always Use VPN:** Enable VPN before connecting
2. **Disable Automatic Connection:** Manually connect to networks
3. **Verify Network Name:** Confirm legitimate network (beware of "evil twin" attacks)
4. **Disable File Sharing:** Turn off network discovery and file sharing
5. **Use HTTPS:** Ensure all websites use HTTPS
6. **Avoid Sensitive Transactions:** No banking, confidential data entry on public Wi-Fi

---

## 6. Physical Security

### 6.1 Device Custody

**General Requirements:**

1. **Maintain Physical Control:**
   - Keep device with you at all times
   - Never leave unattended in public spaces
   - Lock in hotel safe when sleeping
   - Keep in carry-on luggage (not checked baggage)

2. **Vehicle Security:**
   - Store in trunk, not visible in vehicle
   - Lock vehicle doors
   - Remove devices overnight
   - Park in well-lit, secure areas

3. **Home Office:**
   - Store in locked room or drawer when not in use
   - Keep away from windows
   - Secure from family/visitors
   - Lock workspace if possible

4. **Public Spaces:**
   - Use cable lock to secure laptop
   - Keep mobile devices on person, not on table
   - Use privacy screen
   - Position screen away from public view

### 6.2 Loss/Theft Response

**Immediate Actions (Within 1 Hour):**

1. **Report to IT/Security:**
   - Phone: [Emergency IT number]
   - Email: security@nvdigitalsolutions.com
   - Provide: Device type, serial number, last known location

2. **Remote Actions:**
   - IT initiates remote wipe
   - Disable VPN access
   - Revoke certificates
   - Change passwords

3. **Law Enforcement:**
   - File police report for stolen devices
   - Obtain case number
   - Provide to IT/Security

4. **MDM Tracking:**
   - Check last known location
   - Enable lost mode (displays contact info on screen)
   - Attempt recovery if safe

**Follow-Up Actions (Within 24 Hours):**

1. **Document Incident:**
   - Complete incident report form
   - Describe circumstances
   - Timeline of events
   - Data stored on device

2. **Risk Assessment:**
   - Was device encrypted?
   - Was screen locked?
   - What data was accessible?
   - Potential impact?

3. **Notifications:**
   - Notify manager
   - Notify affected parties if data breach
   - Update asset inventory

4. **Replacement:**
   - Request replacement device
   - Provision with same security config
   - Restore backed-up data (if available)

### 6.3 Travel Security

**Domestic Travel:**

1. **Before Travel:**
   - Backup device data
   - Update all software
   - Ensure VPN configured
   - Pack charger and cables
   - Note device serial numbers

2. **During Travel:**
   - Keep devices in carry-on
   - Use privacy screen
   - Avoid sensitive work in public
   - Use hotel room safe
   - Be aware of shoulder surfers

3. **After Return:**
   - Scan for malware
   - Review access logs
   - Report any incidents

**International Travel:**

**Additional Considerations:**

1. **Border Crossing:**
   - Be prepared for device inspection
   - Consider using "clean" loaner device
   - Back up and wipe sensitive data before travel
   - Know legal rights regarding device search

2. **Data Regulations:**
   - Understand data sovereignty laws of destination
   - Comply with local data protection regulations
   - Avoid accessing prohibited content

3. **High-Risk Destinations:**
   - Use dedicated travel device (minimal data)
   - Avoid bringing personal devices
   - Assume network monitoring
   - No sensitive communications
   - Use burner phone if necessary

4. **Upon Return:**
   - Full malware scan
   - Consider device re-imaging
   - Review all access logs
   - Check for tampering

---

## 7. BYOD (Bring Your Own Device) Policy

### 7.1 BYOD Eligibility

**Eligible Devices:**
- Personally-owned smartphones and tablets
- Personal laptops (with approval)
- Must meet minimum security requirements

**Ineligible:**
- Jailbroken/rooted devices
- Devices running unsupported OS
- Shared family devices
- Devices with malware

### 7.2 BYOD Requirements

**Mandatory for BYOD:**

1. **MDM Enrollment (Optional but Recommended):**
   - Work profile container for data separation
   - Corporate apps and data in work profile only
   - Personal data remains private

2. **If No MDM:**
   - Web-only access to organizational systems
   - No local storage of organizational data
   - No access to sensitive systems

3. **Security Requirements:**
   - Device encryption enabled
   - Screen lock with strong password/PIN
   - Up-to-date OS and security patches
   - Antivirus installed (for Android)

4. **User Responsibilities:**
   - Maintain device security
   - Report lost/stolen immediately
   - Allow remote wipe of work data
   - No sharing device with others

### 7.3 BYOD Data Separation

**Work Profile Container:**

- Work apps and data isolated
- Corporate email, calendar, contacts in work profile
- Personal apps cannot access work data
- Work apps cannot access personal data
- IT can wipe work profile only

**Benefits:**
- User privacy protected
- Organization controls work data
- Clear separation of personal/work
- Easy offboarding (remove work profile)

### 7.4 BYOD Termination

**When Employment Ends:**

1. **Remove Organizational Data:**
   - Remote wipe of work profile/container
   - Remove organization email accounts
   - Uninstall organization apps
   - Revoke access certificates

2. **User Keeps Device:**
   - Personal data unaffected
   - Device remains functional
   - No organizational data remains

3. **Verification:**
   - Confirm data removed
   - Verify account deactivation
   - Document completion

---

## 8. Data Storage and Transfer

### 8.1 Local Data Storage

**Prohibited:**
- Storing sensitive data on unencrypted devices
- Saving passwords in plain text files
- Keeping customer data locally without encryption
- Storing API keys in device notes

**Allowed:**
- Encrypted local storage (BitLocker, FileVault)
- Encrypted containers (VeraCrypt)
- Password managers (encrypted vault)
- Temporarily cached data (auto-deleted)

**Best Practices:**
- Minimize local data storage
- Use cloud storage with encryption
- Regularly clean up old files
- Delete sensitive files when no longer needed

### 8.2 Data Transfer

**Secure Methods:**

1. **Cloud Storage:**
   - Organization-approved services only
   - Encrypted in transit and at rest
   - Access controls enforced
   - Audit logging enabled

2. **Email:**
   - Encrypt sensitive attachments
   - Use secure email gateway
   - Verify recipient before sending
   - Avoid public email for sensitive data

3. **File Transfer:**
   - Use SFTP or HTTPS
   - Encrypt files before transfer
   - Use password-protected archives
   - Verify recipient identity

**Prohibited Methods:**
- Unencrypted email
- Public file sharing (Dropbox, Google Drive personal)
- USB drives (unless encrypted)
- Bluetooth file transfer for sensitive data
- Text messages for sensitive information

### 8.3 Removable Media

**USB Drives and External Drives:**

1. **Requirements:**
   - Encrypted (hardware-encrypted preferred)
   - Organization-approved devices only
   - Registered in asset inventory
   - Scanned for malware before use

2. **Prohibited:**
   - Using found USB drives
   - Personal USB drives for work data
   - Leaving USB drives unattended
   - Connecting to untrusted computers

3. **Disposal:**
   - Secure erase before disposal
   - Physical destruction for sensitive data
   - Follow equipment disposal procedures

---

## 9. Compliance and Monitoring

### 9.1 Device Registration

**All Off-Premises Devices Must Be:**

1. **Registered:**
   - Device type, model, serial number
   - Owner/user assigned
   - Purchase date and warranty info
   - Security configuration documented

2. **Tracked:**
   - Asset management system
   - Current location (user)
   - Compliance status
   - Last security check date

3. **Regularly Reviewed:**
   - Quarterly access reviews
   - Annual hardware refresh
   - Compliance verification
   - Security posture assessment

### 9.2 Compliance Checks

**Automated Checks (via MDM):**
- OS version and patch level
- Encryption status
- Screen lock configured
- Required apps installed
- Jailbreak/root detection
- VPN installed and configured

**Manual Checks (Quarterly):**
- Physical device inspection
- Security software verification
- User compliance interview
- Documentation review

**Non-Compliance Actions:**

| Severity | Issue | Action | Timeline |
|----------|-------|--------|----------|
| **High** | Jailbroken device | Immediate access revocation | Immediate |
| **High** | No encryption | Block access until fixed | 24 hours |
| **Medium** | Outdated OS | Warning + upgrade deadline | 7 days |
| **Medium** | No VPN | Block remote access | 48 hours |
| **Low** | Weak password | Forced password change | 30 days |

### 9.3 Audit and Reporting

**Monthly Reports:**
- Device inventory status
- Compliance rates
- Security violations
- Lost/stolen incidents
- MDM enrollment stats

**Quarterly Audits:**
- Physical device audits
- Compliance spot checks
- Security configuration review
- User training effectiveness

---

## 10. User Responsibilities

### 10.1 Device Care

Users MUST:
- Keep devices physically secure
- Maintain up-to-date software
- Use strong authentication
- Report loss/theft immediately
- Follow security policies
- Complete security training annually
- Return devices upon termination

Users MUST NOT:
- Share devices with others
- Disable security features
- Install unauthorized software
- Access prohibited content
- Use devices for illegal activities
- Attempt to bypass security controls

### 10.2 Security Incidents

**Report Immediately:**
- Lost or stolen devices
- Suspected malware infection
- Unauthorized access attempts
- Device damage or malfunction
- Security policy violations
- Suspicious activity

**How to Report:**
- Email: security@nvdigitalsolutions.com
- Phone: [Security Hotline]
- Incident reporting system
- Manager notification

---

## 11. Training and Awareness

### 11.1 Required Training

**All Users with Off-Premises Devices:**
- Initial training before device provisioning
- Annual refresher training
- Training after policy updates

**Training Topics:**
- Physical device security
- Password and authentication
- VPN usage
- Public Wi-Fi risks
- Data encryption
- Loss/theft reporting
- BYOD policies (if applicable)
- Travel security

### 11.2 Security Awareness

**Ongoing Reminders:**
- Monthly security tips
- Phishing simulations
- Security newsletters
- Case studies of incidents
- Best practice sharing

---

## 12. Related Documents

- [Acceptable Use Policy](../Acceptable-Use-Policy.md) - Overall device usage
- [Clear Desk/Screen Policy](./Clear-Desk-Screen-Policy.md) - Workspace security
- [Equipment Disposal](./Equipment-Disposal.md) - Device retirement
- [Incident Management](./Incident-Management.md) - Loss/theft response
- [Remote Work Security Guidelines] - If separate document

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | GitHub Copilot | Initial Mobile Device Management and Off-Premises Security Policy (ISO 27001 A.7.9) |

---

## Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| CISO | [Name] | [Digital Signature] | 2026-01-06 |
| IT Manager | [Name] | [Digital Signature] | 2026-01-06 |
| Operations Manager | [Name] | [Digital Signature] | 2026-01-06 |
| Management | [Name] | [Digital Signature] | 2026-01-06 |

---

**Next Review Date:** 2026-07-06 (6 months)  
**Review Frequency:** Annually or when device technology/threats change
