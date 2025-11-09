# Privacy Policy Guide for WP oOS

**Last Updated:** November 9, 2025  
**Plugin Version:** 1.0.0  
**Audience:** Site Administrators, Legal/Compliance Teams

## Overview

This guide helps WordPress site administrators understand and communicate the data collection, retention, and processing practices of WP Open Operator System (WP oOS) to comply with privacy regulations such as GDPR, CCPA, and other data protection laws.

## Data Collection & Storage

### 1. Chat Conversations

WP oOS handles chat conversations through a two-tier storage system:

#### Client-Side Storage (Browser localStorage)

**What is stored:**
- User messages and AI assistant responses
- Session identifiers
- Timestamp of conversation
- Assistant ID

**Location:** User's web browser (localStorage)

**Retention Period:** 24 hours (automatically expires)

**User Control:** 
- Users can clear browser data at any time through browser settings
- Private/incognito browsing prevents localStorage persistence
- Data is isolated per-domain and never transmitted to other users

**Privacy Implications:**
- Data remains on user's device only
- Not accessible to site administrators
- Not transmitted to external services
- Automatically deleted after 24 hours

#### Server-Side Storage (Optional - JetEngine CCT)

**What is stored:**
- Complete chat transcripts (user messages + AI responses)
- User ID (WordPress user)
- Assistant ID
- Session key
- Timestamp and duration metrics
- Provider and model information

**Location:** WordPress database (JetEngine Custom Content Type table)

**Retention Period:** Indefinite (until manually deleted by administrators)

**User Control:**
- Available only when JetEngine plugin is installed and enabled
- Administrators can enable/disable per-assistant via `save_transcript` parameter
- Users can request deletion of their transcripts (requires manual administrator action)

**Privacy Implications:**
- Contains personally identifiable information (PII) if users share personal details in chat
- Stored permanently in database unless manually deleted
- Accessible to site administrators
- May be subject to data subject access requests (DSAR)

**How to Enable/Disable:**

Site administrators control server-side storage through:

1. **Global Setting:** `Settings → WP oOS → JetEngine Integration`
   - Enable/disable JetEngine CCT storage globally

2. **Per-Request Control:** Include `save_transcript` parameter in chat requests
   ```json
   {
     "assistant_id": 123,
     "messages": [...],
     "save_transcript": true  // or false
   }
   ```

3. **Per-Assistant Default:** Set default behavior in assistant settings (if available)

### 2. External AI Provider Data Transmission

WP oOS sends chat messages to external AI providers for processing. The data egress depends on which provider is configured:

#### Cloud Providers (Data Leaves Your Server)

