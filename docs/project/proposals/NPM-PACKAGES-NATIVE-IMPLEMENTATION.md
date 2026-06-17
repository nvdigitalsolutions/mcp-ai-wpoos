# NPM Packages for Native/Hybrid Ralph Implementation

**Focus:** Web-based autonomous orchestration without CLI requirements  
**Strategy:** Bundle NPM packages with WordPress plugin for enhanced capabilities  
> **Status:** ✅ Implemented (v1.1.29) — 23 published nvoos-* npm packages under packages/
**Date:** 2026-01-22

---

## 🎯 WordPress Plugin Constraints

### What We CAN Do
- ✅ Bundle NPM packages in plugin (already doing this)
- ✅ Use esbuild to compile/minify JavaScript
- ✅ Include Node.js modules in vendor directory
- ✅ Run build process during development
- ✅ Ship pre-built bundles to users

### What We CANNOT Expect
- ❌ Users running `npm install` on production
- ❌ Node.js runtime on user servers (unless Pro + VPS)
- ❌ Build tools on production servers
- ❌ Dynamic package installation

### Current Plugin Pattern (Already Working)
```javascript
// package.json
"postinstall": "npm run install:chartjs && npm run install:vectorizer",
"install:chartjs": "cp node_modules/chart.js/dist/chart.umd.min.js assets/js/vendor/chart.min.js"

// Result: Bundled vendor files shipped with plugin
```

---

## 📦 Recommended NPM Packages for Ralph Pattern

### Category 1: Task Planning & Markdown (CRITICAL) ⭐⭐⭐⭐⭐

#### 1. **marked** (Already Installed ✅)
**Current Version:** 9.1.6  
**Purpose:** Parse markdown task lists  
**Size:** ~50KB minified  
**License:** MIT

**Why Perfect for Us:**
- Already in dependencies ✅
- GitHub Flavored Markdown support (task lists)
- Fast and lightweight
- Browser-safe
- Can parse `- [ ]` and `- [x]` checkboxes

**Usage for Task Plans:**
```javascript
import { marked } from 'marked';

// Parse task plan markdown
const taskPlan = `
# Project: Market Research
- [x] Define research scope
- [ ] Identify competitors
- [ ] Analyze pricing
`;

const tokens = marked.lexer(taskPlan);
// Extract task items, track completion

function parseTaskList(markdown) {
    const tasks = [];
    const lines = markdown.split('\n');
    
    lines.forEach((line, index) => {
        const checkboxMatch = line.match(/^(\s*)- \[([ x])\] (.+)$/);
        if (checkboxMatch) {
            tasks.push({
                index: index,
                indent: checkboxMatch[1].length,
                completed: checkboxMatch[2] === 'x',
                text: checkboxMatch[3],
                line: line
            });
        }
    });
    
    return {
        tasks: tasks,
        total: tasks.length,
        completed: tasks.filter(t => t.completed).length,
        progress: tasks.length ? (tasks.filter(t => t.completed).length / tasks.length * 100) : 0
    };
}

// Update task completion
function updateTaskCompletion(markdown, taskIndex, completed) {
    const lines = markdown.split('\n');
    let taskCount = -1;
    
    lines.forEach((line, index) => {
        if (line.match(/^(\s*)- \[([ x])\] (.+)$/)) {
            taskCount++;
            if (taskCount === taskIndex) {
                const match = line.match(/^(\s*)- \[([ x])\] (.+)$/);
                lines[index] = `${match[1]}- [${completed ? 'x' : ' '}] ${match[3]}`;
            }
        }
    });
    
    return lines.join('\n');
}
```

**Integration:**
```javascript
// In autonomous loop
const taskPlan = getTaskPlan(planId);
const parsed = parseTaskList(taskPlan.content);

if (parsed.progress === 100) {
    // All tasks complete
    completionIndicators++;
}
```

**No Additional Installation Needed** - Already have it!

---

#### 2. **remark** + **remark-gfm** (RECOMMENDED ADD)
**Purpose:** Advanced markdown processing with GFM  
**Size:** ~100KB minified  
**License:** MIT

