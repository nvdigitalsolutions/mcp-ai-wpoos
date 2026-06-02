# Flowhub API Integration

This document provides an overview of the Flowhub API integration for WordPress MCP AI.

## Overview

Flowhub is a cannabis dispensary POS (Point of Sale) and inventory management system. This integration provides 7 professional-tier tools for interacting with Flowhub's API to manage inventory, orders, customers, and products.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Flowhub API credentials (Client ID and API Key)
- `manage_woocommerce` or `manage_options` capability

## Configuration

### Method 1: Remote Sites (Recommended)

1. Navigate to **WordPress Admin → NV oOS → Remote Sites**
2. Click **Add New Connection**
3. Select **Flowhub (POS/Retail)** as the connection type
4. Enter your Flowhub credentials:
   - **Client ID (clientId header)**: Your Flowhub client identifier
   - **API Key (key header)**: Your Flowhub API key
   - **Location ID** (Optional): Specific location/dispensary ID for filtering
5. Click **Save Connection**
6. Click **Test Connection** to verify the credentials

### Method 2: Legacy Settings

1. Navigate to **WordPress Admin → Settings → NV oOS → Integrations → Flowhub**
2. Enter your Flowhub credentials
3. Click **Save Changes**

## Obtaining API Credentials

To obtain your Flowhub API credentials:

1. Contact Flowhub support at **api@flowhub.com**
2. Or visit: https://flowhub.com/api-integration-request
3. You will receive:
   - **Client ID** (clientId)
   - **API Key** (key)
   - **Location ID(s)** for your dispensaries

## Available Tools

- `flowhub_get_inventory` - Retrieve non-zero inventory data
- `flowhub_get_orders` - Retrieve orders/transactions
- `flowhub_get_customers` - Retrieve customer profiles
- `flowhub_get_products` - Retrieve product catalog
- `flowhub_create_order` - Create new orders
- `flowhub_manage_customer` - Create/update customers
- `flowhub_manage_product` - Create/update products

## Authentication

Flowhub API uses **header-based authentication** with the following headers:

- **clientId**: Your client identifier
- **key**: Your API key
- **Accept**: application/json

No OAuth2 flow is required. Credentials are sent directly as headers with each request.

## API Reference

- **Base URL**: `https://api.flowhub.co`
- **Authentication**: Header-based (clientId, key)
- **API Documentation**: https://flowhub.stoplight.io/docs/public-developer-portal/
- **Inventory Endpoint**: `/v0/inventoryNonZero` (returns items with non-zero quantity)

## Example Usage

```php
// Using Remote Sites connection
$client = new WP_MCP_AI_Flowhub_Client( 'conn_flowhub123' );
$inventory = $client->get_inventory( array( 'limit' => 20 ) );

// Using legacy settings
$client = new WP_MCP_AI_Flowhub_Client();
$inventory = $client->get_inventory();
```

## Troubleshooting

### Authentication Errors (401 Unauthorized)

- Verify your Client ID and API Key are correct
- Ensure there are no extra spaces in your credentials
- Contact Flowhub support to verify your API access is active

### Empty Inventory Results

- Check that you have inventory items with non-zero quantities
- Verify the Location ID if you're filtering by location
- Use the test connection feature to verify API access

## Security Notes

- API credentials are **encrypted** when stored in Remote Sites connections
- Never commit API credentials to version control
- Rotate API keys regularly
- Use placeholder values in tests and examples
