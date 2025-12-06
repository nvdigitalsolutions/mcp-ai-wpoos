# OpenAI Jukebox Integration

This document describes the integration of OpenAI's Jukebox neural network for raw audio music generation with vocals.

## Overview

**OpenAI Jukebox** is a research model that generates raw audio music, including vocals, in a wide variety of genres and artist styles. Unlike the Google Gemini Lyria integration (which generates instrumental music), Jukebox can produce complete songs with singing voices.

### Key Differences: Jukebox vs. Gemini Lyria

| Feature | Jukebox | Gemini Lyria |
|---------|---------|--------------|
| **Vocals** | Yes (can sing lyrics) | No (instrumental only) |
| **Installation** | Local (requires setup) | API-based (cloud) |
| **API Availability** | No official API | Official Gemini API |
| **Resources Required** | High (16GB+ GPU VRAM) | None (cloud-based) |
| **Generation Speed** | Very slow (hours/minute) | Fast (seconds) |
| **Audio Quality** | Research-grade | Production-grade |
| **Cost** | Free (local compute) | Paid API usage |
| **Maintenance Status** | Research project (2020) | Active product |

## Architecture

The Jukebox integration consists of three main components:

### 1. Service Layer
**File:** `includes/services/class-wp-mcp-ai-jukebox-service.php`

Handles all interaction with the locally-installed Jukebox model:
- Installation status checking
- Command-line execution
- File management
- Error handling

### 2. Tools Layer
**Files:**
- `includes/tools/class-wp-mcp-ai-tool-generate-jukebox-music.php` - Music generation tool
- `includes/tools/class-wp-mcp-ai-tool-check-jukebox-status.php` - Installation status checker

Provides WordPress/MCP-compatible interfaces for AI assistants to use Jukebox functionality.

### 3. WordPress Integration
- Automatic media library attachment creation
- User permission checking
- Logging and error tracking
- Metadata preservation

## Installation Requirements

### Prerequisites

1. **Python 3.7+** - Required for running Jukebox
2. **CUDA-capable GPU** - Minimum 16GB VRAM recommended
3. **Disk Space** - 20GB+ for models and temporary files
4. **Server Access** - Ability to install Python packages and run commands

### Installation Steps

1. **Install Python Dependencies**
   ```bash
   # Install Python 3 if not already installed
   sudo apt-get install python3 python3-pip

   # Clone Jukebox repository
   cd /opt
   git clone https://github.com/openai/jukebox.git
   cd jukebox

   # Install Jukebox dependencies
   pip3 install -r requirements.txt
   pip3 install mpi4py av
   ```

2. **Download Model Weights**
   ```bash
   # Models will be downloaded automatically on first use
   # Or manually download to save time:
   python3 jukebox/make_models.py
   ```

3. **Configure WordPress Plugin**
   - Go to **Settings → WP oOS → Tools → Jukebox**
   - Set **Python Path**: `/usr/bin/python3` (or your Python path)
   - Set **Installation Path**: `/opt/jukebox` (or wherever you cloned it)
   - Click **Save Changes**

4. **Verify Installation**
   - Use the `check_jukebox_status` tool
   - Or run test generation from command line

## Usage

### Tool: `generate_jukebox_music`

Generates music with vocals from a text prompt.

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `prompt` | string | Yes | Description of desired music |
| `model` | string | No | Model to use: `1b_lyrics`, `5b`, `5b_lyrics` (default) |
| `sample_length` | integer | No | Duration in seconds (1-60, default: 20) |
| `artist` | string | No | Artist style to emulate |
| `genre` | string | No | Music genre |
| `lyrics` | string | No | Custom lyrics to sing (lyrics models only) |
| `temperature` | float | No | Creativity (0.0-1.0, default: 0.98) |
| `file_name` | string | No | Custom filename for saved audio |

**Example Usage:**

