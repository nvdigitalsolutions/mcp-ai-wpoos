# Video Analysis Implementation Roadmap

## Current Status: Phase 3 Complete (OpenAI Video Support via Frame Extraction)

### What's Implemented ✅

**Phase 1: Tool Foundation & Architecture** ✅
- [x] Video analysis tool skeleton (`analyze_video`)
- [x] Video caption tool skeleton (`generate_video_caption`)
- [x] Tool registration in registry
- [x] Capability flag `requires-video-model`
- [x] Parameter validation and permissions
- [x] Comprehensive test suite (15 tests)
- [x] Documentation and usage guides
- [x] Error handling with helpful messages

**Phase 2: Gemini File API Integration** ✅ **COMPLETE**
- [x] Create `WP_MCP_AI_Gemini_File_Service` (SoC-compliant service layer)
  - [x] `upload_file( $file_path, $mime_type, $display_name )` method
  - [x] `get_file_status( $file_name )` method
  - [x] `wait_for_processing( $file_name, $timeout = 300 )` method
  - [x] `delete_file( $file_name )` method
  - [x] Proper error handling and logging
  - [x] Rate limiting consideration

- [x] Enhance `WP_MCP_AI_Gemini_Client` to support fileData
  - [x] Add file part formatting to `build_payload()`
  - [x] Handle `type: 'file'` content segments via `extract_file_parts()`
  - [x] Update message normalization for files via `format_file_part()`

- [x] Update video analysis tools
  - [x] Use Gemini File Service for uploads
  - [x] Handle attachment downloads from WordPress
  - [x] Implement cleanup on completion
  - [x] Support both attachment_id and video_url parameters

- [x] Testing
  - [x] Unit tests for file service (18 tests)
  - [x] Unit tests for enhanced Gemini Client (15 tests)
  - [x] All tests passing
  - [x] All code passes WPCS linting

**Phase 2.1: File Management (Optional Enhancement)** ✅ **COMPLETE**
- [x] File caching to avoid re-uploading same videos
  - [x] Cache key generation based on URL or attachment ID
  - [x] Transient-based caching with 24-hour expiration
  - [x] Cache verification before reuse
- [x] File tracking for lifecycle management
  - [x] Track uploaded files in WordPress options
  - [x] Store upload timestamps for age-based cleanup
  - [x] Handle both cached and expired tracked files
- [x] Automatic cleanup of old files
  - [x] Cron job scheduled daily (`wp_mcp_ai_cleanup_gemini_files`)
  - [x] Cleanup files older than 24 hours
  - [x] Remove both Gemini files and local cache entries
- [x] Comprehensive error handling
  - [x] Graceful handling of upload failures
  - [x] Proper logging at all stages
  - [x] Transient expiration handling

**Phase 3: OpenAI Video Support via Frame Extraction** ✅ **COMPLETE**

For OpenAI GPT-4o, video analysis via frame extraction:

1. **Extract key frames from video** ✅
   - Uses FFmpeg for video frame extraction
   - Selects frames at regular intervals
   - Configurable frame count (default 10, max 20)
   - Extracts high-quality JPEG frames

2. **Send frames as image_url array** ✅
   - Converts frames to base64 data URLs
   - Builds proper message content structure
   - Sends to OpenAI Vision API with GPT-4o
   - Handles response parsing and error handling

**Implementation Tasks:** ✅ **ALL COMPLETE**

- [x] Create `WP_MCP_AI_Video_Frame_Extractor_Service`
  - [x] Check for FFmpeg availability
  - [x] Extract frames at intervals
  - [x] Optimize frame selection (key frames)
  - [x] Handle various video codecs
  - [x] Get video duration using FFprobe
  - [x] Convert frames to base64 data URLs
  - [x] Clean up temporary frames after use

- [x] Update video tools
  - [x] Implement `analyze_with_openai` method
  - [x] Route OpenAI requests to frame extractor
  - [x] Upload frames as base64 data URLs
  - [x] Clean up frames after analysis
  - [x] Proper error handling for missing FFmpeg

