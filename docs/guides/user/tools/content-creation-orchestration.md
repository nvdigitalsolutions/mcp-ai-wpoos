# Content Creation with Multi-Step Orchestration

**Tool:** `save_post`  
**Feature:** Multi-step orchestration mode  
**Status:** Available in latest version

## Quick Start

Enable orchestration for automatic validation:

```php
$result = $tool->execute(array(
    'title'              => 'My Blog Post',
    'content'            => 'Post content goes here...',
    'orchestration_mode' => true,
), $context);
```

## New Parameters

- `orchestration_mode` (boolean): Enable 5-step workflow
- `auto_research` (boolean): Automatically research content topic
- `enhance_content` (boolean): AI-powered content enhancement
- `optimize` (boolean): Post-creation optimization
- `generate_featured_image` (boolean): Auto-generate hero image

## Full Automation Example

```php
$result = $tool->execute(array(
    'title'                   => 'Best Practices for AI Development',
    'content'                 => 'Initial draft about AI development...',
    'orchestration_mode'      => true,
    'auto_research'           => true,
    'enhance_content'         => true,
    'optimize'                => true,
    'generate_featured_image' => true,
), $context);
```

This will:
1. Research the topic online
2. Validate all data (title, content, duplicates)
3. Enhance content with AI (readability, SEO)
4. Create/update the post
5. Generate featured image and optimize SEO

## Benefits

- ✅ Automatic duplicate title detection
- ✅ Content length validation (min 10 chars)
- ✅ Title length validation (max 200 chars)
- ✅ AI-powered content improvement
- ✅ SEO optimization (Rank Math)
- ✅ Featured image generation
- ✅ Detailed error messages

## Backward Compatibility

Default behavior is unchanged. Orchestration is opt-in via the `orchestration_mode` parameter.

## Usage Scenarios

### 1. Create New Post with Validation

```php
$result = $tool->execute(array(
    'title'              => 'New Post Title',
    'content'            => 'Post content...',
    'status'             => 'draft',
    'orchestration_mode' => true,
), $context);
```

### 2. Update Existing Post with Enhancement

```php
$result = $tool->execute(array(
    'post_id'            => 123,
    'content'            => 'Updated content...',
    'orchestration_mode' => true,
    'enhance_content'    => true,
), $context);
```

### 3. Research and Create

```php
$result = $tool->execute(array(
    'title'              => 'WordPress Performance Tips',
    'content'            => 'Basic performance advice...',
    'orchestration_mode' => true,
    'auto_research'      => true,  // Research topic first
    'enhance_content'    => true,  // AI enhancement
), $context);
```

## Error Handling

Orchestration provides detailed error messages:

```json
{
  "error": {
    "code": "orchestration_failed",
    "message": "Post save orchestration failed at step: validate. Content must be at least 10 characters",
    "data": {
      "step": "validate",
      "execution_id": "post_save_abc123..."
    }
  }
}
```

## Response Format

### Legacy Mode
```json
{
  "ID": 123,
  "title": "My Post",
  "status": "draft",
  "permalink": "https://example.com/my-post/"
}
```

### Orchestration Mode
```json
{
  "ID": 123,
  "title": "My Post",
  "status": "draft",
  "permalink": "https://example.com/my-post/",
  "execution_id": "post_save_xyz789...",
  "orchestration": {
    "enabled": true,
    "steps": [
      {"name": "started", "time": "2026-02-13 12:00:00"},
      {"name": "validate", "time": "2026-02-13 12:00:01"},
      {"name": "save", "time": "2026-02-13 12:00:02"},
      {"name": "completed", "time": "2026-02-13 12:00:03"}
    ]
  }
}
```

---

For complete documentation, see the developer guides in `docs/guides/developer/`.
