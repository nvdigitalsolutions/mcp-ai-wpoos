# Data Masking Procedures and Implementation
## ISO 27001 Control A.8.11 - Data Masking

**Document Classification:** Internal  
**Version:** 1.0.0  
**Effective Date:** 2026-01-06  
**Review Date:** 2026-07-06  
**Document Owner:** Chief Information Security Officer (CISO) & Lead Developer  
**ISO 27001:2022 Control:** A.8.11

---

## 1. Purpose

This document establishes comprehensive data masking procedures to protect sensitive information during:
- Development and testing activities
- Logging and debugging
- User interface display
- Data analysis and reporting
- Third-party data sharing
- Demonstrations and training

The objectives are to:
- Prevent unauthorized exposure of personally identifiable information (PII)
- Protect credentials, API keys, and authentication tokens
- Comply with data protection regulations (GDPR, CCPA)
- Enable safe use of production-like data in non-production environments
- Meet ISO/IEC 27001:2022 Control A.8.11 requirements

---

## 2. Scope

### 2.1 Data Types Requiring Masking

**Always Mask:**
1. **Authentication Credentials:**
   - Passwords and password hashes
   - API keys and secret keys
   - Access tokens and refresh tokens
   - Encryption keys
   - OAuth client secrets
   - Database credentials
   - SSH private keys

2. **Personally Identifiable Information (PII):**
   - Email addresses
   - Phone numbers
   - IP addresses (in some contexts)
   - Full names (in some contexts)
   - Physical addresses
   - Social security numbers
   - Government ID numbers

3. **Financial Information:**
   - Credit card numbers
   - Bank account numbers
   - Payment transaction details
   - Billing information

4. **Healthcare Information:**
   - Medical records
   - Health insurance information
   - Prescription data

**Context-Dependent Masking:**
- User IDs (mask in logs, show in admin UI)
- Usernames (mask partially in some contexts)
- Session IDs (mask in client-side logs)
- Timestamps (may need obfuscation for privacy)

### 2.2 Environments and Contexts

**Where Masking Applies:**
- Development environments
- Testing and QA environments
- Staging environments
- Production logs
- Admin interfaces
- API responses
- Error messages
- Debug output
- Database dumps
- Backups used for testing
- Analytics and reporting
- Third-party integrations

---

## 3. Masking Methods

### 3.1 Method Selection Matrix

| Data Type | Display Context | Method | Example | Reversible |
|-----------|----------------|--------|---------|------------|
| **API Keys** | UI Display | Show last 4 | `sk-...kP8z` | No |
| **API Keys** | Logs | Full redaction | `[REDACTED]` | No |
| **Passwords** | All contexts | Never show | `******` | No |
| **Email** | Logs | Hash or partial mask | `u***@example.com` | Optional |
| **Email** | UI (admin) | Show full or partial | `user@example.com` | Yes |
| **IP Address** | Logs | Last octet masked | `192.168.1.XXX` | No |
| **Credit Card** | UI | Show last 4 | `****1234` | No |
| **Phone** | Display | Partial mask | `(***) ***-1234` | No |
| **User ID** | Logs | Hash | `user_a3f5b...` | Optional |
| **Session ID** | Client logs | Full redaction | `[SESSION]` | No |

### 3.2 Masking Techniques

#### 3.2.1 Substitution

**Character Substitution:**
```php
// Replace characters with asterisks
function mask_substitute( $value, $visible_chars = 4, $position = 'end' ) {
    $length = strlen( $value );
    if ( $length <= $visible_chars ) {
        return str_repeat( '*', $length );
    }
    
    if ( 'end' === $position ) {
        $masked = str_repeat( '*', $length - $visible_chars );
        return $masked . substr( $value, -$visible_chars );
    } elseif ( 'start' === $position ) {
        return substr( $value, 0, $visible_chars ) . str_repeat( '*', $length - $visible_chars );
    }
    
    return str_repeat( '*', $length );
}

// Example: mask_substitute('sk-abcd1234efgh5678', 4, 'end') → '****************5678'
```

#### 3.2.2 Partial Masking

