# iSAMS Query Tool Usage Guide

The iSAMS Query tool (`isams_query`) provides AI assistants with read-only access to iSAMS School Management System data through authenticated REST API integration.

## Overview

iSAMS (Interactive School Administration and Management System) is a comprehensive school information management system used by independent schools worldwide. This tool enables assistants to query various aspects of school data including students, staff, organizational structures, and academic information.

## Configuration

### Prerequisites

1. Active iSAMS subscription with API access enabled
2. API credentials from your iSAMS administrator:
   - API URL (e.g., `https://yourschool.isams.cloud/`)
   - API Key
   - API Secret

### Setup in WordPress

1. Navigate to **Settings → NV oOS**
2. Scroll to the **iSAMS Configuration** section
3. Enter your credentials:
   - **API URL**: Your iSAMS instance URL
   - **API Key**: Your API key
   - **API Secret**: Your API secret
4. Click **Save Changes**

Once configured, the tool will automatically appear in the assistant's tool palette.

## Available Endpoints

The tool supports querying the following iSAMS data types:

| Endpoint | Description | Data Returned |
|----------|-------------|---------------|
| `pupils` | Current students | Student records with enrollment details |
| `employees` | Staff members | Employee records with HR information |
| `departments` | School departments | Department structure and details |
| `houses` | School houses | House system organization |
| `terms` | Academic terms | Term dates and academic calendar |
| `subjects` | Teaching subjects | Subject catalog and details |
| `year_groups` | Year groups | Year group structure and details |
| `admission_applicants` | Admissions | Applicant records and status |

## Usage Examples

### Query All Current Pupils

```
Please get a list of all current pupils from iSAMS.
```

The assistant will use:
```json
{
  "endpoint": "pupils",
  "page": 1,
  "limit": 20
}
```

### Query Specific Student by ID

```
Can you get the details for pupil with ID 12345?
```

The assistant will use:
```json
{
  "endpoint": "pupils",
  "id": "12345"
}
```

### Query Staff Members with Pagination

```
Show me the first 50 employees from iSAMS.
```

The assistant will use:
```json
{
  "endpoint": "employees",
  "page": 1,
  "limit": 50
}
```

### Query School Departments

```
What departments does the school have?
```

The assistant will use:
```json
{
  "endpoint": "departments"
}
```

### Query Academic Terms

```
Show me the current academic terms in iSAMS.
```

The assistant will use:
```json
{
  "endpoint": "terms"
}
```

## Response Format

All successful responses follow this structure:

```json
{
  "success": true,
  "data": {
    // iSAMS API response data
    // Structure varies by endpoint
  }
}
```

For paginated list queries, the response typically includes:

```json
{
  "success": true,
  "data": {
    "pupils": [...],  // or employees, departments, etc.
    "page": 1,
    "totalPages": 5,
    "totalCount": 100
  }
}
```

## Security and Permissions

- **Capability Required**: `read` - Users must have at least read access to WordPress
- **API Authentication**: Bearer token authentication with automatic token caching (55-minute TTL)
- **Read-Only Access**: Tool only performs GET requests; no data modification is possible
- **Pro Feature**: This tool is part of the Pro addon and requires Pro activation

## Error Handling

The tool provides detailed error messages for common issues:

### Missing Configuration

```
Error: iSAMS API credentials are not configured. Please configure them in Settings.
```

**Solution**: Complete the configuration steps above.

### Authentication Failed

```
Error: Failed to authenticate with iSAMS: Invalid credentials
```

**Solution**: Verify your API key and secret are correct.

### Invalid Endpoint

```
Error: Invalid iSAMS endpoint: xyz
```

**Solution**: Use one of the supported endpoints listed above.

### Permission Denied

```
Error: You do not have permission to query iSAMS.
```

**Solution**: Ensure the user has at least `read` capability in WordPress.

## Integration Examples

### Student Attendance Report

```
Assistant, can you create a report of all pupils in Year 7 from iSAMS?
```

The assistant will:
1. Query pupils from iSAMS
2. Filter for Year 7 students
3. Generate a formatted report

### Staff Directory

```
Generate a staff directory listing all employees with their departments.
```

The assistant will:
1. Query employees from iSAMS
2. Query departments from iSAMS
3. Combine the data into a directory format

### Academic Calendar

```
What are the term dates for this academic year?
```

The assistant will:
1. Query terms from iSAMS
2. Present the term dates in a readable format

## Technical Details

### Authentication Flow

1. Tool requests access token from iSAMS authentication endpoint
2. Token is cached for 55 minutes (default token lifetime is 60 minutes)
3. Cached token is reused for subsequent requests
4. Token is automatically refreshed when expired

### Pagination

- **Default Page Size**: 20 records
- **Maximum Page Size**: 100 records
- **Page Parameter**: 1-indexed (first page is 1)

### Rate Limiting

The tool respects iSAMS API rate limits. If you encounter rate limit errors:
- Reduce query frequency
- Increase time between requests
- Use pagination instead of fetching all records at once

## Troubleshooting

### Connection Issues

If you experience connection problems:

1. Verify your iSAMS URL is correct and accessible
2. Check that your WordPress server can make outbound HTTPS requests
3. Ensure your firewall allows connections to iSAMS servers
4. Verify your API credentials are still valid

### Data Inconsistencies

If data appears incorrect or outdated:

1. Check your iSAMS instance directly
2. Verify the endpoint you're querying is correct
3. Clear WordPress transient cache if needed
4. Contact your iSAMS administrator

## PHP Library Reference

This tool is designed to work with the iSAMS REST API directly without requiring the `spkm/isams` PHP library. However, for advanced integrations or custom implementations, you may wish to reference:

- GitHub: https://github.com/cranleighschool/isams-php
- Packagist: https://packagist.org/packages/spkm/isams
- iSAMS API Documentation: https://developer.isams.com/

## Support

For issues related to:
- **Tool functionality**: Open an issue in the mcp-ai-wpoos repository
- **iSAMS API access**: Contact your iSAMS administrator
- **API credentials**: Contact iSAMS support at support@isams.com

## Version History

- **v1.0.0** (January 2025) - Initial release
  - Support for 8 core iSAMS endpoints
  - Automatic token management and caching
  - Pagination support
  - Read-only access controls
