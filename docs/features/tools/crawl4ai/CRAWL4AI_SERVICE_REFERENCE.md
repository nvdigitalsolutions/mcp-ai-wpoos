# Crawl4AI Service Reference

**API reference, deployment guides, and integration documentation**

Comprehensive reference for the Crawl4AI remote service integration with NV oOS WordPress plugin.

## Table of Contents

- [API Reference](#api-reference)
- [Deployment Guides](#deployment-guides)
- [Configuration Reference](#configuration-reference)
- [Integration Examples](#integration-examples)
- [Performance Optimization](#performance-optimization)
- [Troubleshooting](#troubleshooting)
- [Security Best Practices](#security-best-practices)

---

## API Reference

### Base URL

```
http://localhost:8000
```

Replace `localhost:8000` with your actual service URL in production.

### Authentication

**Optional Bearer Token**

```http
Authorization: Bearer your-api-key-here
```

Configure in WordPress:
```php
add_filter( 'wp_mcp_ai_crawl4ai_headers', function( $headers, $settings, $context ) {
    $headers['Authorization'] = 'Bearer your-api-key-here';
    return $headers;
}, 10, 3 );
```

---

### Endpoints

#### 1. Health Check

**GET /**

Check service availability.

**Response**

```json
{
  "status": "healthy",
  "service": "crawl4ai",
  "version": "1.0.0",
  "timestamp": "2026-01-09T10:00:00.000Z"
}
```

**Status Codes**
- `200 OK`: Service is healthy
- `503 Service Unavailable`: Service is down

---

#### 2. Detailed Health Status

**GET /health**

Get detailed service health including browser pool status and job statistics.

**Response**

```json
{
  "status": "healthy",
  "browser_pool": {
    "active": 2,
    "idle": 3,
    "max_size": 5
  },
  "jobs": {
    "pending": 5,
    "running": 2,
    "completed": 150
  }
}
```

**Fields**
- `browser_pool.active`: Currently used browser instances
- `browser_pool.idle`: Available browser instances
- `browser_pool.max_size`: Maximum pool size
- `jobs.pending`: Jobs waiting to be processed
- `jobs.running`: Jobs currently executing
- `jobs.completed`: Total completed jobs

---

#### 3. Submit Crawl Job

**POST /crawl**

Submit a new web crawling job.

**Request Body**

```json
{
  "urls": ["https://example.com"],
  "priority": 5,
  "word_count_threshold": 50,
  "extraction_strategy": "NoExtractionStrategy",
  "timeout": 30,
  "wait_for_selector": null,
  "screenshot": false
}
```

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `urls` | array[string] | Yes | - | List of URLs to crawl (min 1) |
| `priority` | integer | No | 5 | Job priority (0-100, higher = more priority) |
| `word_count_threshold` | integer | No | 50 | Minimum word count for content extraction |
| `extraction_strategy` | string | No | NoExtractionStrategy | Content extraction strategy |
| `timeout` | integer | No | 30 | Request timeout in seconds (5-120) |
| `wait_for_selector` | string | No | null | CSS selector to wait for before extraction |
| `screenshot` | boolean | No | false | Capture screenshot (base64 encoded) |

**Extraction Strategies**
- `NoExtractionStrategy`: Extract all content
- `JsonCssExtractionStrategy`: Extract using CSS selectors
- `LLMExtractionStrategy`: Extract using LLM-based parsing

**Response (Synchronous Completion)**

```json
{
  "status": "completed",
  "task_id": "task-abc123def456",
  "results": [
    {
      "url": "https://example.com",
      "status_code": 200,
      "content_type": "text/html",
      "html": "<html>...</html>",
      "text": "Example Domain...",
      "markdown": "# Example Domain\n\n...",
      "metadata": {
        "duration": 1.234,
        "timestamp": "2026-01-09T10:00:00.000Z",
        "word_count": 50
      }
    }
  ],
  "metadata": {
    "completed_at": "2026-01-09T10:00:01.234Z",
    "duration": 1.234
  }
}
```

**Response (Asynchronous Processing)**

```json
{
  "status": "pending",
  "task_id": "task-abc123def456",
  "results": [],
  "metadata": {
    "queued_at": "2026-01-09T10:00:00.000Z",
    "message": "Job queued for background processing"
  }
}
```

**Status Codes**
- `200 OK`: Job submitted successfully
- `400 Bad Request`: Invalid request parameters
- `401 Unauthorized`: Invalid or missing API key
- `429 Too Many Requests`: Rate limit exceeded
- `500 Internal Server Error`: Service error

---

#### 4. Get Task Status

**GET /task/{task_id}**

Retrieve the status and results of a previously submitted job.

**Path Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `task_id` | string | Unique task identifier from submit response |

**Response**

```json
{
  "status": "completed",
  "task_id": "task-abc123def456",
  "results": [
    {
      "url": "https://example.com",
      "status_code": 200,
      "content_type": "text/html",
      "html": "<html>...</html>",
      "text": "Example Domain...",
      "markdown": "# Example Domain\n\n...",
      "metadata": {
        "duration": 1.234,
        "timestamp": "2026-01-09T10:00:00.000Z",
        "word_count": 50
      }
    }
  ],
  "metadata": {
    "created_at": "2026-01-09T10:00:00.000Z",
    "updated_at": "2026-01-09T10:00:01.234Z",
    "retry_count": 0
  }
}
```

**Status Values**
- `pending`: Job is queued
- `running`: Job is being processed
- `completed`: Job finished successfully
- `failed`: Job failed with error

**Status Codes**
- `200 OK`: Task found
- `404 Not Found`: Task ID not found
- `500 Internal Server Error`: Service error

---

#### 5. Cancel Task

**DELETE /task/{task_id}**

Cancel a pending or running task.

**Path Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `task_id` | string | Unique task identifier |

**Response**

```json
{
  "message": "Task cancelled",
  "task_id": "task-abc123def456"
}
```

**Status Codes**
- `200 OK`: Task cancelled successfully
- `400 Bad Request`: Task already completed or failed
- `404 Not Found`: Task ID not found

---

## Deployment Guides

### Local Development

#### Prerequisites

```bash
# Python 3.8+
python3 --version

# pip package manager
pip --version

# Git (optional)
git --version
```

#### Setup Steps

```bash
# 1. Create project directory
mkdir crawl4ai-service
cd crawl4ai-service

# 2. Create files
# - Copy app.py, browser_pool.py, extractor.py from CRAWL4AI_SERVICE_IMPLEMENTATION.md

# 3. Create virtual environment
python3 -m venv venv
source venv/bin/activate  # On Windows: venv\Scripts\activate

# 4. Install dependencies
cat > requirements.txt << EOF
fastapi==0.104.1
uvicorn[standard]==0.24.0
playwright==1.40.0
pydantic==2.5.0
python-multipart==0.0.6
EOF

pip install -r requirements.txt

# 5. Install Playwright browsers
playwright install chromium

# 6. Run service
python app.py

# Service now running at http://localhost:8000
```

#### Test Connection

```bash
# Health check
curl http://localhost:8000/health

# Submit test job
curl -X POST http://localhost:8000/crawl \
  -H "Content-Type: application/json" \
  -d '{"urls": ["https://example.com"]}'
```

---

### Docker Deployment

#### Single Container

```bash
# 1. Create Dockerfile
cat > Dockerfile << 'EOF'
FROM python:3.11-slim

# Install system dependencies
RUN apt-get update && apt-get install -y \
    wget gnupg ca-certificates \
    fonts-liberation libasound2 libatk-bridge2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Install Playwright browsers
RUN playwright install chromium

# Copy application
COPY *.py ./

EXPOSE 8000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
EOF

# 2. Build image
docker build -t crawl4ai-service:latest .

# 3. Run container
docker run -d \
  --name crawl4ai \
  -p 8000:8000 \
  --restart unless-stopped \
  crawl4ai-service:latest

# 4. Check logs
docker logs -f crawl4ai

# 5. Test
curl http://localhost:8000/health
```

#### Docker Compose

```bash
# 1. Create docker-compose.yml
cat > docker-compose.yml << 'EOF'
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
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
EOF

# 2. Start services
docker-compose up -d

# 3. View logs
docker-compose logs -f

# 4. Stop services
docker-compose down
```

---

### Production Deployment (Kubernetes)

#### Deployment Manifest

```yaml
# crawl4ai-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: crawl4ai
  namespace: production
spec:
  replicas: 3
  selector:
    matchLabels:
      app: crawl4ai
  template:
    metadata:
      labels:
        app: crawl4ai
    spec:
      containers:
      - name: crawl4ai
        image: your-registry/crawl4ai:1.0.0
        ports:
        - containerPort: 8000
        env:
        - name: MAX_BROWSER_POOL_SIZE
          value: "5"
        - name: LOG_LEVEL
          value: "info"
        resources:
          requests:
            memory: "1Gi"
            cpu: "500m"
          limits:
            memory: "2Gi"
            cpu: "2000m"
        livenessProbe:
          httpGet:
            path: /health
            port: 8000
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /health
            port: 8000
          initialDelaySeconds: 5
          periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: crawl4ai
  namespace: production
spec:
  selector:
    app: crawl4ai
  ports:
  - protocol: TCP
    port: 80
    targetPort: 8000
  type: LoadBalancer
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: crawl4ai-hpa
  namespace: production
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: crawl4ai
  minReplicas: 3
  maxReplicas: 10
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
```

#### Deploy to Kubernetes

```bash
# 1. Apply deployment
kubectl apply -f crawl4ai-deployment.yaml

# 2. Check status
kubectl get pods -n production -l app=crawl4ai

# 3. Get service URL
kubectl get svc crawl4ai -n production

# 4. View logs
kubectl logs -n production -l app=crawl4ai -f

# 5. Scale manually (if needed)
kubectl scale deployment crawl4ai -n production --replicas=5
```

---

### Cloud Deployment Examples

#### AWS ECS (Fargate)

```bash
# 1. Build and push image to ECR
aws ecr get-login-password --region us-east-1 | \
  docker login --username AWS --password-stdin YOUR_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com

docker build -t crawl4ai:latest .
docker tag crawl4ai:latest YOUR_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com/crawl4ai:latest
docker push YOUR_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com/crawl4ai:latest

# 2. Create task definition (task-definition.json)
{
  "family": "crawl4ai",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "1024",
  "memory": "2048",
  "containerDefinitions": [
    {
      "name": "crawl4ai",
      "image": "YOUR_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com/crawl4ai:latest",
      "portMappings": [
        {
          "containerPort": 8000,
          "protocol": "tcp"
        }
      ],
      "environment": [
        {"name": "MAX_BROWSER_POOL_SIZE", "value": "5"}
      ],
      "healthCheck": {
        "command": ["CMD-SHELL", "curl -f http://localhost:8000/health || exit 1"],
        "interval": 30,
        "timeout": 5,
        "retries": 3
      }
    }
  ]
}

# 3. Register task definition
aws ecs register-task-definition --cli-input-json file://task-definition.json

# 4. Create service
aws ecs create-service \
  --cluster your-cluster \
  --service-name crawl4ai \
  --task-definition crawl4ai \
  --desired-count 3 \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-xxx],securityGroups=[sg-xxx],assignPublicIp=ENABLED}"
```

#### Google Cloud Run

```bash
# 1. Build and deploy
gcloud run deploy crawl4ai \
  --source . \
  --platform managed \
  --region us-central1 \
  --allow-unauthenticated \
  --memory 2Gi \
  --cpu 2 \
  --min-instances 1 \
  --max-instances 10 \
  --port 8000

# 2. Get service URL
gcloud run services describe crawl4ai --region us-central1 --format='value(status.url)'
```

#### Azure Container Instances

```bash
# 1. Create resource group
az group create --name crawl4ai-rg --location eastus

# 2. Create container
az container create \
  --resource-group crawl4ai-rg \
  --name crawl4ai \
  --image your-registry/crawl4ai:latest \
  --cpu 2 \
  --memory 2 \
  --ports 8000 \
  --dns-name-label crawl4ai-service \
  --environment-variables MAX_BROWSER_POOL_SIZE=5

# 3. Get FQDN
az container show \
  --resource-group crawl4ai-rg \
  --name crawl4ai \
  --query ipAddress.fqdn \
  --output tsv
```

---

## Configuration Reference

### WordPress Plugin Configuration

#### Via wp-config.php

```php
<?php
/**
 * Crawl4AI Configuration
 */

// Remote service URL (required)
define( 'WP_MCP_AI_CRAWL4AI_BASE_URL', 'http://localhost:8000' );

// API key (optional)
define( 'WP_MCP_AI_CRAWL4AI_API_KEY', 'your-secret-key' );

// Request timeout in seconds (default: 30)
define( 'WP_MCP_AI_CRAWL4AI_TIMEOUT', 60 );

// Enable/disable local fallback (default: true)
define( 'WP_MCP_AI_CRAWL4AI_LOCAL_FALLBACK', true );
```

#### Via Filters

```php
<?php
/**
 * Dynamic Crawl4AI Configuration
 */

// Set base URL dynamically
add_filter( 'wp_mcp_ai_crawl4ai_base_url', function( $base_url, $settings, $context ) {
    // Use different endpoints for different environments
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
        switch ( WP_ENVIRONMENT_TYPE ) {
            case 'production':
                return 'https://crawl4ai.example.com';
            case 'staging':
                return 'https://crawl4ai-staging.example.com';
            case 'development':
                return 'http://localhost:8000';
        }
    }
    
    return $base_url;
}, 10, 3 );

// Add custom headers
add_filter( 'wp_mcp_ai_crawl4ai_headers', function( $headers, $settings, $context ) {
    $headers['Authorization'] = 'Bearer ' . get_option( 'crawl4ai_api_key' );
    $headers['X-Client-ID'] = get_bloginfo( 'name' );
    return $headers;
}, 10, 3 );

// Customize request timeout
add_filter( 'wp_mcp_ai_crawl4ai_request_timeout', function( $timeout ) {
    return 120; // 2 minutes
} );

// Disable local fallback
add_filter( 'wp_mcp_ai_crawl4ai_local_enabled', '__return_false' );

// Customize result token limit
add_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', function( $limit ) {
    return 200000; // Increase to 200k tokens
} );

// Modify crawl payload before sending
add_filter( 'wp_mcp_ai_crawl4ai_payload', function( $payload, $arguments, $context ) {
    // Add custom options
    $payload['custom_option'] = 'value';
    
    // Modify priority based on user
    if ( isset( $context['user_id'] ) && user_can( $context['user_id'], 'administrator' ) ) {
        $payload['priority'] = 100; // Higher priority for admins
    }
    
    return $payload;
}, 10, 3 );

// Customize local crawler request args
add_filter( 'wp_mcp_ai_crawl4ai_local_request_args', function( $args, $settings, $context, $arguments ) {
    // Increase timeout for local requests
    $args['timeout'] = 60;
    
    // Add custom headers
    $args['headers']['X-Custom-Header'] = 'value';
    
    return $args;
}, 10, 4 );

// Set trusted hosts for crawler (bypass private IP restrictions)
add_filter( 'wp_mcp_ai_crawl4ai_trusted_hosts', function( $hosts, $url, $parts ) {
    // Allow crawling localhost and internal domains
    return array(
        'localhost',
        '*.internal.example.com',
        '192.168.1.*'
    );
}, 10, 3 );
```

### Service Configuration (Environment Variables)

```bash
# .env file for service
# =====================

# Server
HOST=0.0.0.0
PORT=8000
WORKERS=4
LOG_LEVEL=info

# Browser Pool
MAX_BROWSER_POOL_SIZE=5
BROWSER_TIMEOUT=30000
BROWSER_ARGS=--no-sandbox,--disable-setuid-sandbox

# Job Processing
MAX_CONCURRENT_JOBS=10
JOB_TIMEOUT=300
RESULT_CACHE_TTL=3600

# Security
API_KEY=your-secret-key-here
ALLOWED_ORIGINS=https://example.com,https://www.example.com
RATE_LIMIT_PER_MINUTE=60

# Storage (Optional)
REDIS_URL=redis://localhost:6379/0
DATABASE_URL=postgresql://user:pass@localhost:5432/crawl4ai

# Monitoring (Optional)
SENTRY_DSN=https://xxx@sentry.io/xxx
PROMETHEUS_PORT=9090
```

---

## Integration Examples

### Basic Usage

```php
<?php
/**
 * Basic Crawl4AI usage in WordPress
 */

// Get the tool instance
$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

// Execute crawl
$result = $tool->execute(
    array(
        'url' => 'https://example.com',
        'wait_for_completion' => true,
    ),
    array(
        'user_id' => get_current_user_id(),
    )
);

if ( is_wp_error( $result ) ) {
    // Handle error
    error_log( 'Crawl failed: ' . $result->get_error_message() );
} else {
    // Process results
    $markdown = $result['results'][0]['markdown'];
    $text = $result['results'][0]['text'];
    
    // Use the content
    echo $markdown;
}
```

### Async Job Submission

```php
<?php
/**
 * Submit job without waiting for completion
 */

$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

// Submit job (returns immediately)
$result = $tool->execute(
    array(
        'urls' => array(
            'https://example.com/page1',
            'https://example.com/page2',
            'https://example.com/page3',
        ),
        'priority' => 10,
        'wait_for_completion' => false, // Don't wait
    ),
    array(
        'user_id' => get_current_user_id(),
    )
);

// Get task ID for later retrieval
$task_id = $result['task_id'];

// Store task ID for later (e.g., in post meta)
update_post_meta( $post_id, '_crawl4ai_task_id', $task_id );

// Later, check status
$cached_result = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );
if ( $cached_result && 'completed' === $cached_result['status'] ) {
    // Results are ready
    $markdown = $cached_result['results'][0]['markdown'];
}
```

### Batch Crawling

```php
<?php
/**
 * Crawl multiple URLs in batch
 */

function crawl_multiple_urls( array $urls ) {
    $tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
    
    // Split into batches of 10
    $batches = array_chunk( $urls, 10 );
    $all_results = array();
    
    foreach ( $batches as $batch ) {
        $result = $tool->execute(
            array(
                'urls' => $batch,
                'priority' => 5,
            ),
            array(
                'user_id' => get_current_user_id(),
            )
        );
        
        if ( ! is_wp_error( $result ) && ! empty( $result['results'] ) ) {
            $all_results = array_merge( $all_results, $result['results'] );
        }
        
        // Rate limiting: wait 2 seconds between batches
        sleep( 2 );
    }
    
    return $all_results;
}

// Usage
$urls = array(
    'https://example.com/page1',
    'https://example.com/page2',
    // ... up to 100 URLs
);

$results = crawl_multiple_urls( $urls );
foreach ( $results as $result ) {
    echo $result['url'] . ': ' . $result['status_code'] . "\n";
}
```

### Monitor Job Status

```php
<?php
/**
 * Monitor Crawl4AI job status
 */

function monitor_crawl4ai_job( $task_id, $timeout = 120 ) {
    $start_time = time();
    $poll_interval = 3; // 3 seconds
    
    while ( ( time() - $start_time ) < $timeout ) {
        $result = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );
        
        if ( $result ) {
            $status = $result['status'];
            
            if ( 'completed' === $status ) {
                return array(
                    'success' => true,
                    'results' => $result['results'],
                );
            }
            
            if ( 'failed' === $status ) {
                return array(
                    'success' => false,
                    'error' => $result['metadata']['error'] ?? 'Unknown error',
                );
            }
            
            // Still pending/running
            echo "Job status: {$status}, waiting {$poll_interval}s...\n";
        }
        
        sleep( $poll_interval );
    }
    
    return array(
        'success' => false,
        'error' => 'Timeout waiting for job completion',
    );
}

// Usage
$task_id = 'task-abc123def456';
$result = monitor_crawl4ai_job( $task_id, 300 ); // 5 minute timeout

if ( $result['success'] ) {
    print_r( $result['results'] );
} else {
    error_log( 'Crawl failed: ' . $result['error'] );
}
```

### REST API Integration

```php
<?php
/**
 * Custom REST endpoint using Crawl4AI
 */

add_action( 'rest_api_init', function() {
    register_rest_route( 'mysite/v1', '/scrape', array(
        'methods' => 'POST',
        'callback' => 'mysite_scrape_url',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'args' => array(
            'url' => array(
                'required' => true,
                'validate_callback' => function( $param ) {
                    return filter_var( $param, FILTER_VALIDATE_URL );
                },
            ),
        ),
    ) );
} );

function mysite_scrape_url( WP_REST_Request $request ) {
    $url = $request['url'];
    
    $tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
    $result = $tool->execute(
        array(
            'url' => $url,
            'wait_for_completion' => true,
        ),
        array(
            'user_id' => get_current_user_id(),
        )
    );
    
    if ( is_wp_error( $result ) ) {
        return new WP_Error(
            'crawl_failed',
            $result->get_error_message(),
            array( 'status' => 500 )
        );
    }
    
    return array(
        'url' => $url,
        'markdown' => $result['results'][0]['markdown'],
        'text' => $result['results'][0]['text'],
        'word_count' => $result['results'][0]['metadata']['word_count'],
    );
}

// Usage (curl from command line)
// curl -X POST https://example.com/wp-json/mysite/v1/scrape \
//   -H "Content-Type: application/json" \
//   -d '{"url": "https://example.com"}'
```

---

## Performance Optimization

### 1. Caching Strategy

```php
<?php
/**
 * Implement smart caching for Crawl4AI results
 */

function get_cached_crawl_result( $url, $ttl = 3600 ) {
    // Generate cache key
    $cache_key = 'crawl4ai_' . md5( $url );
    
    // Check cache
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }
    
    // Crawl URL
    $tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
    $result = $tool->execute(
        array( 'url' => $url ),
        array( 'user_id' => get_current_user_id() )
    );
    
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    
    // Cache result
    set_transient( $cache_key, $result, $ttl );
    
    return $result;
}
```

### 2. Connection Pooling

```php
<?php
/**
 * Reuse connections for better performance
 */

add_filter( 'wp_mcp_ai_crawl4ai_local_request_args', function( $args ) {
    // Disable SSL verification for internal services (use with caution)
    if ( defined( 'WP_ENV' ) && 'development' === WP_ENV ) {
        $args['sslverify'] = false;
    }
    
    // Keep connections alive
    $args['httpversion'] = '1.1';
    
    return $args;
} );
```

### 3. Parallel Processing

```php
<?php
/**
 * Process multiple URLs in parallel using WordPress cron
 */

function schedule_parallel_crawls( array $urls ) {
    foreach ( $urls as $url ) {
        wp_schedule_single_event(
            time(),
            'mysite_crawl_url',
            array( $url )
        );
    }
}

add_action( 'mysite_crawl_url', function( $url ) {
    $tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
    $result = $tool->execute(
        array( 'url' => $url ),
        array( 'user_id' => 1 )
    );
    
    // Store result
    if ( ! is_wp_error( $result ) ) {
        // Save to database or process further
        update_option( 'crawl_result_' . md5( $url ), $result );
    }
} );
```

### 4. Result Truncation

```php
<?php
/**
 * Limit result size to prevent memory issues
 */

add_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', function() {
    return 50000; // Limit to 50k tokens (~200KB text)
} );

