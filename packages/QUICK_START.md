# Quick Start Guide - NV oOS NPM Packages

## Installation

Install all three packages:

```bash
# Storage utilities (zero dependencies)
npm install @nvdigitalsolutions/nvoos-storage

# Markdown renderer (requires marked and dompurify)
npm install @nvdigitalsolutions/nvoos-markdown marked dompurify

# Event system (requires fetch-event-source)
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source
```

## Usage Examples

### 1. Storage with Web Worker

```javascript
import { StorageUtil } from '@nvdigitalsolutions/nvoos-storage';

// Configure once
StorageUtil.configure({
  workerUrl: '/storage-worker.js',
  sizeThreshold: 10000
});

// Parse large JSON without blocking
const data = await StorageUtil.parseJSON(largeJsonString);

// Stringify large objects
const json = await StorageUtil.stringifyJSON(complexObject);
```

**Create `/public/storage-worker.js`:**

```javascript
self.addEventListener('message', function(e) {
  const { action, data, id } = e.data;
  
  try {
    const result = action === 'parse' 
      ? JSON.parse(data) 
      : JSON.stringify(data);
    
    self.postMessage({ id, success: true, result });
  } catch (error) {
    self.postMessage({ id, success: false, error: error.message });
  }
});
```

### 2. Secure Markdown Rendering

```javascript
import MarkdownRenderer from '@nvdigitalsolutions/nvoos-markdown';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const renderer = new MarkdownRenderer(marked, DOMPurify);

// Render AI-generated content safely
const html = renderer.render(`
# AI Response

Here's some **formatted** text with \`code\`

\`\`\`javascript
const greeting = "Hello World";
\`\`\`
`);

document.getElementById('output').innerHTML = html;
```

### 3. Real-Time Streaming

```javascript
import { SSEService, JobEventBus } from '@nvdigitalsolutions/nvoos-events';

// Stream AI responses
const stream = SSEService.connect('https://api.example.com/chat', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' },
  body: { message: 'Hello AI' },
  
  onMessage: (data) => {
    console.log('Received:', data);
    document.getElementById('output').textContent += data.token;
  },
  
  onError: (error) => {
    console.error('Stream error:', error);
  }
});

// Close when done
stream.close();
```

### 4. Job Tracking

```javascript
import { JobEventBus } from '@nvdigitalsolutions/nvoos-events';

// Listen for job events
JobEventBus.on('job:completed', (event) => {
  console.log(`Job ${event.jobId} done:`, event.data);
});

// Start a background job
const response = await fetch('/api/jobs', {
  method: 'POST',
  body: JSON.stringify({ task: 'generate-report' })
});
const { jobId } = await response.json();

// Watch until completion
try {
  const result = await JobEventBus.watchJob(jobId, {
    onProgress: (data) => {
      updateProgressBar(data.progress);
    },
    timeout: 60000 // 1 minute
  });
  
  console.log('Job result:', result);
} catch (error) {
  console.error('Job failed:', error);
}

// Update from backend webhook/polling
function handleJobUpdate(data) {
  JobEventBus.handleJobUpdate(data.jobId, data);
}
```

## Complete Example: AI Chat Application

```javascript
import { StorageUtil } from '@nvdigitalsolutions/nvoos-storage';
import MarkdownRenderer from '@nvdigitalsolutions/nvoos-markdown';
import { SSEService } from '@nvdigitalsolutions/nvoos-events';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

// Initialize components
StorageUtil.configure({ workerUrl: '/storage-worker.js' });
const renderer = new MarkdownRenderer(marked, DOMPurify);

// Load chat history
async function loadHistory() {
  const stored = localStorage.getItem('chat-history');
  if (stored) {
    return await StorageUtil.parseJSON(stored);
  }
  return [];
}

// Save chat history
async function saveHistory(messages) {
  const json = await StorageUtil.stringifyJSON(messages);
  localStorage.setItem('chat-history', json);
}

// Send message and stream response
async function sendMessage(message) {
  const messages = await loadHistory();
  messages.push({ role: 'user', content: message });
  
  let aiResponse = '';
  
  const stream = SSEService.connect('/api/chat', {
    method: 'POST',
    body: { messages },
    
    onMessage: (data) => {
      if (data.token) {
        aiResponse += data.token;
        const html = renderer.render(aiResponse);
        document.getElementById('response').innerHTML = html;
      }
    },
    
    onError: (error) => {
      console.error('Stream error:', error);
    }
  });
  
  // Wait for stream to complete
  await new Promise(resolve => {
    setTimeout(() => {
      stream.close();
      messages.push({ role: 'assistant', content: aiResponse });
      saveHistory(messages);
      resolve();
    }, 30000); // Max 30 seconds
  });
}

// Usage
sendMessage('Explain quantum computing');
```

## TypeScript Support

All packages include TypeScript definitions:

```typescript
import type { StorageUtilInterface } from '@nvdigitalsolutions/nvoos-storage';
import type { MarkdownConfig } from '@nvdigitalsolutions/nvoos-markdown';
import type { SSEOptions, JobEventBusType } from '@nvdigitalsolutions/nvoos-events';

const config: MarkdownConfig = {
  codeBlockClass: 'my-code',
  allowedTags: ['p', 'strong', 'em']
};
```

## Testing Locally

Build all packages:

```bash
cd packages/nvoos-storage && npm run build
cd ../nvoos-markdown && npm run build
cd ../nvoos-events && npm run build
```

## Common Issues

### "Worker URL not configured"

```javascript
// Make sure to call configure() before using
StorageUtil.configure({ workerUrl: '/storage-worker.js' });
```

### "marked is not defined"

```javascript
// Import marked before using MarkdownRenderer
import { marked } from 'marked';
import DOMPurify from 'dompurify';
```

### SSE Connection Fails

```javascript
// Enable debug logging
SSEService.enableDebug();

// Check CORS headers on server
// Ensure content-type: text/event-stream
```

## Next Steps

1. ⭐ Star the repository
2. 🐛 Report issues
3. 📖 Read full documentation in each package's README
4. 🤝 Contribute improvements

## Support

- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation**: `/packages/[package-name]/README.md`
- **Website**: https://nvdigitalsolutions.com

---

Built with ❤️ by NV Digital Solutions
