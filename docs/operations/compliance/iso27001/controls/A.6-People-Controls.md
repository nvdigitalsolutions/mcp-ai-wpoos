# Annex A.6: People Controls
## ISO/IEC 27001:2022 - Open Operator System (NV oOS)

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-05  
**Review Date:** 2026-04-05  
**Document Owner:** Chief Information Security Officer (CISO)

---

## 1. Introduction

This document details the implementation of the 8 People Controls from ISO/IEC 27001:2022 Annex A.6 for the NV oOS WordPress plugin. These controls address information security aspects related to personnel management throughout the employment lifecycle.

The People Controls are critical because:
- Human error is a leading cause of security incidents
- Insider threats require specific mitigation strategies
- Personnel awareness is essential for security culture
- Clear responsibilities prevent security gaps

---

## 2. Pre-Employment (A.6.1 - A.6.2)

### A.6.1 Screening

**Control Objective:** Verify that candidates and contractors are suitable for their roles and understand their responsibilities.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Background Verification
- **Core Team Members:** Background checks for positions with elevated access
- **Contributors:** Public GitHub profile review and contribution history
- **Contractors:** Professional reference verification
- **Third-Party Developers:** Code review and vetting process

#### Screening Levels by Access Level

| Access Level | Screening Requirements |
|--------------|------------------------|
| **Public Contributors** | GitHub profile review, contribution history |
| **Regular Developers** | Professional references, work history verification |
| **Core Team** | Background check, professional references, education verification |
| **Privileged Access** | Enhanced background check, security clearance (if required) |

#### Screening Process
1. **Initial Application Review**
   - Review resume/CV
   - Check public online presence (GitHub, LinkedIn)
   - Verify claimed credentials

2. **Technical Assessment**
   - Code samples review
   - Technical interview
   - Security awareness evaluation

3. **Background Verification** (for core team)
   - Professional reference checks (minimum 2)
   - Employment history verification
   - Education verification
   - Criminal background check (where legally permitted)

4. **Final Approval**
   - Management review
   - Security team approval for privileged access
   - Documentation of screening results

#### Open Source Considerations
- Balance security screening with open-source community values
- Focus on contribution quality and community behavior
- Escalate screening for commit access and administrative privileges

**Responsibilities:**
- **HR/Management:** Coordinate screening activities
- **CISO:** Define screening requirements per role
- **Team Leads:** Verify technical capabilities

**Evidence:** Screening procedures documentation, background check records (confidential), approval logs

**Next Steps:**
- Formalize screening procedures document
- Define clear screening levels based on role sensitivity
- Implement screening tracking system

---

### A.6.2 Terms and Conditions of Employment

**Control Objective:** Ensure employees and contractors agree to and understand their information security responsibilities.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Employment Agreements
- **Employees:** Security responsibilities included in employment contracts
- **Contractors:** Security clauses in contractor agreements
- **Contributors:** Contributor License Agreement (CLA) includes security obligations

#### Security-Related Terms

**All Personnel Must Agree To:**
1. **Confidentiality Obligations**
   - Protect confidential information
   - No unauthorized disclosure
   - Obligations survive termination

2. **Security Responsibilities**
   - Follow security policies and procedures
   - Report security incidents immediately
   - Protect authentication credentials
   - Use assets only for authorized purposes

3. **Intellectual Property**
   - Work product ownership
   - License to organization's code (GPL v3)
   - Third-party IP respect

4. **Acceptable Use**
   - Appropriate use of information systems
   - Prohibition of illegal activities
   - No unauthorized access attempts

5. **Disciplinary Measures**
   - Consequences of security policy violations
   - Grounds for termination
   - Legal consequences for severe violations

#### Contributor License Agreement (CLA)
- Required for all code contributors
- Includes security and confidentiality clauses
- Grants necessary rights to project
- Acknowledges GPL v3 license terms