**Why Better Than Just Marked:**
- AST (Abstract Syntax Tree) manipulation
- Plugin ecosystem
- Better task list extraction
- Can modify tasks programmatically

**Installation:**
```bash
npm install remark remark-gfm remark-parse
```

**Usage:**
```javascript
import { unified } from 'unified';
import remarkParse from 'remark-parse';
import remarkGfm from 'remark-gfm';

const processor = unified()
    .use(remarkParse)
    .use(remarkGfm);

const ast = processor.parse(markdown);

// Extract all task items
function extractTasks(ast) {
    const tasks = [];
    
    function visit(node) {
        if (node.type === 'listItem' && node.checked !== null) {
            tasks.push({
                checked: node.checked,
                text: extractText(node),
                node: node
            });
        }
        
        if (node.children) {
            node.children.forEach(visit);
        }
    }
    
    visit(ast);
    return tasks;
}
```

**Decision:** Stick with `marked` for now, add `remark` if we need AST manipulation.

---

### Category 2: Circuit Breakers & Resilience (CRITICAL) ⭐⭐⭐⭐⭐

#### 3. **opossum** (HIGHLY RECOMMENDED)
**Purpose:** Circuit breaker pattern  
**Size:** ~15KB minified  
**License:** Apache-2.0  
**Popularity:** 1M+ weekly downloads

**Why Perfect for Ralph Pattern:**
- Production-proven
- Event-driven (perfect for monitoring)
- Timeout support
- Fallback functions
- Statistics tracking
- Promise-based

**Installation:**
```bash
npm install opossum
```

**Usage for Autonomous Loops:**
```javascript
import CircuitBreaker from 'opossum';

// Wrap tool execution in circuit breaker
const toolBreaker = new CircuitBreaker(executeTool, {
    timeout: 30000, // 30 seconds
    errorThresholdPercentage: 50,
    resetTimeout: 30000, // Try again after 30s
    rollingCountTimeout: 10000,
    rollingCountBuckets: 10
});

// Monitor circuit breaker
toolBreaker.on('open', () => {
    console.log('Circuit breaker opened - tool failing repeatedly');
    // Trigger autonomous loop pause
    pauseAutonomousSession(sessionId, 'circuit_breaker_open');
});

toolBreaker.on('halfOpen', () => {
    console.log('Circuit breaker half-open - testing if recovered');
});

toolBreaker.on('close', () => {
    console.log('Circuit breaker closed - tool working again');
});

// Get stats
const stats = toolBreaker.stats;
console.log(`Success rate: ${stats.successRate}%`);
console.log(`Failures: ${stats.failures}`);

// Use in autonomous loop
async function executeToolSafely(toolName, args) {
    try {
        const result = await toolBreaker.fire(toolName, args);
        return { success: true, result };
    } catch (error) {
        if (error.message === 'Breaker is open') {
            // Circuit breaker is open - tool is failing
            return { 
                success: false, 
                error: 'Tool unavailable (circuit breaker open)',
                shouldPauseLoop: true
            };
        }
        return { success: false, error: error.message };
    }
}
```

**Integration Points:**
1. Wrap each tool execution
2. Monitor `open` events → pause autonomous sessions
3. Track stats for health dashboard
4. Use fallback functions for graceful degradation

---

#### 4. **cockatiel** (ALTERNATIVE - TypeScript Native)
**Purpose:** Comprehensive resilience patterns  
**Size:** ~30KB minified  
**License:** MIT

**Features:**
- Circuit breaker
- Retry with exponential backoff
- Timeout
- Bulkhead (rate limiting)
- Fallback
- Rate limiter

**Installation:**
```bash
npm install cockatiel
```

