# ISO 27001 Human Resources Security Procedures

## Controls A.5.11, A.6.1, A.6.2, A.6.5 - HR Security Management

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-06  
**Document Owner:** Chief Information Security Officer (CISO)

---

## 1. Purpose

This document establishes comprehensive human resources security procedures covering:
- **A.5.11:** Return of Assets
- **A.6.1:** Screening
- **A.6.2:** Terms and Conditions of Employment
- **A.6.5:** Responsibilities After Termination or Change of Employment

---

## 2. Control A.6.1 - Screening

### 2.1 Background Screening Requirements

**Objective:** Verify trustworthiness and suitability of all personnel with access to NV oOS systems and information.

#### 2.1.1 Screening Levels by Role

**Level 1: Basic Screening** (All Contributors)
- Identity verification
- Professional reference checks (2 minimum)
- LinkedIn/GitHub profile verification
- Public record check (where legally permitted)

**Level 2: Standard Screening** (Core Team Members)
- Level 1 requirements +
- Employment history verification (5 years)
- Educational credential verification
- Credit check (where legally permitted and relevant)

**Level 3: Enhanced Screening** (Security-Sensitive Roles)
- Level 2 requirements +
- Criminal background check (where legally permitted)
- Enhanced reference checks (3 minimum)
- Security clearance (if applicable)

#### 2.1.2 Role-Based Screening Matrix

| Role | Screening Level | Justification |
|------|----------------|---------------|
| CISO / Security Lead | Level 3 | Full system access, security responsibility |
| Core Developer | Level 2 | Code commit access, sensitive data access |
| Plugin Administrator | Level 2 | WordPress admin access, user data access |
| Contributor | Level 1 | Limited access, supervised contributions |
| External Auditor | Level 2 | Access to security documentation |
| Contractor | Level 2 | Project-based access to systems |

#### 2.1.3 Screening Procedure

**Step 1: Initial Application**
- Candidate completes security questionnaire
- Candidate provides consent for background check
- Candidate signs confidentiality agreement

**Step 2: Verification**
- HR/Security team initiates background check
- Verify identity documents
- Contact references
- Review professional history

**Step 3: Evaluation**
- Review screening results
- Assess risk indicators
- Document findings
- Make hiring recommendation

**Step 4: Decision**
- Hiring manager reviews results
- CISO approval for security-sensitive roles
- Document decision rationale
- Store records securely (7 years minimum)

**Step 5: Onboarding**
- Complete security training (before access granted)
- Sign acceptable use policy
- Acknowledge security responsibilities
- Receive access credentials

#### 2.1.4 Screening Documentation

**Required Documents:**
- Background Screening Authorization Form
- Identity Verification Documents
- Professional Reference Contact Forms
- Screening Results Summary
- Hiring Decision Documentation
- Security Training Completion Certificate

#### 2.1.5 Re-Screening Requirements

**Periodic Re-Screening:**
- Every 3 years for Level 2 and Level 3 roles
- Upon role change to higher sensitivity
- After extended leave (> 6 months)
- If security concerns arise

---

## 3. Control A.6.2 - Terms and Conditions of Employment

### 3.1 Information Security Clauses in Employment Agreements

**Objective:** Ensure all personnel understand and agree to information security responsibilities.

#### 3.1.1 Mandatory Security Clauses

All employment agreements/contracts MUST include:

**1. Confidentiality Obligations**
```
The Employee/Contractor agrees to:
- Maintain confidentiality of all proprietary information
- Not disclose sensitive information to unauthorized parties
- Protect trade secrets and intellectual property
- Return all confidential information upon termination
```

**2. Acceptable Use of Information Systems**
```
The Employee/Contractor agrees to:
- Use systems only for authorized business purposes
- Not install unauthorized software
- Not access systems without proper authorization
- Comply with all security policies and procedures
```

**3. Data Protection Responsibilities**
```
The Employee/Contractor agrees to:
- Handle personal data in accordance with GDPR/CCPA
- Report data breaches immediately
- Not remove sensitive data without authorization
- Protect data according to classification levels
```

**4. Intellectual Property Rights**
```
The Employee/Contractor acknowledges that:
- All work product belongs to the organization
- No personal use of organizational IP without consent
- Obligation to assign all rights to the organization
- No retention of copies after termination
```

