# PayHere Payment Retrieval Tool

## Overview

The **PayHere Get Payment** tool retrieves payment transaction details from the PayHere payment gateway. This Pro feature enables assistants to query payment information by order ID, providing complete transaction details including payment status, customer information, amounts, fees, and payment method details.

## Tool Slug

`payhere_get_payment`

## Requirements

### API Credentials

To use this tool, you need:

1. **PayHere App ID** - Your application identifier from PayHere
2. **PayHere App Secret** - Your secret key for authentication
3. **Environment Selection** - Choose between Sandbox (testing) and Live (production) modes

### How to Get Credentials

1. Log in to your PayHere merchant account at [https://www.payhere.lk/](https://www.payhere.lk/)
2. Navigate to **Settings → API Keys**
3. Create a new API key or use an existing one
4. Copy your **App ID** and **App Secret**
5. In WordPress admin, go to **Settings → NV oOS → Tools → Connections → PayHere**
6. Enter your credentials and select the appropriate environment mode

### User Permissions

Users executing this tool must have one of the following capabilities:
- `manage_woocommerce` - For WooCommerce store managers
- `manage_options` - For site administrators

## Parameters

### Required Parameters

- **order_id** (string) - The PayHere order ID to retrieve payment details for
  - Example: `"LP8006126139"`
  - This is the unique identifier for the transaction in PayHere

### Optional Parameters

- **timeout** (integer) - Request timeout in seconds
  - Range: 5-60 seconds
  - Default: 30 seconds
  - Use longer timeouts for slower network connections

## Response Structure

The tool returns a structured response containing:

### Summary
- Human-readable summary of the operation

### Status
- API status code (1 = success)
- Status message from PayHere

### Payment Details (per transaction)

Each payment record includes:

#### Basic Information
- `payment_id` - PayHere payment ID
- `order_id` - Merchant order ID
- `date` - Payment date and time
- `description` - Payment description
- `status` - Payment status (e.g., "RECEIVED")
- `currency` - Currency code (e.g., "LKR")
- `amount` - Transaction amount

#### Customer Information
- `first_name` - Customer's first name
- `last_name` - Customer's last name
- `email` - Customer's email address
- `phone` - Customer's phone number
- `delivery_details` (if available)
  - `address` - Delivery address
  - `city` - Delivery city
  - `country` - Delivery country

#### Amount Breakdown
- `currency` - Currency used
- `gross` - Gross amount received
- `fee` - PayHere processing fee
- `net` - Net amount after fees
- `exchange_rate` - Exchange rate applied
- `exchange_from` - Original currency
- `exchange_to` - Converted currency

#### Payment Method
- `method` - Payment method used (e.g., "VISA", "MASTERCARD")
- `card_customer_name` - Name on card
- `card_no` - Masked card number (e.g., "************1234")

#### Items (if available)
- Array of line items included in the payment

## Example Usage

### Basic Payment Retrieval

```
Get payment details for order LP8006126139
```

### With Custom Timeout

```
Retrieve PayHere payment information for order LP8006126139 with a timeout of 45 seconds
```

## Environment Modes

### Sandbox Mode (Testing)
- Use for development and testing
- Uses test API endpoints: `https://sandbox.payhere.lk/`
- Test transactions do not process real payments
- Recommended during development

### Live Mode (Production)
- Use for real transactions
- Uses production API endpoints: `https://www.payhere.lk/`
- Processes real payments
- Enable only when ready for production

## Security Considerations

1. **Credential Protection**
   - App Secret is stored as a password field
   - Never expose credentials in logs or error messages
   - Use environment variables for additional security

2. **PII Data**
   - Tool returns personally identifiable information
   - Customer names, emails, phone numbers, and addresses
   - Ensure compliance with data protection regulations
   - Limit access to authorized users only

3. **API Rate Limits**
   - PayHere API may have rate limits
   - Tool includes rate limiting flags
   - Implement appropriate caching strategies

4. **IP Whitelisting**
   - PayHere requires server IP whitelisting for production
   - Contact PayHere support to whitelist your server IPs
   - Test with Sandbox mode first

## Error Handling

The tool provides specific error messages for common issues:

- **Missing Credentials**: "PayHere App ID and App Secret must be configured"
- **Authentication Required**: "You must be authenticated to retrieve PayHere payment details"
- **Permission Denied**: "You do not have permission to retrieve payment details"
- **Missing Order ID**: "An order_id parameter is required"
- **API Errors**: Specific error messages from PayHere API

## Best Practices

1. **Testing**
   - Always test with Sandbox mode first
   - Verify credentials before switching to Live mode
   - Test error scenarios and edge cases

2. **Error Handling**
   - Implement proper error handling in assistants
   - Provide user-friendly error messages
   - Log errors for troubleshooting

3. **Performance**
   - Cache results when appropriate
   - Use reasonable timeout values
   - Consider batch operations for multiple queries

4. **Security**
   - Regularly rotate API credentials
   - Monitor for unauthorized access attempts
   - Follow PayHere security best practices

5. **Compliance**
   - Document data retention policies
   - Implement data protection measures
   - Follow PCI DSS guidelines for payment data

## Capability Flags

This tool declares the following capability flags:

- `pro` - Pro tier feature
- `external-api` - Makes external API calls
- `requires-credentials` - Requires PayHere API credentials
- `requires-capability` - Requires user capabilities
- `read-only` - Only reads data, does not modify state
- `pii-data` - Returns personally identifiable information
- `rate-limited` - Subject to PayHere API rate limits

## Tool Category

**Pro Tool** - Available only in the Pro/Full version of the plugin

**External Tool** - Requires external API credentials and makes external HTTP requests

## Related Documentation

- [PayHere Official Documentation](https://support.payhere.lk/api-&-mobile-sdk/retrieval-api)
- [PayHere API Keys Setup](https://www.payhere.lk/merchant/settings/api-keys)
- [PayHere Payment Gateway](https://www.payhere.lk/)

## Support

For issues related to:
- **Tool functionality**: Create an issue in the plugin repository
- **PayHere API**: Contact PayHere support at [https://support.payhere.lk/](https://support.payhere.lk/)
- **API credentials**: Check your PayHere merchant dashboard