#### Contract Security Clauses Template
```
Security Responsibilities:
- Comply with organization's information security policies
- Report security incidents within 4 hours of discovery
- Protect access credentials and not share with others
- Use encryption for sensitive data transmission
- Return all assets upon termination of relationship
- Maintain confidentiality of proprietary information
- Submit to security screening as appropriate to role
```

**Responsibilities:**
- **HR/Management:** Ensure contracts include security clauses
- **Legal:** Draft and review security terms
- **CISO:** Define required security obligations

**Evidence:** Employment contract templates, contractor agreements, CLA documents

**Next Steps:**
- Develop comprehensive employment contract security template
- Formalize contractor security agreement template
- Review and update existing agreements

---

## 3. During Employment (A.6.3 - A.6.4)

### A.6.3 Information Security Awareness, Education and Training

**Control Objective:** Ensure personnel receive appropriate security awareness training.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Training Program Structure

**1. Initial Security Orientation (All New Personnel)**
- ISMS overview and security policies
- Acceptable use policy
- Password and credential management
- Incident reporting procedures
- Data protection and privacy basics
- Physical security (for employees with office access)
- **Duration:** 1-2 hours
- **Timing:** Within first week of employment

**2. Role-Specific Security Training**

| Role | Training Topics | Frequency |
|------|----------------|-----------|
| **Developers** | Secure coding (OWASP), code review, vulnerability prevention | Onboarding + Annual |
| **DevOps/Operations** | Infrastructure security, access management, monitoring | Onboarding + Annual |
| **Support Team** | Incident handling, social engineering awareness | Onboarding + Annual |
| **Administrators** | Privileged access management, audit logging | Onboarding + Annual |

**3. Ongoing Security Awareness (All Personnel)**
- **Monthly:** Security tips, current threats
- **Quarterly:** Security newsletter with recent incidents and lessons
- **Annual:** Comprehensive security refresher training

**4. Specialized Training**
- **Incident Response Team:** Incident handling procedures (semi-annual)
- **Security Champions:** Advanced security topics (quarterly)
- **Auditors:** ISO 27001 auditing skills (annual)

#### Current Training Materials
- Security documentation (SECURITY.md, ISMS Policy)
- Secure coding guidelines (CONTRIBUTING.md)
- Code review feedback (on-the-job learning)
- Security-focused PR comments

#### Training Effectiveness Measurement
- Completion tracking for mandatory training
- Assessment quizzes for key concepts
- Simulated phishing exercises (planned)
- Security incident metrics (track improvement)
- Feedback surveys from trainees

#### Security Awareness Topics
- **Password Security:** Strong passwords, MFA, password managers
- **Social Engineering:** Phishing, pretexting, baiting recognition
- **Data Protection:** Classification, handling, encryption
- **Incident Reporting:** What to report, how to report, when to report
- **Physical Security:** Clean desk, screen lock, visitor management
- **Remote Work:** VPN use, home network security, video call security
- **Development Security:** Input validation, authentication, least privilege

**Responsibilities:**
- **CISO:** Develop training program and materials
- **Team Leads:** Ensure team members complete training
- **HR:** Track training completion

**Evidence:** [procedures/Security-Training-Program.md](../procedures/Security-Training-Program.md), training materials, completion records

**Next Steps:**
- Develop formal security training program document
- Create training materials (presentations, videos, quizzes)
- Implement training tracking system
- Conduct annual security awareness refresher

---

### A.6.4 Disciplinary Process

**Control Objective:** Establish formal disciplinary process for security policy violations.

**Implementation Status:** ✅ Implemented

**Implementation Details:**

#### Violation Severity Levels

**Level 1 - Minor Violations**
- Unintentional policy violation
- No security impact or data exposure
- First-time offense
- **Examples:** Forgot to lock screen, weak password, late security training

**Level 2 - Moderate Violations**
- Repeated minor violations
- Potential security risk created
- Negligent behavior
- **Examples:** Sharing credentials, disabling security tools, unauthorized software installation

