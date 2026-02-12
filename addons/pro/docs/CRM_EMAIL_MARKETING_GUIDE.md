# CRM & Email Marketing Pro Toolkit - Integration Guide

This guide details the enhanced CRM and Email Marketing capabilities added to the Pro addon, following industry best practices for WordPress CRM and email marketing automation.

## Overview

The Pro addon now includes comprehensive CRM and email marketing tools powered by modern NPM packages, designed to provide enterprise-level customer relationship management and email marketing automation within WordPress.

## Architecture

### Core Components

1. **Contact Management** - Full contact lifecycle management
2. **Email Marketing** - Campaign creation, sending, and tracking
3. **List Segmentation** - Dynamic customer segmentation
4. **Automation** - Drip campaigns, triggered emails, workflows
5. **Analytics** - Campaign performance and customer insights
6. **Compliance** - GDPR-compliant consent management

## NPM Packages Added (v1.1.0)

### Email Sending & Templates
- **nodemailer** v6.9.16 - Advanced SMTP email sending
  - HTML/text emails with attachments
  - OAuth2 authentication support
  - Template support with variables
  - Connection pooling for performance

- **mjml** v4.18.0 - Responsive email templates
  - Mobile-first design
  - Cross-client compatibility (Gmail, Outlook, Apple Mail)
  - Component-based layouts
  - Already implemented in `generate_email_template` tool

### Email Processing
- **mailparser** v3.7.1 - Email parsing and extraction
  - Parse incoming emails
  - Extract attachments
  - Parse MIME structures
  - Use case: Process customer replies, extract signatures

### Validation
- **email-validator** v2.0.4 - Fast email validation
  - RFC 5322 compliant
  - MX record checking
  - Disposable email detection

- **validator** v13.12.0 - Comprehensive validation library
  - Email, URL, credit card validation
  - Input sanitization
  - Phone number, postal code validation
  - 14M+ weekly downloads

### Contact Data Management
- **csv-parse** v5.6.0 - Contact import from CSV
  - High-performance streaming parser
  - Large file support
  - Custom delimiter support
  - Use case: Import contact lists from external sources

- **csv-stringify** v6.5.2 - Contact export to CSV
  - Generate CSV files
  - Custom formatting options
  - Use case: Export contacts for analysis or migration

- **libphonenumber-js** v1.11.21 - Phone number validation
  - International phone number validation
  - Format normalization
  - Country code detection
  - Use case: Ensure valid phone numbers in CRM

### Calendar Integration
- **ical-generator** v8.0.1 - iCalendar generation
  - Create .ics calendar files
  - VTODO, VEVENT, VALARM support
  - Use case: Send calendar invites with campaign emails

## Best Practices Implementation

### 1. List Segmentation

```php
/**
 * Example: Segment customers by behavior
 */
class WP_MCP_AI_Tool_Segment_CRM_Contacts {
    public function execute( $arguments, $context ) {
        // Segment by purchase history, engagement, demographics
        $segments = array(
            'high_value' => array( 'ltv' => '> 1000' ),
            'engaged' => array( 'open_rate' => '> 30%' ),
            'inactive' => array( 'last_activity' => '> 90 days' ),
        );
        
        return $this->apply_segments( $segments );
    }
}
```

### 2. Email Personalization

```php
/**
 * Example: Personalized email with nodemailer
 */
$nodemailer_service = new WP_MCP_AI_Nodemailer_Service();
$email_data = array(
    'to' => $contact['email'],
    'subject' => "Hi {$contact['first_name']}, special offer for you",
    'html' => $this->render_template( 'personalized-offer', $contact ),
    'attachments' => array(
        array(
            'filename' => 'offer.pdf',
            'path' => $pdf_path,
        ),
    ),
);

$result = $nodemailer_service->send_email( $email_data );
```

### 3. Automation Workflows

```php
/**
 * Example: Abandoned cart recovery workflow
 */
add_action( 'woocommerce_cart_updated', 'trigger_cart_recovery_workflow' );

function trigger_cart_recovery_workflow( $cart ) {
    if ( $cart->get_cart_contents_count() > 0 ) {
        // Schedule reminder emails: 1 hour, 24 hours, 7 days
        wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'send_cart_reminder', array( $cart_id, 1 ) );
        wp_schedule_single_event( time() + DAY_IN_SECONDS, 'send_cart_reminder', array( $cart_id, 2 ) );
        wp_schedule_single_event( time() + 7 * DAY_IN_SECONDS, 'send_cart_reminder', array( $cart_id, 3 ) );
    }
}
```

### 4. Deliverability Optimization

```php
/**
 * Example: Email validation before sending
 */
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
$validator = new WP_MCP_AI_Validator_Service();

// Validate email
if ( ! $validator->is_email( $email ) ) {
    return new WP_Error( 'invalid_email', 'Invalid email address' );
}

// Check MX records
if ( ! $validator->has_mx_records( $email ) ) {
    return new WP_Error( 'invalid_domain', 'Email domain has no MX records' );
}

// Proceed with sending
```

### 5. GDPR Compliance

