# CPT AI Assistant Modal Interface

## Overview

The AI Assistant feature in custom post type edit screens has been converted from an inline chat interface to a modal-based interface. This change resolves form validation conflicts where the inline "Send" button was interfering with the page's primary form submission buttons.

## Problem

Previously, the AI Assistant was rendered as an inline metabox with:
- Chat message container
- Text input field
- Send button

The inline "Send" button could trigger unintended form validation on the main post/page edit form, causing conflicts with WordPress's native publish/update buttons.

## Solution

The AI Assistant now uses a modal dialog interface:
- A single "Open AI Assistant" button in the metabox
- Clicking the button opens a modal overlay
- The chat interface is contained within the modal
- The modal can be closed via:
  - Close button (X)
  - Clicking the backdrop
  - Pressing the Escape key

## User Experience

### For Post/Page Edit Screens

1. Users see a metabox labeled "AI Assistant" in the sidebar (or below editor)
2. The metabox contains a brief description and a prominent "Open AI Assistant" button
3. Clicking the button opens a centered modal dialog
4. The modal contains the full chat interface with all existing features:
   - Welcome message with suggestions
   - Chat history
   - Message input
   - Send button
   - Status messages

### For Taxonomy Term Edit Screens

1. Users see an "AI Assistant" section below the term form
2. Same button and modal behavior as post/page screens
3. The modal is contextually aware of the term being edited

## Technical Changes

### PHP Changes (`class-wp-mcp-ai-pro-cpt-ai-integration.php`)

**Metabox Rendering:**
- `render_ai_metabox()`: Now renders a button and separate modal container
- `render_term_ai_metabox()`: Same pattern for term edit screens
- Modal HTML includes backdrop, panel, header with close button, and body

### JavaScript Changes (`cpt-assistant.js`)

**New Modal Functions:**
- `openModal()`: Shows the modal and focuses the input
- `closeModal()`: Hides the modal and removes body class

**Event Handlers:**
- Click on "Open AI Assistant" button → opens modal
- Click on modal close button → closes modal
- Click on backdrop → closes modal
- Press Escape key → closes modal
- Existing chat functionality (send message, enter key) unchanged

### CSS Changes (`cpt-assistant.css`)

**New Modal Styles:**
- `.wp-mcp-ai-cpt-modal`: Fixed position overlay (z-index: 100000)
- `.wp-mcp-ai-cpt-modal__backdrop`: Semi-transparent backdrop with blur
- `.wp-mcp-ai-cpt-modal__panel`: Centered panel (90% width, max 800px)
- `.wp-mcp-ai-cpt-modal__header`: Header with title and close button
- `.wp-mcp-ai-cpt-modal__body`: Scrollable body content
- `body.wp-mcp-ai-cpt-modal-open`: Prevents body scroll when modal is open

**Button Styles:**
- `.wp-mcp-ai-cpt-open-assistant`: Full-width button with icon
- Responsive adjustments for mobile devices

## Benefits

1. **No Form Conflicts**: The modal's submit button is isolated from the page form
2. **Better Focus**: Modal provides a dedicated space for AI interaction
3. **Familiar Pattern**: Consistent with the "Build with AI" modal in the assistant builder
4. **Improved UX**: Clear entry point with contextual information
5. **Accessibility**: Keyboard navigation (Escape key, focus management)

## Backward Compatibility

- All existing chat functionality is preserved
- AJAX endpoints remain unchanged
- No database changes required
- Feature flag (`enable_ai_cpt_management`) still controls availability

## Testing Checklist

- [ ] Open modal on post edit screen
- [ ] Open modal on page edit screen  
- [ ] Open modal on term edit screen (category, tag)
- [ ] Send messages and receive responses
- [ ] Close modal via close button
- [ ] Close modal via backdrop click
- [ ] Close modal via Escape key
- [ ] Verify no conflicts with publish/update buttons
- [ ] Test on mobile/tablet viewports
- [ ] Verify focus management (input auto-focus on open)

## Related Files

- `addons/pro/includes/admin/class-wp-mcp-ai-pro-cpt-ai-integration.php`
- `addons/pro/assets/js/cpt-assistant.js`
- `addons/pro/assets/css/cpt-assistant.css`

## References

- Modal pattern inspired by `class-wp-mcp-ai-build-assistant-page.php`
- Similar modal styles in `assets/css/admin-build-assistant.css`
