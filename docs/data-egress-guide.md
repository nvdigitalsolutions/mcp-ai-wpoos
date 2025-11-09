# Data Egress & AI Provider Routing Guide

**Last Updated:** November 9, 2025  
**Plugin Version:** 1.0.0  
**Audience:** System Administrators, Security Teams, Compliance Officers

## Overview

This guide explains how WP Open Operator System (WP oOS) routes chat messages to different AI providers, the data egress implications of each provider choice, and how to configure privacy-focused deployments.

## Understanding Data Egress

**Data egress** refers to data leaving your server/infrastructure to be processed by external services. Different AI providers have different data egress characteristics:

### Provider Classification

| Provider | Type | Data Egress | Data Location | Privacy Level |
|----------|------|-------------|---------------|---------------|
| **OpenAI** | Cloud | ✅ Yes | United States | Low* |
| **Google Gemini** | Cloud | ✅ Yes | Varies by region | Low* |
| **Anthropic Claude** | Cloud | ✅ Yes | United States | Low* |
| **Ollama** | Local | ❌ No | Your server | High |
| **LM Studio** | Local | ❌ No | Your server/workstation | High |

*Low privacy level means data leaves your control; high privacy level means data stays under your control.

### Data Flow Diagrams

#### Cloud Provider Flow (OpenAI/Gemini/Claude)

```
User Browser → WordPress Server → External AI Provider → WordPress Server → User Browser
              ↓                   ↓
              Your Database       External Servers
                                 (Data Egress Occurs)
```

**Data egress points:**
1. User message leaves your WordPress server
2. Sent to OpenAI/Google/Anthropic servers over HTTPS
3. Processed on external infrastructure
4. Response returns to your server

**Privacy implications:**
- Message content exposed to third-party provider
- Subject to provider's data retention policies
- May be used for abuse monitoring (typically 30 days)
- Potential data residency/sovereignty issues

#### Local Provider Flow (Ollama/LM Studio)

```
User Browser → WordPress Server → Local AI Instance → WordPress Server → User Browser
              ↓                   ↓
              Your Database       Your Server
                                 (No Data Egress)
```

**Data egress points:** None

**Privacy implications:**
- All data remains on your infrastructure
- Full control over data retention
- No third-party access
- Suitable for GDPR, HIPAA, and other strict compliance requirements

## Per-Assistant Provider Configuration

WP oOS allows each assistant to use a different AI provider. This enables flexible, privacy-aware deployments.

### Configuring Assistant Providers

#### Via WordPress Admin

1. **Navigate to:** `Assistants → Edit Assistant`
2. **Locate:** "Model & Provider" metabox
3. **Select Provider:**
   - OpenAI (Cloud - Data egress)
   - Google Gemini (Cloud - Data egress)
   - Anthropic Claude (Cloud - Data egress)
   - Ollama (Local - No data egress)
   - LM Studio (Local - No data egress)
4. **Select Model:** Choose appropriate model for selected provider
5. **Save Assistant**

#### Via Code (Programmatic)

```php
// Set assistant provider
update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'ollama' ); // or 'openai', 'gemini', 'anthropic', 'lm_studio'
update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'llama2' ); // or appropriate model
```

### Provider Visibility in Admin UI

Administrators can see which provider each assistant uses:

**Assistants List View:**
- Add "Provider" column showing: OpenAI, Gemini, Ollama, etc.
- Add visual indicator: 🌐 (cloud) or 🖥️ (local)

**Assistant Edit Screen:**
- Display current provider prominently
- Show data egress warning for cloud providers

## Data Egress Warnings & User Notifications

### Administrator Warnings

When configuring cloud providers, display clear warnings:

#### Example Warning UI

```
┌─────────────────────────────────────────────────────┐
│ ⚠️  Data Egress Notice                              │
│                                                     │
│ You have selected OpenAI as the provider.          │
│                                                     │
│ User messages will be sent to OpenAI servers       │
│ located in the United States for processing.       │
│                                                     │
│ Considerations:                                     │
│ • User data leaves your infrastructure             │
│ • Subject to OpenAI's privacy policy               │
│ • May not comply with strict data residency rules  │
│ • Consider using Ollama for sensitive data         │
│                                                     │
│ [Learn More] [I Understand, Continue]              │
└─────────────────────────────────────────────────────┘
```

#### Implementation

