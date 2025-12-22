# Architecture Plan: Google Gemini & OpenAI Advanced Tools

## Executive Summary
This document outlines the architecture for implementing advanced AI capabilities for both Google Gemini and OpenAI APIs while maintaining strict Separation of Concerns (SoC) principles.

## Requirements
Based on Google Gemini API documentation:
1. **Video Generation** - Veo 3.1 model for video creation
2. **Music Generation** - Gemini music generation API
3. **Imagen 3** - Advanced image generation (separate from existing Gemini image tool)
4. **Embeddings** - Text embeddings for semantic search
5. **Gemini 3 with Thinking** - High thinking mode for complex reasoning

Based on OpenAI API parity:
1. **Video Generation** - OpenAI Sora (when available) or alternative approach
2. **Music Generation** - OpenAI audio generation capabilities
3. **DALL-E 3 Enhanced** - Enhanced image generation tool
4. **Embeddings** - OpenAI text embeddings (already partially exists)
5. **o1/o3 Reasoning** - OpenAI reasoning models

## Architectural Principles (SoC)

### Layer 1: Service Classes (Business Logic)
**Location:** `includes/services/`

Services handle:
- API communication
- Request/response transformation
- Rate limiting logic
- Error handling
- Data validation
- File management

**No WordPress-specific logic** - Services are reusable across contexts.

### Layer 2: Tool Classes (Integration Layer)
**Location:** `includes/tools/`

Tools handle:
- WordPress integration (attachments, users, capabilities)
- Parameter schema definition for LLMs
- Context validation (user permissions, site access)
- Result formatting for AI assistants
- WordPress file storage
- Tool metadata (slugs, descriptions, shortcuts)

Tools **delegate** to services for actual work.

### Layer 3: Client Classes (API Abstraction)
**Location:** `includes/`

Existing clients to extend:
- `WP_MCP_AI_Gemini_Client` - Core Gemini API client
- `WP_MCP_AI_OpenAI_Client` - Core OpenAI API client

Clients handle:
- Low-level HTTP communication
- Authentication
- Response normalization
- Common API patterns

## Implementation Plan

### Phase 1: Service Layer Implementation

#### 1.1 Google Gemini Services

**File: `includes/services/class-wp-mcp-ai-gemini-video-service.php`**
```
Responsibilities:
- Generate videos using Veo 3.1
- Handle video generation parameters (duration, aspect ratio, fps)
- Process video responses
- Extract video data from API responses
- Manage video MIME types
```

**File: `includes/services/class-wp-mcp-ai-gemini-music-service.php`**
```
Responsibilities:
- Generate music using Gemini music API
- Handle music parameters (duration, style, tempo, key)
- Process audio responses
- Extract audio data from API responses
- Manage audio MIME types
```

**File: `includes/services/class-wp-mcp-ai-imagen-service.php`**
```
Responsibilities:
- Generate images using Imagen 3
- Handle advanced image parameters (safety filters, style)
- Process image responses
- Extract image data from API responses
- Separate from existing Gemini image generation
```

**File: `includes/services/class-wp-mcp-ai-gemini-embeddings-service.php`**
```
Responsibilities:
- Generate text embeddings using Gemini
- Handle batch embedding requests
- Process embedding responses
- Normalize embedding vectors
- Support different embedding models
```

**File: `includes/services/class-wp-mcp-ai-gemini-thinking-service.php`**
```
Responsibilities:
- Handle Gemini 3 with high thinking mode
- Manage thinking tokens/budget
- Process reasoning traces
- Extract final answers from thinking responses
```

#### 1.2 OpenAI Services

**File: `includes/services/class-wp-mcp-ai-openai-video-service.php`**
```
Responsibilities:
- Generate videos using OpenAI capabilities
- Handle Sora API when available
- Fallback to image sequences if needed
- Process video responses
- Extract video data
```

**File: `includes/services/class-wp-mcp-ai-openai-music-service.php`**
```
Responsibilities:
- Generate music using OpenAI audio generation
- Handle music parameters
- Process audio responses
- Extract audio data
- Support different audio formats
```

**File: `includes/services/class-wp-mcp-ai-openai-embeddings-service.php`**
```
Responsibilities:
- Generate embeddings using OpenAI models
- Handle batch embedding requests
- Support different embedding models (text-embedding-3-small, large)
- Process embedding responses
- Normalize embedding vectors
```