**Usage:**
```javascript
import { ConsecutiveBreaker, ExponentialBackoff, TimeoutStrategy, circuitBreaker, retry, timeout, wrap } from 'cockatiel';

// Create resilience policy
const policy = wrap(
    retry(new ExponentialBackoff({ maxDelay: 10000 }), { maxAttempts: 3 }),
    circuitBreaker(new ConsecutiveBreaker({ threshold: 5, duration: 30000 })),
    timeout(30000)
);

// Execute with all resilience patterns
async function executeToolWithResilience(toolName, args) {
    return policy.execute(() => executeTool(toolName, args));
}
```

**Decision:** Use **opossum** for simplicity, consider **cockatiel** if we need advanced patterns.

---

### Category 3: Session Management (HIGH PRIORITY) ⭐⭐⭐⭐

#### 5. **node-cache** (RECOMMENDED FOR NODE.JS TOOLS)
**Purpose:** Simple in-memory cache with TTL  
**Size:** ~10KB  
**License:** MIT

**Why Useful:**
- Session state caching
- TTL for automatic expiration
- Statistics tracking
- Event emitters

**Installation:**
```bash
npm install node-cache
```

**Usage for Session Management:**
```javascript
import NodeCache from 'node-cache';

// Create cache with 24h TTL (session expiration)
const sessionCache = new NodeCache({
    stdTTL: 86400, // 24 hours
    checkperiod: 120, // Check for expired keys every 2 minutes
    useClones: false
});

// Store session data
function storeSessionState(sessionId, state) {
    sessionCache.set(sessionId, {
        ...state,
        lastActivity: Date.now()
    });
}

// Get session (auto-expired if > 24h)
function getSessionState(sessionId) {
    return sessionCache.get(sessionId);
}

// Monitor expiration
sessionCache.on('expired', (key, value) => {
    console.log(`Session ${key} expired after 24 hours`);
    // Clean up autonomous session
    terminateAutonomousSession(key, 'session_expired');
});

// Check if session is still valid
function isSessionValid(sessionId) {
    return sessionCache.has(sessionId);
}
```

**Note:** This is for Node.js tools only. For PHP/WordPress, we use database + transients.

---

### Category 4: Data Aggregation & Research (HIGH PRIORITY) ⭐⭐⭐⭐

#### 6. **cheerio** (HIGHLY RECOMMENDED)
**Purpose:** Fast, jQuery-like HTML parsing  
**Size:** ~200KB  
**License:** MIT  
**Popularity:** 7M+ weekly downloads

**Why Perfect for Research Tools:**
- Parse HTML from web_search results
- Extract structured data
- Clean up content for reports
- Lightweight (server-side jQuery)

**Installation:**
```bash
npm install cheerio
```

**Usage for Research Compilation:**
```javascript
import * as cheerio from 'cheerio';

// Enhanced web_search tool with parsing
async function webSearchWithParsing(query) {
    const results = await webSearch(query);
    
    const parsedResults = await Promise.all(
        results.map(async (result) => {
            const html = await fetchUrl(result.url);
            const $ = cheerio.load(html);
            
            return {
                url: result.url,
                title: $('title').text(),
                description: $('meta[name="description"]').attr('content'),
                headings: $('h1, h2, h3').map((i, el) => $(el).text()).get(),
                paragraphs: $('p').slice(0, 5).map((i, el) => $(el).text()).get(),
                images: $('img').map((i, el) => $(el).attr('src')).get(),
                links: $('a').map((i, el) => $(el).attr('href')).get()
            };
        })
    );
    
    return parsedResults;
}

// Use in autonomous research loop
async function autonomousResearchStep(taskPlan, iteration) {
    const currentTask = getNextIncompleteTask(taskPlan);
    
    if (currentTask.text.includes('research')) {
        const query = extractSearchQuery(currentTask.text);
        const data = await webSearchWithParsing(query);
        
        // Aggregate findings
        const summary = {
            query: query,
            sources: data.length,
            keyPoints: extractKeyPoints(data),
            relatedTopics: extractTopics(data)
        };
        
        // Store in knowledge base
        await storeResearchFindings(taskPlan.id, summary);
        
        // Update task as complete
        await updateTaskCompletion(taskPlan.id, currentTask.index, true);
    }
}
```

