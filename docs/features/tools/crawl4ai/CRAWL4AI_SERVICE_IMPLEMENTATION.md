# Crawl4AI Service Implementation

**Complete source code for deploying a Crawl4AI-compatible remote service**

This document provides copy-paste ready implementation code for setting up a Crawl4AI remote service that integrates with the NV oOS WordPress plugin.

## Table of Contents

- [Overview](#overview)
- [System Requirements](#system-requirements)
- [Architecture](#architecture)
- [Complete Python Implementation](#complete-python-implementation)
- [Docker Deployment](#docker-deployment)
- [Configuration Examples](#configuration-examples)
- [Integration with WordPress](#integration-with-wordpress)
- [Testing](#testing)

---

## Overview

The Crawl4AI remote service provides a REST API for web scraping and content extraction. It can run as:

1. **Remote Service**: FastAPI application with browser pool management
2. **Docker Container**: Containerized deployment with Playwright
3. **Local Fallback**: WordPress's built-in HTTP client (automatic in plugin)

The NV oOS plugin automatically:
- Connects to a remote Crawl4AI service when configured
- Falls back to local WordPress HTTP client when no service is available
- Tracks all jobs (remote, local, sync, async) in the job manager
- Provides monitoring UI in WordPress admin

---

## System Requirements

### Remote Service Requirements

```bash
# Python 3.8+
python3 --version

# Dependencies
- FastAPI (web framework)
- Uvicorn (ASGI server)
- Playwright (browser automation)
- pydantic (data validation)
```

### Docker Requirements

```bash
# Docker 20.10+
docker --version

# Docker Compose 2.0+
docker-compose --version
```

---

## Architecture

### Request Flow

```
┌─────────────┐         ┌──────────────┐         ┌─────────────┐
│  WordPress  │ ──────→ │  Crawl4AI    │ ──────→ │  Browser    │
│   Plugin    │  HTTP   │   Service    │  CDP    │   Pool      │
└─────────────┘         └──────────────┘         └─────────────┘
                              │
                              ↓
                        ┌──────────────┐
                        │  Job Queue   │
                        │  (In-Memory) │
                        └──────────────┘
```

### Components

1. **REST API** (`/crawl`): Submit crawl jobs
2. **Task Lookup** (`/task/{task_id}`): Check job status
3. **Browser Pool**: Manage Playwright browser instances
4. **Job Manager**: Track and process jobs
5. **Content Extractor**: HTML → Markdown/Text conversion

---

## Complete Python Implementation

### File Structure

```
crawl4ai-service/
├── app.py              # Main FastAPI application
├── browser_pool.py     # Browser instance manager
├── extractor.py        # Content extraction logic
├── requirements.txt    # Python dependencies
├── Dockerfile          # Container definition
└── docker-compose.yml  # Multi-container setup
```

### app.py - Main Application

```python
#!/usr/bin/env python3
"""
Crawl4AI Remote Service
Compatible with NV oOS WordPress Plugin

Provides a FastAPI-based REST API for web scraping with browser automation.
"""

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.responses import JSONResponse
from pydantic import BaseModel, HttpUrl, Field
from typing import List, Optional, Dict, Any
from enum import Enum
import asyncio
import hashlib
import time
import uuid
from datetime import datetime

app = FastAPI(
    title="Crawl4AI Service",
    description="Web scraping service for NV oOS WordPress plugin",
    version="1.0.0"
)

# In-memory storage (use Redis for production)
jobs_db: Dict[str, Dict[str, Any]] = {}
results_cache: Dict[str, Dict[str, Any]] = {}


class ExtractionStrategy(str, Enum):
    """Content extraction strategies"""
    NO_EXTRACTION = "NoExtractionStrategy"
    JSON_CSS = "JsonCssExtractionStrategy"
    LLM = "LLMExtractionStrategy"


class CrawlRequest(BaseModel):
    """Crawl job request model"""
    urls: List[HttpUrl] = Field(..., min_items=1, description="URLs to crawl")
    priority: Optional[int] = Field(default=5, ge=0, le=100, description="Job priority")
    word_count_threshold: Optional[int] = Field(default=50, description="Minimum word count")
    extraction_strategy: Optional[ExtractionStrategy] = Field(
        default=ExtractionStrategy.NO_EXTRACTION,
        description="Content extraction strategy"
    )
    
    # Additional options
    timeout: Optional[int] = Field(default=30, ge=5, le=120, description="Request timeout")
    wait_for_selector: Optional[str] = Field(default=None, description="CSS selector to wait for")
    screenshot: Optional[bool] = Field(default=False, description="Capture screenshot")
    
    class Config:
        schema_extra = {
            "example": {
                "urls": ["https://example.com"],
                "priority": 5,
                "word_count_threshold": 50,
                "extraction_strategy": "NoExtractionStrategy"
            }
        }


class TaskResponse(BaseModel):
    """Task submission response"""
    status: str = Field(..., description="Job status: pending, running, completed, failed")
    task_id: str = Field(..., description="Unique task identifier")
    results: List[Dict[str, Any]] = Field(default_factory=list, description="Crawl results")
    metadata: Dict[str, Any] = Field(default_factory=dict, description="Job metadata")


@app.get("/")
async def health_check():
    """Health check endpoint"""
    return {
        "status": "healthy",
        "service": "crawl4ai",
        "version": "1.0.0",
        "timestamp": datetime.utcnow().isoformat()
    }


@app.get("/health")
async def health():
    """Detailed health status"""
    from browser_pool import BrowserPool
    
    pool = BrowserPool.get_instance()
    pool_stats = pool.get_stats()
    
    return {
        "status": "healthy",
        "browser_pool": {
            "active": pool_stats["active"],
            "idle": pool_stats["idle"],
            "max_size": pool_stats["max_size"]
        },
        "jobs": {
            "pending": len([j for j in jobs_db.values() if j["status"] == "pending"]),
            "running": len([j for j in jobs_db.values() if j["status"] == "running"]),
            "completed": len([j for j in jobs_db.values() if j["status"] == "completed"])
        }
    }


@app.post("/crawl", response_model=TaskResponse)
async def submit_crawl_job(request: CrawlRequest, background_tasks: BackgroundTasks):
    """
    Submit a new crawl job
    
    Returns immediately with task_id for async processing,
    or with results if job completes quickly (< 5 seconds).
    """
    # Generate unique task ID
    task_id = generate_task_id(request.urls)
    
    # Check if this job was recently completed (cache hit)
    if task_id in results_cache:
        cached = results_cache[task_id]
        age_seconds = time.time() - cached.get("cached_at", 0)
        
        # Return cached results if less than 1 hour old
        if age_seconds < 3600:
            return TaskResponse(
                status="completed",
                task_id=task_id,
                results=cached.get("results", []),
                metadata={
                    "cached": True,
                    "cached_age_seconds": int(age_seconds),
                    "cached_at": datetime.fromtimestamp(cached.get("cached_at", 0)).isoformat()
                }
            )
    
    # Create job record
    job = {
        "task_id": task_id,
        "status": "pending",
        "urls": [str(url) for url in request.urls],
        "priority": request.priority,
        "options": request.dict(exclude={"urls", "priority"}),
        "created_at": time.time(),
        "updated_at": time.time(),
        "retry_count": 0
    }
    
    jobs_db[task_id] = job
    
    # Try synchronous execution first (quick jobs)
    try:
        result = await asyncio.wait_for(
            execute_crawl_job(task_id),
            timeout=5.0
        )
        
        # Job completed synchronously
        return TaskResponse(
            status="completed",
            task_id=task_id,
            results=result.get("results", []),
            metadata=result.get("metadata", {})
        )
    
    except asyncio.TimeoutError:
        # Job taking too long, queue for background processing
        background_tasks.add_task(execute_crawl_job, task_id)
        
        return TaskResponse(
            status="pending",
            task_id=task_id,
            results=[],
            metadata={
                "queued_at": datetime.utcnow().isoformat(),
                "message": "Job queued for background processing"
            }
        )
    
    except Exception as e:
        # Job failed immediately
        job["status"] = "failed"
        job["error"] = str(e)
        job["updated_at"] = time.time()
        
        return TaskResponse(
            status="failed",
            task_id=task_id,
            results=[],
            metadata={
                "error": str(e),
                "failed_at": datetime.utcnow().isoformat()
            }
        )


@app.get("/task/{task_id}", response_model=TaskResponse)
async def get_task_status(task_id: str):
    """
    Retrieve task status and results
    
    Args:
        task_id: Unique task identifier
    
    Returns:
        Task status and results if available
    """
    # Check results cache first
    if task_id in results_cache:
        cached = results_cache[task_id]
        return TaskResponse(
            status="completed",
            task_id=task_id,
            results=cached.get("results", []),
            metadata=cached.get("metadata", {})
        )
    
    # Check active jobs
    if task_id not in jobs_db:
        raise HTTPException(status_code=404, detail="Task not found")
    
    job = jobs_db[task_id]
    
    response = TaskResponse(
        status=job["status"],
        task_id=task_id,
        results=job.get("results", []),
        metadata={
            "created_at": datetime.fromtimestamp(job["created_at"]).isoformat(),
            "updated_at": datetime.fromtimestamp(job["updated_at"]).isoformat(),
            "retry_count": job.get("retry_count", 0)
        }
    )
    
    if job["status"] == "failed" and "error" in job:
        response.metadata["error"] = job["error"]
    
    return response


@app.delete("/task/{task_id}")
async def cancel_task(task_id: str):
    """Cancel a pending or running task"""
    if task_id not in jobs_db:
        raise HTTPException(status_code=404, detail="Task not found")
    
    job = jobs_db[task_id]
    
    if job["status"] in ["completed", "failed"]:
        raise HTTPException(
            status_code=400,
            detail=f"Cannot cancel {job['status']} task"
        )
    
    job["status"] = "cancelled"
    job["updated_at"] = time.time()
    
    return {"message": "Task cancelled", "task_id": task_id}


# Helper Functions

def generate_task_id(urls: List[HttpUrl]) -> str:
    """Generate deterministic task ID from URLs for caching"""
    urls_str = "|".join(sorted(str(url) for url in urls))
    hash_obj = hashlib.md5(urls_str.encode())
    return f"task-{hash_obj.hexdigest()[:16]}"


async def execute_crawl_job(task_id: str) -> Dict[str, Any]:
    """
    Execute a crawl job using browser pool
    
    Args:
        task_id: Task identifier
    
    Returns:
        Crawl results dictionary
    """
    from browser_pool import BrowserPool
    from extractor import ContentExtractor
    
    if task_id not in jobs_db:
        raise ValueError(f"Task {task_id} not found")
    
    job = jobs_db[task_id]
    job["status"] = "running"
    job["updated_at"] = time.time()
    
    pool = BrowserPool.get_instance()
    extractor = ContentExtractor()
    
    results = []
    
    try:
        for url in job["urls"]:
            # Get browser from pool
            async with pool.acquire() as page:
                start_time = time.time()
                
                # Navigate to URL
                options = job.get("options", {})
                timeout = options.get("timeout", 30) * 1000  # Convert to ms
                
                await page.goto(url, timeout=timeout, wait_until="networkidle")
                
                # Wait for selector if specified
                if options.get("wait_for_selector"):
                    await page.wait_for_selector(
                        options["wait_for_selector"],
                        timeout=timeout
                    )
                
                # Extract content
                html = await page.content()
                
                # Take screenshot if requested
                screenshot_data = None
                if options.get("screenshot"):
                    screenshot_data = await page.screenshot(type="png", full_page=True)
                
                # Extract text content
                text_content = extractor.html_to_text(html)
                markdown_content = extractor.html_to_markdown(html)
                
                duration = time.time() - start_time
                
                result = {
                    "url": url,
                    "status_code": 200,
                    "content_type": "text/html",
                    "html": html,
                    "text": text_content,
                    "markdown": markdown_content,
                    "metadata": {
                        "duration": round(duration, 3),
                        "timestamp": datetime.utcnow().isoformat(),
                        "word_count": len(text_content.split()),
                    }
                }
                
                if screenshot_data:
                    import base64
                    result["metadata"]["screenshot"] = base64.b64encode(screenshot_data).decode()
                
                results.append(result)
        
        # Job completed successfully
        job["status"] = "completed"
        job["results"] = results
        job["updated_at"] = time.time()
        
        # Cache results
        results_cache[task_id] = {
            "results": results,
            "metadata": {
                "completed_at": datetime.utcnow().isoformat(),
                "duration": round(job["updated_at"] - job["created_at"], 3)
            },
            "cached_at": time.time()
        }
        
        return {
            "results": results,
            "metadata": results_cache[task_id]["metadata"]
        }
    
    except Exception as e:
        # Job failed
        job["status"] = "failed"
        job["error"] = str(e)
        job["updated_at"] = time.time()
        
        raise


if __name__ == "__main__":
    import uvicorn
    
    uvicorn.run(
        "app:app",
        host="0.0.0.0",
        port=8000,
        reload=True,
        log_level="info"
    )
```

### browser_pool.py - Browser Pool Manager

```python
"""
Browser Pool Manager for Crawl4AI Service

Manages a pool of Playwright browser instances for efficient page loading.
"""

from playwright.async_api import async_playwright, Browser, Page
from contextlib import asynccontextmanager
from typing import Optional
import asyncio


class BrowserPool:
    """
    Manages a pool of browser instances
    
    Features:
    - Lazy initialization
    - Connection pooling
    - Automatic cleanup
    - Singleton pattern
    """
    
    _instance: Optional['BrowserPool'] = None
    
    def __init__(self, max_size: int = 5):
        """
        Initialize browser pool
        
        Args:
            max_size: Maximum number of concurrent browser instances
        """
        self.max_size = max_size
        self.playwright = None
        self.browser: Optional[Browser] = None
        self._pages = []
        self._lock = asyncio.Lock()
        self._initialized = False
    
    @classmethod
    def get_instance(cls, max_size: int = 5) -> 'BrowserPool':
        """Get or create singleton instance"""
        if cls._instance is None:
            cls._instance = cls(max_size=max_size)
        return cls._instance
    
    async def initialize(self):
        """Initialize Playwright and launch browser"""
        if self._initialized:
            return
        
        async with self._lock:
            if self._initialized:
                return
            
            self.playwright = await async_playwright().start()
            
            # Launch browser with optimal settings
            self.browser = await self.playwright.chromium.launch(
                headless=True,
                args=[
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-accelerated-2d-canvas',
                    '--disable-gpu',
                    '--window-size=1920x1080',
                ]
            )
            
            self._initialized = True
    
    @asynccontextmanager
    async def acquire(self):
        """
        Acquire a browser page from the pool
        
        Usage:
            async with pool.acquire() as page:
                await page.goto("https://example.com")
        """
        await self.initialize()
        
        # Create new page
        page = await self.browser.new_page(
            viewport={"width": 1920, "height": 1080},
            user_agent="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36"
        )
        
        try:
            self._pages.append(page)
            yield page
        finally:
            # Close and remove page
            await page.close()
            if page in self._pages:
                self._pages.remove(page)
    
    def get_stats(self) -> dict:
        """Get pool statistics"""
        return {
            "active": len(self._pages),
            "idle": 0,
            "max_size": self.max_size
        }
    
    async def cleanup(self):
        """Clean up all resources"""
        async with self._lock:
            # Close all pages
            for page in self._pages:
                try:
                    await page.close()
                except:
                    pass
            
            self._pages.clear()
            
            # Close browser
            if self.browser:
                await self.browser.close()
                self.browser = None
            
            # Stop playwright
            if self.playwright:
                await self.playwright.stop()
                self.playwright = None
            
            self._initialized = False
    
    def __del__(self):
        """Destructor to ensure cleanup"""
        if self._initialized:
            # Note: Can't use async in __del__, handle in application shutdown
            pass
```

### extractor.py - Content Extraction

```python
"""
Content Extractor for Crawl4AI Service

Converts HTML to Markdown and plain text.
"""

from html.parser import HTMLParser
from typing import List, Tuple
import re


class ContentExtractor:
    """Extract and convert HTML content"""
    
    def html_to_text(self, html: str) -> str:
        """
        Convert HTML to plain text
        
        Args:
            html: HTML content
        
        Returns:
            Plain text content
        """
        parser = TextExtractor()
        parser.feed(html)
        return parser.get_text()
    
    def html_to_markdown(self, html: str) -> str:
        """
        Convert HTML to Markdown
        
        Args:
            html: HTML content
        
        Returns:
            Markdown formatted content
        """
        parser = MarkdownConverter()
        parser.feed(html)
        return parser.get_markdown()


class TextExtractor(HTMLParser):
    """Extract plain text from HTML"""
    
    def __init__(self):
        super().__init__()
        self.text_parts = []
        self.skip_tags = {'script', 'style', 'noscript', 'iframe'}
        self.current_tag = None
    
    def handle_starttag(self, tag, attrs):
        self.current_tag = tag
    
    def handle_endtag(self, tag):
        self.current_tag = None
    
    def handle_data(self, data):
        if self.current_tag not in self.skip_tags:
            text = data.strip()
            if text:
                self.text_parts.append(text)
    
    def get_text(self) -> str:
        return "\n".join(self.text_parts)


class MarkdownConverter(HTMLParser):
    """Convert HTML to Markdown"""
    
    def __init__(self):
        super().__init__()
        self.markdown_parts = []
        self.skip_tags = {'script', 'style', 'noscript', 'iframe'}
        self.tag_stack = []
        self.list_level = 0
    
    def handle_starttag(self, tag, attrs):
        if tag in self.skip_tags:
            return
        
        self.tag_stack.append(tag)
        
        if tag in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6'):
            level = int(tag[1])
            self.markdown_parts.append('\n' + '#' * level + ' ')
        elif tag == 'p':
            self.markdown_parts.append('\n\n')
        elif tag == 'br':
            self.markdown_parts.append('\n')
        elif tag == 'a':
            # Extract href
            href = dict(attrs).get('href', '')
            self.markdown_parts.append('[')
        elif tag in ('ul', 'ol'):
            self.list_level += 1
            self.markdown_parts.append('\n')
        elif tag == 'li':
            indent = '  ' * (self.list_level - 1)
            self.markdown_parts.append(f'\n{indent}- ')
        elif tag == 'code':
            self.markdown_parts.append('`')
        elif tag == 'pre':
            self.markdown_parts.append('\n```\n')
    
    def handle_endtag(self, tag):
        if tag in self.skip_tags:
            return
        
        if self.tag_stack and self.tag_stack[-1] == tag:
            self.tag_stack.pop()
        
        if tag == 'a':
            self.markdown_parts.append(']')
        elif tag == 'code':
            self.markdown_parts.append('`')
        elif tag == 'pre':
            self.markdown_parts.append('\n```\n')
        elif tag in ('ul', 'ol'):
            self.list_level -= 1
            self.markdown_parts.append('\n')
    
    def handle_data(self, data):
        if self.tag_stack and self.tag_stack[-1] not in self.skip_tags:
            self.markdown_parts.append(data.strip())
    
    def get_markdown(self) -> str:
        markdown = ''.join(self.markdown_parts)
        # Clean up excessive newlines
        markdown = re.sub(r'\n{3,}', '\n\n', markdown)
        return markdown.strip()
```

### requirements.txt

```
fastapi==0.104.1
uvicorn[standard]==0.24.0
playwright==1.40.0
pydantic==2.5.0
python-multipart==0.0.6
```

---

## Docker Deployment

### Dockerfile

```dockerfile
FROM python:3.11-slim

# Install system dependencies
RUN apt-get update && apt-get install -y \
    wget \
    gnupg \
    ca-certificates \
    fonts-liberation \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libatspi2.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libwayland-client0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxkbcommon0 \
    libxrandr2 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy requirements and install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Install Playwright browsers
RUN playwright install chromium

# Copy application code
COPY app.py browser_pool.py extractor.py ./

# Expose port
EXPOSE 8000

# Run application
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  crawl4ai:
    build: .
    ports:
      - "8000:8000"
    environment:
      - MAX_BROWSER_POOL_SIZE=5
      - LOG_LEVEL=info
    volumes:
      - ./logs:/app/logs
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          cpus: '1'
          memory: 1G

  # Optional: Redis for production job queue
  # redis:
  #   image: redis:7-alpine
  #   ports:
  #     - "6379:6379"
  #   volumes:
  #     - redis-data:/data
  #   restart: unless-stopped

# volumes:
#   redis-data:
```

### Build and Run

```bash
# Clone or create directory
mkdir crawl4ai-service
cd crawl4ai-service

# Copy files (app.py, browser_pool.py, extractor.py, requirements.txt)
# ... (copy the files above)

# Build Docker image
docker-compose build

# Start service
docker-compose up -d

# Check logs
docker-compose logs -f crawl4ai

# Check health
curl http://localhost:8000/health

# Stop service
docker-compose down
```

---

## Configuration Examples

### WordPress Plugin Configuration

```php
// In wp-config.php or theme's functions.php

// Configure Crawl4AI base URL
define( 'WP_MCP_AI_CRAWL4AI_BASE_URL', 'http://localhost:8000' );

// Optional: Configure API key
define( 'WP_MCP_AI_CRAWL4AI_API_KEY', 'your-secret-key-here' );

// Optional: Disable local fallback
add_filter( 'wp_mcp_ai_crawl4ai_local_enabled', '__return_false' );
```

### Environment Variables

```bash
# .env file for Docker Compose
CRAWL4AI_BASE_URL=http://localhost:8000
CRAWL4AI_API_KEY=your-secret-key
MAX_BROWSER_POOL_SIZE=5
REQUEST_TIMEOUT=30
LOG_LEVEL=info
```

---

## Integration with WordPress

### WordPress Settings UI

Navigate to: **WordPress Admin → Settings → NV oOS → Crawl4AI**

```
┌─────────────────────────────────────────┐
│ Crawl4AI Base URL                       │
│ http://localhost:8000                   │  ← Remote service URL
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ API Key (Optional)                      │
│ your-secret-key                         │
└─────────────────────────────────────────┘

☑ Enable Local Fallback
  Falls back to WordPress HTTP client if remote service is unavailable
```

### Testing Connection

```bash
# Test from WordPress server
curl -X POST http://localhost:8000/crawl \
  -H "Content-Type: application/json" \
  -d '{
    "urls": ["https://example.com"],
    "priority": 5
  }'

# Expected response:
# {
#   "status": "completed",
#   "task_id": "task-abc123...",
#   "results": [...]
# }
```

---

## Testing

### Unit Tests (pytest)

```python
# test_app.py
import pytest
from fastapi.testclient import TestClient
from app import app

client = TestClient(app)

def test_health_check():
    response = client.get("/")
    assert response.status_code == 200
    assert response.json()["status"] == "healthy"

def test_submit_crawl_job():
    response = client.post("/crawl", json={
        "urls": ["https://example.com"],
        "priority": 5
    })
    assert response.status_code == 200
    assert "task_id" in response.json()

def test_get_task_status():
    # Submit job first
    submit_response = client.post("/crawl", json={
        "urls": ["https://example.com"]
    })
    task_id = submit_response.json()["task_id"]
    
    # Get status
    status_response = client.get(f"/task/{task_id}")
    assert status_response.status_code == 200
    assert status_response.json()["task_id"] == task_id

def test_task_not_found():
    response = client.get("/task/invalid-task-id")
    assert response.status_code == 404
```

### Run Tests

```bash
# Install pytest
pip install pytest pytest-asyncio

# Run tests
pytest test_app.py -v

# Run with coverage
pytest test_app.py --cov=app --cov-report=html
```

---

## Production Considerations

### Security

1. **API Authentication**: Implement Bearer token authentication
2. **Rate Limiting**: Use middleware to limit requests per IP
3. **HTTPS**: Deploy behind reverse proxy (nginx) with SSL
4. **Input Validation**: Validate and sanitize all inputs

### Scalability

1. **Horizontal Scaling**: Run multiple containers behind load balancer
2. **Job Queue**: Use Redis or RabbitMQ for distributed queue
3. **Result Storage**: Use PostgreSQL or MongoDB for persistence
4. **Caching**: Implement Redis caching for frequent URLs

### Monitoring

1. **Health Checks**: Kubernetes liveness/readiness probes
2. **Metrics**: Prometheus + Grafana for monitoring
3. **Logging**: Centralized logging (ELK stack, CloudWatch)
4. **Alerting**: Set up alerts for failures and slow responses

### Example Production Stack

```yaml
# docker-compose.prod.yml
version: '3.8'

services:
  crawl4ai:
    image: your-registry/crawl4ai:latest
    deploy:
      replicas: 3
      update_config:
        parallelism: 1
        delay: 10s
    environment:
      - REDIS_URL=redis://redis:6379
      - DB_URL=postgresql://user:pass@postgres:5432/crawl4ai
  
  redis:
    image: redis:7-alpine
    volumes:
      - redis-data:/data
  
  postgres:
    image: postgres:15-alpine
    environment:
      - POSTGRES_PASSWORD=secure-password
    volumes:
      - postgres-data:/var/lib/postgresql/data
  
  nginx:
    image: nginx:alpine
    ports:
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf
      - ./ssl:/etc/nginx/ssl
    depends_on:
      - crawl4ai

volumes:
  redis-data:
  postgres-data:
```

---

## Troubleshooting

### Common Issues

**1. Browser Launch Failures**

```bash
# Install missing dependencies
apt-get install -y libgbm1 libnss3 libxss1

# Or reinstall Playwright
playwright install --with-deps chromium
```

**2. Memory Issues**

```yaml
# Increase Docker memory limits
services:
  crawl4ai:
    deploy:
      resources:
        limits:
          memory: 4G  # Increase from 2G
```

**3. Connection Timeouts**

```python
# Increase timeout in WordPress
add_filter( 'wp_mcp_ai_crawl4ai_request_timeout', function() {
    return 60; // 60 seconds
});
```

---

## Related Documentation

- [CRAWL4AI_SERVICE_REFERENCE.md](./CRAWL4AI_SERVICE_REFERENCE.md) - API reference and deployment guides
- [CRAWL4AI-JOB-TRACKING.md](./CRAWL4AI-JOB-TRACKING.md) - Job tracking implementation
- [REST API Documentation](../../../reference/rest-api.md) - WordPress REST endpoints

---

**Version**: 1.0  
**Last Updated**: January 9, 2026  
**Compatibility**: NV oOS 1.1.0+