```php
// In assistant metabox
function wp_mcp_ai_render_provider_warning( $provider ) {
    $cloud_providers = array( 'openai', 'gemini', 'anthropic' );
    
    if ( in_array( $provider, $cloud_providers, true ) ) {
        ?>
        <div class="notice notice-warning inline">
            <p>
                <strong><?php _e( '⚠️ Data Egress Notice', 'wp-mcp-ai' ); ?></strong><br>
                <?php
                printf(
                    __( 'This assistant uses %s, a cloud-based AI provider. User messages will be transmitted to external servers for processing.', 'wp-mcp-ai' ),
                    '<strong>' . esc_html( ucfirst( $provider ) ) . '</strong>'
                );
                ?>
            </p>
            <p>
                <?php _e( 'For maximum privacy, consider using <strong>Ollama</strong> (local processing).', 'wp-mcp-ai' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings&section=providers' ) ); ?>">
                    <?php _e( 'Configure Ollama', 'wp-mcp-ai' ); ?>
                </a>
            </p>
        </div>
        <?php
    } else {
        ?>
        <div class="notice notice-success inline">
            <p>
                <strong><?php _e( '✓ Privacy-Focused Configuration', 'wp-mcp-ai' ); ?></strong><br>
                <?php _e( 'This assistant uses local AI processing. User data does not leave your server.', 'wp-mcp-ai' ); ?>
            </p>
        </div>
        <?php
    }
}
```

### End-User Notices

Display privacy notices to users before they interact with cloud-provider assistants:

#### Chat Interface Notice

```javascript
// Display provider notice in chat UI
function displayProviderNotice(provider) {
    if (['openai', 'gemini', 'anthropic'].includes(provider)) {
        const notice = document.createElement('div');
        notice.className = 'wp-mcp-ai-privacy-notice';
        notice.innerHTML = `
            <p>
                <strong>Privacy Notice:</strong> 
                Your messages will be processed by ${getProviderName(provider)} 
                to generate responses. 
                <a href="/privacy-policy" target="_blank">Learn more</a>
            </p>
        `;
        chatContainer.insertBefore(notice, chatMessages);
    }
}

function getProviderName(provider) {
    const names = {
        'openai': 'OpenAI',
        'gemini': 'Google Gemini',
        'anthropic': 'Anthropic Claude'
    };
    return names[provider] || provider;
}
```

## Provider Routing Policies

### Strategy 1: Sensitivity-Based Routing

Route conversations based on data sensitivity:

```php
/**
 * Route to local provider for sensitive topics
 */
function wp_mcp_ai_intelligent_provider_routing( $provider, $messages, $assistant_id ) {
    // Check for sensitive keywords
    $sensitive_patterns = array(
        '/\b(password|credit card|ssn|social security)\b/i',
        '/\b(health|medical|diagnosis|prescription)\b/i',
        '/\b(financial|bank account|tax)\b/i',
    );
    
    foreach ( $messages as $message ) {
        if ( ! isset( $message['content'] ) ) {
            continue;
        }
        
        $content = is_array( $message['content'] ) 
            ? wp_json_encode( $message['content'] ) 
            : $message['content'];
        
        foreach ( $sensitive_patterns as $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                // Force local provider
                return 'ollama';
            }
        }
    }
    
    return $provider; // Use configured provider
}
add_filter( 'wp_mcp_ai_chat_provider', 'wp_mcp_ai_intelligent_provider_routing', 10, 3 );
```

### Strategy 2: User Role-Based Routing

Different providers for different user types:

```php
/**
 * Route internal staff to local provider, public to cloud
 */
function wp_mcp_ai_role_based_routing( $provider, $messages, $assistant_id ) {
    if ( ! is_user_logged_in() ) {
        return 'openai'; // Public users use cloud (cost-effective)
    }
    
    $user = wp_get_current_user();
    
    // Internal staff use local (privacy)
    if ( in_array( 'administrator', $user->roles, true ) || 
         in_array( 'editor', $user->roles, true ) ) {
        return 'ollama';
    }
    
    return $provider;
}
add_filter( 'wp_mcp_ai_chat_provider', 'wp_mcp_ai_role_based_routing', 10, 3 );
```

### Strategy 3: Geographic Routing

Route based on user location for data residency compliance:

```php
/**
 * Route EU users to local provider for GDPR compliance
 */
function wp_mcp_ai_geographic_routing( $provider, $messages, $assistant_id ) {
    // Get user's country (requires GeoIP plugin/service)
    $country = wp_mcp_ai_get_user_country();
    
    // EU countries require local processing
    $eu_countries = array( 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', /* ... */ );
    
    if ( in_array( $country, $eu_countries, true ) ) {
        return 'ollama'; // GDPR-compliant local processing
    }
    
    return $provider;
}
add_filter( 'wp_mcp_ai_chat_provider', 'wp_mcp_ai_geographic_routing', 10, 3 );
```

## PII Redaction Before Egress

### Automatic PII Redaction

Implement PII detection and redaction before sending to cloud providers:

```php
/**
 * Redact PII from messages before cloud processing
 */
function wp_mcp_ai_redact_pii( $messages, $provider, $assistant_id ) {
    // Only redact for cloud providers
    if ( ! in_array( $provider, array( 'openai', 'gemini', 'anthropic' ), true ) ) {
        return $messages;
    }
    
    $pii_patterns = array(
        // Email addresses
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[EMAIL REDACTED]',
        
        // Phone numbers (various formats)
        '/\b(\+\d{1,2}\s?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/' => '[PHONE REDACTED]',
        
        // Credit card numbers
        '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/' => '[CARD REDACTED]',
        
        // Social Security Numbers (US)
        '/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN REDACTED]',
        
        // IP Addresses
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/' => '[IP REDACTED]',
    );
    
    foreach ( $messages as $key => $message ) {
        if ( ! isset( $message['content'] ) ) {
            continue;
        }
        
        if ( is_string( $message['content'] ) ) {
            foreach ( $pii_patterns as $pattern => $replacement ) {
                $messages[ $key ]['content'] = preg_replace( $pattern, $replacement, $message['content'] );
            }
        }
    }
    
    return $messages;
}
add_filter( 'wp_mcp_ai_chat_messages', 'wp_mcp_ai_redact_pii', 10, 3 );
```

### Manual Redaction UI

Provide admin UI to configure redaction rules:

```php
// Settings page
function wp_mcp_ai_render_redaction_settings() {
    $settings = get_option( 'wp_mcp_ai_redaction_settings', array() );
    $enabled = isset( $settings['enabled'] ) ? $settings['enabled'] : false;
    $custom_patterns = isset( $settings['custom_patterns'] ) ? $settings['custom_patterns'] : array();
    ?>
    <div class="wrap">
        <h2><?php _e( 'PII Redaction Settings', 'wp-mcp-ai' ); ?></h2>
        
        <form method="post" action="options.php">
            <?php settings_fields( 'wp_mcp_ai_redaction' ); ?>
            
            <table class="form-table">
                <tr>
                    <th><?php _e( 'Enable PII Redaction', 'wp-mcp-ai' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_mcp_ai_redaction[enabled]" value="1" <?php checked( $enabled, true ); ?> />
                            <?php _e( 'Automatically redact PII before sending to cloud providers', 'wp-mcp-ai' ); ?>
                        </label>
                        <p class="description">
                            <?php _e( 'Detects and redacts email addresses, phone numbers, credit cards, SSNs, and IP addresses.', 'wp-mcp-ai' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e( 'Default Patterns', 'wp-mcp-ai' ); ?></th>
                    <td>
                        <ul>
                            <li>✓ Email addresses</li>
                            <li>✓ Phone numbers</li>
                            <li>✓ Credit card numbers</li>
                            <li>✓ Social Security Numbers</li>
                            <li>✓ IP addresses</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th><?php _e( 'Custom Redaction Patterns', 'wp-mcp-ai' ); ?></th>
                    <td>
                        <textarea name="wp_mcp_ai_redaction[custom_patterns]" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", $custom_patterns ) ); ?></textarea>
                        <p class="description">
                            <?php _e( 'Enter custom regex patterns (one per line) to detect additional PII.', 'wp-mcp-ai' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
```

## Provider Comparison Matrix

### Feature Comparison

| Feature | OpenAI | Gemini | Claude | Ollama | LM Studio |
|---------|--------|--------|--------|--------|-----------|
| **Data Egress** | Yes | Yes | Yes | No | No |
| **Cost** | Pay per token | Pay per token | Pay per token | Free | Free |
| **Performance** | Excellent | Excellent | Excellent | Good* | Good* |
| **Privacy** | Low | Low | Low | High | High |
| **Setup Complexity** | Easy | Easy | Easy | Medium | Medium |
| **Model Selection** | Wide | Wide | Limited | Wide | Wide |
| **Streaming** | Yes | Yes | Yes | Yes | Yes |
| **Tool Calling** | Yes | Yes | Yes | Limited** | Limited** |