**File: `includes/services/class-wp-mcp-ai-openai-reasoning-service.php`**
```
Responsibilities:
- Handle o1/o3 reasoning models
- Manage reasoning tokens
- Process reasoning traces
- Extract final answers
- Handle extended thinking time
```

### Phase 2: Client Extensions

**Extend: `includes/class-wp-mcp-ai-gemini-client.php`**
```
Add methods:
- generate_video()
- generate_music()
- generate_imagen_image()
- create_embeddings()
- create_thinking_completion()
```

**Extend: `includes/class-wp-mcp-ai-openai-client.php`**
```
Add methods:
- generate_video() [when Sora available]
- generate_music()
- create_embeddings_v3()
- create_reasoning_completion()
```

### Phase 3: Tool Layer Implementation

#### 3.1 Google Gemini Tools

**File: `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`**
```
Implements:
- WP_MCP_AI_Tool_Interface
- WP_MCP_AI_Tool_Capability_Flags_Interface
- WP_MCP_AI_Tool_Model_Requirements_Interface
- WP_MCP_AI_Tool_Rules_Interface
- WP_MCP_AI_Tool_LLM_Sanitizer_Interface

Handles:
- User authentication & capabilities
- Video attachment storage
- Parameter validation
- Result formatting for LLM
- Usage tracking
```

**File: `includes/tools/class-wp-mcp-ai-tool-generate-music.php`**
```
Similar structure for music generation
Stores as audio attachment in Media Library
```

**File: `includes/tools/class-wp-mcp-ai-tool-generate-imagen-image.php`**
```
Separate from existing Gemini image tool
Uses Imagen 3 specific features
```

**File: `includes/tools/class-wp-mcp-ai-tool-generate-embeddings.php`**
```
Dual provider support (Gemini & OpenAI)
Stores embeddings in post meta or custom table
Supports batch operations
```

**File: `includes/tools/class-wp-mcp-ai-tool-thinking-chat.php`**
```
Enhanced chat with thinking mode
Shows reasoning process
Dual provider support (Gemini 3 & OpenAI o1)
```

#### 3.2 OpenAI Tools

**File: `includes/tools/class-wp-mcp-ai-tool-generate-openai-video.php`**
```
OpenAI video generation tool
Parallel structure to Gemini video tool
```

**File: `includes/tools/class-wp-mcp-ai-tool-generate-openai-music.php`**
```
OpenAI music generation tool
```

**File: `includes/tools/class-wp-mcp-ai-tool-generate-dalle3-enhanced.php`**
```
Enhanced DALL-E 3 tool with new features
Separate from existing OpenAI image tool
```

### Phase 4: Registration & Configuration

**Update: `includes/class-wp-mcp-ai-tool-registry.php`**
```php
Add to $base_tools array:
- 'WP_MCP_AI_Tool_Generate_Veo_Video'
- 'WP_MCP_AI_Tool_Generate_Music'
- 'WP_MCP_AI_Tool_Generate_Imagen_Image'
- 'WP_MCP_AI_Tool_Generate_Embeddings'
- 'WP_MCP_AI_Tool_Thinking_Chat'
- 'WP_MCP_AI_Tool_Generate_OpenAI_Video'
- 'WP_MCP_AI_Tool_Generate_OpenAI_Music'
- 'WP_MCP_AI_Tool_Generate_DALLE3_Enhanced'
```

**Update: Tool group map**
```php
Add to get_tool_group_map():
'generate_veo_video'          => 'external-tools',
'generate_music'              => 'external-tools',
'generate_imagen_image'       => 'external-tools',
'generate_embeddings'         => 'external-tools',
'thinking_chat'               => 'external-tools',
'generate_openai_video'       => 'external-tools',
'generate_openai_music'       => 'external-tools',
'generate_dalle3_enhanced'    => 'external-tools',
```

### Phase 5: Rate Limiting