**Email Masking:**
```php
function mask_email( $email ) {
    $parts = explode( '@', $email );
    if ( count( $parts ) !== 2 ) {
        return '[INVALID_EMAIL]';
    }
    
    $username = $parts[0];
    $domain = $parts[1];
    
    $username_length = strlen( $username );
    if ( $username_length <= 2 ) {
        $masked_username = str_repeat( '*', $username_length );
    } else {
        $visible_chars = min( 2, floor( $username_length / 3 ) );
        $masked_username = substr( $username, 0, $visible_chars ) . 
                          str_repeat( '*', $username_length - $visible_chars );
    }
    
    return $masked_username . '@' . $domain;
}

// Example: mask_email('john.doe@example.com') → 'jo******@example.com'
```

**Phone Number Masking:**
```php
function mask_phone( $phone ) {
    // Remove non-numeric characters
    $cleaned = preg_replace( '/[^0-9]/', '', $phone );
    $length = strlen( $cleaned );
    
    if ( $length < 4 ) {
        return str_repeat( '*', $length );
    }
    
    // Show last 4 digits
    $last_four = substr( $cleaned, -4 );
    $masked = str_repeat( '*', $length - 4 );
    
    // Format as phone number
    if ( $length === 10 ) {
        return '(' . substr( $masked, 0, 3 ) . ') ' . 
               substr( $masked, 3, 3 ) . '-' . $last_four;
    }
    
    return $masked . $last_four;
}

// Example: mask_phone('1234567890') → '(***) ***-7890'
```

#### 3.2.3 Hashing

**One-Way Hashing (Non-Reversible):**
```php
function mask_hash( $value, $algorithm = 'sha256', $truncate = 8 ) {
    $hash = hash( $algorithm, $value );
    return 'hash_' . substr( $hash, 0, $truncate );
}

// Example: mask_hash('sensitive_value') → 'hash_a1b2c3d4'
// Use for: User IDs in logs, session identifiers
```

**Keyed Hashing (Consistent Masking):**
```php
function mask_hmac( $value, $secret_key, $truncate = 8 ) {
    $hmac = hash_hmac( 'sha256', $value, $secret_key );
    return 'id_' . substr( $hmac, 0, $truncate );
}

// Example: Generates same masked value for same input
// Use for: Consistent anonymization across datasets
```

#### 3.2.4 Tokenization

**Reversible Masking (with key):**
```php
function mask_tokenize( $value, $secret_key ) {
    // Store mapping in secure database
    $token = 'tok_' . bin2hex( random_bytes( 16 ) );
    wp_mcp_ai_store_token_mapping( $token, $value, $secret_key );
    return $token;
}

function unmask_tokenize( $token, $secret_key ) {
    return wp_mcp_ai_retrieve_token_mapping( $token, $secret_key );
}

// Example: mask_tokenize('user@example.com', $key) → 'tok_a1b2c3d4...'
// Use for: Data that needs to be unmasked later with proper authorization
```

#### 3.2.5 Synthetic Data Generation

**For Test Data:**
```php
function generate_synthetic_email() {
    $usernames = ['test', 'demo', 'sample', 'user', 'john', 'jane'];
    $domains = ['example.com', 'test.local', 'demo.org'];
    
    return $usernames[ array_rand( $usernames ) ] . 
           rand( 100, 999 ) . '@' . 
           $domains[ array_rand( $domains ) ];
}

function generate_synthetic_phone() {
    // Use 555 area code (reserved for fictional use)
    return sprintf( '(%03d) 555-%04d', rand( 200, 999 ), rand( 0, 9999 ) );
}

// Use for: Creating test datasets
```

---

## 4. Implementation by Context

### 4.1 API Key Masking (UI Display)

**Current Implementation Enhancement:**

```php
/**
 * Enhanced API key masking for UI display
 *
 * @param string $api_key Full API key
 * @return string Masked API key showing only last 4 characters
 */
function wp_mcp_ai_mask_api_key_ui( $api_key ) {
    if ( empty( $api_key ) || strlen( $api_key ) < 8 ) {
        return '[INVALID_KEY]';
    }
    
    // Determine key type prefix
    $prefix = '';
    if ( strpos( $api_key, 'sk-' ) === 0 ) {
        $prefix = 'sk-';
        $key_part = substr( $api_key, 3 );
    } elseif ( strpos( $api_key, 'pk-' ) === 0 ) {
        $prefix = 'pk-';
        $key_part = substr( $api_key, 3 );
    } else {
        $key_part = $api_key;
    }
    
    $length = strlen( $key_part );
    $last_four = substr( $key_part, -4 );
    
    // Show prefix + dots + last 4
    return $prefix . str_repeat( '•', min( $length - 4, 16 ) ) . $last_four;
}

// Example: wp_mcp_ai_mask_api_key_ui('sk-abcd1234efgh5678ijkl9012') 
//          → 'sk-••••••••••••••••9012'
```