```php
/**
 * Example: Consent management
 */
class WP_MCP_AI_Tool_Manage_Email_Consent {
    public function record_consent( $contact_id, $consent_type ) {
        update_post_meta( $contact_id, '_email_consent', array(
            'type' => $consent_type,
            'timestamp' => current_time( 'timestamp' ),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'source' => 'signup_form',
        ) );
    }
    
    public function handle_unsubscribe( $contact_id ) {
        update_post_meta( $contact_id, '_email_subscribed', false );
        update_post_meta( $contact_id, '_unsubscribe_date', current_time( 'mysql' ) );
        
        // Log unsubscribe event
        do_action( 'wp_mcp_ai_contact_unsubscribed', $contact_id );
    }
}
```

## New Tools to Create

### 1. CRM Contact Management Tool

**Tool**: `manage_crm_contact`

**Capabilities**:
- Create, read, update, delete contacts
- Import contacts from CSV
- Export contacts to CSV
- Validate email and phone numbers
- Track contact activity history

**Parameters**:
```json
{
    "action": "create|update|delete|import|export",
    "contact_data": {
        "first_name": "string",
        "last_name": "string",
        "email": "string",
        "phone": "string",
        "company": "string",
        "tags": ["array"],
        "custom_fields": {}
    }
}
```

### 2. Email Campaign Builder Tool

**Tool**: `create_email_campaign`

**Capabilities**:
- Create multi-step email campaigns
- Schedule campaign sends
- A/B testing support
- Dynamic content personalization
- Automated follow-ups

**Parameters**:
```json
{
    "campaign_name": "string",
    "emails": [
        {
            "subject": "string",
            "template": "mjml|html",
            "delay": "0|1h|1d|7d",
            "conditions": {}
        }
    ],
    "segment": "segment_id",
    "schedule": "immediate|scheduled",
    "send_time": "ISO-8601 timestamp"
}
```

### 3. Lead Scoring Tool

**Tool**: `calculate_lead_score`

**Capabilities**:
- Score leads based on behavior
- Email engagement tracking
- Website activity tracking
- Demographic scoring
- Automatic lead routing

**Parameters**:
```json
{
    "contact_id": "integer",
    "scoring_rules": {
        "email_opened": 5,
        "link_clicked": 10,
        "form_submitted": 25,
        "page_views": 2
    }
}
```

### 4. Email Analytics Dashboard Tool

**Tool**: `get_email_analytics`

**Capabilities**:
- Campaign performance metrics
- Open rates, click rates, conversions
- Geographic data
- Device breakdown
- Revenue attribution

**Parameters**:
```json
{
    "campaign_id": "integer",
    "date_range": "7d|30d|90d|custom",
    "start_date": "ISO-8601 date",
    "end_date": "ISO-8601 date",
    "metrics": ["opens", "clicks", "conversions", "revenue"]
}
```

## Service Classes

### WP_MCP_AI_Nodemailer_Service

```php
class WP_MCP_AI_Nodemailer_Service {
    public function is_available();
    public function send_email( $email_data );
    public function send_bulk( $recipients, $email_data );
    public function verify_connection( $smtp_config );
}
```

### WP_MCP_AI_Validator_Service

```php
class WP_MCP_AI_Validator_Service {
    public function is_email( $email );
    public function has_mx_records( $email );
    public function is_phone_number( $phone, $country = 'US' );
    public function sanitize_input( $input, $type );
}
```

### WP_MCP_AI_Contact_Importer_Service

```php
class WP_MCP_AI_Contact_Importer_Service {
    public function parse_csv( $file_path );
    public function import_contacts( $data, $options );
    public function map_fields( $csv_headers, $contact_fields );
    public function validate_import_data( $data );
}
```

### WP_MCP_AI_Calendar_Service

```php
class WP_MCP_AI_Calendar_Service {
    public function create_event( $event_data );
    public function generate_ics( $events );
    public function attach_to_email( $ics_data );
}
```

## Integration Patterns

### Pattern 1: Contact Import Flow

```php
// 1. Upload CSV file
$file = $_FILES['contact_file'];

// 2. Parse CSV
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-contact-importer-service.php';
$importer = new WP_MCP_AI_Contact_Importer_Service();
$contacts = $importer->parse_csv( $file['tmp_name'] );

// 3. Validate contacts
$validator = new WP_MCP_AI_Validator_Service();
$valid_contacts = array_filter( $contacts, function( $contact ) use ( $validator ) {
    return $validator->is_email( $contact['email'] );
} );

// 4. Import into WordPress
foreach ( $valid_contacts as $contact ) {
    $post_id = wp_insert_post( array(
        'post_type' => 'crm_contact',
        'post_title' => $contact['name'],
        'meta_input' => $contact,
    ) );
}
```

### Pattern 2: Campaign Send Flow

