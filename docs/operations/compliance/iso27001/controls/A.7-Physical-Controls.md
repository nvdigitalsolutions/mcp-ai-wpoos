# Annex A.7: Physical Controls
## ISO/IEC 27001:2022 - Open Operator System (NV oOS)

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-05  
**Review Date:** 2026-04-05  
**Document Owner:** Chief Information Security Officer (CISO)

---

## 1. Introduction

This document details the implementation of the 14 Physical Controls from ISO/IEC 27001:2022 Annex A.7 for the NV oOS WordPress plugin. These controls address physical and environmental security of information processing facilities.

### Unique Context for NV oOS

The NV oOS project operates in a **primarily cloud-based and distributed environment**:
- No centralized physical facilities under direct control
- Cloud infrastructure managed by third-party providers (AWS, Google Cloud, etc.)
- Distributed development team working remotely
- Infrastructure as a Service (IaaS) and Platform as a Service (PaaS) model

As a result:
- Many physical controls are **Not Applicable** (managed by cloud providers)
- Remaining controls focus on **endpoint device security** for remote workers
- Heavy reliance on **cloud provider certifications** (ISO 27001, SOC 2)

---

## 2. Physical Security Perimeters (A.7.1 - A.7.6)

### A.7.1 Physical Security Perimeters

**Control Objective:** Prevent unauthorized physical access to information and information processing facilities.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- No physical facilities under direct organizational control
- All infrastructure hosted in cloud provider data centers
- Cloud providers maintain physical security perimeters with:
  - Fenced perimeters
  - Security guards
  - Surveillance systems
  - Access control systems
  - Intrusion detection

**Cloud Provider Security:**
- **AWS:** ISO 27001, SOC 2 Type II certified data centers
- **Google Cloud:** ISO 27001, SOC 2 Type II certified facilities
- **Other Hosting Providers:** Security certifications verified before selection

**Shared Responsibility Model:**
- **Cloud Provider Responsibility:** Physical facility security
- **Our Responsibility:** Logical access controls, data encryption, application security

**Evidence:** Cloud provider security documentation, ISO 27001 certificates, SOC 2 reports

---

### A.7.2 Physical Entry

**Control Objective:** Secure areas should be protected by appropriate entry controls.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- No secure areas under direct control
- Physical access control managed by cloud providers
- Cloud provider facilities implement:
  - Multi-factor authentication for entry
  - Biometric access controls
  - Mantrap entry systems
  - Visitor management systems
  - Access logging and monitoring

**Developer Endpoint Access:**
- Remote workers control physical access to their workspace
- Clear desk/screen policy provides endpoint physical security (see A.7.7)

**Evidence:** Cloud provider access control documentation

---

### A.7.3 Securing Offices, Rooms and Facilities

**Control Objective:** Physical security for offices, rooms and facilities should be designed and implemented.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- No central office facilities
- Distributed remote workforce
- Individual developers responsible for their workspace security
- See A.6.7 (Remote Working) for home office security requirements

**Evidence:** Distributed team structure, remote work policy

---

### A.7.4 Physical Security Monitoring

**Control Objective:** Premises should be continuously monitored for unauthorized physical access.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- Cloud provider responsibility for data center monitoring
- Cloud providers implement:
  - 24/7 video surveillance
  - Motion detectors
  - Alarm systems
  - Security operations center (SOC) monitoring
  - Incident response teams on-site

**Evidence:** Cloud provider security documentation

---

### A.7.5 Protecting Against Physical and Environmental Threats

**Control Objective:** Physical protection against natural disasters, malicious attack or accidents should be designed and applied.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- Cloud provider responsibility
- Data centers protected against:
  - **Fire:** Fire suppression systems, smoke detection
  - **Flood:** Elevated floors, water detection, drainage
  - **Power Loss:** Redundant power supplies, UPS, backup generators
  - **Climate:** HVAC systems, temperature/humidity monitoring
  - **Earthquake:** Seismic bracing (in applicable regions)

**Evidence:** Cloud provider environmental protection documentation, SLA agreements

---

### A.7.6 Working in Secure Areas

**Control Objective:** Security measures for working in secure areas should be designed and implemented.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- No designated secure areas in organization
- Development work performed remotely
- Sensitive operations (production access, security incident response) performed through secure remote access channels