```json
{
  "tool": "generate_jukebox_music",
  "arguments": {
    "prompt": "upbeat rock ballad with powerful vocals",
    "model": "5b_lyrics",
    "sample_length": 30,
    "artist": "Queen",
    "genre": "rock",
    "lyrics": "We are the champions, my friends...",
    "temperature": 0.98
  }
}
```

**Response:**

```json
{
  "attachment_id": 123,
  "url": "https://example.com/wp-content/uploads/2024/12/jukebox-music-20241206-143022.wav",
  "file_path": "/var/www/html/wp-content/uploads/2024/12/jukebox-music-20241206-143022.wav",
  "file_name": "jukebox-music-20241206-143022.wav",
  "mime_type": "audio/wav",
  "bytes": 2560000,
  "format": "wav",
  "sample_length": 30,
  "prompt": "upbeat rock ballad with powerful vocals",
  "model": "5b_lyrics",
  "artist": "Queen",
  "genre": "rock",
  "title": "Jukebox Music: upbeat rock ballad with powerful vocals"
}
```

### Tool: `check_jukebox_status`

Checks installation status and configuration.

**Parameters:** None

**Example Usage:**

```json
{
  "tool": "check_jukebox_status"
}
```

**Response (Not Installed):**

```json
{
  "installed": false,
  "message": "Jukebox installation path is not configured or does not exist.",
  "python_path": "python3",
  "configuration": {
    "python_path_setting": "python3",
    "install_path_setting": ""
  },
  "setup_instructions": {
    "step_1": "Install Python 3.7+ on your server.",
    "step_2": "Clone the Jukebox repository: git clone https://github.com/openai/jukebox.git",
    "step_3": "Install Jukebox dependencies: pip install -r jukebox/requirements.txt",
    "step_4": "Install additional dependencies: pip install mpi4py av",
    "step_5": "Configure the installation path in WP oOS settings.",
    "note": "Jukebox requires significant GPU resources (CUDA-capable GPU with 16GB+ VRAM recommended)."
  }
}
```

**Response (Installed):**

```json
{
  "installed": true,
  "message": "Jukebox is installed and available.",
  "python_path": "/usr/bin/python3",
  "jukebox_path": "/opt/jukebox",
  "configuration": {
    "python_path_setting": "/usr/bin/python3",
    "install_path_setting": "/opt/jukebox"
  },
  "available_models": {
    "1b_lyrics": "Small model with lyrics support (faster, lower quality)",
    "5b": "Large model without lyrics support (better quality)",
    "5b_lyrics": "Large model with lyrics support (best quality, slowest)"
  }
}
```

## Model Options

### 1b_lyrics (Small Model with Lyrics)
- **Size:** 1 billion parameters
- **Features:** Lyrics support, faster generation
- **Quality:** Lower quality audio
- **Use Case:** Quick prototypes, testing
- **Speed:** Moderate (still slow by normal standards)

### 5b (Large Model without Lyrics)
- **Size:** 5 billion parameters
- **Features:** Better quality, instrumental only
- **Quality:** Higher quality audio
- **Use Case:** Instrumental music, better quality
- **Speed:** Slow

### 5b_lyrics (Large Model with Lyrics) - **Recommended**
- **Size:** 5 billion parameters
- **Features:** Lyrics support, best quality
- **Quality:** Best available quality
- **Use Case:** Production-quality music with vocals
- **Speed:** Very slow

## Performance Considerations

### Generation Time

Jukebox is **extremely compute-intensive**. Generation times vary based on:

- **Sample Length:** ~1-2 hours per minute of audio (on high-end GPU)
- **Model Size:** Larger models (5b) take longer than smaller ones (1b)
- **Hardware:** GPU VRAM and compute capability directly affect speed

**Example Times (RTX 3090 24GB):**
- 20-second sample: 30-60 minutes
- 30-second sample: 45-90 minutes
- 60-second sample: 2-4 hours

### Resource Usage

- **GPU VRAM:** Minimum 16GB, 24GB+ recommended
- **System RAM:** 16GB+ recommended
- **Disk Space:** 20GB for models, plus temporary files
- **CPU:** Multi-core recommended for preprocessing

