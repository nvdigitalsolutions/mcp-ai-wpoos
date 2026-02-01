# Trade.gov Tariff Rates API Setup

## Overview

The Trade.gov Tariff Rates API integration enables the `get_import_duty` tool to look up import duty rates for products being imported into the United States, Jamaica, or Sri Lanka. This is a **Pro feature**.

## Getting Your API Key

1. Visit the [Trade.gov Developer Portal](https://developer.trade.gov/)
2. Sign up for a developer account if you don't have one
3. Request an API key for the ITA Tariff Rates API
4. Copy your API key for use in WordPress

## Configuring the Plugin

### Location in WordPress Admin

Navigate to: **NV oOS → General Settings → Tools & Features → External Tools → Trade.gov Tariff Rates**

### Step-by-Step Configuration

1. Log into your WordPress admin dashboard
2. Click **NV oOS** in the left sidebar menu
3. Click **General Settings** from the submenu
4. Click the **Tools & Features** tab
5. Scroll down to the **External Tools** section
6. Click the **Trade.gov Tariff Rates** subtab
7. Paste your API key in the **ITA Tariff Rate API Key** field
8. Click **Save Changes** at the bottom of the page

## Using the Import Duty Tool

Once configured, assistants can use the `get_import_duty` tool to look up tariff information:

### Example Query
```
What is the import duty for electronics (HS code 8517) importing into the United States?
```

### Tool Parameters
- **country** (required): Destination country (`united_states`, `jamaica`, or `sri_lanka`)
- **hs_code** (optional): 6-10 digit Harmonized System code
- **description** (optional): Free-form product description
- **max_results** (optional): Number of results to return (1-10, default 5)

Either `hs_code` or `description` must be provided.

## Troubleshooting

### Error: "The tariff service redirected the request"

**Full error message:**
> The tariff service redirected the request. Verify that your Trade.gov API key is valid and stored in NV oOS > General Settings > Tools & Features > External Tools > Trade.gov Tariff Rates.

**Solutions:**
1. Verify your API key is entered correctly (no extra spaces)
2. Confirm the API key is active on the Trade.gov Developer Portal
3. Check that you have saved the settings after entering the key
4. Try regenerating your API key on the Trade.gov portal

### API Key Not Working

If your API key isn't working:
- Ensure you're using the key for the **ITA Tariff Rates API** specifically
- Check that your Trade.gov developer account is in good standing
- Verify that the API key hasn't expired
- Contact Trade.gov developer support if the issue persists

## API Documentation

For more information about the Trade.gov Tariff Rates API:
- [API Documentation](https://developer.trade.gov/ita-tariff-rates-api)
- [Developer Portal](https://developer.trade.gov/)

## Related Documentation

- [Tool Reference](../reference/tools/tool-reference.md) - Complete list of all tools
- [Pro Features](../reference/models/FEATURE-MATRIX-CORE-PRO.md) - Core vs Pro feature comparison
- [Settings Guide](../guides/admin/settings/SETTINGS-ARCHITECTURE-COMPARISON.md) - Settings system overview