add_filter( 'wp_mcp_ai_crawl4ai_chars_per_token', function() {
    return 4; // Conservative estimate
} );
```

---

## Troubleshooting

### Common Issues

#### 1. Connection Timeout

**Symptom**: "The Crawl4AI request failed to complete"

**Solutions**:

```php
// Increase timeout
add_filter( 'wp_mcp_ai_crawl4ai_request_timeout', function() {
    return 120; // 2 minutes
} );

// Check service health
$response = wp_remote_get( 'http://localhost:8000/health' );
if ( is_wp_error( $response ) ) {
    error_log( 'Crawl4AI service unreachable: ' . $response->get_error_message() );
}
```

#### 2. Invalid SSL Certificate

**Symptom**: "SSL certificate problem"

**Solutions**:

```php
// For development only - disable SSL verification
add_filter( 'wp_mcp_ai_crawl4ai_local_request_args', function( $args ) {
    if ( defined( 'WP_ENV' ) && 'development' === WP_ENV ) {
        $args['sslverify'] = false;
    }
    return $args;
} );

// Or: Use HTTP instead of HTTPS for local services
define( 'WP_MCP_AI_CRAWL4AI_BASE_URL', 'http://localhost:8000' );
```

#### 3. Memory Limit Exceeded

**Symptom**: "Allowed memory size exhausted"

**Solutions**:

```php
// Limit result size
add_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', function() {
    return 25000; // Reduce to 25k tokens
} );