### 4.2 Logging with Data Masking

**Comprehensive Logging Function:**

```php
/**
 * Log message with automatic PII/credential masking
 *
 * @param string $message Log message
 * @param string $level Log level (info, warning, error, debug)
 * @param array $context Additional context data
 */
function wp_mcp_ai_log_masked( $message, $level = 'info', $context = array() ) {
    // Mask common sensitive patterns in message
    $message = wp_mcp_ai_mask_sensitive_data( $message );
    
    // Mask sensitive fields in context
    if ( ! empty( $context ) ) {
        $context = wp_mcp_ai_mask_context_data( $context );
    }
    
    // Standard logging
    error_log( sprintf(
        '[%s] [%s] %s %s',
        date( 'Y-m-d H:i:s' ),
        strtoupper( $level ),
        $message,
        ! empty( $context ) ? json_encode( $context ) : ''
    ) );
}

/**
 * Mask sensitive data patterns in text
 *
 * @param string $text Text to mask
 * @return string Masked text
 */
function wp_mcp_ai_mask_sensitive_data( $text ) {
    // API keys (OpenAI, Gemini, generic)
    $text = preg_replace( '/sk-[a-zA-Z0-9]{32,}/', '[API_KEY_REDACTED]', $text );
    $text = preg_replace( '/AIza[a-zA-Z0-9_-]{35}/', '[API_KEY_REDACTED]', $text );
    
    // Bearer tokens
    $text = preg_replace( '/Bearer\s+[a-zA-Z0-9_.-]{20,}/', 'Bearer [TOKEN_REDACTED]', $text );
    
    // Passwords in URLs
    $text = preg_replace( '/:\/\/[^:]+:([^@]+)@/', '://username:[PASSWORD]@', $text );
    
    // Email addresses (partial masking)
    $text = preg_replace_callback(
        '/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/',
        function( $matches ) {
            return wp_mcp_ai_mask_email( $matches[0] );
        },
        $text
    );
    
    // IP addresses (mask last octet)
    $text = preg_replace( '/\b(\d{1,3}\.){3}\d{1,3}\b/', '\1XXX', $text );
    
    // Credit card numbers (show last 4)
    $text = preg_replace( '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?(\d{4})\b/', '****-****-****-\1', $text );
    
    return $text;
}

/**
 * Mask sensitive fields in context array
 *
 * @param array $context Context data
 * @return array Masked context data
 */
function wp_mcp_ai_mask_context_data( $context ) {
    $sensitive_keys = array(
        'password', 'passwd', 'pwd',
        'api_key', 'apikey', 'secret', 'token',
        'authorization', 'auth',
        'credit_card', 'cc_number', 'card_number',
        'ssn', 'social_security',
        'private_key', 'encryption_key'
    );
    
    foreach ( $context as $key => $value ) {
        $key_lower = strtolower( $key );
        
        // Check if key matches sensitive patterns
        foreach ( $sensitive_keys as $sensitive ) {
            if ( strpos( $key_lower, $sensitive ) !== false ) {
                $context[ $key ] = '[REDACTED]';
                break;
            }
        }
        
        // Recursively mask nested arrays
        if ( is_array( $value ) ) {
            $context[ $key ] = wp_mcp_ai_mask_context_data( $value );
        }
        
        // Mask email-like values
        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
            $context[ $key ] = wp_mcp_ai_mask_email( $value );
        }
    }
    
    return $context;
}
```

### 4.3 Database Dumps and Backups

**Data Anonymization for Test Databases:**

