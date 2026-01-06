# Secure Equipment Disposal and Reuse Procedures
## ISO 27001 Control A.7.14 - Secure Disposal or Reuse of Equipment

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-06  
**Review Date:** 2026-07-06  
**Document Owner:** Chief Information Security Officer (CISO) & IT Operations  
**ISO 27001:2022 Control:** A.7.14

---

## 1. Purpose

This document establishes procedures for secure disposal or reuse of equipment containing or having processed sensitive information. The objectives are to:

- Prevent unauthorized disclosure of information stored on equipment
- Ensure complete data sanitization before disposal or reuse
- Comply with data protection regulations (GDPR, CCPA)
- Meet ISO/IEC 27001:2022 Control A.7.14 requirements
- Establish clear procedures for equipment lifecycle end
- Protect organizational and customer data

---

## 2. Scope

### 2.1 Covered Equipment

This procedure applies to all equipment that stores, processes, or has processed sensitive information:

**Computing Devices:**
- Desktop computers and workstations
- Laptop computers and notebooks
- Tablets and mobile devices
- Servers (physical and virtual)
- Network attached storage (NAS)

**Storage Media:**
- Hard disk drives (HDD)
- Solid state drives (SSD)
- USB flash drives and external drives
- SD cards and memory cards
- Backup tapes and optical media (CD/DVD)

**Mobile Devices:**
- Smartphones
- Tablets
- Portable storage devices
- IoT devices with storage

**Network Equipment:**
- Routers and switches with configuration storage
- Firewalls
- Access points
- Network security appliances

**Other Equipment:**
- Multifunction printers/copiers (with hard drives)
- Fax machines with memory
- Security cameras with local storage
- Any device with non-volatile memory

### 2.2 Data Sensitivity Levels

Disposal procedures vary by data classification:

- **Restricted:** Customer PII, API keys, encryption keys, credentials
- **Confidential:** Source code, business plans, internal communications
- **Internal:** Non-sensitive business data
- **Public:** No special procedures required

---

## 3. Equipment Disposal Decision Tree

### 3.1 End of Life Assessment

**When to Dispose/Retire Equipment:**

1. **End of Useful Life:** Device no longer performs adequately
2. **Outdated Technology:** Cannot run required software/security updates
3. **Beyond Economical Repair:** Cost of repair exceeds replacement cost
4. **Security Risk:** Device cannot support current security requirements
5. **Lease/Rental Return:** Equipment must be returned to vendor
6. **Employee Termination:** Equipment return upon separation
7. **Lost or Stolen:** Device compromised and cannot be recovered

### 3.2 Disposition Options

**Option 1: Reuse Internally**
- Sanitize data completely
- Redeploy to different user/purpose
- Follow redeployment procedures (Section 5)

**Option 2: Donate/Sell**
- Sanitize data completely (certified)
- Remove all organizational identifiers
- Provide certificate of sanitization
- Follow donation/sale procedures (Section 6)

**Option 3: Recycle**
- Sanitize data if possible
- If sanitization not possible, physically destroy
- Use certified e-waste recycler
- Obtain certificate of recycling

**Option 4: Physical Destruction**
- For devices containing highly sensitive data
- Use certified destruction service
- Obtain certificate of destruction
- Document destruction with serial numbers

---

## 4. Data Sanitization Methods

### 4.1 Method Selection by Device Type

| Device Type | Primary Method | Secondary Method | Documentation Required |
|-------------|---------------|------------------|----------------------|
| **HDD (Hard Disk Drive)** | DoD 5220.22-M wipe (3-pass) | Physical destruction | Certificate of sanitization |
| **SSD (Solid State Drive)** | Secure Erase (ATA) or Crypto Erase | Physical destruction | Certificate + verification |
| **USB/Flash Drives** | Secure Erase or overwrite | Physical destruction | Log entry |
| **Mobile Devices** | Factory reset + encryption key destruction | Physical destruction | Device wipe confirmation |
| **Servers** | DBAN or vendor tools | Degaussing + destruction | Multiple certificates |
| **Backup Tapes** | Degaussing | Physical shredding | Certificate of destruction |
| **Printers/Copiers** | Secure overwrite of storage | Remove and destroy HDD | Service report |
| **Network Devices** | Factory reset + firmware reinstall | Physical destruction | Configuration backup deleted |

### 4.2 Sanitization Standards

**Data Overwriting:**

- **DoD 5220.22-M (3-pass):**
  - Pass 1: Write zeros to all locations
  - Pass 2: Write ones to all locations
  - Pass 3: Write random characters
  - Verify overwrite completion