**Evidence:** Remote work policy, secure access procedures

---

## 3. Endpoint and Mobile Security (A.7.7 - A.7.9)

### A.7.7 Clear Desk and Clear Screen

**Control Objective:** Clear desk and clear screen policies should be implemented.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Clear Desk Policy

**Requirements for Remote Workers:**
1. **No Confidential Documents Left Visible**
   - All confidential documents stored securely when not in use
   - Physical documents locked in drawer or cabinet
   - No sensitive information visible to visitors or passersby

2. **Proper Document Disposal**
   - Shred confidential documents (personal shredder recommended)
   - No confidential documents in regular trash
   - Digital document deletion procedures

3. **Secure Storage**
   - Lockable drawer or cabinet for confidential materials
   - Encryption for digital documents on devices
   - Remove portable media (USB drives) when not in use

**Implementation Guidance:**
- End-of-day desk check for visible confidential materials
- Locked storage for sensitive physical documents
- Secure file deletion for no-longer-needed documents

#### Clear Screen Policy

**Requirements for All Personnel:**

**Automatic Screen Lock:**
- **Timeout:** Maximum 5 minutes of inactivity
- **Protection:** Password/PIN required to unlock
- **Enforcement:** Mandatory for all work devices

**Manual Lock When Leaving Device:**
- Lock screen immediately when stepping away
- **Windows:** Win+L
- **macOS:** Cmd+Ctrl+Q or hot corners
- **Linux:** Ctrl+Alt+L or screen lock shortcut

**Screen Privacy:**
- Privacy screen filters recommended for work in public spaces
- No viewing of confidential information in public view
- Screen positioned away from windows and public areas

**Video Calls:**
- Blur or virtual background to hide sensitive information
- No confidential documents visible on camera
- Careful with screen sharing (share specific window, not entire screen)

#### Implementation Status

**Currently Implemented:**
- Automatic screen lock recommendations in remote work policy
- Security awareness of clear desk/screen importance

**In Progress:**
- Formal clear desk and clear screen policy document
- Enforcement mechanisms (periodic checks)
- Screen lock configuration verification

**Planned:**
- Automated screen lock enforcement (device management)
- Clear desk/screen compliance monitoring
- Privacy screen filter provision for public workers

**Responsibilities:**
- **Individual Workers:** Comply with clear desk/screen policies
- **Team Leads:** Monitor compliance, remind team members
- **CISO:** Define policy requirements, periodic audits

**Evidence:** Remote work security guidelines, device configuration requirements

**Next Steps:**
- Develop formal clear desk and clear screen policy
- Implement automated screen lock verification
- Provide privacy screen filters for team members who work in public
- Conduct quarterly compliance checks

---

### A.7.8 Equipment Siting and Protection

**Control Objective:** Equipment should be sited and protected to reduce risks from environmental threats and hazards, and opportunities for unauthorized access.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Developer Endpoint Protection

**Device Placement:**
- Work devices used in secure home environment
- Avoid leaving devices in vehicles
- No devices left unattended in public spaces
- Devices physically secured when working in public (cable lock)

**Environmental Protection:**
- Protect devices from:
  - **Liquid damage:** No drinks near devices
  - **Physical damage:** Protective cases for mobile devices
  - **Theft:** Cable locks in public spaces, secure storage at home
  - **Extreme temperatures:** Avoid extreme heat or cold exposure

**Power Protection:**
- Surge protectors recommended for desktop workstations
- UPS (Uninterruptible Power Supply) recommended for critical systems
- Proper power cable management to prevent tripping hazards

**Workspace Setup:**
- Ergonomic setup reduces physical strain and errors
- Adequate lighting to prevent eye strain and mistakes
- Stable work surface for equipment
- Equipment positioned to prevent unauthorized viewing

#### Mobile Device Protection

**Smartphones and Tablets:**
- Strong password/PIN/biometric lock
- Auto-lock after short timeout (1-2 minutes)
- Find My Device / Remote wipe capability enabled
- Protective cases to prevent damage
- No jailbreaking or rooting (compromises security)

**Laptops:**
- Full disk encryption mandatory
- Strong boot password
- Physical laptop lock for public spaces
- Protective laptop bag/case
- Backpack with padded laptop compartment