- [x] Fallback handling
  - [x] Gracefully handle missing FFmpeg
  - [x] Provide helpful error messages
  - [x] Suggest installation instructions

- [x] Testing
  - [x] Comprehensive unit tests for frame extractor (17 tests)
  - [x] Integration tests for OpenAI video analysis (12 tests)
  - [x] Test FFmpeg availability checking
  - [x] Test frame extraction and cleanup
  - [x] Test error scenarios (missing FFmpeg, invalid video, etc.)

**Current Behavior:**
- ✅ Video analysis is fully functional via Gemini
- ✅ Automatic file upload to Gemini File API
- ✅ Polling for processing completion
- ✅ File cleanup after analysis
- ✅ Support for WordPress attachments
- ✅ Support for remote video URLs
- ✅ Comprehensive error handling
- ✅ File caching to avoid duplicate uploads
- ✅ Automatic cleanup via daily cron job
- ✅ **NEW:** OpenAI video analysis via frame extraction
- ✅ **NEW:** FFmpeg integration for frame extraction
- ✅ **NEW:** Base64 data URL conversion for frames
- ✅ **NEW:** Automatic cleanup of extracted frames

### Implementation Status

**Completed Phases:** Phase 1 ✅, Phase 2 ✅, Phase 2.1 ✅, Phase 3 ✅
**Lines of Code Added:** ~2,800 (includes caching, cleanup, and frame extraction)
**Test Coverage:** 83 unit tests (54 from Phase 2 + 29 from Phase 3)
**Services Added:** 3 (Gemini File Service, Video Analysis Service, Frame Extractor Service)
**Client Enhancements:** 2 new methods (file support in Gemini Client)
**Tools Updated:** 2 (analyze_video, generate_video_caption)
**Cron Jobs Added:** 2 (Gemini file cleanup, OpenAI file cleanup)

### What's Not Yet Implemented ⚠️

**Phase 3: OpenAI Video Support via Frame Extraction** ✅ **COMPLETE**

All Phase 3 tasks have been completed:
- ✅ FFmpeg integration for frame extraction
- ✅ Frame extractor service with configurable settings
- ✅ OpenAI video analysis implementation
- ✅ Base64 data URL conversion for frames
- ✅ Automatic cleanup of temporary frames
- ✅ Comprehensive test coverage (29 unit tests)
- ✅ Error handling for missing FFmpeg
- ✅ Helpful error messages with installation instructions

**Estimated Effort:** 2-3 weeks (Completed)
**Priority:** High - **DONE**

---

For OpenAI GPT-4o, video analysis requires extracting frames:

1. **Extract key frames from video**
   - Use FFmpeg or similar
   - Select representative frames (e.g., every 2 seconds)
   - Limit to reasonable number (e.g., 10-20 frames)

2. **Send frames as image_url array**
   ```php
   {
     "messages": [{
       "role": "user",
       "content": [
         { "type": "text", "text": "Analyze these video frames" },
         { "type": "image_url", "image_url": { "url": "frame1.jpg" } },
         { "type": "image_url", "image_url": { "url": "frame2.jpg" } },
         // ... more frames
       ]
     }]
   }
   ```

**Implementation Tasks:**

- [ ] Create `WP_MCP_AI_Video_Frame_Extractor_Service`
  - [ ] Check for FFmpeg availability
  - [ ] Extract frames at intervals
  - [ ] Optimize frame selection (key frames)
  - [ ] Handle various video codecs

- [ ] Update video tools
  - [ ] Route OpenAI requests to frame extractor
  - [ ] Upload frames temporarily
  - [ ] Clean up frames after analysis

- [ ] Fallback handling
  - [ ] Gracefully handle missing FFmpeg
  - [ ] Provide alternative (manual frame upload?)

**Estimated Effort:** 2-3 weeks
**Risk:** High (FFmpeg dependency, server resources)

