# Remove Background Tool - Installation Guide

The Remove Background tool provides two methods for removing image backgrounds:

## Method 1: Free (Python rembg)

### Requirements
- Python 3.x installed on your server
- pip3 package manager

### Installation

#### Option A: Using pipx (Recommended for managed hosting)
```bash
# Install pipx if not available
apt install pipx  # or: pip3 install --user pipx

# Install rembg using pipx
pipx install rembg
```

#### Option B: Using virtual environment (Alternative)
```bash
# Create virtual environment
python3 -m venv /path/to/your-site/rembg-env

# Install packages in venv
/path/to/your-site/rembg-env/bin/pip install rembg pillow
```

Then update the tool to use the venv Python:
- For pipx: The `rembg` command will be available globally
- For venv: Use `/path/to/your-site/rembg-env/bin/python3`

#### Option C: System-wide (Requires root/sudo)
```bash
# Only if you have root access
sudo pip3 install rembg pillow --break-system-packages
```

**Note**: If you encounter "externally-managed-environment" error, use Option A (pipx) or Option B (venv). Modern Debian/Ubuntu systems (PEP 668) prevent direct pip installation to protect the system Python.

### Usage
Once installed, the tool will automatically use the free rembg method when:
- `method` parameter is set to "free" or "auto" (default)
- No remove.bg API key is configured

### Advantages
- ✅ Completely free
- ✅ No API limits
- ✅ Works offline
- ✅ Privacy-friendly (no data sent to external services)

### Disadvantages
- ⚠️ Requires Python environment
- ⚠️ Requires server-side package installation
- ⚠️ May be slower than cloud API
- ⚠️ May use more server resources

## Method 2: Paid (remove.bg API)

### Requirements
- remove.bg API key

### Setup
1. Sign up at https://www.remove.bg/api
2. Get your API key
3. In WordPress Admin, go to **Settings → WP oOS → Tools → External Tools**
4. Enter your API key in the "remove.bg API Key" field
5. Save settings

### Usage
The tool will use the remove.bg API when:
- `method` parameter is set to "paid"
- `method` is set to "auto" (default) and free method failed or is unavailable
- API key is configured in settings

### Advantages
- ✅ High quality results
- ✅ Fast processing (cloud-based)
- ✅ No server-side dependencies
- ✅ Professional-grade accuracy

### Disadvantages
- ⚠️ Requires API key
- ⚠️ Usage costs (free tier: 50 images/month)
- ⚠️ Internet connection required
- ⚠️ Data sent to external service

## Method Parameter

The tool accepts a `method` parameter to control which approach to use:

- **`auto`** (default): Try free method first, fall back to paid if free fails or is unavailable
- **`free`**: Use only the free rembg method (returns error if not available)
- **`paid`**: Use only the remove.bg API (returns error if no API key)

## Example Usage

### Via Chat Interface
```
Remove the background from this image [attach image]
```

The assistant will use the remove_background tool with the default "auto" method.

### Via API/Code
```php
// Auto mode (recommended)
$result = $registry->execute_tool(
    'remove_background',
    array(
        'attachment_id' => 123,
        'method' => 'auto'
    ),
    $context
);

// Free only
$result = $registry->execute_tool(
    'remove_background',
    array(
        'image_url' => 'https://example.com/image.jpg',
        'method' => 'free'
    ),
    $context
);

// Paid only
$result = $registry->execute_tool(
    'remove_background',
    array(
        'image_data' => base64_encode($image_binary),
        'method' => 'paid'
    ),
    $context
);
```

## Troubleshooting

### "externally-managed-environment" error
If you see this error when trying to install rembg:
```
error: externally-managed-environment
× This environment is externally managed
```

**Solution**: Modern Python installations (Debian 12+, Ubuntu 23.04+) protect the system Python. Use one of these methods:

**Method 1: pipx (Easiest)**
```bash
# Install pipx
sudo apt install pipx
# or if no sudo: pip3 install --user pipx

# Install rembg
pipx install rembg
```

**Method 2: Virtual Environment**
```bash
# Create venv in your WordPress directory
cd /path/to/wordpress
python3 -m venv rembg-venv

# Install packages
rembg-venv/bin/pip install rembg pillow
```

**Method 3: Override (Not recommended)**
```bash
# Only if you understand the risks
pip3 install --break-system-packages rembg pillow
```

After using pipx or venv, the tool will automatically detect the rembg installation.

### "rembg not installed"
Install rembg with: `pip3 install rembg pillow`

### "Python is not available on this system"
Install Python 3 or use the paid method instead.

### "remove.bg API key is not configured"
Add your API key in Settings → WP oOS → Tools → External Tools

### "remove.bg API returned error 403"
Your API key is invalid. Check your key or sign up at https://www.remove.bg/api

### "remove.bg API returned error 402"
You've exceeded your API quota. Upgrade your plan or use the free method.

## Testing Installation

Run the integration test to verify setup:

```bash
php bin/test-remove-background.php
```

This will check:
- Tool class exists
- Settings configured
- Python availability
- rembg installation status
- Helper function exists

## Performance Considerations

### Free Method (rembg)
- Processing time: 2-10 seconds per image
- Memory usage: ~500MB-1GB
- Best for: Small to medium images, limited budget

### Paid Method (remove.bg API)
- Processing time: 1-3 seconds per image
- Memory usage: Minimal (cloud processing)
- Best for: Production sites, high quality requirements, large volumes

## Security

Both methods:
- ✅ Require authentication (user must be logged in)
- ✅ Require `upload_files` capability
- ✅ Validate all input parameters
- ✅ Create new attachments (don't modify originals)

Free method:
- ✅ All processing done locally
- ✅ No data sent to external services

Paid method:
- ⚠️ Images sent to remove.bg (check their privacy policy)
- ✅ API key stored securely in WordPress options
- ✅ HTTPS encryption for API requests