**Portable Storage (USB drives, external drives):**
- Encrypted storage mandatory for confidential data
- Physically secure when not in use
- Proper labeling (no content description, just contact info)
- Minimize use (prefer cloud storage with encryption)

#### Public Space Security

**When Working in Public (Cafes, Airports, etc.):**
- Use cable lock to secure laptop to fixed object
- Never leave device unattended (even briefly)
- Use privacy screen filter
- Avoid displaying confidential information
- Use VPN for all network connections
- Sit with back to wall, screen away from public view

**Co-working Spaces:**
- Verify security of co-working space
- Use secure locker for equipment storage if available
- Follow same public space security measures
- Consider private office or meeting room for confidential work

#### Implementation Status

**Currently Implemented:**
- Personal device security guidelines in remote work policy
- Environmental and physical protection awareness

**In Progress:**
- Equipment protection standards document
- Cable lock provision for public workers
- Comprehensive mobile device security policy

**Planned:**
- Mobile Device Management (MDM) for company-issued devices
- Equipment insurance for work devices
- Regular equipment security audits

**Responsibilities:**
- **Individual Workers:** Protect assigned equipment
- **IT/Operations:** Provide security tools (cable locks, privacy screens)
- **CISO:** Define equipment protection standards

**Evidence:** Device security guidelines, remote work policy, equipment provision records

**Next Steps:**
- Develop comprehensive equipment siting and protection policy
- Provide cable locks and privacy screens to team members
- Implement Mobile Device Management (MDM) solution
- Conduct equipment security awareness training

---

### A.7.9 Security of Assets Off-Premises

**Control Objective:** Off-site assets should be protected.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Off-Premises Asset Types

**Work Equipment Off-Site:**
- Laptops taken home or while traveling
- Smartphones and tablets
- USB drives or external hard drives
- Paper documents (if any)
- Access tokens or hardware keys

#### Security Requirements for Off-Site Assets

**1. Physical Security**

**At Home:**
- Secure storage when not in use (locked drawer/cabinet)
- Keep devices away from windows (visible from outside)
- No family members or visitors using work devices
- Lock home office when leaving

**While Traveling:**
- Never check laptops or sensitive items in luggage (carry-on only)
- Hotel room safe for storage when not in room
- Never leave in vehicle (especially visible)
- Be aware of theft risks in different locations
- Consider travel insurance for high-value equipment