**Level 3 - Serious Violations**
- Deliberate policy violation
- Significant security risk or incident
- Gross negligence
- **Examples:** Bypassing security controls, unauthorized data access, failure to report incidents

**Level 4 - Critical Violations**
- Intentional malicious activity
- Major security breach or data loss
- Illegal activity
- **Examples:** Data theft, sabotage, fraud, industrial espionage

#### Disciplinary Actions

| Violation Level | First Offense | Second Offense | Third Offense |
|----------------|---------------|----------------|---------------|
| **Level 1** | Verbal warning, retraining | Written warning | Final warning |
| **Level 2** | Written warning, retraining | Final warning, privilege restriction | Termination consideration |
| **Level 3** | Final warning, privilege suspension | Termination | Immediate termination |
| **Level 4** | Immediate termination, legal action | N/A | N/A |

#### Disciplinary Process Steps

**1. Incident Detection and Reporting**
- Security monitoring detects violation
- Team member reports observed violation
- Self-reporting by violator (encouraged)

**2. Investigation**
- Gather facts about the incident
- Interview relevant parties
- Review logs and evidence
- Determine severity and intent

**3. Determination**
- Assess violation severity level
- Review individual's history
- Consider mitigating factors
- Decide on appropriate action

**4. Action Implementation**
- Inform individual of violation and consequence
- Document in personnel file
- Implement disciplinary action
- Revoke/restrict access if appropriate

**5. Follow-up**
- Retraining if appropriate
- Monitor for repeated violations
- Verify corrective measures taken

**6. Appeals Process**
- Individual may appeal decision to management
- Independent review of facts
- Final decision within 10 business days

#### Immediate Actions for Serious Violations
- Suspend access to systems immediately
- Secure evidence (logs, files, communications)
- Notify management and CISO
- Involve legal counsel if necessary
- Consider law enforcement involvement for criminal acts

#### Documentation Requirements
- Incident report documenting violation
- Investigation findings
- Determination rationale
- Disciplinary action taken
- Individual acknowledgment (signature)
- Appeals and resolution (if applicable)

**Responsibilities:**
- **Team Leads:** Initial investigation, recommendation
- **CISO:** Final determination for security violations
- **HR:** Process implementation, documentation
- **Management:** Appeals review, termination approval

**Evidence:** [procedures/Disciplinary-Process.md](../procedures/Disciplinary-Process.md), violation records (confidential), disciplinary action logs

---

## 4. Termination or Change of Employment (A.6.5 - A.6.6)

### A.6.5 Responsibilities After Termination or Change of Employment

**Control Objective:** Ensure information security responsibilities continue after employment changes.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### Post-Termination Obligations

**Ongoing Confidentiality**
- Confidentiality obligations survive termination
- No disclosure of confidential information (permanent)
- Return of all confidential materials
- Deletion of personal copies of work materials

**Non-Compete and Non-Solicitation**
- If applicable per jurisdiction and contract
- Typically 6-12 months post-termination
- Cannot solicit clients or employees

**Intellectual Property**
- All work product remains property of organization
- No use of proprietary information in new role
- GPL v3 obligations continue for contributed code

#### Offboarding Process

**Immediate Actions (Last Working Day)**
1. Revoke all system access
   - WordPress user accounts (deactivate)
   - GitHub repository access (remove)
   - VPN/remote access (disable)
   - API keys and tokens (revoke)
   - Third-party services (remove)
   - Physical access (key cards, keys)

2. Return of Assets
   - Company-issued equipment (laptops, phones, etc.)
   - Security tokens or hardware keys
   - Access cards or badges
   - Confidential documents (physical and digital)

3. Knowledge Transfer
   - Document current projects and status
   - Transfer responsibilities to another team member
   - Share passwords for shared accounts (then change)
   - Provide handover documentation

4. Exit Interview
   - Review ongoing confidentiality obligations
   - Return asset verification
   - Provide security incident reporting contact
   - Address any final security concerns