```php
/**
 * Anonymize production data for test environment
 * Run this on database copy before use in testing
 */
function wp_mcp_ai_anonymize_test_database() {
    global $wpdb;
    
    // Anonymize user data
    $wpdb->query( "
        UPDATE {$wpdb->users}
        SET user_email = CONCAT('user', ID, '@example.com'),
            user_url = '',
            user_login = CONCAT('test_user_', ID)
        WHERE ID > 1
    " );
    
    // Clear user meta with sensitive info
    $wpdb->query( "
        DELETE FROM {$wpdb->usermeta}
        WHERE meta_key IN (
            'last_ip_address',
            'session_tokens',
            '_wp_mcp_ai_api_keys',
            'billing_address',
            'shipping_address'
        )
    " );
    
    // Anonymize API keys in options
    $wpdb->query( "
        UPDATE {$wpdb->options}
        SET option_value = 'TEST_KEY_ANONYMIZED'
        WHERE option_name LIKE '%_api_key%'
           OR option_name LIKE '%_secret%'
           OR option_name LIKE '%_token%'
    " );
    
    // Clear chat transcripts (if stored in post meta)
    $wpdb->query( "
        DELETE FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_mcp_ai_chat_transcript'
    " );
    
    // Add watermark
    update_option( 'wp_mcp_ai_test_database', array(
        'anonymized' => true,
        'date' => current_time( 'mysql' ),
        'source' => 'production',
    ) );
    
    wp_mcp_ai_log_masked( 'Test database anonymization completed', 'info' );
}
```

### 4.4 Error Messages and Debug Output

**Safe Error Handling:**

```php
/**
 * Display user-friendly error without exposing sensitive details
 *
 * @param Exception $exception Exception object
 * @param bool $is_admin Whether current user is admin
 * @return string Safe error message
 */
function wp_mcp_ai_safe_error_message( $exception, $is_admin = false ) {
    $safe_message = 'An error occurred. Please try again or contact support.';
    
    if ( $is_admin && current_user_can( 'manage_options' ) ) {
        // Show more details to admins, but still mask sensitive data
        $admin_message = sprintf(
            'Error: %s (Code: %s)',
            wp_mcp_ai_mask_sensitive_data( $exception->getMessage() ),
            $exception->getCode()
        );
        
        // Log full details (masked) for debugging
        wp_mcp_ai_log_masked(
            'Exception occurred',
            'error',
            array(
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            )
        );
        
        return $admin_message;
    }
    
    // Log error (masked) but show generic message to users
    wp_mcp_ai_log_masked(
        'User-facing error occurred',
        'error',
        array(
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        )
    );
    
    return $safe_message;
}
```

### 4.5 API Responses

**Response Filtering:**

```php
/**
 * Filter API response to mask sensitive data
 *
 * @param array $response API response data
 * @param string $context Response context (admin, public, api)
 * @return array Filtered response
 */
function wp_mcp_ai_filter_api_response( $response, $context = 'public' ) {
    // Always mask these fields
    $always_mask = array( 'api_key', 'secret', 'password', 'token', 'credentials' );
    
    foreach ( $always_mask as $field ) {
        if ( isset( $response[ $field ] ) ) {
            $response[ $field ] = '[REDACTED]';
        }
    }
    
    // Context-specific masking
    if ( 'public' === $context || 'api' === $context ) {
        // Mask email addresses
        if ( isset( $response['email'] ) ) {
            $response['email'] = wp_mcp_ai_mask_email( $response['email'] );
        }
        
        // Mask IP addresses
        if ( isset( $response['ip_address'] ) ) {
            $response['ip_address'] = wp_mcp_ai_mask_ip( $response['ip_address'] );
        }
        
        // Remove internal IDs
        unset( $response['internal_id'], $response['user_id'] );
    }
    
    // Admin context shows more but still masks credentials
    if ( 'admin' === $context ) {
        // Show partial API keys
        if ( isset( $response['api_key'] ) ) {
            $response['api_key'] = wp_mcp_ai_mask_api_key_ui( $response['api_key'] );
        }
    }
    
    return $response;
}
```

---

## 5. Test Data Management

### 5.1 Test Data Generation

**Synthetic Data Generator:**

```php
/**
 * Generate realistic but fake test data
 */
class WP_MCP_AI_Test_Data_Generator {
    
    /**
     * Generate test user data
     */
    public function generate_user() {
        $first_names = array( 'Test', 'Demo', 'Sample', 'Example', 'John', 'Jane', 'Alex' );
        $last_names = array( 'User', 'Account', 'Person', 'Smith', 'Doe', 'Johnson' );
        
        $first = $first_names[ array_rand( $first_names ) ];
        $last = $last_names[ array_rand( $last_names ) ];
        $username = strtolower( $first . '_' . $last . '_' . rand( 100, 999 ) );
        
        return array(
            'username' => $username,
            'email' => $username . '@example.com',
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $first . ' ' . $last,
        );
    }
    
    /**
     * Generate test API interaction
     */
    public function generate_api_request() {
        $prompts = array(
            'What is the weather today?',
            'Tell me a joke',
            'Explain quantum physics',
            'Write a haiku about testing',
        );
        
        return array(
            'prompt' => $prompts[ array_rand( $prompts ) ],
            'user_id' => 'test_user_' . rand( 1, 100 ),
            'timestamp' => time(),
        );
    }
}
```

