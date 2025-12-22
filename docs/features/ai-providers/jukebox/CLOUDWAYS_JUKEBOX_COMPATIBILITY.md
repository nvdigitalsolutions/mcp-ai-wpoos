# Cloudways Hosting Compatibility

## Question: Will Jukebox work on Cloudways?

**Answer: YES ✅**

The Jukebox integration is fully compatible with Cloudways and other managed WordPress hosting platforms.

## Why It Works

### Python Path Validation Logic

The security validation in `class-wp-mcp-ai-jukebox-service.php` uses a flexible approach:

```php
// Validation code (lines 58-71)
$allowed_python_names = array( 'python', 'python3', 'python3.7', ... );
$python_basename = basename( $python_path );
$is_absolute = strpos( $python_path, '/' ) === 0;

// Accept if: whitelisted name OR absolute path
if ( in_array( $python_basename, $allowed_python_names, true ) || $is_absolute ) {
    // Valid! Continue...
}
```

### What This Means

**Any absolute path is accepted**, which includes all typical server configurations:

| Hosting | Typical Python Path | Valid? |
|---------|-------------------|--------|
| **Cloudways** | `/usr/bin/python3` | ✅ Yes (absolute) |
| **Cloudways** | `/opt/alt/python39/bin/python3` | ✅ Yes (absolute) |
| **Standard VPS** | `/usr/bin/python3` | ✅ Yes (absolute) |
| **Custom Install** | `/usr/local/bin/python3.11` | ✅ Yes (absolute) |
| **Simple Name** | `python3` | ✅ Yes (whitelisted) |
| **Invalid** | `weird-python` | ❌ No (not whitelisted, not absolute) |

## Cloudways-Specific Setup

### Step 1: SSH Access
Ensure you have SSH access to your Cloudways server.

### Step 2: Find Python Path
```bash
which python3
# Output: /usr/bin/python3 (or similar)
```

### Step 3: Install Jukebox
```bash
# Navigate to a persistent directory
cd /home/master/applications/your-app/public_html/

# Or use /opt if you have root access
cd /opt

# Clone Jukebox
git clone https://github.com/openai/jukebox.git
cd jukebox

# Install dependencies
pip3 install -r requirements.txt
pip3 install mpi4py av
```

### Step 4: Configure in WordPress
1. Go to **Settings → WP oOS → Tools → Jukebox**
2. Set **Python Path**: `/usr/bin/python3` (or path from `which python3`)
3. Set **Installation Path**: Full path to your jukebox directory
4. Click **Save Changes**

### Step 5: Verify
Use the `check_jukebox_status` tool to confirm installation.

## Important Cloudways Considerations

### GPU Requirements
⚠️ **Important:** Jukebox requires a GPU with 16GB+ VRAM.

- **Standard Cloudways servers** typically don't have GPUs
- You'll need a **GPU-enabled server** or custom infrastructure
- Consider AWS EC2 with GPU instances if using Cloudways

### Alternative for Cloudways Users

If your Cloudways server doesn't have a GPU:

1. **Use Gemini Lyria instead** (included in the plugin)
   - No GPU required
   - Cloud-based API
   - Fast generation (seconds vs hours)
   - Instrumental music only (no vocals)

2. **Hybrid approach:**
   - Use a separate GPU server for Jukebox
   - Configure remote execution via API/webhook
   - Store results in WordPress media library

## Testing on Cloudways

### Quick Test
```bash
# SSH into your Cloudways server
ssh user@your-server

# Check Python version
python3 --version
# Should show: Python 3.7 or higher

# Check if path works
/usr/bin/python3 --version
# Should show same version

# Test with WordPress (using WP-CLI if available)
wp eval 'echo shell_exec("/usr/bin/python3 --version");'
```

### WordPress Configuration Test
```php
// Add to wp-config.php temporarily for testing
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Then check the jukebox status via the tool
// Logs will be in wp-content/debug.log
```

## Security Note

The absolute path validation is **intentional for security**:
- Prevents command injection with arbitrary names
- Only allows known-good Python names or full paths
- Admins control the path configuration (`manage_options` capability)
- All commands are escaped with `escapeshellcmd()` and `escapeshellarg()`

## Support

If you encounter issues on Cloudways:

1. **Check Python version:** Must be 3.7+
2. **Verify permissions:** Server must allow command execution
3. **Check logs:** WordPress debug log or WP oOS logs
4. **GPU availability:** Confirm server has compatible GPU
5. **Storage space:** Ensure 20GB+ available for models

## Conclusion

**Yes, the Python path validation works on Cloudways** because it accepts any absolute path. The flexible validation ensures compatibility with:
- Cloudways
- Standard VPS hosting
- Managed WordPress hosting
- Custom server configurations
- Any hosting with Python 3.7+

The only requirement is a **GPU-enabled server**, which may require upgrading from standard Cloudways plans.

---

**Documentation:** See full integration guide at `docs/jukebox-integration.md`  
**Security details:** See `docs/SECURITY_SUMMARY_JUKEBOX.md`