**Post-Termination Actions**
- Monitor for unusual account activity (30 days)
- Review access logs for final working days
- Update documentation with role changes
- Remove from email distribution lists and team channels

#### Change of Employment (Internal Transfer)

**Role Change Assessment**
- Review new role's access requirements
- Adjust privileges accordingly (principle of least privilege)
- Revoke access no longer needed
- Provide training for new security responsibilities
- Update access control records

**Same Organization Transfers**
- Don't fully revoke access (transfer instead)
- Ensure no excess privileges accumulate
- Document access changes
- Notify relevant teams of role change

**Responsibilities:**
- **HR:** Coordinate offboarding process
- **IT/Operations:** Revoke system access
- **Manager:** Conduct exit interview, knowledge transfer
- **CISO:** Verify security obligations communicated

**Evidence:** Offboarding checklist, access revocation logs, asset return receipts, exit interview notes

**Next Steps:**
- Develop comprehensive offboarding checklist
- Automate access revocation where possible
- Implement post-termination monitoring procedures
- Create knowledge transfer templates

---

### A.6.6 Confidentiality or Non-Disclosure Agreements

**Control Objective:** Protect information through legally enforceable confidentiality agreements.

**Implementation Status:** 🔄 Partial

**Implementation Details:**

#### When NDAs Are Required

**Mandatory for:**
- All employees with access to confidential information
- Contractors and consultants
- Third-party vendors with data access
- Business partners with information sharing
- External auditors and assessors

**Not Required for:**
- Public contributors (GPL v3 code is public)
- Community members without special access
- Public users of the plugin

#### Types of Confidentiality Agreements

**1. Employee Confidentiality Clause**
- Included in employment contract
- Covers all confidential information
- Survives termination
- **Scope:** Comprehensive, all business information

**2. Contractor NDA**
- Standalone agreement for contractors
- Project-specific or general
- Time-limited or perpetual based on information sensitivity
- **Scope:** Defined by contract scope

**3. Mutual NDA**
- For business partnerships
- Both parties protect shared information
- Common for integration partners
- **Scope:** Defined shared information

**4. Third-Party NDA**
- For vendors and service providers
- One-way protection of our information
- Required before sharing confidential data
- **Scope:** Specific to service relationship

#### Standard NDA Provisions

**1. Definition of Confidential Information**
- All non-public information disclosed
- Includes technical, business, financial information
- Marked as confidential or reasonably understood as such
- Exclusions: public domain, independently developed, rightfully obtained

**2. Obligations of Receiving Party**
- Use only for authorized purpose
- Maintain confidentiality
- Limit access to need-to-know basis
- Apply same care as for own confidential information (minimum reasonable care)
- No reverse engineering or copying (unless GPL v3 allows)

**3. Duration**
- Typically 2-5 years for business information
- Permanent for trade secrets
- Survives termination of relationship

**4. Permitted Disclosures**
- As required by law (with notice if possible)
- To advisors under similar confidentiality obligation
- With prior written consent

**5. Return or Destruction**
- Upon request or termination
- Certification of destruction may be required

**6. Remedies**
- Injunctive relief available
- Damages for breach
- Attorney's fees for prevailing party

**7. Governing Law and Jurisdiction**
- Specified jurisdiction
- Dispute resolution mechanism

#### Open Source Considerations

**GPL v3 License Compatibility**
- Code itself is not confidential (GPL v3 requirement)
- NDAs cover non-code confidential information:
  - API keys and credentials
  - Business plans and strategies
  - Customer data and lists
  - Internal security procedures
  - Unreleased features and roadmap (until public)
  - Security vulnerabilities (until patched and disclosed)

**Contributor Agreements**
- Contributor License Agreement (CLA) includes confidentiality terms
- Protects confidential information shared during contribution process
- Does not conflict with GPL v3 code sharing

#### NDA Management

**Tracking and Monitoring**
- Maintain registry of all NDAs
- Track expiration dates and renewal requirements
- Monitor compliance
- Annual review of NDA effectiveness