// Increase PHP memory limit
ini_set( 'memory_limit', '256M' );
```

#### 4. Service Returns 503

**Symptom**: "Service Unavailable"

**Causes**:
- Service is down
- Too many concurrent requests
- Browser pool exhausted

**Solutions**:

```bash
# Check service health
curl http://localhost:8000/health

# Restart service
docker-compose restart crawl4ai

# Increase browser pool size
# In docker-compose.yml:
# environment:
#   - MAX_BROWSER_POOL_SIZE=10
```

#### 5. Job Stays in "Pending" Status

**Symptom**: Job never completes, status remains "pending"

**Solutions**:

```php
// Check WP-Cron is running
// Add to wp-config.php:
define( 'DISABLE_WP_CRON', false );

// Manually trigger cron
wp_cron();

// Check job status
$status = WP_MCP_AI_Crawler::get_job_status( $task_id );
print_r( $status );
```

### Debug Mode

```php
<?php
/**
 * Enable Crawl4AI debug logging
 */

// In wp-config.php
define( 'WP_MCP_AI_DEBUG', true );

// View logs
$errors = get_option( 'wp_mcp_ai_recent_errors', array() );
print_r( $errors );

// Enable WordPress debug log
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

// Check debug.log
tail -f wp-content/debug.log
```

---

## Security Best Practices

### 1. API Authentication

```python
# In app.py - Add authentication middleware