- **NIST SP 800-88 (Clear/Purge):**
  - Clear: Logical techniques (overwrite, block erase)
  - Purge: Physical or logical techniques that render data recovery infeasible
  - Destroy: Physical destruction

- **Gutmann Method (35-pass):**
  - For highest security requirements
  - Used for extremely sensitive data

**Cryptographic Erasure:**
- Destroy encryption keys rendering encrypted data unreadable
- Applicable for self-encrypting drives (SED)
- Much faster than overwriting
- Requires verification that data was encrypted

**Physical Destruction:**
- **Degaussing:** Strong magnetic field destroys magnetic data (HDDs, tapes)
- **Shredding:** Physical shredding into < 2mm particles
- **Disintegration:** Reduces device to < 2mm particles
- **Incineration:** Complete burning (for highly classified data)
- **Pulverization:** Crushing into fine particles

---

## 5. Internal Reuse Procedures

### 5.1 Redeployment Process

**Step 1: Data Backup (If Needed)**
- Back up any data that must be preserved
- Verify backup integrity
- Store backup securely

**Step 2: Data Sanitization**
- Perform full data wipe using appropriate method
- For HDDs: Use DBAN or dd command (Linux)
- For SSDs: Use vendor secure erase utility
- Verify sanitization completion

**Step 3: Operating System Reinstall**
- Install clean OS image
- Apply all security patches and updates
- Install required software and security tools
- Configure security settings (encryption, firewall, antivirus)

**Step 4: Hardware Verification**
- Test all hardware components
- Verify performance meets requirements
- Check for physical damage

**Step 5: Documentation**
- Update asset inventory
- Record sanitization performed
- Document new assignment
- Update user in system

**Step 6: Handoff to New User**
- Provide device to new user
- Ensure user signs acceptance form
- Provide security guidelines
- Schedule follow-up check

### 5.2 Sanitization Commands

**Linux/macOS (HDD):**
```bash
# Secure wipe using dd (WARNING: Destructive!)
sudo dd if=/dev/zero of=/dev/sdX bs=1M status=progress
# Or use shred
sudo shred -vfz -n 3 /dev/sdX

# Verify wipe
sudo hexdump -C /dev/sdX | head
```

**Linux (SSD Secure Erase):**
```bash
# Check if drive is frozen
sudo hdparm -I /dev/sdX | grep frozen

# If not frozen, set security password
sudo hdparm --user-master u --security-set-pass password /dev/sdX

# Issue secure erase command
sudo hdparm --user-master u --security-erase password /dev/sdX

# Verify
sudo hdparm -I /dev/sdX | grep -i erase
```

**Windows (Using Cipher):**
```cmd
REM Wipe free space on C: drive
cipher /w:C:\

REM For full drive wipe, use DBAN or vendor tools
```

---

## 6. External Disposal/Donation Procedures

### 6.1 Preparation for Disposal

**Pre-Disposal Checklist:**

☐ **Approval:** Obtain approval from IT Manager and CISO  
☐ **Data Backup:** Backup any necessary data  
☐ **Decommission:** Remove from production use  
☐ **Inventory Update:** Update asset management system  
☐ **Data Sanitization:** Complete appropriate sanitization method  
☐ **Verification:** Verify data unrecoverable  
☐ **Documentation:** Complete disposal form  
☐ **Physical Prep:** Remove labels, organizational markings  

### 6.2 Vendor Selection

**Certified Disposal/Recycling Vendors:**

Must provide:
- **Certifications:**
  - R2 (Responsible Recycling) certification
  - e-Stewards certification
  - ISO 14001 environmental certification
  - NIST SP 800-88 compliance

- **Insurance:** 
  - Minimum $2 million liability coverage
  - Cyber liability insurance

- **Tracking:**
  - Chain of custody documentation
  - Serial number tracking
  - GPS tracking of transport vehicles

- **Certificates:**
  - Certificate of data destruction/sanitization
  - Certificate of recycling
  - Certificate of environmentally responsible disposal

**Approved Vendors:**
- [Maintain list of pre-approved vendors]
- Annual vendor review and re-certification

### 6.3 Physical Destruction Services

**For High-Security Items:**

Use certified destruction services that provide:
- **On-site destruction** (witnessed)  or
- **Off-site destruction** with video documentation

**Destruction Methods:**
- Shredding to < 2mm particles
- Disintegration
- Degaussing (for magnetic media) + physical destruction

**Documentation:**
- Certificate of destruction with:
  - List of serial numbers destroyed
  - Date and method of destruction
  - Witness signatures
  - Photos/video of destruction process
  - Environmental disposal certificate

### 6.4 Donation Procedures

**When Donating Equipment:**

1. **Approval Required:**
   - CISO approval for all donations
   - Management approval for donations > $1,000 value