**Enforcement**
- Incident reporting for suspected breaches
- Investigation of potential violations
- Legal action when necessary
- Regular reminders of obligations

**Responsibilities:**
- **Legal:** Draft and review NDA terms
- **HR:** Obtain signatures from employees
- **Management:** Ensure contractors sign before access
- **CISO:** Oversee compliance and enforce violations

**Evidence:** NDA templates, signed agreements (confidential), NDA registry

**Next Steps:**
- Develop standard NDA templates (employee, contractor, third-party)
- Implement NDA tracking system
- Conduct review of existing agreements
- Create NDA compliance monitoring procedures

---

## 5. Remote Working (A.6.7)

### A.6.7 Remote Working

**Control Objective:** Implement security measures for remote work arrangements.

**Implementation Status:** ✅ Implemented

**Implementation Details:**

#### Remote Work Security Policy

**Eligibility and Approval**
- Remote work permitted for all development roles
- Approval from team lead required for permanent remote
- Temporary remote work allowed without approval (flexible policy)
- Remote workers must meet security requirements

**Work Environment Requirements**
- Dedicated workspace (recommended, not required)
- Secure home network
- Physical security measures (lock door, secure devices)
- Minimal distractions during work hours

#### Technical Security Requirements

**1. Device Security**

**Company-Issued Devices:**
- Full disk encryption mandatory (BitLocker, FileVault, LUKS)
- Automatic screen lock (5 minutes idle)
- Security software (antivirus, firewall)
- Automatic updates enabled
- Strong password/PIN protection

**Personal Devices (BYOD):**
- Device security standards must be met
- Company data segregated (separate user account or container)
- Remote wipe capability if company data stored
- Regular security assessments

**2. Network Security**

**Required:**
- Secure Wi-Fi with WPA3 or WPA2 (strong password)
- No use of public Wi-Fi for accessing confidential data
- VPN required for accessing internal systems (if applicable)
- Router firmware kept updated

**Recommended:**
- Dedicated network for work devices
- Network firewall enabled
- Guest network for personal devices and visitors

**3. Access Control**

**Authentication:**
- Multi-factor authentication (MFA) required for all remote access
- VPN with certificate-based authentication (if used)
- GitHub two-factor authentication mandatory
- WordPress admin accounts require MFA

**Authorization:**
- Access based on role and need-to-know
- Temporary elevated access must be time-limited
- Regular access reviews for remote workers

**4. Data Protection**

**Data Handling:**
- Confidential data encrypted in transit (HTTPS/TLS)
- Local storage of confidential data minimized
- Encryption for any stored confidential files
- Secure deletion when no longer needed

**Backup:**
- Regular backups of work data to approved locations
- Encrypted backup storage
- Version control for code (Git)

#### Communication Security

**Video Conferencing**
- Use approved platforms (with security features)
- Waiting rooms enabled for sensitive meetings
- Screen sharing only when necessary
- No recording without consent
- Verify participant identities for sensitive discussions

**Messaging and Email**
- Use organization-approved tools
- Encryption for confidential communications
- Careful with attachments and links
- No sensitive data in unencrypted email

**File Sharing**
- Use approved file sharing services
- Access controls on shared files
- Expiring links for temporary shares
- No public sharing of confidential files

#### Physical Security

**Device Protection**
- Never leave devices unattended in public
- Use cable locks when working in public spaces
- Close and lock laptop when away
- No sensitive information visible to others

**Visitor Management**
- Visitors should not have view of screens with confidential data
- Lock screen when visitors present
- No discussion of confidential matters with visitors present

**Clean Desk**
- No confidential documents left out
- Lock or secure documents when not in use
- Proper disposal (shred confidential papers)

#### Incident Reporting

**Remote Workers Must Report:**
- Device loss or theft immediately
- Suspected unauthorized access attempts
- Malware or security software alerts
- Unusual system behavior
- Physical security incidents (break-in, theft)

