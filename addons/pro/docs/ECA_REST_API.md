# ECA Management REST API Documentation

## Overview

The ECA Management REST API provides standardized HTTP endpoints for managing Extra-Curricular Activities (ECAs) and Students. All endpoints follow WordPress REST API conventions and standards.

## Base URL

```
/wp-json/mcp-ai/v1/
```

## Authentication

All endpoints require WordPress authentication. Use one of the following methods:

- **Cookie Authentication** (for same-origin requests)
- **Application Passwords** (recommended for external applications)
- **OAuth** (for third-party integrations)

## Permissions

- **Read Operations** (GET): Requires `read` capability
- **Write Operations** (POST, PUT/PATCH): Requires `edit_posts` capability
- **Delete Operations** (DELETE): Requires `delete_posts` capability

---

## ECA Endpoints

### List ECAs

**Endpoint:** `GET /wp-json/mcp-ai/v1/ecas`

**Description:** Retrieve a list of Extra-Curricular Activities with optional filtering and pagination.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `eca_type` | string | Filter by type: `club`, `society`, `sport_squad`, `sport_academy`, `activity` |
| `day` | string | Filter by day: `Monday`, `Tuesday`, `Wednesday`, etc. |
| `year_group` | string | Filter by eligible year group |
| `status` | string | Filter by status: `active`, `inactive`, `full`, `cancelled` |
| `is_paid` | boolean | Filter by paid/free activities |
| `has_availability` | boolean | Show only ECAs with available spots |
| `search` | string | Search by name or description |
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Results per page (default: 20, max: 100) |

**Example Request:**

```bash
curl -X GET "https://example.com/wp-json/mcp-ai/v1/ecas?eca_type=club&day=Monday&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response:**

```json
{
  "success": true,
  "ecas": [
    {
      "eca_id": 123,
      "name": "Chess Club",
      "eca_code": "CH-01",
      "type": "club",
      "day": "Monday",
      "start_time": "3:30 PM",
      "end_time": "4:30 PM",
      "venue": "Room 101",
      "year_groups": ["Year 7", "Year 8"],
      "max_students": 20,
      "current_enrollment": 15,
      "available_spots": 5,
      "is_full": false,
      "is_paid": false,
      "teachers": ["Mr. Smith"]
    }
  ],
  "total": 45,
  "page": 1,
  "per_page": 10,
  "total_pages": 5,
  "has_more": true
}
```

---

### Get Single ECA

**Endpoint:** `GET /wp-json/mcp-ai/v1/ecas/{id}`

**Description:** Retrieve detailed information about a specific ECA.

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | ECA ID (required) |

**Example Request:**

```bash
curl -X GET "https://example.com/wp-json/mcp-ai/v1/ecas/123" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response:**

```json
{
  "success": true,
  "eca": {
    "id": 123,
    "name": "Chess Club",
    "description": "Learn strategic thinking and compete in tournaments.",
    "eca_code": "CH-01",
    "eca_type": "club",
    "day": "Monday",
    "start_time": "3:30 PM",
    "end_time": "4:30 PM",
    "venue": "Room 101",
    "year_groups": ["Year 7", "Year 8", "Year 9"],
    "max_students": 20,
    "teachers": ["Mr. Smith", "Ms. Johnson"],
    "cost": 0,
    "currency": "GBP",
    "term": "Yearly",
    "enrolled_count": 15,
    "created_at": "2024-01-15 10:00:00",
    "modified_at": "2024-02-01 14:30:00"
  }
}
```

---

### Create ECA

**Endpoint:** `POST /wp-json/mcp-ai/v1/ecas`

**Description:** Create a new Extra-Curricular Activity.

**Request Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | ECA name |
| `eca_code` | string | No | ECA code/identifier |
| `description` | string | No | ECA description |
| `eca_type` | string | No | Type: `club`, `society`, `sport_squad`, `sport_academy`, `activity` (default: `club`) |
| `day` | string | No | Day of week |
| `start_time` | string | No | Start time (HH:MM AM/PM format) |
| `end_time` | string | No | End time (HH:MM AM/PM format) |
| `venue` | string | No | Venue/location |
| `year_groups` | array | No | Array of eligible year groups |
| `max_students` | integer | No | Maximum capacity (1-200) |
| `teachers` | array | No | Array of teacher names |
| `cost` | number | No | Cost amount |
| `currency` | string | No | Currency code: `GBP`, `USD`, `EUR`, etc. |
| `term` | string | No | Term: `Term 1`, `Term 2`, `Term 3`, `Yearly` |

