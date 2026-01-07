# Flowhub API Integration

This document provides an overview of the Flowhub API integration for WordPress MCP AI.

## Overview

Flowhub is a cannabis dispensary POS (Point of Sale) and inventory management system. This integration provides 7 professional-tier tools for interacting with Flowhub's API to manage inventory, orders, customers, and products.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Flowhub API credentials (API Key, Client ID, Client Secret, Location ID)
- `manage_woocommerce` or `manage_options` capability

## Configuration

1. Navigate to **WordPress Admin → Settings → NV oOS → Integrations → Flowhub**
2. Enter your Flowhub credentials
3. Click **Save Changes**

## Available Tools

- `flowhub_get_inventory` - Retrieve inventory data
- `flowhub_get_orders` - Retrieve orders/transactions
- `flowhub_get_customers` - Retrieve customer profiles
- `flowhub_get_products` - Retrieve product catalog
- `flowhub_create_order` - Create new orders
- `flowhub_manage_customer` - Create/update customers
- `flowhub_manage_product` - Create/update products

## API Reference

- **Base URL**: `https://api.flowhub.co`
- **Authentication**: OAuth2 (Client Credentials)
- **Auth Endpoint**: `https://flowhub.auth0.com/oauth/token`
- **API Documentation**: https://flowhub.stoplight.io/docs/public-developer-portal/
