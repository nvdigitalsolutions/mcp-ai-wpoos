# OAuth Redirect URI Fix - Visual Explanation

## The Problem

### What was happening (BEFORE the fix):

```
┌─────────────────────────────────────────────────────────────────────┐
│ WordPress Admin Interface                                           │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ Authorized Redirect URI:                                            │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ https://site.com/admin.php?page=test&oauth_handler=callback    │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│              ▲                                                      │
│              │                                                      │
│              │ User clicks to select and copy                      │
└──────────────┼──────────────────────────────────────────────────────┘
               │
               │
               ▼
      ┌────────────────────┐
      │ Browser DevTools   │
      │ (View Source)      │
      ├────────────────────┤
      │ HTML shows:        │
      │ value="...?page=   │
      │ test&amp;oauth_   │
      │ handler=callback"  │
      └────────┬───────────┘
               │
               │ User copies from DevTools
               ▼
     ┌──────────────────────┐
     │ Clipboard contains:  │
     │ ...?page=test&amp;   │
     │ oauth_handler=...    │  ← ❌ HTML entity!
     └──────────┬───────────┘
                │
                │ User pastes
                ▼
  ┌──────────────────────────────────┐
  │ Google Cloud Console             │
  ├──────────────────────────────────┤
  │ Authorized redirect URI:         │
  │ ...?page=test&amp;oauth_handler │  ← Literal &amp; stored!
  └──────────────────────────────────┘
                │
                ▼
     ┌─────────────────────────┐
     │ OAuth Flow              │
     ├─────────────────────────┤
     │ WordPress sends:        │
     │ ...?page=test&oauth_    │  ← Plain & sent
     │ handler=callback        │
     │                         │
     │ Google expects:         │
     │ ...?page=test&amp;oauth│  ← Literal &amp; expected
     │ handler=callback        │
     │                         │
     │ MISMATCH! ❌           │
     └─────────────────────────┘
```

### What happens now (AFTER the fix):

```
┌─────────────────────────────────────────────────────────────────────┐
│ WordPress Admin Interface                                           │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ Authorized Redirect URI:                                            │
│ ┌─────────────────────────────────────────────────────────────────┐ │
│ │ https://site.com/admin.php?page=test&oauth_handler=callback    │ │
│ └─────────────────────────────────────────────────────────────────┘ │
│              ▲                                                      │
│              │                                                      │
│              │ User clicks to select and copy                      │
└──────────────┼──────────────────────────────────────────────────────┘
               │
               │
               ▼
      ┌────────────────────┐
      │ Browser DevTools   │
      │ (View Source)      │
      ├────────────────────┤
      │ HTML shows:        │
      │ value="...?page=   │
      │ test&oauth_handler │  ← Plain & in HTML!
      │ =callback"         │
      └────────┬───────────┘
               │
               │ User copies from anywhere
               ▼
     ┌──────────────────────┐
     │ Clipboard contains:  │
     │ ...?page=test&oauth_ │
     │ handler=callback     │  ← ✅ Plain &!
     └──────────┬───────────┘
                │
                │ User pastes
                ▼
  ┌──────────────────────────────────┐
  │ Google Cloud Console             │
  ├──────────────────────────────────┤
  │ Authorized redirect URI:         │
  │ ...?page=test&oauth_handler=...  │  ← Plain & stored ✅
  └──────────────────────────────────┘
                │
                ▼
     ┌─────────────────────────┐
     │ OAuth Flow              │
     ├─────────────────────────┤
     │ WordPress sends:        │
     │ ...?page=test&oauth_    │  ← Plain & sent
     │ handler=callback        │
     │                         │
     │ Google expects:         │
     │ ...?page=test&oauth_    │  ← Plain & expected
     │ handler=callback        │
     │                         │
     │ MATCH! ✅              │
     └─────────────────────────┘
```

## Technical Details

### The Code Change

```php
// BEFORE (Line 967):
<input value="<?php echo esc_attr( $gmail_redirect_uri ); ?>">
// Output in HTML: value="...?page=test&amp;oauth_handler=callback"
//                                        ^^^^^ HTML entity

// AFTER (Line 967):
<input value="<?php echo esc_url( $gmail_redirect_uri ); ?>">
// Output in HTML: value="...?page=test&oauth_handler=callback"
//                                       ^^^ Plain ampersand
```

### Why This Matters

**esc_attr()** - Designed for HTML attributes, escapes special chars:
- `&` becomes `&amp;`
- `<` becomes `&lt;`
- `>` becomes `&gt;`
- `"` becomes `&quot;`

**esc_url()** - Designed for URLs, preserves URL structure:
- `&` stays as `&` (valid in URLs)
- Only escapes characters that are invalid in URLs
- Maintains query parameter separators

### Browser Behavior

Modern browsers **usually** decode `&amp;` back to `&` when:
- Displaying the input value
- User copies via normal selection

BUT browsers **don't** decode when:
- Viewing HTML source
- Using DevTools to copy
- Some browser extensions interfere
- Automated scraping/tooling

Our fix eliminates this inconsistency by keeping `&` plain in the HTML itself.

## Summary

| Aspect | Before Fix | After Fix |
|--------|-----------|-----------|
| HTML Output | `&amp;` | `&` |
| Copy from UI | Usually works | Always works |
| Copy from DevTools | Breaks | Works |
| Copy from Source | Breaks | Works |
| Google OAuth | ❌ Mismatch | ✅ Match |

**Result:** Users can reliably copy the redirect URI from anywhere and paste it into Google Cloud Console without errors.