**Example Request:**

```bash
curl -X POST "https://example.com/wp-json/mcp-ai/v1/ecas" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Drama Club",
    "eca_type": "club",
    "day": "Wednesday",
    "start_time": "3:30 PM",
    "end_time": "5:00 PM",
    "venue": "Drama Studio",
    "year_groups": ["Year 7", "Year 8", "Year 9"],
    "max_students": 25,
    "teachers": ["Ms. Theatre"]
  }'
```

**Example Response:**

```json
{
  "success": true,
  "message": "ECA created successfully.",
  "eca_id": 456
}
```

---

### Update ECA

**Endpoint:** `PUT /wp-json/mcp-ai/v1/ecas/{id}` or `PATCH /wp-json/mcp-ai/v1/ecas/{id}`

**Description:** Update an existing ECA. Only provide fields you want to update.

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | ECA ID (required) |

**Request Body Parameters:** Same as Create ECA, but all fields are optional.

**Example Request:**

```bash
curl -X PUT "https://example.com/wp-json/mcp-ai/v1/ecas/123" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "max_students": 30,
    "venue": "New Room 205"
  }'
```

**Example Response:**

```json
{
  "success": true,
  "message": "ECA updated successfully.",
  "eca_id": 123
}
```

---

### Delete ECA

**Endpoint:** `DELETE /wp-json/mcp-ai/v1/ecas/{id}`

**Description:** Delete an ECA. This action cannot be undone.

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | ECA ID (required) |

**Example Request:**

```bash
curl -X DELETE "https://example.com/wp-json/mcp-ai/v1/ecas/123" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response:**

```json
{
  "success": true,
  "message": "ECA deleted successfully.",
  "eca_id": 123
}
```

---

## Student Endpoints

### List Students

**Endpoint:** `GET /wp-json/mcp-ai/v1/students`

**Description:** Retrieve a list of students with optional filtering and pagination.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `year_group` | string | Filter by year group |
| `house` | string | Filter by house |
| `search` | string | Search by student name |
| `per_page` | integer | Results per page (default: 20, max: 100) |
| `page` | integer | Page number (default: 1) |

**Example Request:**

```bash
curl -X GET "https://example.com/wp-json/mcp-ai/v1/students?year_group=Year%208&per_page=20" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response:**

```json
{
  "success": true,
  "students": [
    {
      "id": 789,
      "name": "Jane Smith",
      "first_name": "Jane",
      "last_name": "Smith",
      "year_group": "Year 8",
      "house": "Gryffindor",
      "email": "jane.smith@school.edu",
      "enrollment_count": 3
    }
  ],
  "pagination": {
    "total": 156,
    "total_pages": 8,
    "current_page": 1,
    "per_page": 20
  }
}
```

---

### Get Single Student

**Endpoint:** `GET /wp-json/mcp-ai/v1/students/{id}`

**Description:** Retrieve detailed information about a specific student.

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Student ID (required) |

**Example Request:**

```bash
curl -X GET "https://example.com/wp-json/mcp-ai/v1/students/789" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response:**

```json
{
  "success": true,
  "student": {
    "id": 789,
    "name": "Jane Smith",
    "first_name": "Jane",
    "last_name": "Smith",
    "year_group": "Year 8",
    "house": "Gryffindor",
    "email": "jane.smith@school.edu",
    "isams_id": "STU-2024-789",
    "enrollments": [
      {
        "eca_id": 123,
        "eca_name": "Chess Club",
        "day": "Monday"
      },
      {
        "eca_id": 456,
        "eca_name": "Drama Club",
        "day": "Wednesday"
      }
    ]
  }
}
```

---

### Create Student

**Endpoint:** `POST /wp-json/mcp-ai/v1/students`

**Description:** Create a new student record.

**Request Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `first_name` | string | Yes | Student first name |
| `last_name` | string | Yes | Student last name |
| `year_group` | string | No | Year group (e.g., "Year 7") |
| `house` | string | No | House name |
| `email` | string | No | Student email address |
| `isams_id` | string | No | iSAMS student ID |

**Example Request:**

```bash
curl -X POST "https://example.com/wp-json/mcp-ai/v1/students" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "year_group": "Year 7",
    "house": "Ravenclaw",
    "email": "john.doe@school.edu"
  }'