**Already Have:** We have web_search tool, add cheerio to enhance it!

---

#### 7. **turndown** (RECOMMENDED)
**Purpose:** Convert HTML to Markdown  
**Size:** ~30KB  
**License:** MIT

**Why Useful for Research:**
- Convert scraped HTML to clean markdown
- Preserve formatting
- Remove clutter
- Create readable reports

**Installation:**
```bash
npm install turndown
```

**Usage:**
```javascript
import TurndownService from 'turndown';

const turndownService = new TurndownService({
    headingStyle: 'atx',
    codeBlockStyle: 'fenced'
});

// Clean up web content for reports
function htmlToMarkdown(html) {
    return turndownService.turndown(html);
}

// Use in research compilation
async function compileResearchReport(findings) {
    let markdown = `# Research Report\n\n`;
    
    findings.forEach(finding => {
        markdown += `## ${finding.title}\n\n`;
        markdown += `**Source:** ${finding.url}\n\n`;
        
        // Convert HTML content to markdown
        const cleanContent = htmlToMarkdown(finding.content);
        markdown += cleanContent + '\n\n';
    });
    
    return markdown;
}
```

---

### Category 5: Report Generation (MEDIUM PRIORITY) ⭐⭐⭐

#### 8. **pdf-lib** (LIGHTWEIGHT ALTERNATIVE)
**Purpose:** Create PDFs in JavaScript  
**Size:** ~200KB  
**License:** MIT

**Why Better Than PDFKit for Us:**
- Browser-compatible (can run client-side)
- No native dependencies
- Smaller size
- Modern API

**Installation:**
```bash
npm install pdf-lib
```

**Usage:**
```javascript
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';

async function generateResearchReportPDF(markdown) {
    const pdfDoc = await PDFDocument.create();
    const page = pdfDoc.addPage();
    const font = await pdfDoc.embedFont(StandardFonts.Helvetica);
    
    const { width, height } = page.getSize();
    const fontSize = 12;
    
    page.drawText(markdown, {
        x: 50,
        y: height - 50,
        size: fontSize,
        font: font,
        color: rgb(0, 0, 0),
        maxWidth: width - 100
    });
    
    const pdfBytes = await pdfDoc.save();
    return pdfBytes;
}
```

**Note:** Pro addon already has `pdfkit` (17KB types). Consider if we need both.

---

### Category 6: Workflow Orchestration (OPTIONAL) ⭐⭐

#### 9. **p-queue** (RECOMMENDED)
**Purpose:** Promise queue with concurrency control  
**Size:** ~10KB  
**License:** MIT

**Why Useful:**
- Rate limit tool executions
- Control concurrent API calls
- Prevent overwhelming servers
- Perfect for autonomous loops

**Installation:**
```bash
npm install p-queue
```

**Usage:**
```javascript
import PQueue from 'p-queue';

// Create queue with concurrency limit
const toolQueue = new PQueue({
    concurrency: 3, // Max 3 simultaneous tool executions
    interval: 1000, // Time window
    intervalCap: 10 // Max 10 executions per second
});

// Add tool execution to queue
async function executeToolQueued(toolName, args) {
    return toolQueue.add(async () => {
        return executeTool(toolName, args);
    }, {
        priority: args.priority || 0
    });
}

// Monitor queue
console.log(`Queue size: ${toolQueue.size}`);
console.log(`Pending: ${toolQueue.pending}`);

// Use in autonomous loop
async function autonomousLoopWithQueue(taskPlan, maxIterations) {
    for (let i = 0; i < maxIterations; i++) {
        const tasks = getToolCallsForIteration(taskPlan, i);
        
        // Execute all tasks with rate limiting
        const results = await Promise.all(
            tasks.map(task => executeToolQueued(task.tool, task.args))
        );
        
        // Process results
        await processIterationResults(results, taskPlan);
        
        // Check exit conditions
        if (await shouldExit(taskPlan, results)) {
            break;
        }
    }
}
```

---

### Category 7: Semantic Analysis (OPTIONAL) ⭐⭐

#### 10. **natural** (TEXT ANALYSIS)
**Purpose:** NLP toolkit for Node.js  
**Size:** ~1MB (large)  
**License:** MIT

**Features:**
- Tokenization
- Sentiment analysis
- Classification
- TF-IDF
- Phonetics

**Why Useful for Exit Detection:**
- Analyze response text for completion indicators
- Sentiment analysis (frustrated vs confident)
- Classify responses (question vs statement)

**Installation:**
```bash
npm install natural
```

**Usage:**
```javascript
import natural from 'natural';