**Reporting Contact:** security@nvdigitalsolutions.com  
**Response Time Required:** Within 4 hours of discovery

#### Remote Work Security Training

**Required for All Remote Workers:**
- Remote work security policy overview
- Home network security best practices
- Physical security at home
- Incident reporting procedures
- Communication security

**Annual Refresher Training**

#### Monitoring and Compliance

**Periodic Checks:**
- Quarterly security self-assessment by remote workers
- Annual security audit of remote work practices
- Random checks of device security configurations

**Metrics:**
- Remote work security incidents
- Policy compliance rates
- Training completion rates
- Security assessment results

**Responsibilities:**
- **IT/Operations:** Provide secure remote access tools
- **CISO:** Define remote work security requirements
- **Team Leads:** Ensure team compliance
- **Remote Workers:** Comply with security requirements

**Evidence:** Remote work security guidelines, VPN configurations, MFA enforcement, security training records

---

## 6. Information Security Event Reporting (A.6.8)

### A.6.8 Information Security Event Reporting

**Control Objective:** Ensure timely reporting of security events.

**Implementation Status:** ✅ Implemented

**Implementation Details:**

#### Reporting Obligation

**All Personnel Must Report:**
- Suspected security incidents
- Policy violations observed
- Vulnerabilities discovered
- Unusual system behavior
- Lost or stolen devices/credentials
- Unauthorized access attempts
- Malware infections
- Data breaches or leaks
- Social engineering attempts

**No Penalty for Good Faith Reporting**
- Encourage reporting without fear of blame
- Focus on learning and improvement
- Protect whistleblowers

#### What to Report

**Definite Security Incidents:**
- Confirmed unauthorized access
- Malware infection
- Data breach or loss
- System compromise
- DDoS attack
- Successful phishing attack

**Suspicious Activity:**
- Unusual login attempts
- Strange system behavior
- Unexpected network traffic
- Suspicious emails or messages
- Unrecognized processes or files
- Anomalous user behavior

**Policy Violations:**
- Improper data handling
- Security control bypasses
- Unauthorized software installation
- Access control violations

**Vulnerabilities:**
- Security flaws in code
- Configuration weaknesses
- Missing patches or updates
- Third-party vulnerabilities affecting the plugin

#### How to Report

**Primary Contact:** security@nvdigitalsolutions.com

**Reporting Channels:**

**1. Email (Preferred for Most Events)**
- To: security@nvdigitalsolutions.com
- Subject: [SECURITY EVENT] Brief description
- Include: What happened, when, impact, evidence

**2. GitHub Security Advisory (For Code Vulnerabilities)**
- Use GitHub's private security advisory feature
- Repository: nvdigitalsolutions/mcp-ai-wpoos
- Follow responsible disclosure guidelines

**3. Emergency Contact (Critical Incidents)**
- For incidents requiring immediate response
- Available 24/7 for critical issues
- Contact: [To be established - on-call rotation]

**4. Anonymous Reporting**
- Anonymous reporting channel available
- For situations where reporter wants anonymity
- [To be implemented - anonymous form or third-party service]

#### Reporting Timeline

| Incident Severity | Reporting Deadline | Response Expected |
|-------------------|-------------------|-------------------|
| **Critical** | Immediately (phone if after hours) | Within 1 hour |
| **High** | Within 4 hours | Within 8 hours |
| **Medium** | Within 24 hours | Within 48 hours |
| **Low** | Within 1 week | As appropriate |

**Critical Incidents Examples:**
- Active security breach
- Data breach with PII exposure
- Ransomware infection
- Compromised privileged account

#### Information to Include in Report

**Essential Information:**
1. **What:** Description of the event
2. **When:** Date and time (include timezone)
3. **Who:** Reporter name and contact (unless anonymous)
4. **Where:** Affected systems/applications
5. **Impact:** Known or potential damage
6. **Evidence:** Logs, screenshots, error messages