**OpenAI (GPT Models)**
- **Data Sent:** User messages, conversation context, system prompts
- **Location:** OpenAI servers (United States)
- **Retention:** Per [OpenAI's data usage policy](https://openai.com/policies/usage-policies)
- **Default:** 30 days for abuse monitoring, then deleted (as of Nov 2024)
- **Note:** OpenAI does not use API data for model training by default

**Google Gemini**
- **Data Sent:** User messages, conversation context, system prompts
- **Location:** Google servers (varies by region)
- **Retention:** Per [Google's AI data usage policy](https://ai.google/responsibility/principles/)
- **Note:** Review Google's current terms for data retention and usage

**Anthropic Claude** (if configured)
- **Data Sent:** User messages, conversation context, system prompts
- **Location:** Anthropic servers (United States)
- **Retention:** Per [Anthropic's privacy policy](https://www.anthropic.com/privacy)

#### Local Providers (Data Stays On Your Server)

**Ollama**
- **Data Processing:** Entirely local on your server/machine
- **No External Transmission:** Data never leaves your infrastructure
- **Recommended For:** Privacy-sensitive applications, GDPR compliance, data residency requirements

**LM Studio**
- **Data Processing:** Entirely local on your server/machine
- **No External Transmission:** Data never leaves your infrastructure
- **Recommended For:** Development, testing, privacy-focused deployments

#### Mixed Deployments

WP oOS supports mesh routing, allowing different assistants to use different providers:

- **Example:** Public FAQs use OpenAI, internal HR chatbot uses Ollama
- **Benefit:** Balance cost/performance with privacy requirements
- **Configuration:** Per-assistant provider selection in WordPress admin

### 3. API Keys & Credentials

**What is stored:**
- OpenAI API keys
- Google Gemini API keys
- Anthropic API keys (if used)
- Ollama endpoint URLs
- Assistant credentials (for REST API access)

**Location:** WordPress database (wp_options table, wp_postmeta table)

**Security:**
- Assistant credentials are hashed before storage
- API keys stored in plaintext in database (should use environment variables for production)
- Protected by WordPress database credentials

**Best Practice:**
Store API keys in environment variables instead of database:

```php
// wp-config.php
define( 'WP_MCP_AI_OPENAI_API_KEY', getenv('OPENAI_API_KEY') );
define( 'WP_MCP_AI_GEMINI_API_KEY', getenv('GEMINI_API_KEY') );
```

### 4. Usage Tracking & Logging

**What is tracked:**
- API request counts
- Token usage (input/output)
- Error logs (if logging enabled)
- Rate limit violations

**Location:** WordPress database (wp_options table)

**Retention Period:** Configurable (default: 30 days)

**Purpose:** Cost monitoring, debugging, abuse prevention

**User Data:** Does not include message content, only metadata

## Privacy Rights & User Controls

### Right to Access (GDPR Article 15)

**What users can request:**
- Access to their stored chat transcripts
- Information about which AI provider processed their data
- Timestamps of conversations

**How to provide:**
1. Query JetEngine CCT for user's chat transcripts
2. Export data in machine-readable format (JSON/CSV)
3. Include metadata: timestamps, assistant used, provider

**Implementation:**
```php
// Example: Retrieve user's chat transcripts
$user_id = get_current_user_id();
$transcripts = jet_engine()->cct->get_cct_items( 'chat_transcripts', array(
    'user_id' => $user_id,
    'number'  => -1,
) );
```

### Right to Erasure / Right to be Forgotten (GDPR Article 17)

**What users can request:**
- Deletion of all stored chat transcripts
- Removal of their data from usage logs

**How to implement:**

1. **Delete JetEngine Chat Transcripts:**
   ```php
   // Delete all transcripts for a user
   $user_id = absint( $_REQUEST['user_id'] );
   
   // Verify request is legitimate and user has authority
   if ( ! current_user_can( 'delete_users' ) && $user_id !== get_current_user_id() ) {
       wp_die( 'Unauthorized' );
   }
   
   // Delete transcripts
   jet_engine()->cct->delete_cct_items_by_meta( 'chat_transcripts', 'user_id', $user_id );
   ```

2. **Clear localStorage (User-Initiated):**
   Users can clear their browser data at any time:
   - Chrome: Settings → Privacy → Clear browsing data
   - Firefox: Settings → Privacy → Clear Data
   - Safari: Settings → Clear History and Website Data

3. **Instruct External Providers:**
   - For OpenAI: Users can request deletion via [OpenAI Privacy Portal](https://privacy.openai.com/)
   - For Google: Users can request deletion via [Google Account](https://myaccount.google.com/)
   - Document this process in your privacy policy

**WordPress Privacy Tools Integration:**

WP oOS should integrate with WordPress's built-in privacy tools:

```php
// Register personal data exporter
add_filter( 'wp_privacy_personal_data_exporters', 'wp_mcp_ai_register_exporters' );
function wp_mcp_ai_register_exporters( $exporters ) {
    $exporters['wp-mcp-ai-chat-transcripts'] = array(
        'exporter_friendly_name' => __( 'Chat Transcripts' ),
        'callback'               => 'wp_mcp_ai_export_chat_data',
    );
    return $exporters;
}

// Register personal data eraser
add_filter( 'wp_privacy_personal_data_erasers', 'wp_mcp_ai_register_erasers' );
function wp_mcp_ai_register_erasers( $erasers ) {
    $erasers['wp-mcp-ai-chat-transcripts'] = array(
        'eraser_friendly_name' => __( 'Chat Transcripts' ),
        'callback'             => 'wp_mcp_ai_erase_chat_data',
    );
    return $erasers;
}
```

### Right to Data Portability (GDPR Article 20)

**What users can request:**
- Export of chat transcripts in structured format

**Recommended Format:** JSON or CSV

**Example Export Structure:**
```json
{
  "user_id": 123,
  "export_date": "2025-11-09T12:00:00Z",
  "transcripts": [
    {
      "session_key": "abc123",
      "assistant_id": 456,
      "assistant_name": "Customer Support Bot",
      "timestamp": "2025-11-08T15:30:00Z",
      "provider": "openai",
      "model": "gpt-4",
      "messages": [
        {
          "role": "user",
          "content": "How do I reset my password?",
          "timestamp": "2025-11-08T15:30:00Z"
        },
        {
          "role": "assistant",
          "content": "To reset your password...",
          "timestamp": "2025-11-08T15:30:05Z"
        }
      ]
    }
  ]
}
```

### Right to Restriction of Processing (GDPR Article 18)

**What users can request:**
- Temporarily pause chat transcript recording

**How to implement:**
- Add user meta flag: `_wp_mcp_ai_restrict_processing`
- Check flag before recording transcripts
- Honor flag in chat endpoint

```php
// Before recording transcript
$user_id = get_current_user_id();
if ( get_user_meta( $user_id, '_wp_mcp_ai_restrict_processing', true ) ) {
    // Skip transcript recording
    return;
}
```

## Consent Mechanisms

### Explicit Consent for Server-Side Storage

WP oOS should obtain explicit user consent before storing chat transcripts server-side.

#### Recommended Implementation:

1. **First-Time Chat Dialog:**
   - Display consent dialog before first message
   - Explain what data is stored and why
   - Provide accept/decline options
   - Store consent choice in user meta

2. **Consent Dialog UX Copy:**

   ```
   Privacy Notice
   
   Your conversations with this AI assistant may be saved to improve 
   your experience. Saved conversations include your messages and the 
   assistant's responses.
   
   □ Save my conversations for future reference
   
   By saving conversations:
   - Your chat history will be stored on our server
   - You can review past conversations anytime
   - Site administrators may access stored conversations
   - Your data will be processed by [OpenAI/Google/Local AI]
   
   You can change this setting or delete your conversations at any time 
   in your Account Settings.
   
   [Learn More About Privacy] [Accept] [Decline]
   ```

3. **Per-Request Consent (Alternative):**
   - Display checkbox before each chat session
   - "□ Save this conversation for later"
   - Default to unchecked (opt-in model)

4. **Account Settings Page:**
   - Allow users to view consent status
   - Enable/disable transcript recording
   - View and delete stored transcripts
   - Export data

#### Implementation in Chat UI:

```javascript
// Check for existing consent
var userConsent = localStorage.getItem('wp_mcp_ai_consent_' + userId);

if (!userConsent) {
    // Show consent dialog
    showConsentDialog(function(accepted) {
        localStorage.setItem('wp_mcp_ai_consent_' + userId, accepted ? 'granted' : 'denied');
        if (accepted) {
            // Proceed with save_transcript=true
        }
    });
}
```

### Consent for External Data Processing

When using cloud AI providers, inform users that their messages will be sent to external services:

**Recommended Notice:**

```
Your messages will be processed by [OpenAI/Google/Anthropic] to 
generate responses. Their privacy policy applies to this processing: 
[Link to Provider Privacy Policy]
```

**Location for Notice:**
- Below chat input field
- In assistant description/help text
- On first message submission

## Privacy Policy Template

### Sample Privacy Policy Section for WP oOS

```markdown
### AI Chat Assistants

Our website uses AI-powered chat assistants to provide automated support 
and information. When you interact with our AI assistants, the following 
data practices apply:

#### Data Collection

We collect and process:
- Your messages sent to the AI assistant
- AI-generated responses
- Conversation timestamps
- Your WordPress user account (if logged in)

#### Data Storage

**Browser Storage (Temporary)**
- Conversations are temporarily stored in your browser for 24 hours
- This data is stored locally on your device only
- You can clear this data through your browser settings

**Server Storage (Optional)**
- With your consent, conversations may be saved to our server
- Saved conversations are linked to your user account
- You can view, export, or delete saved conversations in your Account Settings

#### Third-Party Processing

Depending on the AI assistant, your messages may be processed by:
- **OpenAI** (United States) - [Privacy Policy](https://openai.com/policies/privacy-policy)
- **Google** (various regions) - [Privacy Policy](https://policies.google.com/privacy)
- **Local Processing** - Some assistants process data entirely on our server

#### Your Rights

You have the right to:
- Access your stored conversations
- Delete your conversations at any time
- Export your data in a portable format
- Withdraw consent for server-side storage
- Request information about third-party processing

To exercise these rights, contact us at [contact email] or use the 
Privacy Tools in your Account Settings.

#### Data Retention

- Browser storage: 24 hours (automatic expiration)
- Server storage: Until you delete or request deletion
- Usage logs: 30 days

#### Security

We protect your data using:
- Encrypted HTTPS transmission
- WordPress security best practices
- Access controls and authentication
- Regular security updates

For more information, see our complete Privacy Policy or contact [contact email].
```

## Recommendations for Compliance

### GDPR Compliance Checklist

- [ ] **Lawful Basis:** Determine lawful basis for processing (consent, legitimate interest, contract)
- [ ] **Privacy Policy:** Update privacy policy to include WP oOS data practices
- [ ] **Consent Mechanism:** Implement explicit consent for server-side storage
- [ ] **Data Minimization:** Only collect necessary data, disable transcript recording when not needed
- [ ] **Purpose Limitation:** Use collected data only for stated purposes
- [ ] **Data Subject Rights:** Implement access, deletion, portability, restriction workflows
- [ ] **Data Retention:** Define and enforce retention periods
- [ ] **Third-Party Agreements:** Review DPA (Data Processing Agreements) with AI providers
- [ ] **Data Protection Impact Assessment (DPIA):** Conduct if processing high-risk data
- [ ] **Security Measures:** Implement appropriate technical and organizational measures

### CCPA Compliance Checklist

- [ ] **Privacy Notice:** Disclose categories of data collected and purposes
- [ ] **Right to Know:** Provide mechanism for users to request collected data
- [ ] **Right to Delete:** Implement deletion workflow
- [ ] **Right to Opt-Out:** Allow users to opt-out of data "sale" (if applicable)
- [ ] **Non-Discrimination:** Don't discriminate against users who exercise privacy rights
- [ ] **Authorized Agent:** Accept requests from authorized agents

### Additional Recommendations

1. **Use Local AI Providers for Sensitive Data:**
   - Deploy Ollama for conversations containing PII, health data, or financial information
   - Reserve cloud providers (OpenAI, Gemini) for non-sensitive use cases

2. **Data Residency:**
   - If operating in EU, consider using EU-based AI providers or Ollama
   - Document data transfer mechanisms (Standard Contractual Clauses, etc.)

3. **Anonymization:**
   - Consider anonymizing chat transcripts before long-term storage
   - Remove user IDs, replace with pseudonymous identifiers

4. **Regular Audits:**
   - Audit stored transcripts quarterly
   - Delete transcripts older than [defined retention period]
   - Review third-party processor compliance annually

5. **Staff Training:**
   - Train administrators on privacy obligations
   - Establish procedures for handling data subject requests
   - Document all privacy-related processes

## Technical Implementation Examples

### Opt-Out of Transcript Recording

Add user meta option and check in REST endpoint:

```php
// In user profile settings
add_action( 'show_user_profile', 'wp_mcp_ai_privacy_settings' );
add_action( 'edit_user_profile', 'wp_mcp_ai_privacy_settings' );

function wp_mcp_ai_privacy_settings( $user ) {
    $opt_out = get_user_meta( $user->ID, '_wp_mcp_ai_opt_out_transcripts', true );
    ?>
    <h2><?php _e( 'AI Chat Privacy Settings', 'wp-mcp-ai' ); ?></h2>
    <table class="form-table">
        <tr>
            <th><label><?php _e( 'Chat Transcript Recording', 'wp-mcp-ai' ); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" name="wp_mcp_ai_opt_out_transcripts" value="1" <?php checked( $opt_out, '1' ); ?> />
                    <?php _e( 'Do not save my chat conversations on the server', 'wp-mcp-ai' ); ?>
                </label>
                <p class="description">
                    <?php _e( 'When enabled, your conversations will only be stored temporarily in your browser and will not be saved to our server.', 'wp-mcp-ai' ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}

// Save user preference
add_action( 'personal_options_update', 'wp_mcp_ai_save_privacy_settings' );
add_action( 'edit_user_profile_update', 'wp_mcp_ai_save_privacy_settings' );

function wp_mcp_ai_save_privacy_settings( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    
    $opt_out = isset( $_POST['wp_mcp_ai_opt_out_transcripts'] ) ? '1' : '0';
    update_user_meta( $user_id, '_wp_mcp_ai_opt_out_transcripts', $opt_out );
}

// Check in chat endpoint before recording
function wp_mcp_ai_should_record_transcript( $user_id ) {
    $opt_out = get_user_meta( $user_id, '_wp_mcp_ai_opt_out_transcripts', true );
    return '1' !== $opt_out;
}
```

### Bulk Delete Old Transcripts

Create WP-CLI command or scheduled task:

```php
// Delete transcripts older than 90 days
function wp_mcp_ai_cleanup_old_transcripts() {
    if ( ! function_exists( 'jet_engine' ) ) {
        return;
    }
    
    $cutoff_date = strtotime( '-90 days' );
    
    // Query old transcripts
    $old_transcripts = jet_engine()->cct->get_cct_items( 'chat_transcripts', array(
        'meta_query' => array(
            array(
                'key'     => 'timestamp',
                'value'   => $cutoff_date,
                'compare' => '<',
                'type'    => 'NUMERIC',
            ),
        ),
        'number' => -1,
    ) );
    
    // Delete each transcript
    foreach ( $old_transcripts as $transcript ) {
        jet_engine()->cct->delete_cct_item( 'chat_transcripts', $transcript->_ID );
    }
    
    WP_MCP_AI_Logger::log_activity( 
        sprintf( 'Deleted %d old chat transcripts', count( $old_transcripts ) )
    );
}

// Schedule daily cleanup
add_action( 'wp_mcp_ai_daily_cleanup', 'wp_mcp_ai_cleanup_old_transcripts' );
```

## Frequently Asked Questions

### Q: Is chat data encrypted?

**A:** Chat data is transmitted over HTTPS (encrypted in transit). Data at rest in the WordPress database is not encrypted by default, but you can enable database encryption through hosting provider features or WordPress plugins.

### Q: Can users see other users' conversations?

**A:** No. Chat transcripts are isolated per-user. Only WordPress administrators with appropriate capabilities can access stored transcripts.

### Q: What happens if I switch from OpenAI to Ollama?

**A:** Existing conversations processed by OpenAI remain in OpenAI's systems per their retention policy. Future conversations will be processed locally by Ollama and will not be sent to OpenAI.

### Q: Can I use WP oOS in a HIPAA-compliant environment?

**A:** WP oOS itself does not guarantee HIPAA compliance. You would need to:
1. Use Ollama (local processing only)
2. Disable server-side transcript recording
3. Implement additional security controls (encryption at rest, audit logging, access controls)
4. Execute Business Associate Agreements with all service providers
5. Consult with HIPAA compliance experts

### Q: How do I handle a data breach?

**A:** Follow your organization's incident response plan. For WP oOS specifically:
1. Identify scope: Which transcripts/users affected?
2. Contain: Disable affected assistants, rotate API keys
3. Notify: Inform affected users per GDPR (72 hours) or CCPA requirements
4. Remediate: Patch vulnerability, enhance security measures
5. Document: Log all breach-related activities
6. Report: To supervisory authority if required

## Additional Resources

- [GDPR Official Text](https://gdpr-info.eu/)
- [CCPA Official Text](https://oag.ca.gov/privacy/ccpa)
- [WordPress Privacy Tools](https://wordpress.org/about/privacy/)
- [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)
- [Google AI Privacy Principles](https://ai.google/responsibility/principles/)
- [ICO Data Protection Guidance (UK)](https://ico.org.uk/for-organisations/guide-to-data-protection/)

## Conclusion

Privacy compliance is an ongoing process. This guide provides a foundation, but you should:

1. Consult with legal counsel for your specific jurisdiction
2. Conduct regular privacy audits
3. Update policies as regulations evolve
4. Document all privacy-related procedures
5. Train staff on privacy obligations

**Remember:** When in doubt about privacy requirements, err on the side of user privacy and transparency.

---

**Document Maintained By:** NV Digital Solutions  
**Last Review Date:** November 9, 2025  
**Next Review Date:** February 9, 2026