const classifier = new natural.BayesClassifier();

// Train classifier for completion indicators
classifier.addDocument('task is complete', 'complete');
classifier.addDocument('all done', 'complete');
classifier.addDocument('finished working on this', 'complete');
classifier.addDocument('ready for review', 'complete');
classifier.addDocument('need more information', 'incomplete');
classifier.addDocument('working on it', 'incomplete');
classifier.addDocument('still in progress', 'incomplete');

classifier.train();

// Detect completion
function detectCompletionIndicators(responseText) {
    const classification = classifier.classify(responseText);
    const confidence = classifier.getClassifications(responseText)[0].value;
    
    return {
        isComplete: classification === 'complete',
        confidence: confidence,
        indicators: extractIndicatorPhrases(responseText)
    };
}

// Use in exit detection
async function checkExitConditions(sessionId, lastResponse) {
    const semantic = detectCompletionIndicators(lastResponse);
    const explicitSignal = lastResponse.includes('EXIT_SIGNAL: true');
    
    return {
        shouldExit: semantic.isComplete && explicitSignal,
        completionScore: semantic.confidence,
        explicitSignal: explicitSignal
    };
}
```

**Decision:** Maybe later - large size for marginal benefit. Use regex patterns first.

---

## 📋 Recommended Package List

### Tier 1: IMMEDIATE ADD (Week 1)

| Package | Purpose | Size | Already Have? |
|---------|---------|------|---------------|
| **marked** | Markdown parsing | 50KB | ✅ Yes |
| **opossum** | Circuit breaker | 15KB | ❌ ADD |
| **cheerio** | HTML parsing | 200KB | ❌ ADD |
| **p-queue** | Rate limiting | 10KB | ❌ ADD |

**Total Added:** ~225KB minified

### Tier 2: NEAR TERM (Phase 2)

| Package | Purpose | Size | Priority |
|---------|---------|------|----------|
| **turndown** | HTML → Markdown | 30KB | High |
| **remark** + **remark-gfm** | Advanced markdown | 100KB | Medium |
| **pdf-lib** | PDF generation | 200KB | Medium |

**Total Added:** ~330KB minified

### Tier 3: OPTIONAL (Phase 3)

| Package | Purpose | Size | Priority |
|---------|---------|------|----------|
| **node-cache** | Session caching | 10KB | Low (Node.js only) |
| **natural** | NLP/Semantic | 1MB | Very Low (too large) |
| **cockatiel** | Advanced resilience | 30KB | Low (opossum sufficient) |

---

## 🔧 Implementation Strategy

### Step 1: Update package.json

```json
{
  "dependencies": {
    "@microsoft/fetch-event-source": "^2.0.1",
    "@neplex/vectorizer": "^0.0.5",
    "chart.js": "^4.4.7",
    "cheerio": "^1.0.0", // ADD
    "dompurify": "^3.3.0",
    "ky": "^1.14.0",
    "marked": "^9.1.6",
    "opossum": "^8.1.4", // ADD
    "p-queue": "^8.0.1", // ADD
    "turndown": "^7.2.0" // ADD
  }
}
```

### Step 2: Build Vendor Bundles

```javascript
// esbuild.config.js enhancements
import esbuild from 'esbuild';

// Build orchestration bundle
await esbuild.build({
    entryPoints: ['src/orchestration/index.js'],
    bundle: true,
    outfile: 'assets/js/orchestration-bundle.min.js',
    minify: true,
    format: 'iife',
    globalName: 'WpMcpAiOrchestration',
    external: [], // Bundle everything
});