```php
// 1. Generate MJML template
$mjml_service = new WP_MCP_AI_MJML_Service();
$html = $mjml_service->compile( $mjml_template );

// 2. Validate recipients
$validator = new WP_MCP_AI_Validator_Service();
$valid_recipients = array_filter( $recipients, function( $email ) use ( $validator ) {
    return $validator->is_email( $email ) && $validator->has_mx_records( $email );
} );

// 3. Send via Nodemailer
$nodemailer = new WP_MCP_AI_Nodemailer_Service();
$results = $nodemailer->send_bulk( $valid_recipients, array(
    'subject' => $campaign['subject'],
    'html' => $html,
) );

// 4. Track results
foreach ( $results as $result ) {
    $this->log_send_event( $result );
}
```

### Pattern 3: Contact Segmentation Flow

```php
// 1. Define segment criteria
$segment_criteria = array(
    'last_purchase' => '< 30 days',
    'total_spent' => '> $500',
    'email_engagement' => 'high',
);

// 2. Query contacts
$contacts = $this->query_contacts( $segment_criteria );

// 3. Export segment
$csv_service = new WP_MCP_AI_CSV_Service();
$csv_data = $csv_service->generate( $contacts );

// 4. Save or send
file_put_contents( $output_path, $csv_data );
```

## WordPress Filter Hooks

All CRM and email marketing services use WordPress filters for extensibility:

```php
// Nodemailer service
apply_filters( 'wp_mcp_ai_nodemailer_send_email', false, $email_data );
apply_filters( 'wp_mcp_ai_nodemailer_smtp_config', $config );

// Validator service
apply_filters( 'wp_mcp_ai_validator_email_rules', $rules );
apply_filters( 'wp_mcp_ai_validator_phone_rules', $rules );

// Contact import service
apply_filters( 'wp_mcp_ai_contact_import_field_mapping', $mapping );
apply_filters( 'wp_mcp_ai_contact_import_validation', $valid, $contact_data );

// Calendar service
apply_filters( 'wp_mcp_ai_calendar_event_defaults', $defaults );
```

## Security Considerations

### 1. Email Validation
- Always validate email addresses before storing or sending
- Check MX records to prevent bounces
- Detect disposable email addresses
- Rate limit validation requests

### 2. Spam Prevention
- Implement CAPTCHA on contact forms
- Rate limit email sends per contact
- Monitor bounce rates and unsubscribes
- Maintain sender reputation

### 3. Data Protection
- Encrypt sensitive contact data
- Log all data access and modifications
- Implement data retention policies
- Support GDPR right-to-be-forgotten

### 4. Email Security
- Use SPF, DKIM, DMARC authentication
- Implement HTTPS for unsubscribe links
- Validate all user inputs
- Sanitize HTML content

## Performance Optimization

### 1. Bulk Operations
- Use WordPress batch processing for large imports
- Implement queue system for campaign sends
- Cache frequently accessed contact data
- Use transients for temporary data

### 2. Database Optimization
- Index frequently queried fields (email, tags)
- Use custom tables for high-volume data
- Implement archiving for old campaign data
- Regular database maintenance

### 3. Email Sending
- Use connection pooling with Nodemailer
- Implement send rate limiting
- Queue emails for background processing
- Monitor deliverability metrics

## Testing

### Unit Tests
```php
class Test_CRM_Contact_Manager extends WP_UnitTestCase {
    public function test_create_contact() {
        $tool = new WP_MCP_AI_Tool_Manage_CRM_Contact();
        $result = $tool->execute( array(
            'action' => 'create',
            'contact_data' => array(
                'email' => 'test@example.com',
                'first_name' => 'John',
            ),
        ) );
        
        $this->assertTrue( $result['success'] );
        $this->assertArrayHasKey( 'contact_id', $result );
    }
}
```

### Integration Tests
- Test email sending with test SMTP servers
- Validate CSV import/export with sample files
- Test campaign workflows end-to-end
- Verify GDPR compliance features

## Future Enhancements

1. **AI-Powered Features**
   - Predictive lead scoring
   - Optimal send time prediction
   - Subject line optimization
   - Churn prediction

2. **Advanced Segmentation**
   - RFM (Recency, Frequency, Monetary) analysis
   - Predictive segments
   - Lookalike audiences
   - Behavioral cohorts

3. **Multi-Channel**
   - SMS integration
   - Push notifications
   - In-app messaging
   - Social media integration

4. **Enhanced Analytics**
   - Attribution modeling
   - Customer journey mapping
   - Revenue forecasting
   - Lifetime value prediction

## Support Resources

- **NPM Package Docs**:
  - [Nodemailer](https://nodemailer.com/)
  - [MJML](https://mjml.io/)
  - [validator.js](https://github.com/validatorjs/validator.js)
  - [libphonenumber-js](https://gitlab.com/catamphetamine/libphonenumber-js)
  - [ical-generator](https://github.com/sebbo2002/ical-generator)

- **WordPress CRM Best Practices**:
  - See `INTEGRATION_BEST_PRACTICES.md`
  - See `NPM_INTEGRATION_GUIDE.md`
  - Check existing ecommerce tools for examples

- **Community**:
  - GitHub Issues: Report bugs or request features
  - Documentation: Check the `docs/` directory
  - Examples: See `addons/pro/includes/tools/ecommerce/` for similar patterns

---

**Version**: 1.1.0  
**Last Updated**: January 22, 2025  
**Maintainer**: NV Digital Solutions