**5. Security Incident Reporting**
```
The Employee/Contractor agrees to:
- Report security incidents immediately
- Cooperate with security investigations
- Not interfere with incident response activities
- Follow incident response procedures
```

**6. Monitoring and Audit Rights**
```
The Employee/Contractor acknowledges that:
- Systems may be monitored for security purposes
- Audit logs may be reviewed
- No expectation of privacy on company systems
- Consent to security audits
```

**7. Consequences of Non-Compliance**
```
The Employee/Contractor understands that:
- Security violations may result in disciplinary action
- Serious violations may lead to termination
- Legal action may be taken for breaches
- Financial liability for damages caused
```

#### 3.1.2 Non-Disclosure Agreement (NDA)

**Scope of Confidential Information:**
- Source code and algorithms
- API keys and credentials
- Customer/user data
- Security procedures and controls
- Business strategies and roadmaps
- Vulnerability information

**Duration:**
- During employment/contract period
- 3 years after termination (for trade secrets)
- Perpetual for certain proprietary information

**Exceptions:**
- Information already in public domain
- Information independently developed
- Information received from third party without restriction
- Information required by law to disclose

#### 3.1.3 Security Responsibilities by Role

**All Employees/Contractors:**
- Complete annual security awareness training
- Follow password policy
- Lock workstations when unattended
- Report security incidents
- Comply with acceptable use policy

**Developers:**
- Follow secure coding standards
- Perform code security reviews
- No hardcoded credentials in code
- Use approved development tools only
- Test security controls before deployment

**Administrators:**
- Follow principle of least privilege
- Log all administrative actions
- Perform regular security reviews
- Maintain system hardening
- Document configuration changes

**Security Team:**
- Monitor security alerts
- Respond to security incidents
- Conduct security audits
- Maintain security documentation
- Report to CISO

#### 3.1.4 Acknowledgment and Acceptance

**Required Signatures:**
- Employee/Contractor signature
- Manager signature
- HR representative signature
- Date of acknowledgment

**Distribution:**
- Original to HR file
- Copy to employee
- Copy to immediate supervisor
- Copy to Security team

---

## 4. Control A.5.11 - Return of Assets

### 4.1 Asset Return Procedure

**Objective:** Ensure all organizational assets are returned upon termination or role change.

#### 4.1.1 Asset Categories

**Physical Assets:**
- Laptops and computers
- Mobile devices (phones, tablets)
- Access cards and keys
- Security tokens
- Storage media (USB drives, external drives)
- Documentation (printed materials)

**Digital Assets:**
- Access credentials (username/password)
- API keys and tokens
- Encryption keys
- Source code repositories access
- Cloud service accounts
- VPN access
- Email accounts

**Intellectual Property:**
- Code repositories (local copies)
- Documentation files
- Customer data copies
- Proprietary information
- Trade secrets
- Project files

#### 4.1.2 Asset Return Checklist

**For Employee Termination:**

**Day of Termination:**
- [ ] Collect all physical assets
  - [ ] Laptop/computer
  - [ ] Mobile devices
  - [ ] Access cards/keys
  - [ ] Security tokens
  - [ ] USB drives and media
  - [ ] Printed documentation

- [ ] Revoke all digital access
  - [ ] Disable user accounts (WordPress, GitHub, etc.)
  - [ ] Revoke API keys
  - [ ] Reset passwords for shared accounts
  - [ ] Remove from distribution lists
  - [ ] Deactivate VPN access
  - [ ] Disable email forwarding

- [ ] Verify data deletion
  - [ ] Delete local code repositories
  - [ ] Remove customer data copies
  - [ ] Clear browser passwords/tokens
  - [ ] Wipe mobile devices
  - [ ] Clear cloud storage access

**Within 24 Hours:**
- [ ] Change all shared passwords
- [ ] Review access logs for unusual activity
- [ ] Verify no backdoors created
- [ ] Remove from emergency contact lists
- [ ] Update documentation (remove as owner/author)

**Within 1 Week:**
- [ ] Conduct exit interview with security focus
- [ ] Obtain signed asset return confirmation
- [ ] Document any missing assets
- [ ] Bill for unreturned assets (if applicable)
- [ ] Update asset inventory