from fastapi import Security, HTTPException
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials

security = HTTPBearer()

API_KEYS = {
    "your-secret-key": "client-1",
    "another-secret-key": "client-2",
}

async def verify_api_key(credentials: HTTPAuthorizationCredentials = Security(security)):
    if credentials.credentials not in API_KEYS:
        raise HTTPException(status_code=401, detail="Invalid API key")
    return API_KEYS[credentials.credentials]

# Add to endpoints
@app.post("/crawl")
async def submit_crawl_job(
    request: CrawlRequest,
    client_id: str = Depends(verify_api_key)
):
    # Process request
    pass
```

### 2. Rate Limiting

```python
# Install slowapi
# pip install slowapi

from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded

limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

@app.post("/crawl")
@limiter.limit("10/minute")  # 10 requests per minute
async def submit_crawl_job(request: CrawlRequest):
    pass
```

### 3. Input Validation

```php
<?php
/**
 * Validate URLs before crawling
 */

add_filter( 'wp_mcp_ai_crawl4ai_payload', function( $payload, $arguments, $context ) {
    // Validate URLs
    foreach ( $payload['urls'] as $index => $url ) {
        // Block private IPs
        $parsed = wp_parse_url( $url );
        if ( isset( $parsed['host'] ) ) {
            $ip = gethostbyname( $parsed['host'] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) === false ) {
                unset( $payload['urls'][ $index ] );
            }
        }
        
        // Block dangerous schemes
        if ( isset( $parsed['scheme'] ) && ! in_array( $parsed['scheme'], array( 'http', 'https' ) ) ) {
            unset( $payload['urls'][ $index ] );
        }
    }
    
    $payload['urls'] = array_values( $payload['urls'] ); // Reindex
    
    return $payload;
}, 10, 3 );
```

### 4. HTTPS/TLS

```nginx
# nginx configuration for HTTPS
server {
    listen 443 ssl http2;
    server_name crawl4ai.example.com;
    
    ssl_certificate /etc/letsencrypt/live/crawl4ai.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crawl4ai.example.com/privkey.pem;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    
    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 5. Network Isolation

```yaml
# docker-compose.yml - Use internal network
version: '3.8'

services:
  crawl4ai:
    networks:
      - crawl4ai-internal
    # Only expose to specific IPs
    # Don't expose publicly

networks:
  crawl4ai-internal:
    internal: true
```

---

## Related Documentation

- [CRAWL4AI_SERVICE_IMPLEMENTATION.md](./CRAWL4AI_SERVICE_IMPLEMENTATION.md) - Full implementation code
- [CRAWL4AI-JOB-TRACKING.md](./CRAWL4AI-JOB-TRACKING.md) - Job tracking system
- [Tool Reference](../../../reference/tool-reference.md) - All available tools
- [REST API](../../../reference/rest-api.md) - WordPress REST endpoints

---

## FAQ

### Q: Can I run multiple Crawl4AI services?

Yes, use a load balancer to distribute requests across multiple instances.

### Q: How do I handle JavaScript-heavy sites?

Use the `wait_for_selector` option to wait for dynamic content to load:

```php
$result = $tool->execute(
    array(
        'url' => 'https://example.com',
        'options' => array(
            'wait_for_selector' => '.dynamic-content',
        ),
    ),
    $context
);
```

### Q: What happens if the service is unavailable?

The plugin automatically falls back to WordPress's built-in HTTP client for basic crawling (no JavaScript execution).

### Q: How do I monitor service health?

Use the `/health` endpoint with monitoring tools (Prometheus, Datadog, etc.).

### Q: Can I crawl authenticated pages?

Yes, add authentication headers in the crawl options:

```php
'options' => array(
    'headers' => array(
        'Authorization' => 'Bearer ' . $auth_token,
    ),
)
```

---

**Version**: 1.0  
**Last Updated**: January 9, 2026  
**Compatibility**: NV oOS 1.1.0+