### 5.2 Production Data Sanitization

**Script for Creating Test Database:**

```bash
#!/bin/bash
# sanitize-production-data.sh

# Export production database
wp db export production-backup.sql

# Create sanitized copy
cp production-backup.sql test-sanitized.sql

# Run anonymization queries
wp db query "$(cat <<EOF
-- Anonymize users
UPDATE wp_users 
SET user_email = CONCAT('user', ID, '@example.com'),
    user_login = CONCAT('test_user_', ID),
    user_url = '';

-- Clear sensitive meta
DELETE FROM wp_usermeta 
WHERE meta_key IN ('session_tokens', 'last_ip_address');

-- Clear API keys
UPDATE wp_options 
SET option_value = 'TEST_KEY' 
WHERE option_name LIKE '%_api_key%';

-- Clear chat transcripts
DELETE FROM wp_postmeta 
WHERE meta_key = '_wp_mcp_ai_chat_transcript';
EOF
)" --path=/path/to/test/wordpress

echo "Test database sanitized and ready"
```

---

## 6. Compliance and Auditing

### 6.1 Data Masking Audit Log

**Track All Masking Operations:**

```php
/**
 * Log data masking operations for audit
 *
 * @param string $operation Type of masking operation
 * @param string $data_type Type of data masked
 * @param string $context Where masking occurred
 */
function wp_mcp_ai_log_masking_operation( $operation, $data_type, $context ) {
    $log_entry = array(
        'timestamp' => current_time( 'mysql' ),
        'operation' => $operation,
        'data_type' => $data_type,
        'context' => $context,
        'user_id' => get_current_user_id(),
        'ip_address' => wp_mcp_ai_mask_ip( $_SERVER['REMOTE_ADDR'] ?? '' ),
    );
    
    // Store in secure log
    wp_mcp_ai_store_audit_log( 'data_masking', $log_entry );
}
```

### 6.2 Masking Effectiveness Testing

**Verify Masking Implementation:**

```php
/**
 * Test suite for data masking functions
 */
class WP_MCP_AI_Masking_Tests extends WP_UnitTestCase {
    
    public function test_api_key_masking() {
        $key = 'sk-abcd1234efgh5678ijkl9012mnop3456';
        $masked = wp_mcp_ai_mask_api_key_ui( $key );
        
        // Should not contain original key
        $this->assertStringNotContainsString( 'abcd1234', $masked );
        
        // Should show last 4
        $this->assertStringContainsString( '3456', $masked );
        
        // Should be shorter than original
        $this->assertLessThan( strlen( $key ), strlen( $masked ) );
    }
    
    public function test_email_masking() {
        $email = 'john.doe@example.com';
        $masked = wp_mcp_ai_mask_email( $email );
        
        // Should contain domain
        $this->assertStringContainsString( '@example.com', $masked );
        
        // Should not show full username
        $this->assertStringNotContainsString( 'john.doe', $masked );
    }
    
    public function test_log_masking() {
        $message = 'User login with key sk-test1234 and email user@test.com';
        $masked = wp_mcp_ai_mask_sensitive_data( $message );
        
        // Should not contain API key
        $this->assertStringNotContainsString( 'sk-test1234', $masked );
        
        // Should contain redaction marker
        $this->assertStringContainsString( '[API_KEY_REDACTED]', $masked );
    }
}
```

---

## 7. Related Documents

- [Data Classification Policy](../Data-Classification.md) - Information sensitivity levels
- [Acceptable Use Policy](../Acceptable-Use-Policy.md) - Data handling guidelines
- [Privacy Policy](../../PRIVACY.md) - User data protection
- [Security Hardening](../../features/security/SECURITY_HARDENING.md) - Security implementation

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0.0 | 2026-01-06 | GitHub Copilot | Initial Data Masking Procedures (ISO 27001 A.8.11) |

---

## Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| CISO | [Name] | [Digital Signature] | 2026-01-06 |
| Lead Developer | [Name] | [Digital Signature] | 2026-01-06 |
| Data Protection Officer | [Name] | [Digital Signature] | 2026-01-06 |

---

**Next Review Date:** 2026-07-06 (6 months)  
**Review Frequency:** Quarterly or when data handling requirements change