#### 4.1.3 Asset Return Form

```
Asset Return Confirmation

Employee Name: _______________________
Employee ID: _________________________
Termination Date: ____________________
Last Working Day: ____________________

Physical Assets Returned:
[ ] Laptop (Serial: ______________)
[ ] Mobile Device (Serial: ______________)
[ ] Access Cards (ID: ______________)
[ ] Other: _________________________

Digital Access Revoked:
[ ] WordPress Admin Access
[ ] GitHub Repository Access
[ ] API Keys Deactivated
[ ] Email Account Disabled
[ ] VPN Access Removed
[ ] Other: _________________________

Data Confirmation:
[ ] All company data deleted from personal devices
[ ] No copies of customer/user data retained
[ ] All cloud storage access removed
[ ] Intellectual property returned

Employee Signature: _________________ Date: ________
Manager Signature: __________________ Date: ________
HR Signature: ______________________ Date: ________
IT/Security Signature: ______________ Date: ________
```

#### 4.1.4 Automated Access Revocation

**WordPress Access:**
```php
// Automated user deactivation
function wp_mcp_ai_deactivate_user( $user_id ) {
    // Set user role to 'none' (no capabilities)
    wp_update_user( array(
        'ID' => $user_id,
        'role' => '',
    ) );
    
    // Revoke all sessions
    $sessions = WP_Session_Tokens::get_instance( $user_id );
    $sessions->destroy_all();
    
    // Revoke API credentials
    wp_mcp_ai_revoke_user_credentials( $user_id );
    
    // Log the action
    wp_mcp_ai_log_security_event( 'user_deactivated', array(
        'user_id' => $user_id,
        'timestamp' => current_time( 'mysql' ),
    ) );
}
```

**GitHub Access:**
- Remove from organization/teams
- Revoke personal access tokens
- Remove SSH keys
- Review commit history for suspicious activity

**Third-Party Services:**
- Revoke OpenAI API keys
- Remove Google/Gemini access
- Disable Ollama integrations
- Remove from Auth0 (if applicable)

---

## 5. Control A.6.5 - Responsibilities After Termination

### 5.1 Post-Termination Obligations

**Objective:** Define continuing responsibilities after employment/contract ends.

#### 5.1.1 Continuing Confidentiality Obligations

**Duration:** 3 years minimum, perpetual for trade secrets

**Scope:**
- Cannot disclose confidential information
- Cannot use proprietary information for personal gain
- Cannot compete using insider knowledge
- Must return all confidential materials

**Enforcement:**
- Legal action for breaches
- Financial damages
- Injunctive relief
- Criminal prosecution (if applicable)

#### 5.1.2 Non-Compete and Non-Solicitation

**Non-Compete (where legally enforceable):**
- Duration: 12 months
- Geographic scope: [Defined]
- Industry scope: WordPress plugin development
- Reasonable restrictions only

**Non-Solicitation:**
- Cannot solicit customers (24 months)
- Cannot recruit employees (12 months)
- Cannot interfere with business relationships
- Cannot use customer lists

#### 5.1.3 Knowledge Transfer Requirements

**Before Last Day:**
- Document all current projects
- Transfer ownership of documentation
- Share passwords for shared accounts (securely)
- Brief successor on ongoing work
- Complete handover checklist

**Handover Checklist:**
```
Knowledge Transfer Checklist

Project Documentation:
[ ] All projects documented
[ ] Code repositories up to date
[ ] Configuration documented
[ ] Passwords transferred securely
[ ] Contact information provided

Ongoing Work:
[ ] Current status documented
[ ] Blockers identified
[ ] Next steps outlined
[ ] Dependencies noted
[ ] Timeline provided

Security Information:
[ ] Known security issues documented
[ ] Access credentials transferred
[ ] Security procedures reviewed
[ ] Emergency contacts updated
[ ] Incident response info current

Successor Briefing:
[ ] One-on-one handover meeting held
[ ] Questions answered
[ ] Documentation reviewed
[ ] Critical items highlighted
[ ] Follow-up contact info provided

Completed By: _________________ Date: ________
Reviewed By: __________________ Date: ________
```