**Additional Helpful Information:**
- Steps leading to discovery
- Actions already taken
- Suspected cause
- Affected users or data
- Similar past incidents

#### Reporter Responsibilities

**Do:**
- Report promptly
- Preserve evidence if safe to do so
- Follow instructions from security team
- Maintain confidentiality of incident details
- Cooperate with investigation

**Don't:**
- Attempt to investigate on your own (unless security team)
- Delete or modify evidence
- Discuss incident publicly or on social media
- Panic or assume worst case

#### Response Process

**Upon Receiving Report:**

1. **Acknowledge Receipt**
   - Confirm report received within 1 hour
   - Provide ticket/case number
   - Set expectations for next steps

2. **Initial Triage**
   - Assess severity and urgency
   - Classify as incident or event
   - Assign to appropriate team member

3. **Investigation**
   - Gather additional information
   - Analyze evidence
   - Determine root cause

4. **Response**
   - Contain and mitigate
   - Remediate root cause
   - Communicate with affected parties

5. **Follow-up**
   - Update reporter on outcome
   - Document lessons learned
   - Implement preventive measures

#### Feedback to Reporter

- Confirmation of report received
- Regular updates on investigation status
- Final outcome notification
- Recognition for helpful reports

#### Awareness and Training

**All Personnel Trained On:**
- What to report and why
- How and when to report
- Reporting channels available
- Importance of timely reporting
- Protection from retaliation

**Training Methods:**
- Security awareness training (annual)
- Incident reporting guidelines in onboarding
- Periodic reminders and updates
- Examples and case studies

#### Metrics and Monitoring

**Track:**
- Number of reports received
- Report response times
- False positive rate
- Incident detection via reporting vs. monitoring
- Time from event to report

**Goal:**
- Increase employee reporting
- Reduce time to detect incidents
- Improve report quality
- Decrease false positives over time

**Responsibilities:**
- **All Personnel:** Report security events
- **CISO/Security Team:** Receive and triage reports
- **Incident Response Team:** Investigate and respond
- **Management:** Support reporting culture

**Evidence:** [SECURITY.md](../../SECURITY.md), [procedures/Incident-Management.md](../procedures/Incident-Management.md), incident reporting logs

---

## 7. Summary

### Overall Implementation Status

| Control | Status | Priority |
|---------|--------|----------|
| A.6.1 Screening | 🔄 Partial | High |
| A.6.2 Terms and Conditions | 🔄 Partial | High |
| A.6.3 Security Training | 🔄 Partial | High |
| A.6.4 Disciplinary Process | ✅ Implemented | Medium |
| A.6.5 Post-Termination | 🔄 Partial | Medium |
| A.6.6 NDAs | 🔄 Partial | High |
| A.6.7 Remote Working | ✅ Implemented | Medium |
| A.6.8 Event Reporting | ✅ Implemented | Low |

### Implementation Summary
- **Implemented:** 3/8 (37.5%)
- **Partial:** 5/8 (62.5%)
- **Planned:** 0/8 (0%)

### Key Strengths
- Strong remote working security controls
- Well-defined incident reporting procedures
- Clear disciplinary process for violations

### Areas for Improvement
1. **Formal security awareness training program** (A.6.3)
   - Develop comprehensive training materials
   - Implement training tracking system
   - Schedule regular refresher training

2. **Background screening procedures** (A.6.1)
   - Formalize screening requirements per role
   - Implement screening tracking
   - Document verification processes

3. **Confidentiality agreements** (A.6.6)
   - Develop standard NDA templates
   - Implement NDA tracking system
   - Review existing agreements

### Priority Actions
1. Complete security training program development (Q1 2026)
2. Formalize background screening procedures (Q1 2026)
3. Implement NDA management system (Q2 2026)
4. Enhance employment contract security clauses (Q2 2026)
5. Develop comprehensive offboarding procedures (Q2 2026)

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-05 | GitHub Copilot | Initial people controls documentation |

---

**Next Review:** 2026-04-05 (Quarterly)