### Best Practices

1. **Use Async Execution** - Jukebox generation should always run asynchronously
2. **Limit Sample Length** - Keep samples under 60 seconds (plugin enforces this)
3. **Monitor Resources** - Watch GPU memory usage during generation
4. **Clean Up Files** - Plugin automatically cleans up temporary files
5. **Set Expectations** - Inform users about long generation times

## Troubleshooting

### Common Issues

#### 1. "Jukebox installation path is not configured"

**Solution:** Configure the installation path in WP oOS settings.

```
Settings → WP oOS → Tools → Jukebox
```

#### 2. "Python is not available at the configured path"

**Solutions:**
- Verify Python 3 is installed: `python3 --version`
- Update Python path in settings
- Install Python if missing

#### 3. "Jukebox sample script not found"

**Solution:** Ensure Jukebox is properly cloned and the path points to the repository root.

#### 4. Out of Memory Errors

**Solutions:**
- Reduce sample length
- Use smaller model (1b_lyrics instead of 5b_lyrics)
- Upgrade GPU VRAM
- Close other GPU-intensive applications

#### 5. Very Slow Generation

**Expected Behavior:** Jukebox is inherently slow. See Performance Considerations above.

**Optimization:**
- Use 1b_lyrics model for faster results
- Reduce sample length
- Ensure GPU is being used (not CPU fallback)

## Security Considerations

### Permission Requirements

- **Generate Music:** Requires `upload_files` capability
- **Check Status:** Requires `manage_options` capability

### Command Execution

The service uses PHP's `exec()` function with:
- Escaped shell commands using `escapeshellcmd()` and `escapeshellarg()`
- No user input directly in shell commands
- Sanitized metadata files
- Temporary file cleanup

### File System Access

- Outputs saved to WordPress uploads directory
- Temporary files use `sys_get_temp_dir()`
- Automatic cleanup of generated files after upload
- Files validated before WordPress attachment creation

## Capability Flags

Both Jukebox tools declare capability flags for the orchestration system:

### `generate_jukebox_music`
- `local-execution` - Executes commands on the local server
- `requires-capability` - Requires user capabilities

### `check_jukebox_status`
- `read-only` - Does not modify data
- `local-execution` - Checks local system
- `requires-capability` - Requires user capabilities

## Logging

All Jukebox operations are logged via `WP_MCP_AI_Logger`:

- `jukebox_generation_start` - When generation begins
- `jukebox_generation_complete` - Successful generation
- `jukebox_generation_failed` - Failed generation
- `jukebox_music_generated` - WordPress attachment created

## Comparison with Other Music Tools

| Capability | Jukebox | Gemini Lyria |
|-----------|---------|--------------|
| Instrumental Music | ✅ Yes | ✅ Yes |
| Vocal Music | ✅ Yes | ❌ No |
| Custom Lyrics | ✅ Yes | ❌ No |
| Artist Style | ✅ Yes | ✅ Limited |
| API-Based | ❌ No | ✅ Yes |
| Fast Generation | ❌ No | ✅ Yes |
| Production Ready | ❌ Research | ✅ Yes |
| Cost | Free (compute) | Paid (API) |

## References

- [OpenAI Jukebox Blog Post](https://openai.com/index/jukebox/)
- [Jukebox GitHub Repository](https://github.com/openai/jukebox)
- [Jukebox Paper](https://arxiv.org/abs/2005.00341)
- [WP oOS Tool Reference](tool-reference.md)
- [WP oOS Architecture](ARCHITECTURE.md)

## Future Enhancements

Potential improvements for future versions:

1. **Queue Management** - Better handling of long-running generations
2. **Progress Updates** - Real-time progress via SSE
3. **Model Selection UI** - Admin interface for model configuration
4. **Sample Library** - Store and reuse generated samples
5. **Continuation** - Start from existing audio and continue
6. **Format Options** - Support MP3, OGG output formats
7. **GPU Monitoring** - Display GPU usage in admin