**In Transit:**
- Keep devices in carry-on luggage, not checked bags
- Maintain physical control of devices during travel
- Be discreet (don't advertise expensive equipment)
- Secure bag straps to person or fixed object

**2. Logical Security**

**Encryption:**
- Full disk encryption mandatory (protects if device stolen)
- Encrypted file containers for confidential files
- Encrypted communication (VPN, HTTPS)

**Authentication:**
- Strong device passwords/PINs
- Biometric authentication enabled where available
- Auto-lock after short timeout
- No automatic login

**Remote Management:**
- Remote wipe capability enabled
- Find My Device / tracking enabled
- Remote lock capability
- Regular device check-in to management system

**3. Data Protection**

**Minimize Local Data Storage:**
- Use cloud storage for working files
- Sync, don't store (if possible)
- Delete local copies when no longer needed
- Regular backups to secure cloud storage

**Confidential Data:**
- Extra caution with confidential information
- Encryption mandatory
- Minimize time stored locally
- Secure deletion when done

**4. Network Security**

**Public Wi-Fi:**
- Always use VPN on public networks
- No confidential data access without VPN
- Disable auto-connect to Wi-Fi networks
- Forget public Wi-Fi networks after use

**International Travel:**
- Check country-specific security risks
- Consider using "travel" device without confidential data for high-risk countries
- Be aware of border search and seizure laws
- Backup before travel, restore after return

#### Reporting Requirements

**Must Report Immediately:**
- Device loss or theft
- Suspected device compromise
- Border device seizure
- Unusual device behavior after travel

**Reporting Contact:** security@nvdigitalsolutions.com  
**Response Time:** Immediately upon discovery

#### Asset Tracking

**Off-Site Asset Register:**
- Track all assets assigned to individuals
- Record when assets taken off-site
- Document asset return
- Regular asset inventory (quarterly)

**Check-In Requirements:**
- Long-term off-site assets check-in monthly (remote)
- Verify asset status and security compliance
- Update asset register

#### Implementation Status

**Currently Implemented:**
- Remote access security via VPN (if applicable)
- Encryption requirements for devices
- Device loss reporting procedures

**In Progress:**
- Comprehensive off-site asset security policy
- Asset tracking system
- Remote device management capabilities
- Travel security guidelines

**Planned:**
- Mobile Device Management (MDM) with remote wipe
- Asset insurance for off-site equipment
- Travel device loaner program for high-risk destinations
- Regular off-site asset security audits

**Responsibilities:**
- **Individual Workers:** Secure off-site assets, report incidents
- **IT/Operations:** Enable remote management, asset tracking
- **CISO:** Define off-site security requirements
- **Management:** Approve asset off-site use

**Evidence:** Remote access policies, encryption implementation, device tracking logs

**Next Steps:**
- Develop comprehensive off-site asset security policy
- Implement Mobile Device Management (MDM)
- Create asset tracking system
- Develop travel security guidelines
- Provide travel security training

---

## 4. Storage Media and Utilities (A.7.10 - A.7.13)

### A.7.10 Storage Media

**Control Objective:** Storage media should be managed securely throughout their lifecycle.

**Implementation Status:** ✅ Implemented

**Implementation Details:**

#### Types of Storage Media

**Digital Storage:**
- Database servers (cloud-hosted)
- File storage systems (cloud-based)
- Developer workstation hard drives
- USB drives and external hard drives
- Backup media

**Physical Storage:**
- Minimal physical documents (primarily digital)
- Printed confidential documents (if any)

#### Storage Media Security Measures

**1. Data at Rest Encryption**

**Mandatory Encryption:**
- Full disk encryption on all work devices (BitLocker, FileVault, LUKS)
- Database encryption at rest (cloud provider managed)
- Encrypted file storage for confidential data
- Encrypted USB drives for confidential data transfer

**Encryption Standards:**
- Algorithm: AES-256 or stronger
- Key management: Secure key storage (TPM, secure enclave, key management service)
- No storage of encryption keys with encrypted data

**2. Access Controls**

**Physical Media:**
- Restricted access to storage areas (if physical storage exists)
- Logged access to storage media
- Check-out/check-in procedures for removable media

**Digital Media:**
- File system permissions (least privilege)
- Database access controls
- Audit logging of data access
- No shared accounts

**3. Media Handling Procedures**

**Creation/Receipt:**
- Classify data upon creation/receipt
- Apply appropriate security controls based on classification
- Label media appropriately (physical labels or metadata)

**Use:**
- Use only for authorized purposes
- No mixing of data classifications without proper protection
- Regular vulnerability scanning
- Malware scanning before use (especially removable media)

**Storage:**
- Secure storage environment (locked cabinets for physical)
- Climate-controlled for physical media longevity
- Off-site backup storage in secure facility
- Inventory tracking

**Transfer:**
- Encryption for all transfers
- Secure protocols (HTTPS, SFTP, SCP)
- Audit trail of transfers
- Verification of recipient authorization

**Destruction/Disposal:**
- See A.7.14 (Secure Disposal or Reuse of Equipment)
- Secure deletion for digital media
- Physical destruction for highly sensitive data

**4. Backup Media Security**

**Backup Storage:**
- Encrypted backups mandatory
- Off-site backup storage (different geographic location)
- Access controls on backup media
- Regular backup verification (restore tests)

**Retention:**
- Daily backups retained for 30 days
- Monthly backups retained for 12 months
- Critical data additional retention as required

**5. Removable Media Policy**

**USB Drives and External Drives:**
- Encryption mandatory for organizational data
- Malware scan before use
- Minimize use (prefer secure cloud storage)
- Approved devices only (whitelist if MDM available)

**Restrictions:**
- No auto-run from removable media
- Disable unnecessary removable media ports (if policy requires)
- Data Loss Prevention (DLP) scanning on file copy operations

#### Cloud Storage Security

**Cloud Provider Controls:**
- ISO 27001, SOC 2 Type II certified providers
- Encryption at rest and in transit
- Access controls and authentication
- Audit logging
- Geographic data residency controls

**Our Controls:**
- Application-layer encryption for highly sensitive data (encrypt before upload)
- Access management and review
- Data classification and handling
- Backup and redundancy

#### Implementation Status

**Fully Implemented:**
- Data at rest encryption (disk, database, credential storage)
- Secure credential storage (AES-256 encryption)
- Cloud storage security (certified providers)
- Backup encryption and off-site storage

**Operational:**
- Access controls on storage systems
- Audit logging of data access
- Secure deletion procedures

**Responsibilities:**
- **IT/Operations:** Manage storage infrastructure, encryption
- **Developers:** Encrypt devices, follow media handling procedures
- **CISO:** Define storage media security requirements

**Evidence:** Encryption implementation in code, cloud provider certifications, backup procedures documentation

---

### A.7.11 Supporting Utilities

**Control Objective:** Information processing facilities should be protected from power failures and other disruptions.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- Cloud provider responsibility for data center utilities
- Cloud data centers have:
  - **Redundant power supplies:** Multiple utility feeds
  - **Backup generators:** Automatic failover
  - **Uninterruptible Power Supply (UPS):** Seamless transition
  - **HVAC systems:** Redundant climate control
  - **Water supply:** For cooling systems
  - **Fire suppression:** Advanced systems

**Developer Endpoint Utilities:**
- Individual responsibility for home/office utilities
- UPS recommended for critical workstations (optional)
- Work can be suspended during power outages (no 24/7 uptime requirement)

**Evidence:** Cloud provider utility redundancy documentation, SLA agreements

---

### A.7.12 Cabling Security

**Control Objective:** Cabling carrying power or data should be protected.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- No organizational cabling infrastructure
- Cloud provider manages data center cabling security:
  - Protected in conduits or overhead trays
  - Restricted access to cabling areas
  - Physical security monitoring
  - Tamper detection

**Developer Endpoints:**
- Wireless connectivity primarily used (Wi-Fi)
- Ethernet cabling at home/office is individual responsibility
- No sensitive organizational cabling infrastructure

**Evidence:** Cloud-based architecture, distributed remote workforce

---

### A.7.13 Equipment Maintenance

**Control Objective:** Equipment should be maintained correctly to ensure availability, integrity and confidentiality.

**Implementation Status:** ❌ Not Applicable

**Justification:**
- Cloud provider maintains infrastructure equipment:
  - Scheduled preventive maintenance
  - 24/7 monitoring and support
  - Rapid failure response and replacement
  - Firmware and hardware updates

**Developer Endpoints:**
- Individual responsibility for personal device maintenance
- Company-issued equipment maintenance (if applicable):
  - Warranty and repair services
  - Regular software updates
  - Hardware replacement as needed

**Guidelines for Developer Endpoints:**
- Keep software and firmware updated
- Regular malware scans
- Disk health monitoring
- Professional repair services (not DIY for company devices)
- Backup before maintenance

**Evidence:** Cloud provider maintenance SLAs, device maintenance guidelines

---

## 5. Secure Disposal and Reuse (A.7.14)

### A.7.14 Secure Disposal or Reuse of Equipment

**Control Objective:** Items of equipment containing storage media should be verified to ensure that any sensitive data and licensed software has been removed or securely overwritten prior to disposal or reuse.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Equipment Disposal Policy

**When Equipment is Disposed:**
- End of useful life
- Device damage beyond repair
- Upgrade to new equipment
- End of employment (if company-owned)
- Security incident (compromised device)

#### Disposal Process

**1. Data Sanitization**

**Before Disposal or Reuse:**

**Option A: Secure Data Wiping (Preferred for Reuse)**
- Use certified data wiping software:
  - **DBAN (Darik's Boot and Nuke)** for full disk wipe
  - **BitRaser** or **Blancco** for certified wiping (with certificate)
  - Built-in secure erase (if available)
- Wipe standards:
  - **DoD 5220.22-M** (3-pass minimum)
  - **NIST 800-88** Guidelines for Media Sanitization
  - **Gutmann method** (35-pass, for highly sensitive data)
- Verify successful wipe completion
- Document wiping (certificate or log)

**Option B: Physical Destruction (Required for End-of-Life or Highly Sensitive)**
- **Hard Drives:**
  - Degaussing (magnetic erasure)
  - Physical shredding or crushing
  - Multiple pass drilling
- **Solid State Drives (SSD):**
  - Cryptographic erasure (if encrypted)
  - Physical shredding or incineration
  - Note: Standard wiping less effective on SSD due to wear-leveling
- **Optical Media (CD/DVD):**
  - Shredding or incineration
- **USB Drives:**
  - Physical destruction or cryptographic erasure

**2. Verification**

**Pre-Disposal Checklist:**
- [ ] All data backed up (if needed)
- [ ] Data wiping completed and verified
- [ ] No confidential data remains
- [ ] Software licenses deactivated
- [ ] Device removed from asset inventory
- [ ] Device tracking/management software removed
- [ ] Accessories inventoried (chargers, cables, etc.)

**3. Physical Disposal**

**Disposal Methods:**
- **Resale/Donation:** Only after secure wiping, for non-sensitive devices
- **Trade-In:** Secure wiping required, use reputable vendors
- **Recycling:** Use certified e-waste recycling (R2 or e-Stewards certified)
- **Destruction:** On-site or via certified destruction service

**Environmental Responsibility:**
- Prefer recycling to landfill disposal
- Use certified e-waste recycling services
- Comply with local regulations (WEEE Directive, etc.)
- Document environmentally responsible disposal

**4. Documentation**

**Disposal Records:**
- Device identification (serial number, asset tag)
- Disposal date and method
- Data sanitization method and verification
- Disposal service or recipient (if applicable)
- Authorization (who approved disposal)
- Certificate of destruction (if service used)

**Retention:** Disposal records retained for 7 years

#### Equipment Reuse Policy

**Internal Reuse (Reassignment):**

**Process:**
1. Secure data wipe (previous user's data)
2. Reinstall OS and software (clean install)
3. Remove from previous user, assign to new user in asset register
4. Verify functionality
5. Provide to new user

**No Mixing of Classifications:**
- Device used for Restricted data should not be downgraded to lower classification without thorough sanitization or destruction
- Consider dedicated devices for highly sensitive work

**External Reuse (Sale/Donation):**
- Only devices without highly sensitive data history
- Secure data wiping mandatory
- Remove all organizational identifiers (asset tags, engravings)
- Include disposal certificate or wiping proof in disposal records

#### Storage Media Disposal

**Hard Drives and SSDs:**
- Never dispose of in regular trash
- Secure wipe or physical destruction
- SSDs: Physical destruction preferred (secure wipe less reliable)

**USB Drives:**
- Physical destruction for sensitive data devices
- Secure wipe acceptable for general use devices

**Optical Media (CD/DVD/Blu-ray):**
- Shredding or destruction
- Don't assume "unreadable" discs are secure

**Backup Tapes (If Used):**
- Degaussing or physical destruction
- Never reuse or donate backup media

**Mobile Devices:**
- Factory reset insufficient for sensitive devices
- Use manufacturer secure wipe or physical destruction
- Remove SIM cards and memory cards

**Paper Documents:**
- Cross-cut shredding minimum (4mm or less)
- Pulping or incineration for highly sensitive documents
- Shredding service for large volumes (with certificate)

#### Special Considerations

**Broken/Damaged Devices:**
- Still require data sanitization if possible
- Physical destruction if wiping not possible
- Document inability to wipe and method of destruction

**Devices with Encryption:**
- Crypto-shredding (destroying encryption keys) may be sufficient
- Physical destruction still preferred for highly sensitive devices
- Document encryption and key destruction

**Warranty Returns:**
- Secure wipe before return to manufacturer
- Consider removing storage media before return (install dummy drive)
- Document sanitization before return

**Law Enforcement Seizure:**
- Follow legal requirements
- Document chain of custody
- Notify CISO and legal counsel
- Remote wipe if possible and legally permissible

#### Implementation Status

**Currently Implemented:**
- Data wiping procedures documented
- Secure deletion functions in code (crypto-shredding)

**In Progress:**
- Formal equipment disposal policy
- Disposal tracking system
- Certified e-waste recycling vendor selection

**Planned:**
- Automated disposal workflow
- Disposal certificates from vendors
- Regular disposal audit

**Responsibilities:**
- **IT/Operations:** Perform data sanitization, coordinate disposal
- **Asset Management:** Track disposal, maintain records
- **CISO:** Define disposal security requirements, approve exceptions
- **Individual Workers:** Initiate disposal requests, backup needed data

**Evidence:** Equipment disposal guidelines, disposal records, destruction certificates

**Next Steps:**
- Develop comprehensive equipment disposal and reuse policy
- Select certified e-waste recycling vendor
- Implement disposal tracking system
- Provide data wiping tools to team members
- Conduct disposal procedure training

---

## 6. Summary

### Overall Implementation Status

| Control | Status | Applicability |
|---------|--------|---------------|
| A.7.1 Physical Security Perimeters | ❌ N/A | Cloud provider managed |
| A.7.2 Physical Entry | ❌ N/A | Cloud provider managed |
| A.7.3 Securing Offices | ❌ N/A | No central facilities |
| A.7.4 Physical Security Monitoring | ❌ N/A | Cloud provider managed |
| A.7.5 Environmental Threats | ❌ N/A | Cloud provider managed |
| A.7.6 Working in Secure Areas | ❌ N/A | No secure areas |
| A.7.7 Clear Desk and Screen | 🔄 Partial | Applicable (remote work) |
| A.7.8 Equipment Siting | 🔄 Partial | Applicable (endpoints) |
| A.7.9 Assets Off-Premises | 🔄 Partial | Applicable (remote work) |
| A.7.10 Storage Media | ✅ Implemented | Applicable |
| A.7.11 Supporting Utilities | ❌ N/A | Cloud provider managed |
| A.7.12 Cabling Security | ❌ N/A | Cloud provider managed |
| A.7.13 Equipment Maintenance | ❌ N/A | Cloud provider managed |
| A.7.14 Secure Disposal | 🔄 Partial | Applicable |

### Implementation Summary
- **Implemented:** 1/14 (7%)
- **Partial:** 4/14 (29%)
- **Not Applicable:** 9/14 (64%)

### Applicability Analysis

**Not Applicable (9 controls):**
Most physical controls are not applicable due to cloud-based, distributed architecture. These controls are managed by cloud providers who maintain ISO 27001 certification for their data centers.

**Applicable (5 controls):**
Remaining controls focus on endpoint device security and remote worker physical security, which are under organizational control.

### Key Strengths
- Strong storage media encryption (data at rest)
- Cloud provider security reliance (certified providers)
- Storage media handling procedures

### Areas for Improvement
1. **Clear Desk and Clear Screen Policy** (A.7.7)
   - Formalize policy document
   - Automated screen lock enforcement
   - Periodic compliance audits

2. **Equipment Siting and Protection** (A.7.8)
   - Comprehensive equipment protection standards
   - Provision of security accessories (cable locks, privacy screens)
   - Mobile Device Management (MDM) implementation

3. **Assets Off-Premises Security** (A.7.9)
   - Formal off-site asset security policy
   - Asset tracking system
   - Travel security guidelines

4. **Secure Disposal Procedures** (A.7.14)
   - Formal disposal policy
   - Disposal tracking system
   - Certified disposal vendor contracts

### Priority Actions
1. Develop formal clear desk and clear screen policy (Q1 2026)
2. Implement automated screen lock enforcement via MDM (Q2 2026)
3. Create equipment siting and protection standards (Q1 2026)
4. Develop off-site asset security policy with travel guidelines (Q2 2026)
5. Implement asset tracking system (Q2 2026)
6. Formalize equipment disposal policy and procedures (Q2 2026)
7. Select and contract certified e-waste recycling vendor (Q2 2026)

### Shared Responsibility Model

**Cloud Provider Responsibilities (Not Applicable Controls):**
- Physical facility security and access control
- Environmental protection and utilities
- Infrastructure equipment maintenance
- Data center cabling and monitoring

**Organization Responsibilities (Applicable Controls):**
- Endpoint device security (encryption, protection, disposal)
- Remote worker physical security (clear desk/screen)
- Off-site asset security
- Storage media handling
- Secure disposal of organizational equipment

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-05 | GitHub Copilot | Initial physical controls documentation |

---

**Next Review:** 2026-04-05 (Quarterly)