#### 5.1.4 Exit Interview - Security Focus

**Security Questions:**
1. Do you have any security concerns about current systems?
2. Are you aware of any unreported security incidents?
3. Have you created any backdoors or undocumented access methods?
4. Do you have copies of customer/user data?
5. Have you shared credentials with anyone?
6. Are there any security vulnerabilities you're aware of?
7. Have you disclosed confidential information to anyone?

**Documentation:**
- Record all responses
- Follow up on any security concerns
- Escalate critical issues immediately
- Update security procedures if needed

#### 5.1.5 Post-Termination Monitoring

**First 30 Days:**
- Monitor system logs for unusual access attempts
- Review code commits for malicious changes
- Check for data exfiltration attempts
- Monitor external communications

**First 90 Days:**
- Verify no ongoing access to systems
- Check for use of organizational IP
- Monitor for competitive activities (if NDA exists)
- Review for potential security incidents

#### 5.1.6 Legal Remedies for Breaches

**Available Remedies:**
- Injunctive relief (court order to stop)
- Monetary damages
- Return of assets/information
- Legal fees reimbursement
- Criminal prosecution (for serious breaches)

**Breach Response Procedure:**
1. Document the breach
2. Consult legal counsel
3. Send cease and desist letter
4. File lawsuit if necessary
5. Seek criminal prosecution if warranted

---

## 6. HR Security Checklist Summary

### 6.1 Pre-Employment Phase
- [ ] Background screening completed
- [ ] References checked
- [ ] Security questionnaire completed
- [ ] Confidentiality agreement signed

### 6.2 Onboarding Phase
- [ ] Employment agreement signed (with security clauses)
- [ ] NDA executed
- [ ] Security training completed
- [ ] Acceptable use policy acknowledged
- [ ] Role-specific security responsibilities reviewed

### 6.3 During Employment
- [ ] Annual security training completed
- [ ] Periodic re-screening (every 3 years for sensitive roles)
- [ ] Security policy acknowledgment (annually)
- [ ] Asset inventory updated (when assets issued/returned)

### 6.4 Termination Phase
- [ ] Asset return checklist completed
- [ ] All access revoked (same day)
- [ ] Knowledge transfer completed
- [ ] Exit interview conducted
- [ ] Post-termination obligations reviewed
- [ ] Asset return form signed

### 6.5 Post-Termination Phase
- [ ] Monitor for security incidents (30 days)
- [ ] Verify no ongoing system access
- [ ] Review for NDA compliance (ongoing)
- [ ] Update security documentation

---

## 7. Forms and Templates

### 7.1 Available Forms
1. Background Screening Authorization
2. Confidentiality Agreement
3. Non-Disclosure Agreement
4. Acceptable Use Policy Acknowledgment
5. Security Responsibilities by Role
6. Asset Return Form
7. Knowledge Transfer Checklist
8. Exit Interview Form
9. Post-Termination Obligations Notice

### 7.2 Form Location
All forms are maintained in:
- `docs/compliance/iso27001/forms/` (digital templates)
- HR secure file system (completed forms)

---

## 8. Compliance and Audit

### 8.1 Audit Points
- Background screening completion rate
- Employment agreement security clause review
- Asset return completion rate
- Access revocation timeliness
- Knowledge transfer completion
- Exit interview completion rate
- Post-termination monitoring compliance

### 8.2 Metrics
- Time to revoke access (target: same day)
- Asset return rate (target: 100%)
- Security training completion (target: 100%)
- Exit interview completion (target: 100%)
- Post-termination incidents (target: 0)

---

## 9. References

- ISO/IEC 27001:2022 Controls A.5.11, A.6.1, A.6.2, A.6.5
- ISMS Policy: [ISMS-Policy.md](./ISMS-Policy.md)
- Acceptable Use Policy: [Acceptable-Use-Policy.md](./Acceptable-Use-Policy.md)
- Asset Inventory: Asset Inventory System (WP Admin)

---

## 10. Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | CISO | Initial comprehensive HR security procedures |

**Next Review:** 2026-06-06  
**Review Frequency:** Semi-annually

---

**Approval:**

CISO: _________________ Date: ________
HR Manager: ___________ Date: ________
Legal Counsel: ________ Date: ________