---

### Phase 4: Enhanced Features (Future)

**Video-Specific Capabilities:**
- [ ] Timestamp-specific analysis ("What happens at 0:30?")
- [ ] Scene detection and segmentation
- [ ] Audio transcription integration (Whisper)
- [ ] Multi-language subtitle generation
- [ ] Video summarization with chapters
- [ ] Action/object tracking over time

**Performance Optimizations:**
- [ ] Video thumbnail caching
- [ ] Incremental analysis (analyze new videos only)
- [ ] Background processing for large videos
- [ ] CDN integration for video delivery

**Cost Management:**
- [ ] Video length limits by user role
- [ ] Token estimation before processing
- [ ] Budget alerts for video analysis
- [ ] Batch processing for efficiency

---

## Architecture Considerations (SoC)

### Service Layer (Recommended Approach)

Following the SoC principles established in Phases 1-3:

```
Tools (Presentation)
   ↓
Services (Business Logic)
   ↓
Clients (External APIs)
   ↓
Repositories (Data Storage)
```

**New Services Needed:**

1. **`WP_MCP_AI_Gemini_File_Service`** (handles File API)
   - Upload management
   - Processing status polling
   - Cleanup and lifecycle

2. **`WP_MCP_AI_Video_Analysis_Service`** (orchestrates video analysis)
   - Provider selection (Gemini, OpenAI)
   - Caching logic
   - Cost tracking integration
   - Error recovery

3. **`WP_MCP_AI_Video_Frame_Extractor_Service`** (frame extraction for OpenAI)
   - FFmpeg integration
   - Frame selection logic
   - Temporary storage

**Service Dependencies:**
```php
class WP_MCP_AI_Tool_Analyze_Video {
    protected $video_service;
    protected $file_service;
    
    public function __construct(
        WP_MCP_AI_Video_Analysis_Service $video_service,
        WP_MCP_AI_File_Service $file_service
    ) {
        $this->video_service = $video_service;
        $this->file_service = $file_service;
    }
    
    public function execute( $arguments, $context ) {
        // Delegate to service
        return $this->video_service->analyze(
            $arguments['video_url'],
            $arguments['prompt'],
            $context
        );
    }
}
```

This approach:
- ✅ Separates concerns (tools orchestrate, services implement)
- ✅ Testable (mock services in tests)
- ✅ Reusable (services can be used by multiple tools)
- ✅ Maintainable (business logic centralized)
- ✅ Follows established architecture patterns

---

## Migration Path

### Step 1: Create Services (Week 1-2)
- Implement `WP_MCP_AI_Gemini_File_Service`
- Add comprehensive tests
- Document API

### Step 2: Enhance Client (Week 2)
- Update `WP_MCP_AI_Gemini_Client` for file support
- Test with uploaded files
- Ensure backward compatibility

### Step 3: Update Tools (Week 3)
- Refactor video tools to use services
- Add dependency injection
- Update tests

### Step 4: Integration Testing (Week 3)
- End-to-end testing with real videos
- Performance testing
- Error scenario testing

### Step 5: Documentation & Release (Week 4)
- Update user documentation
- Create video tutorials
- Release notes

---

## Testing Strategy

### Unit Tests
- Service methods in isolation
- Mock external API calls
- Test error conditions

### Integration Tests
- Full video upload → analysis → cleanup flow
- Test with various video formats
- Test with different file sizes

### Performance Tests
- Large video handling
- Concurrent upload limits
- Memory usage monitoring

### User Acceptance Tests
- Real-world video scenarios
- Cross-browser compatibility
- Mobile device testing

---

## Success Metrics

**Phase 2 Complete When:** ✅ **ALL ACHIEVED**
- ✅ Videos can be analyzed via Gemini File API
- ✅ Automatic cleanup of uploaded files works
- ✅ Error handling is robust
- ✅ 80%+ test coverage (33 new unit tests added)
- ✅ Documentation complete
- ✅ Average analysis time < 30 seconds for 60s videos (with 5-minute timeout)