**File: `includes/class-wp-mcp-ai-rate-limit-manager.php` (extend existing)**
```
Add rate limit profiles per Google's documentation:
- Veo 3.1: 15 RPM, 100 RPH
- Music generation: 10 RPM, 50 RPH
- Imagen 3: 20 RPM, 200 RPH
- Embeddings: 1500 RPM, 15000 RPH
- Gemini 3 thinking: 5 RPM, 50 RPH (higher cost)

Add OpenAI rate limits:
- Sora: TBD (when available)
- Music: TBD
- DALL-E 3: 50 RPM
- Embeddings: 3000 RPM
- o1: 20 RPM (higher cost)
```

### Phase 6: Testing

**Create test files:**
```
tests/test-gemini-video-service.php
tests/test-gemini-music-service.php
tests/test-imagen-service.php
tests/test-gemini-embeddings-service.php
tests/test-gemini-thinking-service.php
tests/test-openai-video-service.php
tests/test-openai-music-service.php
tests/test-openai-embeddings-service.php
tests/test-openai-reasoning-service.php
tests/test-veo-video-tool.php
tests/test-music-generation-tool.php
tests/test-imagen-tool.php
tests/test-embeddings-tool.php
tests/test-thinking-chat-tool.php
```

## SoC Benefits

### 1. Service Classes are Reusable
```php
// Can be used outside WordPress context
$video_service = new WP_MCP_AI_Gemini_Video_Service();
$result = $video_service->generate_video( $prompt, $options );
```

### 2. Tools Handle WordPress Integration
```php
// Tool handles WP-specific concerns
$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();
$result = $tool->execute( $arguments, $context );
// - Checks user capabilities
// - Stores as WP attachment
// - Formats for LLM
```

### 3. Easy to Test
```php
// Mock service for testing tool
$mock_service = $this->createMock( WP_MCP_AI_Gemini_Video_Service::class );
$mock_service->method( 'generate_video' )->willReturn( $fake_video );
```

### 4. Easy to Swap Providers
```php
// Tool can switch between providers
if ( 'gemini' === $provider ) {
    $service = new WP_MCP_AI_Gemini_Video_Service();
} else {
    $service = new WP_MCP_AI_OpenAI_Video_Service();
}
```

### 5. Clear Responsibilities
- **Services**: API communication, data processing
- **Tools**: WordPress integration, LLM formatting
- **Clients**: Low-level HTTP, authentication
- **Registry**: Tool discovery, registration

## API Documentation References

### Google Gemini
- Video (Veo 3.1): https://ai.google.dev/gemini-api/docs/video?example=dialogue
- Music: https://ai.google.dev/gemini-api/docs/music-generation
- Imagen 3: https://ai.google.dev/gemini-api/docs/imagen
- Embeddings: https://ai.google.dev/gemini-api/docs/embeddings
- Gemini 3: https://ai.google.dev/gemini-api/docs/gemini-3?thinking=high
- Rate Limits: https://ai.google.dev/gemini-api/docs/rate-limits

### OpenAI
- Video: Sora API (when available)
- Music: Audio generation API
- Images: DALL-E 3
- Embeddings: text-embedding-3-small/large
- Reasoning: o1-preview, o1-mini, o3

## Implementation Order

1. **Week 1**: Service layer for Gemini (5 services)
2. **Week 2**: Service layer for OpenAI (4 services)
3. **Week 3**: Tool layer for Gemini (5 tools)
4. **Week 4**: Tool layer for OpenAI (4 tools)
5. **Week 5**: Client extensions, rate limiting
6. **Week 6**: Testing, documentation, refinement

## File Count Summary

**New Service Files**: 9
- 5 Gemini services
- 4 OpenAI services

**New Tool Files**: 9
- 5 Gemini tools
- 4 OpenAI tools (3 unique + 1 shared embeddings)

**Modified Files**: 3
- WP_MCP_AI_Gemini_Client (extend methods)
- WP_MCP_AI_OpenAI_Client (extend methods)
- WP_MCP_AI_Tool_Registry (register new tools)

**New Test Files**: 14
- 9 service tests
- 5 tool tests

**Total New Files**: 32
**Total Modified Files**: 3

## Success Criteria

1. ✅ All services are independent of WordPress
2. ✅ All tools delegate business logic to services
3. ✅ No code duplication between Gemini and OpenAI implementations
4. ✅ All tools have comprehensive tests
5. ✅ Rate limiting properly implemented per API guidelines
6. ✅ All tools registered and discoverable
7. ✅ All tools work for professionals/assistants
8. ✅ Documentation complete and accurate
