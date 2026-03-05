# Protected Property Access Fix

**Date**: February 18, 2026  
**Issue**: `convert_image_format` tool was accessing protected property `WP_Image_Editor_Imagick::$mime_type`  
**Error Message**: "Cannot access protected property WP_Image_Editor_Imagick::$mime_type"

## Problem

The `convert_image_format` tool and other image manipulation tools were directly accessing the protected `$mime_type` property of `WP_Image_Editor` objects, which violates PHP encapsulation and fails on PHP 7.4+.

### Affected Files

1. `includes/tools/class-wp-mcp-ai-tool-convert-image-format.php` (2 locations)
2. `includes/tools/class-wp-mcp-ai-tool-image-base.php` (3 locations)
3. `includes/tools/class-wp-mcp-ai-tool-graphic-editor-plus.php` (1 location)

## Solution

### Replace Direct Property Access with Public API Method

**Before (Incorrect)**:
```php
$original_mime = $image_editor->mime_type;  // ❌ Accessing protected property
$image_editor->mime_type = $new_mime;       // ❌ Cannot set protected property
```

**After (Correct)**:
```php
$original_mime = $image_editor->get_mime_type();  // ✅ Using public method
// Use save() method with MIME type parameter instead of setting property
$saved = $image_editor->save( $file_path, $new_mime );
```

### Key Changes

#### 1. convert_image_format Tool (line 162)
```php
// Changed from:
$original_mime = $image_editor->mime_type;

// To:
$original_mime = $image_editor->get_mime_type();
```

#### 2. convert_image_format Tool (lines 245-348)
Complete refactoring to use WordPress API correctly:
- Removed direct `$image_editor->mime_type = $new_mime;` assignment
- Used `$image_editor->save( $file_path, $new_mime )` instead
- Manually created attachment from saved file with correct MIME type
- Proper cleanup of temporary files

#### 3. image-base.php (line 618)
```php
// Changed from:
$extension = $this->get_extension_from_mime_type( $image_editor->mime_type );

// To:
$extension = $this->get_extension_from_mime_type( $image_editor->get_mime_type() );
```

#### 4. image-base.php (line 665)
```php
// Changed from:
'post_mime_type' => $image_editor->mime_type,

// To:
'post_mime_type' => $image_editor->get_mime_type(),
```

#### 5. image-base.php (line 699)
```php
// Changed from:
'mime_type' => $image_editor->mime_type,

// To:
'mime_type' => $image_editor->get_mime_type(),
```

#### 6. graphic-editor-plus.php (line 439)
```php
// Changed from:
'mime_type' => $image_editor->mime_type,

// To:
'mime_type' => $image_editor->get_mime_type(),
```

## WordPress API Reference

### WP_Image_Editor Public Methods

The `WP_Image_Editor` abstract class provides these public methods:

- `get_mime_type()` - Returns the current MIME type
- `save( $filename = null, $mime_type = null )` - Saves the image to a file
- `set_quality( $quality )` - Sets output quality (for JPEG/WebP)
- `get_size()` - Returns array with 'width' and 'height'
- `generate_filename( $suffix = null, $dest_path = null, $extension = null )` - Generates a filename

### Correct Pattern for Format Conversion

```php
// 1. Load image
$image_editor = wp_get_image_editor( $file_path );

// 2. Get original MIME type
$original_mime = $image_editor->get_mime_type();

// 3. Set quality (optional, for JPEG/WebP)
$image_editor->set_quality( 90 );

// 4. Generate filename with new extension
$new_file = $image_editor->generate_filename( 'converted', null, 'png' );

// 5. Save with new MIME type
$saved = $image_editor->save( $new_file, 'image/png' );

// 6. Create attachment from saved file
$attachment_id = wp_insert_attachment( array(
    'post_mime_type' => 'image/png',
    'post_title'     => 'Converted Image',
    'post_status'    => 'inherit',
), $saved['path'] );
```

## Testing

### Manual Verification

1. **Syntax Check**: All files pass `php -l` syntax validation
2. **Existing Tests**: Tool registration tests continue to pass
3. **API Compatibility**: Uses only public WordPress methods

### Expected Behavior

- ✅ Tool can read original MIME type
- ✅ Tool can convert between formats (PNG, JPEG, WebP, GIF)
- ✅ Tool creates new attachments with correct MIME type
- ✅ No protected property access errors
- ✅ Works across PHP 7.4+ and PHP 8.x

## Additional Documentation

Also confirmed in this fix:

### Sharp Pre-Packaging Status

Sharp is **confirmed pre-packaged** in `addons/pro/assets/vendor/sharp/` with:
- Sharp library (324 KB)
- Linux x64 binaries (~16.3 MB)
- Required dependencies (detect-libc, color, semver)
- Total size: ~17 MB

See: `addons/pro/docs/SHARP_PREPACKAGING_COMPLETE.md`

## References

- WordPress Codex: [WP_Image_Editor](https://developer.wordpress.org/reference/classes/wp_image_editor/)
- PHP Manual: [Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
- Related Fix: [IMAGE_EDIT_403_FIX.md](../archive/fixes/IMAGE_EDIT_403_FIX.md)