// Build research bundle
await esbuild.build({
    entryPoints: ['src/research/index.js'],
    bundle: true,
    outfile: 'assets/js/research-bundle.min.js',
    minify: true,
    format: 'iife',
    globalName: 'WpMcpAiResearch',
});
```

### Step 3: Create Orchestration Module

```javascript
// src/orchestration/index.js
import CircuitBreaker from 'opossum';
import PQueue from 'p-queue';
import { marked } from 'marked';

export class AutonomousOrchestrator {
    constructor(options = {}) {
        this.maxIterations = options.maxIterations || 25;
        this.sessionTimeout = options.sessionTimeout || 86400000; // 24h
        
        // Circuit breaker for tool execution
        this.toolBreaker = new CircuitBreaker(this.executeTool.bind(this), {
            timeout: 30000,
            errorThresholdPercentage: 50,
            resetTimeout: 30000
        });
        
        // Rate limiting queue
        this.toolQueue = new PQueue({
            concurrency: 3,
            interval: 1000,
            intervalCap: 10
        });
        
        this.setupEventHandlers();
    }
    
    setupEventHandlers() {
        this.toolBreaker.on('open', () => {
            this.handleCircuitOpen();
        });
    }
    
    async executeAutonomousLoop(taskPlan, config) {
        const session = this.createSession(taskPlan, config);
        
        for (let i = 0; i < this.maxIterations; i++) {
            // Check session expiration
            if (this.isSessionExpired(session)) {
                return this.terminateSession(session, 'expired');
            }
            
            // Execute iteration
            const result = await this.executeIteration(session, i);
            
            // Update task plan
            await this.updateTaskPlan(taskPlan, result);
            
            // Check exit conditions
            const exitCheck = await this.checkExitConditions(session, result);
            if (exitCheck.shouldExit) {
                return this.completeSession(session, exitCheck.reason);
            }
            
            // Health check
            const health = await this.analyzeLoopHealth(session);
            if (health.shouldPause) {
                return this.pauseSession(session, health.reason);
            }
        }
        
        return this.terminateSession(session, 'max_iterations');
    }
    
    parseTaskPlan(markdown) {
        const tasks = [];
        const lines = markdown.split('\n');
        
        lines.forEach((line, index) => {
            const match = line.match(/^(\s*)- \[([ x])\] (.+)$/);
            if (match) {
                tasks.push({
                    index: index,
                    indent: match[1].length,
                    completed: match[2] === 'x',
                    text: match[3]
                });
            }
        });
        
        return {
            tasks,
            total: tasks.length,
            completed: tasks.filter(t => t.completed).length,
            progress: tasks.length ? 
                (tasks.filter(t => t.completed).length / tasks.length * 100) : 0
        };
    }
    
    async executeTool(toolName, args) {
        return this.toolQueue.add(async () => {
            return this.toolBreaker.fire(toolName, args);
        });
    }
    
    async checkExitConditions(session, result) {
        const taskPlan = await this.getTaskPlan(session.planId);
        const parsed = this.parseTaskPlan(taskPlan.content);
        
        // Condition 1: All tasks complete
        const allTasksComplete = parsed.progress === 100;
        
        // Condition 2: Completion indicators in response
        const completionIndicators = this.detectCompletionIndicators(result.response);
        
        // Condition 3: Explicit EXIT_SIGNAL
        const explicitSignal = result.response.includes('EXIT_SIGNAL: true');
        
        // Dual-condition gate: Indicators + Explicit Signal
        const shouldExit = (completionIndicators >= 2 && explicitSignal) || allTasksComplete;
        
        return {
            shouldExit,
            reason: shouldExit ? (allTasksComplete ? 'all_tasks_complete' : 'explicit_exit') : null,
            completionScore: completionIndicators,
            explicitSignal,
            taskProgress: parsed.progress
        };
    }
    