```

**Example Response:**

```json
{
  "success": true,
  "message": "Student created successfully.",
  "student_id": 890
}
```

---

### Update Student

**Endpoint:** `PUT /wp-json/mcp-ai/v1/students/{id}` or `PATCH /wp-json/mcp-ai/v1/students/{id}`

**Description:** Update an existing student. Only provide fields you want to update.

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Student ID (required) |

**Request Body Parameters:** Same as Create Student, but all fields are optional.

**Example Request:**

```bash
curl -X PUT "https://example.com/wp-json/mcp-ai/v1/students/789" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "year_group": "Year 9",
    "email": "jane.smith.new@school.edu"
  }'
```

**Example Response:**

```json
{
  "success": true,
  "message": "Student updated successfully.",
  "student_id": 789
}
```

---

### Delete Student

**Endpoint:** `DELETE /wp-json/mcp-ai/v1/students/{id}`

**Description:** Delete a student record. This action cannot be undone.

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Student ID (required) |

**Example Request:**

```bash
curl -X DELETE "https://example.com/wp-json/mcp-ai/v1/students/789" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Example Response:**

```json
{
  "success": true,
  "message": "Student deleted successfully.",
  "student_id": 789
}
```

---

## Error Responses

All endpoints return errors in the standard WordPress REST API format:

```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to view ECAs or students.",
  "data": {
    "status": 403
  }
}
```

### Common Error Codes

| Code | Status | Description |
|------|--------|-------------|
| `rest_forbidden` | 403 | Insufficient permissions |
| `rest_tool_not_available` | 500 | Required tool class not loaded |
| `wp_mcp_ai_missing_id` | 400 | Required ID parameter missing |
| `wp_mcp_ai_invalid_eca` | 404 | ECA not found |
| `wp_mcp_ai_invalid_student` | 404 | Student not found |

---

## Rate Limiting

API endpoints follow WordPress's built-in rate limiting. High-volume applications should:

- Cache responses when possible
- Use pagination efficiently
- Implement exponential backoff on errors

---

## Standards Compliance

This API follows:

- **WordPress REST API Handbook** - Official WordPress REST API standards
- **HTTP/1.1 RFC 7231** - Standard HTTP methods (GET, POST, PUT, DELETE)
- **JSON API Specification** - Consistent JSON response structure
- **OAuth 2.0** - Secure authentication (when using OAuth)

---

## Examples with Different Languages

### JavaScript (Fetch API)

```javascript
// List ECAs
fetch('https://example.com/wp-json/mcp-ai/v1/ecas?day=Monday', {
  method: 'GET',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN'
  }
})
.then(response => response.json())
.then(data => console.log(data));

// Create ECA
fetch('https://example.com/wp-json/mcp-ai/v1/ecas', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    name: 'Art Club',
    eca_type: 'club',
    day: 'Friday'
  })
})
.then(response => response.json())
.then(data => console.log(data));
```

### Python (Requests)

```python
import requests

# List students
url = 'https://example.com/wp-json/mcp-ai/v1/students'
headers = {'Authorization': 'Bearer YOUR_TOKEN'}
params = {'year_group': 'Year 8', 'per_page': 10}

response = requests.get(url, headers=headers, params=params)
students = response.json()

# Create student
url = 'https://example.com/wp-json/mcp-ai/v1/students'
data = {
    'first_name': 'Alice',
    'last_name': 'Wonder',
    'year_group': 'Year 7'
}

response = requests.post(url, headers=headers, json=data)
result = response.json()
```

### PHP (WordPress HTTP API)

```php
// List ECAs
$response = wp_remote_get( 'https://example.com/wp-json/mcp-ai/v1/ecas?eca_type=club', array(
    'headers' => array(
        'Authorization' => 'Bearer YOUR_TOKEN'
    )
) );

$ecas = json_decode( wp_remote_retrieve_body( $response ), true );

// Update ECA
$response = wp_remote_request( 'https://example.com/wp-json/mcp-ai/v1/ecas/123', array(
    'method' => 'PUT',
    'headers' => array(
        'Authorization' => 'Bearer YOUR_TOKEN',
        'Content-Type' => 'application/json'
    ),
    'body' => json_encode( array(
        'max_students' => 35
    ) )
) );
```

---

## Testing

You can test the API endpoints using:

- **Postman** - Import the examples above as a collection
- **curl** - Use the command-line examples provided
- **WP-CLI** - Use `wp rest` commands
- **Browser DevTools** - Test authenticated requests from your WordPress site

---

## Support

For issues or questions:

- Check the main plugin documentation
- Review WordPress REST API Handbook: https://developer.wordpress.org/rest-api/
- Contact support: support@nvdigitalsolutions.com
