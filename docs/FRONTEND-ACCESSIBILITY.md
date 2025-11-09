# Frontend Accessibility and User Experience

This document describes the accessibility features and responsive design of the WP oOS chat interface.

## Table of Contents
- [Accessibility Features](#accessibility-features)
- [Responsive Design](#responsive-design)
- [User Feedback](#user-feedback)
- [Testing Guidelines](#testing-guidelines)

## Accessibility Features

The chat interface follows Web Content Accessibility Guidelines (WCAG) 2.1 Level AA standards and implements comprehensive ARIA (Accessible Rich Internet Applications) attributes.

### ARIA Roles and Attributes

#### Messages Container
- **Role**: `log` - Indicates a live region containing chat messages
- **aria-live**: `polite` - Screen readers announce new messages when user is idle
- **aria-atomic**: `false` - Only new additions are announced, not the entire log
- **aria-relevant**: `additions` - Screen readers only announce new messages added to the chat

```html
<div class="wp-mcp-ai-chat__messages" 
     role="log" 
     aria-live="polite" 
     aria-atomic="false" 
     aria-relevant="additions">
</div>
```

#### Individual Messages
Each message bubble has:
- **Role**: `article` - Semantic role indicating self-contained content
- **aria-label**: Descriptive label identifying the sender:
  - "User message" for user messages
  - "Assistant message" for AI responses
  - "System message" for system notifications

#### Status Area
- **Role**: `status` - Indicates important status information
- **aria-live**: `polite` - Announces status changes to screen readers
- **Hidden**: Dynamically shown/hidden based on activity

#### Interactive Elements
- **Buttons**: All buttons have descriptive `aria-label` attributes
- **Transcript Toggle**: Has `aria-expanded` state indicating collapsed/expanded state
- **Form**: Has `aria-busy` state during message processing
- **Tool Shortcuts**: Container has `role="group"` with descriptive `aria-label`

### Streaming Messages
When the assistant is composing a response:
- Message element gets `aria-label="Assistant is typing…"`
- Visual typing indicator appears
- Once content arrives, label updates to "Assistant message"
- `aria-live="polite"` ensures progressive content is announced

### Screen Reader Support

The interface is fully compatible with:
- **JAWS** (Job Access With Speech)
- **NVDA** (NonVisual Desktop Access)
- **VoiceOver** (macOS and iOS)
- **TalkBack** (Android)
- **Narrator** (Windows)

#### Screen Reader Text
Hidden text provides context for screen reader users:
```css
.screen-reader-text {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}
```

### Keyboard Navigation

All interactive elements are fully keyboard accessible:

- **Tab**: Navigate between interactive elements
- **Shift+Tab**: Navigate backwards
- **Enter/Space**: Activate buttons and controls
- **Escape**: Close expanded panels (when applicable)

#### Focus Indicators
Clear focus indicators for keyboard navigation:
```css
.wp-mcp-ai-chat__button:focus-visible {
    outline: 2px solid var(--wp-mcp-ai-color-shortcut-focus-ring, rgba(59, 130, 246, 0.6));
    outline-offset: 2px;
}
```

### Color Contrast

All text and interactive elements meet WCAG AA contrast requirements:
- **Normal text**: Minimum 4.5:1 contrast ratio
- **Large text**: Minimum 3:1 contrast ratio
- **Interactive elements**: Minimum 3:1 contrast ratio against background

### High Contrast Mode

Special styles for users with high contrast preferences:
```css
@media (prefers-contrast: high) {
    .wp-mcp-ai-chat {
        border-width: 2px;
    }
    /* Thicker borders for better visibility */
}
```

### Reduced Motion

Respects user preference for reduced motion:
```css
@media (prefers-reduced-motion: reduce) {
    .wp-mcp-ai-chat__message--streaming .wp-mcp-ai-chat__bubble::after,
    .wp-mcp-ai-chat__loading-spinner {
        animation: none;
        transition: none;
    }
}
```

## Responsive Design

The chat interface is fully responsive and optimized for all device sizes.

### Breakpoints

#### Small Mobile (< 480px)
- **Container Padding**: 12px
- **Border Radius**: 8px
- **Messages Height**: 280px
- **Button Size**: 44px × 44px (touch-friendly)
- **Input Font Size**: 16px (prevents iOS zoom)
- **Bubble Padding**: 10px 12px
- **Bubble Font Size**: 15px

#### Mobile (480px - 639px)
- **Container Padding**: 16px
- **Messages Height**: 320px
- **Button Size**: 44px × 44px
- **Input Font Size**: 16px

#### Tablet (640px - 1023px)
- **Max Width**: 640px
- **Container Padding**: 18px
- **Messages Height**: 340px

#### Desktop (1024px+)
- **Max Width**: 720px
- **Messages Height**: 400px

### Touch-Friendly Design

All interactive elements meet minimum touch target size of **44px × 44px** as recommended by Apple and Google accessibility guidelines.

### Viewport Optimization

```html
<!-- Recommended viewport meta tag -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
```

### iOS-Specific Improvements

- Input font size is **16px minimum** to prevent automatic zoom on iOS
- Touch targets meet iOS Human Interface Guidelines
- Proper handling of iOS keyboard appearance

### Responsive Images and Media

All attachments and media scale appropriately:
```css
.wp-mcp-ai-chat__bubble {
    max-width: 100%;
    word-break: break-word;
}
```

## User Feedback

The interface provides clear feedback for all user actions and system states.

### Loading States

#### Visual Loading Indicator
When sending messages or processing:
```html
<div class="wp-mcp-ai-chat__status wp-mcp-ai-chat__status--loading">
    <span class="wp-mcp-ai-chat__loading-spinner" aria-hidden="true"></span>
    <span>Sending message…</span>
</div>
```

#### Typing Indicator
During streaming responses, a visual pulse animation appears:
```css
.wp-mcp-ai-chat__message--streaming .wp-mcp-ai-chat__bubble::after {
    content: '';
    animation: wp-mcp-ai-typing-pulse 1.4s infinite ease-in-out;
}
```

### Status Messages

Status messages automatically appear for:
- **Sending**: "Sending message…"
- **Waiting**: "Waiting for the assistant…"
- **Processing**: "Processing response…"
- **Uploading**: "Uploading '[filename]'…"
- **Transcribing**: "Transcribing audio…"
- **Recording**: "Recording… tap to stop."
- **Tool Execution**: "Running tool: [tool name]"

### Progress Indication

File uploads and long-running operations show:
1. **Status message** describing the action
2. **Visual spinner** animation
3. **Disabled controls** to prevent duplicate actions
4. **aria-busy** state for screen readers

### Error States

Clear error messages with:
- Visual error styling
- Descriptive error text
- Accessible announcements via aria-live regions

### Success Confirmations

Successful actions provide:
- Visual confirmation (e.g., checkmark for copy)
- Status message updates
- Accessible announcements

## Testing Guidelines

### Manual Accessibility Testing

#### Keyboard Navigation Test
1. Use only keyboard (Tab, Shift+Tab, Enter, Space)
2. Verify all interactive elements are reachable
3. Check focus indicators are visible
4. Test form submission with Enter key

#### Screen Reader Test
1. Enable screen reader (NVDA, JAWS, VoiceOver)
2. Navigate through the chat interface
3. Verify all messages are announced
4. Check button labels are descriptive
5. Test status updates are announced
6. Verify streaming messages are accessible

#### Color Contrast Test
Use tools like:
- **WAVE** (Web Accessibility Evaluation Tool)
- **axe DevTools**
- **Chrome Lighthouse**
- **Color Contrast Analyzer**

#### Responsive Design Test
Test on:
- **Mobile devices**: iPhone, Android phones (< 480px)
- **Tablets**: iPad, Android tablets (640px - 1023px)
- **Desktop**: Various screen sizes (1024px+)
- **Orientation**: Portrait and landscape

### Automated Testing

#### HTML Validation
```bash
# Validate rendered HTML
npx html-validate path/to/output.html
```

#### ARIA Validation
```bash
# Check ARIA attributes
npx eslint --plugin jsx-a11y
```

#### PHPUnit Tests
Run accessibility test:
```bash
composer run test -- --filter test_chat_shortcode_has_accessibility_attributes
```

### Browser Compatibility

Tested and supported on:
- **Chrome** 90+
- **Firefox** 88+
- **Safari** 14+
- **Edge** 90+
- **Mobile browsers**: iOS Safari, Chrome Mobile, Samsung Internet

### Assistive Technology Compatibility

Tested with:
- **NVDA** 2020.4+
- **JAWS** 2020+
- **VoiceOver** (macOS 11+, iOS 14+)
- **TalkBack** (Android 10+)

## Best Practices

### For Developers

1. **Always provide aria-label** for icon-only buttons
2. **Use semantic HTML** (button, form, input, label)
3. **Test with keyboard only** before committing
4. **Run screen reader** to verify announcements
5. **Check color contrast** for all text
6. **Respect user preferences** (prefers-reduced-motion, prefers-contrast)
7. **Maintain logical tab order**
8. **Provide text alternatives** for all non-text content

### For Content Creators

1. **Use descriptive assistant names** for better context
2. **Provide clear assistant descriptions** visible to all users
3. **Avoid relying on color alone** to convey information
4. **Test with actual assistive technology**

### For Site Administrators

1. **Don't disable focus outlines** globally
2. **Ensure sufficient color contrast** in theme customizations
3. **Test responsive behavior** on real devices
4. **Monitor accessibility** in production

## Resources

- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [ARIA Authoring Practices Guide](https://www.w3.org/WAI/ARIA/apg/)
- [WebAIM Screen Reader Testing](https://webaim.org/articles/screenreader_testing/)
- [iOS Human Interface Guidelines](https://developer.apple.com/design/human-interface-guidelines/accessibility)
- [Material Design Accessibility](https://material.io/design/usability/accessibility.html)

## Changelog

### Version 1.0.0 (Current)
- Added comprehensive ARIA attributes to all interactive elements
- Implemented responsive breakpoints for mobile, tablet, and desktop
- Added visual loading indicators and typing animations
- Added high contrast and reduced motion support
- Implemented keyboard navigation with visible focus indicators
- Added comprehensive screen reader support
- Added accessibility test coverage