2. **Recipient Verification:**
   - Verify recipient is legitimate charity or educational institution
   - Confirm recipient has 501(c)(3) status (US) or equivalent
   - Obtain written acceptance from recipient

3. **Data Sanitization:**
   - MUST complete NIST SP 800-88 purge-level sanitization
   - Obtain third-party certification if possible
   - Document sanitization method and verification

4. **Asset Removal:**
   - Remove all organizational identifiers (stickers, engraving)
   - Remove asset tags and serial number labels if possible
   - Ensure no proprietary hardware modifications remain

5. **Documentation:**
   - Donation receipt for tax purposes
   - Certificate of sanitization
   - Photos of equipment condition
   - Recipient acknowledgment

6. **Liability:**
   - Include disclaimer that equipment is "as-is"
   - No warranty provided
   - Recipient acknowledges data has been sanitized

---

## 7. Lost or Stolen Equipment

### 7.1 Immediate Actions (Within 1 Hour)

1. **Report Incident:**
   - Notify CISO and security team immediately
   - File incident report
   - Notify manager

2. **Remote Actions:**
   - **If Mobile Device:** Initiate remote wipe
   - **If Laptop/Desktop:** Attempt remote connection and wipe
   - Disable VPN access
   - Revoke device certificates
   - Change any credentials used on device

3. **Assess Risk:**
   - Was device encrypted?
   - What data was stored locally?
   - Were credentials saved?
   - Access to what systems?

4. **Containment:**
   - Revoke access to sensitive systems
   - Monitor for suspicious access attempts
   - Reset passwords for accounts accessed from device
   - Review recent access logs

### 7.2 Follow-up Actions (Within 24 Hours)

1. **Law Enforcement:**
   - File police report if stolen
   - Provide serial number and description
   - Obtain copy of police report

2. **Insurance:**
   - File insurance claim if applicable
   - Provide documentation

3. **User Communication:**
   - Notify user of security requirements
   - Issue replacement device if needed
   - Conduct security reminder training

4. **Incident Documentation:**
   - Complete incident report
   - Document timeline of events
   - Record actions taken
   - Lessons learned

### 7.3 Recovery Procedures

**If Device is Recovered:**

1. **Do Not Boot or Connect:**
   - Treat as potentially compromised
   - Do not connect to network
   - Place in secure isolation

2. **Forensic Analysis:**
   - Check for signs of tampering
   - Review logs if accessible
   - Assess if data accessed

3. **Disposition:**
   - **If No Tampering Detected:** Full sanitization, then reuse
   - **If Tampering Suspected:** Physical destruction
   - **If Uncertain:** Err on side of destruction

---

## 8. Documentation and Recordkeeping

### 8.1 Required Documentation

**Equipment Disposal Log:**

| Date | Asset ID | Serial Number | Device Type | Sanitization Method | Disposal Method | Performed By | Verified By | Certificate # |
|------|----------|---------------|-------------|-------------------|-----------------|--------------|-------------|---------------|
| 2026-01-06 | A12345 | SN789ABC | Laptop Dell | DBAN 3-pass | Recycling | J. Smith | M. Johnson | CERT-2026-001 |

**Disposal Form Template:**

```
EQUIPMENT DISPOSAL FORM

Device Information:
- Asset ID: _____________
- Serial Number: _____________
- Make/Model: _____________
- Device Type: _____________
- Last User: _____________

Data Classification:
☐ Public  ☐ Internal  ☐ Confidential  ☐ Restricted

Reason for Disposal:
☐ End of Life  ☐ Hardware Failure  ☐ Obsolete  ☐ Lost/Stolen  ☐ Other: _______

Sanitization:
- Method Used: _____________
- Software/Tool: _____________
- Date Performed: _____________
- Performed By: _____________
- Verification Method: _____________
- Verified By: _____________

Disposition:
☐ Internal Reuse  ☐ Donation  ☐ Recycling  ☐ Physical Destruction
- Recipient/Vendor: _____________
- Date: _____________
- Certificate Number: _____________

Approvals:
- IT Manager: _____________ Date: _______
- CISO (if required): _____________ Date: _______

Attachments:
☐ Certificate of Sanitization
☐ Certificate of Destruction/Recycling
☐ Photos of destruction (if applicable)
```

### 8.2 Record Retention

**Retention Periods:**
- Disposal logs: 7 years
- Certificates of destruction: Permanent
- Sanitization records: 7 years
- Incident reports (lost/stolen): 7 years

**Storage:**
- Secure electronic storage (encrypted)
- Access restricted to IT management and CISO
- Regular backups
- Annual review and purge of expired records

---

## 9. Mobile Device Specific Procedures