**Actual Implementation:**
- ✅ 100% of Phase 2 tasks completed
- ✅ All code passes WordPress Coding Standards
- ✅ Comprehensive error handling throughout
- ✅ Proper logging at all stages
- ✅ Memory-efficient temporary file handling
- ✅ Support for both attachments and remote URLs

**Phase 2.1 Complete When:** ✅ **ALL ACHIEVED**
- ✅ File caching implemented and functional
- ✅ Same video not re-uploaded within 24 hours
- ✅ Files tracked in WordPress options
- ✅ Daily cron job cleaning up old files
- ✅ Comprehensive test coverage (21 new unit tests)
- ✅ All code passes WordPress Coding Standards
- ✅ Proper logging and error handling

**Actual Implementation:**
- ✅ 100% of Phase 2.1 tasks completed
- ✅ Caching via WordPress transients (24-hour expiration)
- ✅ File tracking via WordPress options (persistent)
- ✅ Automated cleanup via WordPress cron (daily schedule)
- ✅ Cache key generation with file modification detection
- ✅ Support for both URL-based and attachment-based caching
- ✅ Graceful handling of expired transients

**Phase 3 Complete When:** ✅ **ALL ACHIEVED**
- ✅ Frame extraction works for all common codecs
- ✅ OpenAI analysis produces quality results via vision API
- ✅ Automatic provider selection works (Gemini vs OpenAI)
- ✅ FFmpeg availability checking implemented
- ✅ Helpful error messages for missing FFmpeg
- ✅ Comprehensive test coverage (17 frame extractor + 12 integration tests)
- ✅ All code passes WordPress Coding Standards
- ✅ Proper logging and error handling
- ✅ Automatic cleanup of temporary frames

**Actual Implementation:**
- ✅ 100% of Phase 3 tasks completed
- ✅ FFmpeg integration with proper error handling
- ✅ Configurable frame count (10 default, 20 max)
- ✅ High-quality JPEG frame extraction
- ✅ Base64 data URL conversion for OpenAI API
- ✅ Memory-efficient temporary file handling
- ✅ Support for both attachments and remote URLs
- ✅ Graceful degradation when FFmpeg unavailable

---

## Current Limitations

**RESOLVED:** ~~Video analysis for Gemini requires uploading the video file to the Gemini File API first.~~

**Now Implemented:** ✅
- ✅ Full video analysis support via Gemini File API
- ✅ Support for both WordPress attachments and remote URLs
- ✅ Automatic upload, processing, and cleanup
- ✅ Comprehensive error messages
- ✅ **OpenAI video support via frame extraction** ✅ **NEW**
- ✅ **FFmpeg integration for video frame extraction** ✅ **NEW**

**Remaining Limitations:**
- ~~OpenAI video support not yet implemented (requires frame extraction - Phase 3)~~ ✅ **RESOLVED**
- ~~No file caching (same video re-uploaded each time - Phase 2.1 optional)~~ ✅ **RESOLVED**
- ~~No upload tracking for lifecycle management (Phase 2.1 optional)~~ ✅ **RESOLVED**
- **NEW:** OpenAI video analysis requires FFmpeg to be installed on the server
- **NEW:** OpenAI video analysis is limited to 10-20 frames per video (configurable)

---

## Resources

- [Gemini File API Documentation](https://ai.google.dev/gemini-api/docs/vision#upload-files)
- [Gemini Video Understanding Guide](https://ai.google.dev/gemini-api/docs/vision#video)
- [OpenAI Vision API](https://platform.openai.com/docs/guides/vision)
- [FFmpeg Documentation](https://ffmpeg.org/documentation.html)

---

**Last Updated:** 2025-11-21
**Status:** Phase 3 Complete ✅
**Next Phase:** Phase 4 (Enhanced Features - Optional)
**All Core Video Analysis Features:** ✅ COMPLETE