    detectCompletionIndicators(text) {
        const patterns = [
            /task\s+(is\s+)?complete/i,
            /all\s+done/i,
            /finished/i,
            /ready\s+for\s+review/i,
            /no\s+further\s+action/i,
            /objectives?\s+met/i,
            /requirements?\s+satisfied/i
        ];
        
        let count = 0;
        patterns.forEach(pattern => {
            if (pattern.test(text)) count++;
        });
        
        return count;
    }
    
    async analyzeLoopHealth(session) {
        const stats = this.toolBreaker.stats;
        const queueSize = this.toolQueue.size;
        
        // Check for stuck loop (same error repeating)
        const recentErrors = session.history.slice(-5).filter(h => h.error);
        const stuckLoop = recentErrors.length >= 5 && 
            recentErrors.every(e => e.error === recentErrors[0].error);
        
        // Check for circuit breaker open
        const circuitOpen = this.toolBreaker.opened;
        
        // Check for queue backup
        const queueBacklog = queueSize > 20;
        
        return {
            shouldPause: stuckLoop || circuitOpen || queueBacklog,
            reason: stuckLoop ? 'stuck_loop' : 
                    circuitOpen ? 'circuit_breaker_open' : 
                    queueBacklog ? 'queue_backlog' : null,
            health: {
                successRate: stats.successRate,
                failures: stats.failures,
                queueSize: queueSize
            }
        };
    }
}

// Export for WordPress
window.WpMcpAiOrchestration = { AutonomousOrchestrator };
```

### Step 4: Create Research Module

```javascript
// src/research/index.js
import * as cheerio from 'cheerio';
import TurndownService from 'turndown';

export class ResearchCompiler {
    constructor() {
        this.turndownService = new TurndownService({
            headingStyle: 'atx',
            codeBlockStyle: 'fenced'
        });
    }
    
    async enhancedWebSearch(query) {
        // Call existing web_search tool
        const results = await this.webSearch(query);
        
        // Parse and extract structured data
        const enriched = await Promise.all(
            results.map(async (result) => {
                const html = await this.fetchUrl(result.url);
                return this.parseHtml(html, result.url);
            })
        );
        
        return enriched;
    }
    
    parseHtml(html, url) {
        const $ = cheerio.load(html);
        
        return {
            url,
            title: $('title').text() || $('h1').first().text(),
            description: $('meta[name="description"]').attr('content'),
            headings: this.extractHeadings($),
            content: this.extractMainContent($),
            images: this.extractImages($),
            links: this.extractLinks($),
            markdown: this.convertToMarkdown(html)
        };
    }
    
    extractHeadings($) {
        return $('h1, h2, h3').map((i, el) => ({
            level: el.name,
            text: $(el).text().trim()
        })).get();
    }
    
    extractMainContent($) {
        // Remove navigation, footer, ads
        $('nav, footer, aside, .ad, .advertisement').remove();
        
        // Extract paragraphs
        return $('article p, main p, .content p, p').slice(0, 10)
            .map((i, el) => $(el).text().trim())
            .get()
            .filter(text => text.length > 50); // Filter short snippets
    }
    
    extractImages($) {
        return $('img').map((i, el) => ({
            src: $(el).attr('src'),
            alt: $(el).attr('alt'),
            title: $(el).attr('title')
        })).get().filter(img => img.src);
    }
    
    extractLinks($) {
        return $('a[href]').map((i, el) => ({
            href: $(el).attr('href'),
            text: $(el).text().trim()
        })).get();
    }
    
    convertToMarkdown(html) {
        return this.turndownService.turndown(html);
    }
    