### 9.1 Smartphone/Tablet Disposal

**iOS Devices:**

1. **Backup (if needed):** Settings → iCloud → Backup
2. **Sign Out:** Settings → [Your Name] → Sign Out
3. **Factory Reset:** Settings → General → Transfer or Reset → Erase All Content and Settings
4. **Verify:** Device should boot to setup screen
5. **Remove SIM/SD cards**

**Android Devices:**

1. **Backup (if needed):** Settings → System → Backup
2. **Encrypt:** Settings → Security → Encrypt phone (if not already encrypted)
3. **Factory Reset:** Settings → System → Reset → Factory data reset
4. **Verify:** Device should boot to setup screen
5. **Remove SIM/SD cards**

### 9.2 Remote Wipe

**Mobile Device Management (MDM):**

If device is enrolled in MDM:
1. Access MDM console
2. Select device
3. Issue remote wipe command
4. Verify wipe completion
5. Document action

**Find My iPhone/Android Device Manager:**
- Last resort if MDM not available
- Requires Apple ID/Google account credentials
- Document wipe was attempted

---

## 10. Special Cases

### 10.1 Failed Hard Drives

**Cannot Be Sanitized:**

1. **Physical Destruction Required:**
   - Use certified destruction service
   - Witness destruction if possible
   - Obtain certificate

2. **On-site Destruction:**
   - Use HDD shredder or drill
   - Multiple drill holes through platters
   - Deform platters with hammer
   - Dispose of remains via certified recycler

### 10.2 Cloud/Virtual Machines

**Virtual Machine Disposal:**

1. **Data Export:** Backup necessary data
2. **Snapshot Deletion:** Delete all VM snapshots
3. **Disk Wiping:** Overwrite virtual disk files
4. **VM Deletion:** Delete VM configuration
5. **Verification:** Confirm deletion from all locations

**Cloud Storage:**

1. **Data Migration:** Move necessary data to new location
2. **Secure Deletion:** Use provider's secure deletion feature if available
3. **Key Destruction:** Delete encryption keys
4. **Verification:** Confirm deletion from all regions/replicas

### 10.3 Multifunction Printers/Copiers

**With Hard Drives:**

1. **Secure Overwrite:** Use printer's built-in secure erase function
2. **Or Remove HDD:** Remove hard drive and destroy separately
3. **Configuration Reset:** Factory reset to remove network settings
4. **Documentation:** Service technician report or destruction certificate

---

## 11. Training and Awareness

### 11.1 Required Training

**IT Staff:**
- Proper sanitization techniques for each device type
- Use of sanitization tools
- Documentation requirements
- Vendor management
- Annual refresher training

**All Personnel:**
- Equipment return procedures
- Lost/stolen device reporting
- Data protection responsibilities
- Annual awareness training

### 11.2 Training Topics

- Data sanitization standards
- Disposal vs. reuse decision making
- Proper use of sanitization tools
- Documentation requirements
- Vendor selection and management
- Physical destruction methods
- Special handling for failed drives
- Incident response for lost/stolen devices

---

## 12. Compliance and Audit

### 12.1 Audit Procedures

**Quarterly Audits:**
- Review disposal logs for completeness
- Verify certificates received from vendors
- Check asset inventory for disposed items removed
- Confirm training completion

**Annual Audits:**
- Comprehensive review of all disposals
- Vendor compliance verification
- Process effectiveness assessment
- Update procedures as needed

### 12.2 Compliance Checklist

☐ All disposed equipment documented  
☐ Appropriate sanitization method used for each device  
☐ Certificates received and filed  
☐ Asset inventory updated  
☐ No sensitive data accessible on disposed equipment  
☐ Vendor certifications current  
☐ Training completed for all relevant personnel  
☐ No outstanding lost/stolen devices unresolved  

---

## 13. Related Documents

- [Asset Inventory Guide](../Asset-Inventory-Guide.md) - Asset tracking
- [Data Classification Policy](../Data-Classification.md) - Information sensitivity
- [Incident Management](./Incident-Management.md) - Lost/stolen device procedures
- [ISMS Policy](../ISMS-Policy.md) - Overall security framework

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | GitHub Copilot | Initial Equipment Disposal Procedures (ISO 27001 A.7.14) |

---

## Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| CISO | [Name] | [Digital Signature] | 2026-01-06 |
| IT Manager | [Name] | [Digital Signature] | 2026-01-06 |
| Operations Manager | [Name] | [Digital Signature] | 2026-01-06 |
| Management | [Name] | [Digital Signature] | 2026-01-06 |

---

**Next Review Date:** 2026-07-06 (6 months, then annually)  
**Review Frequency:** Annually or when disposal methods/standards change
