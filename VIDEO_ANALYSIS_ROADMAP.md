# Video Analysis Implementation Roadmap

## Current Status: Phase 1 Complete (Foundation)

### What's Implemented ✅

**Phase 1: Tool Foundation & Architecture**
- [x] Video analysis tool skeleton (`analyze_video`)
- [x] Video caption tool skeleton (`generate_video_caption`)
- [x] Tool registration in registry
- [x] Capability flag `requires-video-model`
- [x] Parameter validation and permissions
- [x] Comprehensive test suite (15 tests)
- [x] Documentation and usage guides
- [x] Error handling with helpful messages

**Current Behavior:**
- Tools are registered and discoverable
- Parameter validation works correctly
- Permission checks functional
- Returns helpful error explaining File API requirement

### What's Not Yet Implemented ⚠️

**Phase 2: Gemini File API Integration** (Next - 2-3 weeks)

Gemini's video analysis requires a multi-step process:

1. **Upload video to Gemini File API**
   ```php
   // POST https://generativelanguage.googleapis.com/upload/v1beta/files
   POST /upload/v1beta/files?uploadType=multipart
   Content-Type: multipart/related
   
   {
     "file": {
       "displayName": "video.mp4"
     }
   }
   // Returns: { "file": { "name": "files/abc123", "state": "PROCESSING" } }
   ```

2. **Poll for processing completion**
   ```php
   // GET https://generativelanguage.googleapis.com/v1beta/files/abc123
   // Returns: { "state": "ACTIVE" } when ready
   ```

3. **Use file reference in generation**
   ```php
   {
     "contents": [{
       "parts": [
         { "text": "Analyze this video" },
         { "fileData": { "fileUri": "https://generativelanguage.googleapis.com/v1beta/files/abc123", "mimeType": "video/mp4" } }
       ]
     }]
   }
   ```

4. **Delete file after use (cleanup)**
   ```php
   // DELETE https://generativelanguage.googleapis.com/v1beta/files/abc123
   ```

**Implementation Tasks:**

- [ ] Create `WP_MCP_AI_Gemini_File_Service` (SoC-compliant service layer)
  - [ ] `upload_file( $file_path, $mime_type, $display_name )` method
  - [ ] `get_file_status( $file_name )` method
  - [ ] `wait_for_processing( $file_name, $timeout = 300 )` method
  - [ ] `delete_file( $file_name )` method
  - [ ] Proper error handling and logging
  - [ ] Rate limiting consideration

- [ ] Enhance `WP_MCP_AI_Gemini_Client` to support fileData
  - [ ] Add file part formatting to `build_payload()`
  - [ ] Handle `type: 'file'` content segments
  - [ ] Update message normalization for files

- [ ] Update video analysis tools
  - [ ] Use Gemini File Service for uploads
  - [ ] Handle attachment downloads from WordPress
  - [ ] Implement cleanup on completion
  - [ ] Add caching to avoid re-uploading same videos

- [ ] Add file management
  - [ ] Track uploaded files in post meta or transients
  - [ ] Cleanup old files (cron job)
  - [ ] Handle upload failures gracefully

- [ ] Testing
  - [ ] Unit tests for file service
  - [ ] Integration tests with real files
  - [ ] Test various video formats and sizes
  - [ ] Test cleanup and error recovery

**Estimated Effort:** 2-3 weeks
**Risk:** Medium (external API dependencies, async processing)

---

### Phase 3: OpenAI Video Support via Frame Extraction (Future)

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

**Phase 2 Complete When:**
- ✅ Videos can be analyzed via Gemini File API
- ✅ Automatic cleanup of uploaded files works
- ✅ Error handling is robust
- ✅ 80%+ test coverage
- ✅ Documentation complete
- ✅ Average analysis time < 30 seconds for 60s videos

**Phase 3 Complete When:**
- ✅ Frame extraction works for all common codecs
- ✅ OpenAI analysis produces quality results
- ✅ Automatic provider selection works

---

## Current Limitations (Documented)

Users attempting to use video tools will see:

```
Error: Video analysis for Gemini requires uploading the video file to the 
Gemini File API first. Direct URL analysis is not yet supported.

Next steps:
1. Download the video file
2. Upload to WordPress media library
3. Use attachment_id parameter instead of video_url
4. Alternatively, wait for File API integration to be completed
```

This provides:
- ✅ Clear explanation of limitation
- ✅ Workaround guidance
- ✅ Timeline expectations
- ✅ Better UX than silent failure

---

## Resources

- [Gemini File API Documentation](https://ai.google.dev/gemini-api/docs/vision#upload-files)
- [Gemini Video Understanding Guide](https://ai.google.dev/gemini-api/docs/vision#video)
- [OpenAI Vision API](https://platform.openai.com/docs/guides/vision)
- [FFmpeg Documentation](https://ffmpeg.org/documentation.html)

---

**Last Updated:** 2024-11-16
**Status:** Phase 1 Complete, Phase 2 Planning
**Next Review:** After Phase 2 completion