    async compileResearchReport(findings, template = 'default') {
        let markdown = `# Research Report\n\n`;
        markdown += `**Date:** ${new Date().toISOString().split('T')[0]}\n`;
        markdown += `**Sources:** ${findings.length}\n\n`;
        markdown += `---\n\n`;
        
        findings.forEach((finding, index) => {
            markdown += `## ${index + 1}. ${finding.title}\n\n`;
            markdown += `**Source:** [${finding.url}](${finding.url})\n\n`;
            
            if (finding.description) {
                markdown += `> ${finding.description}\n\n`;
            }
            
            markdown += `### Key Points\n\n`;
            finding.content.slice(0, 3).forEach(point => {
                markdown += `- ${point}\n`;
            });
            markdown += `\n`;
        });
        
        markdown += `---\n\n`;
        markdown += `**Report Generated:** ${new Date().toISOString()}\n`;
        
        return markdown;
    }
    
    async aggregateData(sources, options = {}) {
        const data = await Promise.all(
            sources.map(source => this.fetchAndParse(source))
        );
        
        return {
            total: data.length,
            successful: data.filter(d => d.success).length,
            failed: data.filter(d => !d.success).length,
            combined: this.combineFindings(data.filter(d => d.success)),
            insights: this.extractInsights(data)
        };
    }
}

// Export for WordPress
window.WpMcpAiResearch = { ResearchCompiler };
```

### Step 5: PHP Integration

```php
/**
 * Enqueue orchestration assets
 */
function wp_mcp_ai_enqueue_orchestration_assets() {
    // Orchestration bundle
    wp_enqueue_script(
        'wp-mcp-ai-orchestration',
        WP_MCP_AI_URL . 'assets/js/orchestration-bundle.min.js',
        array(),
        WP_MCP_AI_VERSION,
        true
    );
    
    // Research bundle
    wp_enqueue_script(
        'wp-mcp-ai-research',
        WP_MCP_AI_URL . 'assets/js/research-bundle.min.js',
        array('wp-mcp-ai-orchestration'),
        WP_MCP_AI_VERSION,
        true
    );
    
    // Configuration
    wp_localize_script('wp-mcp-ai-orchestration', 'wpMcpAiOrchestrationConfig', array(
        'maxIterations' => get_option('wp_mcp_ai_max_iterations', 25),
        'sessionTimeout' => get_option('wp_mcp_ai_session_timeout', 86400),
        'circuitBreakerThreshold' => 50,
        'rateLimitPerMinute' => 10
    ));
}
add_action('admin_enqueue_scripts', 'wp_mcp_ai_enqueue_orchestration_assets');
```

---

## 📊 Size Analysis

### Current Plugin Size
- Base plugin: ~2MB (includes vendor JS)
- Pro addon: ~15MB (includes heavy dependencies like ffmpeg, sharp, pdfkit)

### Impact of New Packages (Tier 1)
- opossum: 15KB
- cheerio: 200KB
- p-queue: 10KB
- turndown: 30KB
**Total:** 255KB (0.25MB) - negligible impact!

### Bundled Size After Build
With minification + tree-shaking:
- orchestration-bundle.min.js: ~80KB
- research-bundle.min.js: ~150KB
**Total Added:** 230KB

---

## ✅ Recommendation

### Implement Immediately (Week 1)
1. ✅ Add `opossum` for circuit breakers
2. ✅ Add `cheerio` for HTML parsing
3. ✅ Add `p-queue` for rate limiting
4. ✅ Add `turndown` for HTML→Markdown
5. ✅ Use existing `marked` for task lists

### Phase 2 (Week 4-6)
1. Consider `remark` if AST manipulation needed
2. Evaluate `pdf-lib` vs existing `pdfkit`
3. Add `node-cache` for Node.js tools

### Avoid
1. ❌ `natural` - Too large (1MB)
2. ❌ Heavy workflow engines (Temporal, Zeebe) - Overkill
3. ❌ Anything requiring runtime Node.js on production

---

## 🚀 Next Steps

1. ✅ Update package.json with Tier 1 packages
2. ✅ Run `npm install`
3. ✅ Create orchestration module
4. ✅ Create research module
5. ✅ Build bundles with esbuild
6. ✅ Test in WordPress admin
7. ✅ Document usage for tools

**Timeline:** 2-3 days for package integration, 1-2 weeks for full implementation

**Result:** Native/hybrid autonomous orchestration with no CLI dependencies! 🎉