*Performance depends on local hardware
**Depends on model support

### Use Case Recommendations

| Use Case | Recommended Provider | Rationale |
|----------|---------------------|-----------|
| **Public FAQ/Support** | OpenAI or Gemini | Cost-effective, scalable, high performance |
| **Internal HR Chatbot** | Ollama | Employee data privacy, no egress |
| **Healthcare Assistant** | Ollama | HIPAA compliance, PHI protection |
| **Financial Advisor** | Ollama | PCI DSS compliance, financial data protection |
| **Customer Service** | OpenAI or Gemini | 24/7 availability, multi-language support |
| **Legal Document Review** | Ollama | Attorney-client privilege, confidentiality |
| **Development/Testing** | LM Studio | Local development, no API costs |
| **Marketing Content** | Gemini | Creative tasks, cost-effective |
| **Code Assistant** | OpenAI (GPT-4) | Technical accuracy, code generation |
| **EU User Base** | Ollama | GDPR compliance, data residency |

## Compliance Scenarios

### Scenario 1: GDPR-Compliant Deployment

**Requirements:**
- Data stays in EU
- No transfer to US companies
- User consent for processing

**Solution:**
```
1. Deploy Ollama on EU-based server
2. Configure all assistants to use Ollama
3. Disable OpenAI/Gemini/Claude providers
4. Implement consent mechanism for transcript storage
5. Document data flows in DPIA (Data Protection Impact Assessment)
```

**Configuration:**
```php
// Force Ollama for all assistants
add_filter( 'wp_mcp_ai_chat_provider', function( $provider ) {
    return 'ollama';
}, 999 );

// Disable cloud provider selection in admin
add_filter( 'wp_mcp_ai_allowed_providers', function( $providers ) {
    return array( 'ollama' );
} );
```

### Scenario 2: HIPAA-Compliant Healthcare Application

**Requirements:**
- PHI (Protected Health Information) must not leave infrastructure
- Business Associate Agreements (BAA) with service providers
- Audit logging
- Encryption at rest and in transit

**Solution:**
```
1. Deploy Ollama locally
2. Disable all cloud providers
3. Disable server-side transcript storage (or encrypt)
4. Enable comprehensive audit logging
5. Use HTTPS with strong TLS
6. Implement access controls
```

**Configuration:**
```php
// HIPAA-compliant configuration
add_filter( 'wp_mcp_ai_allowed_providers', function() {
    return array( 'ollama' );
} );

// Disable transcript recording
add_filter( 'wp_mcp_ai_should_record_transcript', '__return_false' );

// Enhanced audit logging
add_action( 'wp_mcp_ai_chat_completed', function( $assistant_id, $user_id, $message_count ) {
    error_log( sprintf(
        'AUDIT: User %d accessed assistant %d (%d messages)',
        $user_id,
        $assistant_id,
        $message_count
    ) );
}, 10, 3 );
```

### Scenario 3: Mixed Environment (Public + Private)

**Requirements:**
- Public-facing FAQ can use cloud
- Employee-facing tools must be private

**Solution:**
```
1. Create separate assistants for public vs private
2. Public assistants use OpenAI (cost-effective)
3. Private assistants use Ollama (privacy)
4. Apply routing rules based on user authentication
```

**Configuration:**
```php
// Separate assistants by purpose
// Assistant ID 1: Public FAQ (OpenAI)
// Assistant ID 2: Employee Tools (Ollama)

// Enforce provider by assistant
add_filter( 'wp_mcp_ai_chat_provider', function( $provider, $messages, $assistant_id ) {
    $private_assistants = array( 2, 3, 4 ); // Employee tools
    
    if ( in_array( $assistant_id, $private_assistants, true ) ) {
        return 'ollama'; // Force local
    }
    
    return $provider; // Use configured
}, 10, 3 );
```

## Monitoring & Auditing

### Track Data Egress Events

Log when data is sent to external providers:

```php
/**
 * Log data egress events for audit trail
 */
add_action( 'wp_mcp_ai_before_api_request', function( $provider, $assistant_id, $user_id, $message_count ) {
    $cloud_providers = array( 'openai', 'gemini', 'anthropic' );
    
    if ( in_array( $provider, $cloud_providers, true ) ) {
        // Log egress event
        WP_MCP_AI_Logger::log_activity(
            'Data egress to external provider',
            array(
                'provider'     => $provider,
                'assistant_id' => $assistant_id,
                'user_id'      => $user_id,
                'message_count' => $message_count,
                'timestamp'    => current_time( 'mysql' ),
                'ip_address'   => $_SERVER['REMOTE_ADDR'],
            )
        );
    }
}, 10, 4 );
```

### Generate Compliance Reports

Create report showing provider usage:

```php
/**
 * Generate provider usage report
 */
function wp_mcp_ai_generate_provider_report( $start_date, $end_date ) {
    // Query usage logs
    $logs = wp_mcp_ai_query_usage_logs( $start_date, $end_date );
    
    $report = array(
        'period' => array(
            'start' => $start_date,
            'end'   => $end_date,
        ),
        'summary' => array(
            'total_requests' => 0,
            'cloud_requests' => 0,
            'local_requests' => 0,
        ),
        'by_provider' => array(),
        'by_assistant' => array(),
    );
    
    foreach ( $logs as $log ) {
        $report['summary']['total_requests']++;
        
        if ( in_array( $log['provider'], array( 'openai', 'gemini', 'anthropic' ), true ) ) {
            $report['summary']['cloud_requests']++;
        } else {
            $report['summary']['local_requests']++;
        }
        
        // Count by provider
        if ( ! isset( $report['by_provider'][ $log['provider'] ] ) ) {
            $report['by_provider'][ $log['provider'] ] = 0;
        }
        $report['by_provider'][ $log['provider'] ]++;
        
        // Count by assistant
        if ( ! isset( $report['by_assistant'][ $log['assistant_id'] ] ) ) {
            $report['by_assistant'][ $log['assistant_id'] ] = array(
                'name'     => get_the_title( $log['assistant_id'] ),
                'provider' => $log['provider'],
                'count'    => 0,
            );
        }
        $report['by_assistant'][ $log['assistant_id'] ]['count']++;
    }
    
    return $report;
}
```

## Best Practices Summary

### For Maximum Privacy

1. **Use Ollama exclusively** for all assistants
2. **Disable cloud providers** via filter
3. **Disable transcript recording** or enable user opt-out
4. **Enable PII redaction** as defense-in-depth
5. **Monitor and audit** all data flows
6. **Train users** on privacy-safe chat practices

### For Balanced Approach

1. **Use Ollama for sensitive assistants** (HR, Finance, Legal)
2. **Use OpenAI/Gemini for public assistants** (FAQ, Support)
3. **Implement intelligent routing** based on content/user
4. **Enable PII redaction** for cloud providers
5. **Provide clear privacy notices** to users
6. **Regular compliance audits**

### For Maximum Performance

1. **Use cloud providers** (OpenAI, Gemini) for all assistants
2. **Implement PII redaction** for protection
3. **Clear privacy policy** explaining data egress
4. **User consent** before processing
5. **Monitor costs** and token usage
6. **Consider data residency** requirements

## Troubleshooting

### Q: Ollama is slow. Can I use OpenAI for some assistants?

**A:** Yes! Configure different assistants with different providers. High-volume public assistants can use OpenAI while sensitive internal tools use Ollama.

### Q: How do I know if data egressed?

**A:** Enable logging and check the `wp_mcp_ai_activity_log` option. Look for entries with `provider: openai|gemini|anthropic`.

### Q: Can I block certain data from going to cloud providers?

**A:** Yes. Use the PII redaction filter or implement custom validation to reject requests containing sensitive patterns.

### Q: What if I need to comply with multiple regulations?

**A:** Use the strictest configuration (Ollama only) or implement complex routing logic that considers user location, data type, and regulatory requirements.

## Additional Resources

- [Ollama Setup Guide](lm-studio-setup.md)
- [Privacy Policy Guide](privacy-policy-guide.md)
- [Mesh Routing Documentation](mesh-routing-guide.md)
- [Security Best Practices](../SECURITY.md)

---

**Document Maintained By:** NV Digital Solutions  
**Last Review Date:** November 9, 2025  
**Next Review Date:** February 9, 2026
