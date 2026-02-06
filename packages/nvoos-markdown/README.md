# @nvdigital/nvoos-markdown

Security-hardened markdown renderer with built-in XSS protection, powered by marked and DOMPurify.

**Extracted from:** [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress Plugin

## Why This Package?

This renderer was built to safely display AI-generated content in the NV oOS WordPress plugin. AI assistants can generate arbitrary markdown, including potentially malicious code. This package provides:

- **Pre-configured Security**: Sensible defaults that prevent XSS attacks
- **Production-Tested**: Battle-tested rendering thousands of AI responses daily
- **Customizable**: Override defaults while maintaining security
- **Lightweight Wrapper**: Minimal abstraction over industry-standard libraries

### Real-World Use Case

In NV oOS, GPT-4 generates markdown responses that include:
- Code blocks with syntax highlighting
- Links to external resources
- Inline formatting (bold, italic, code)
- Lists and blockquotes

This package ensures all output is safe while preserving formatting and functionality.

## Installation

```bash
npm install @nvdigital/nvoos-markdown marked dompurify
```

## Quick Start

```javascript
import MarkdownRenderer from '@nvdigital/nvoos-markdown';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const renderer = new MarkdownRenderer(marked, DOMPurify);

// Render AI-generated markdown safely
const html = renderer.render('# Hello **World**\n\nThis is `code`');
```

## API

### `new MarkdownRenderer(marked, DOMPurify, config)`

Create a new renderer instance.

**Parameters:**
- `marked` - The marked library instance
- `DOMPurify` - The DOMPurify library instance
- `config` (optional) - Custom configuration

**Config Options:**
- `codeBlockClass` (string): CSS class for code blocks (default: 'nvoos-code-block')
- `imageClass` (string): CSS class for images (default: 'nvoos-image')
- `allowedTags` (array): HTML tags to allow
- `allowedAttributes` (array): HTML attributes to allow

### `renderer.render(markdown)`

Render markdown to sanitized HTML.

**Returns:** `string` - Safe HTML output

### `renderer.renderInline(text)`

Render inline markdown only (bold, italic, code).

**Returns:** `string` - Safe HTML output

## Security Features

✅ XSS protection via DOMPurify  
✅ URL validation (blocks javascript: and data: schemes)  
✅ HTML entity escaping  
✅ Configurable tag/attribute allowlist  
✅ Safe code block rendering  
✅ Image lazy loading by default

## Examples

### Custom CSS Classes

```javascript
const renderer = new MarkdownRenderer(marked, DOMPurify, {
  codeBlockClass: 'my-code',
  imageClass: 'my-img'
});
```

### Restricted HTML

```javascript
const renderer = new MarkdownRenderer(marked, DOMPurify, {
  allowedTags: ['p', 'strong', 'em', 'code'],
  allowedAttributes: ['class']
});
```

### Inline Rendering Only

```javascript
const inline = renderer.renderInline('This is **bold** and `code`');
// Output: This is <strong>bold</strong> and <code>code</code>
```

## Browser Support

- Chrome/Edge 113+
- Firefox 115+
- Safari 16.4+
- Any modern browser

## From WordPress to Universal

Extracted from production WordPress code and made framework-agnostic:

- ❌ Removed: `window.wpMcpAiChatMarkdown` global
- ❌ Removed: WordPress IIFE wrapper
- ✅ Added: ES module exports
- ✅ Added: Class-based API
- ✅ Added: TypeScript definitions
- ✅ Added: Customizable configuration

## Performance

- **Parsing**: Handled by marked (fast)
- **Sanitization**: Handled by DOMPurify (battle-tested)
- **Overhead**: <1ms for typical AI responses

## License

MIT © NV Digital Solutions

## Links

- [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [NV Digital Solutions](https://nvdigitalsolutions.com)
- [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
