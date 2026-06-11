# OpenTofu Infrastructure-as-Code Adoption Plan

**Status:** Proposal  
**Branch:** `feature/opentofu-iac-migration`  
**Target:** `alpha-working`  
**Date:** 2026-06-09  

---

## 1. Executive Summary

The NV oOS SaaS Controller addon currently manages Cloudflare infrastructure (Workers, KV, D1, AI Gateway) through a **custom-built IaC engine** written in PHP. This engine — `PlanGenerator`, `ApplyEngine`, `DriftDetector`, `CloudflareMutatingClient` — replicates what OpenTofu already does, but without state management, without a declarative language, and without the ecosystem of 3,900+ providers.

**The proposal:** Replace the PHP-based Cloudflare infrastructure management with OpenTofu `.tf` files in the repository, managed through GitHub Actions CI/CD.

**What this deletes:** ~2,500 lines of PHP across four service classes in `addons/saas-controller/includes/services/`.

**What this keeps:** SaaS Controller's business logic — credential store, Stripe billing, OpenRouter key management, license enforcement, audit log, webhook handling, REST API, admin UI, and the "One-Click Wizard" (now backed by OpenTofu plans instead of PHP-generated plans).

---

## 2. Why OpenTofu

| Concern | PHP Engine (Current) | OpenTofu (Proposed) |
|---|---|---|
| **State management** | None — diffs live API on every run | Versioned state file, knows what was last applied |
| **Declarative config** | PHP arrays in `DeploymentConfig` | HCL `.tf` files — industry standard, human-readable |
| **Drift detection** | Custom `DriftDetector` comparing SHA256/etag | `tofu plan -detailed-exitcode` — built-in, reliable |
| **Plan preview** | Custom `PlanGenerator` with creates/updates/noops/orphans | `tofu plan` — battle-tested, with dependency graph |
| **Apply execution** | Custom `ApplyEngine` with token-gated HITL | `tofu apply` — parallel execution, automatic rollback |
| **Provider ecosystem** | Hand-coded Cloudflare API calls | 3,900+ providers (Cloudflare, Stripe, GitHub, etc.) |
| **Secret handling** | Custom `CredentialStore` with WP options | Provider env vars / GitHub Secrets — industry standard |
| **Auditability** | Custom `AuditLog` | Git history + CI logs + `tofu plan` output |
| **Multi-environment** | Not supported | Separate var files per env (staging/production) |
| **Community** | Hand-maintained by NV team | Linux Foundation project, 180+ contributors |
| **License** | Proprietary PHP code | MPL-2.0 (open source, no BUSL trap like Terraform) |

---

## 3. Current State: SaaS Controller Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    WP Admin (SaaS Controller UI)               │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌─────────────┐  │
│  │  Wizard  │  │ Plan/Apply│  │  Drift   │  │  Operations │  │
│  │ (Phase 2)│  │ (Phase 5) │  │ (Phase5c)│  │  (Phase 5d) │  │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └──────┬──────┘  │
│       │             │             │                │          │
│  ┌────▼─────────────▼─────────────▼────────────────▼───────┐ │
│  │                   SaaS Controller PHP                     │ │
│  │  ┌────────────────┐  ┌──────────────┐  ┌──────────────┐ │ │
│  │  │ PlanGenerator   │  │ ApplyEngine  │  │DriftDetector │ │ │
│  │  │ (diff desired   │  │ (HITL-gated  │  │ (SHA256/etag │ │ │
│  │  │  vs live API)   │  │  mutations)  │  │  comparison) │ │ │
│  │  └───────┬────────┘  └──────┬───────┘  └──────┬───────┘ │ │
│  │          │                 │                  │          │ │
│  │  ┌───────▼─────────────────▼──────────────────▼───────┐ │ │
│  │  │      CloudflareMutatingClient (HTTP to CF API)     │ │ │
│  │  └────────────────────────────────────────────────────┘ │ │
│  │  ┌──────────────┐  ┌──────────┐  ┌──────────────────┐  │ │
│  │  │CredentialStore│  │AuditLog  │  │DeploymentConfig  │  │ │
│  │  └──────────────┘  └──────────┘  └──────────────────┘  │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### Files to be fully or partially replaced:

| File | Approx. Lines | Disposition |
|---|---|---|
| `includes/services/class-nvoos-saas-controller-plan-generator.php` | ~522 | **DELETE** — replaced by `tofu plan` |
| `includes/services/class-nvoos-saas-controller-apply-engine.php` | ~1,400 | **SHRINK** — keep Stripe/OpenRouter apply methods; delete Cloudflare apply methods |
| `includes/services/class-nvoos-saas-controller-drift-detector.php` | ~374 | **DELETE** — replaced by `tofu plan -detailed-exitcode` |
| `includes/services/class-nvoos-saas-controller-cloudflare-mutating-client.php` | ~573 | **DELETE** — replaced by Cloudflare provider |
| `includes/services/class-nvoos-saas-controller-cloudflare-client.php` | ~400 | **SHRINK** — keep read-only list operations for admin UI; delete create/update/delete methods |
| `includes/services/class-nvoos-saas-controller-apply-job.php` | ~200 | **REFACTOR** — invoke `tofu apply` via CI webhook instead of PHP mutations |

**Total PHP deleted: ~2,500 lines**  
**Total PHP kept/slimmed: ~1,200 lines** (credential store, Stripe, OpenRouter, audit, admin UI)

---

## 4. Target State: Hybrid PHP + OpenTofu

```
┌──────────────────────────────────────────────────────────────────┐
│                        GitHub Repository                          │
│  ┌──────────────────────────────────────────────┐                │
│  │            infra/  (NEW DIRECTORY)            │                │
│  │  ┌──────────────────────────────────────────┐│                │
│  │  │ main.tf          (providers, backend)    ││                │
│  │  │ variables.tf     (input variables)       ││                │
│  │  │ outputs.tf       (output values)         ││                │
│  │  │ terraform.tfvars (non-secret defaults)   ││                │
│  │  │ workers.tf       (Cloudflare Workers)    ││                │
│  │  │ kv.tf            (KV namespaces)         ││                │
│  │  │ d1.tf            (D1 databases)          ││                │
│  │  │ ai-gateway.tf    (AI Gateway config)     ││                │
│  │  │ routes.tf        (Worker routes/DNS)     ││                │
│  │  │ secrets.tf       (Worker secrets)        ││                │
│  │  │ modules/                                ││                │
│  │  │   └── tenant-worker/                    ││                │
│  │  │       ├── main.tf                       ││                │
│  │  │       ├── variables.tf                  ││                │
│  │  │       └── outputs.tf                    ││                │
│  │  │ environments/                           ││                │
│  │  │   ├── staging.tfvars                    ││                │
│  │  │   └── production.tfvars                 ││                │
│  │  │ .terraform.lock.hcl  (committed)        ││                │
│  │  └──────────────────────────────────────────┘│                │
│  └──────────────────────────────────────────────┘                │
│                                                                   │
│  ┌──────────────────────────────────────────────┐                │
│  │      .github/workflows/opentofu.yml (NEW)    │                │
│  │  ┌──────────────────────────────────────────┐│                │
│  │  │ Plan (PR trigger)  → PR comment with diff││                │
│  │  │ Apply (merge to main) → tofu apply       ││                │
│  │  │ Drift Check (scheduled, hourly) → alert  ││                │
│  │  └──────────────────────────────────────────┘│                │
│  └──────────────────────────────────────────────┘                │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│                    WP Admin (SaaS Controller UI)                   │
│  ┌──────────┐  ┌──────────────┐  ┌──────────┐  ┌─────────────┐  │
│  │  Wizard  │  │ Deployments  │  │  Drift   │  │  Operations │  │
│  │(config   │  │(reads CI     │  │(reads CI │  │(Stripe,     │  │
│  │ editor)  │  │ status via   │  │ artifacts│  │ OpenRouter, │  │
│  │          │  │ GitHub API)  │  │ + status)│  │ billing)    │  │
│  └────┬─────┘  └──────┬───────┘  └────┬─────┘  └──────┬──────┘  │
│       │               │               │                │         │
│  ┌────▼───────────────▼───────────────▼────────────────▼──────┐  │
│  │              SaaS Controller PHP (SLIMMED)                  │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐ │  │
│  │  │CredentialStore│  │  AuditLog    │  │  GitHub API      │ │  │
│  │  │(encrypted WP  │  │  (all ops    │  │  client (NEW)    │ │  │
│  │  │ options)      │  │   logged)    │  │  — reads CI runs │ │  │
│  │  └──────────────┘  └──────────────┘  └──────────────────┘ │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐ │  │
│  │  │StripeClient   │  │OpenRouter    │  │  Webhook Receiver│ │  │
│  │  │(products,     │  │Client        │  │  (CI status      │ │  │
│  │  │ prices, subs) │  │(key mgmt)    │  │   callbacks)     │ │  │
│  │  └──────────────┘  └──────────────┘  └──────────────────┘ │  │
│  └────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
```

### SaaS Controller responsibilities after migration:

| Responsibility | Before (PHP IaC) | After (OpenTofu) |
|---|---|---|
| Define desired Cloudflare topology | `DeploymentConfig` PHP arrays | `infra/*.tf` HCL files |
| Preview changes | `PlanGenerator::generate()` | `tofu plan` in CI |
| Apply changes | `ApplyEngine::apply()` | `tofu apply` in CI |
| Detect drift | `DriftDetector::check()` | `tofu plan -detailed-exitcode` hourly CI |
| Store credentials | `CredentialStore` | `CredentialStore` (feeds GitHub Actions secrets) |
| Audit trail | `AuditLog` | `AuditLog` + CI run history |
| HITL approval | Token-gated `ApplyEngine` | GitHub PR review + protected branch |
| Wizard UI | PHP generates desired config | PHP edits `.tfvars` files + triggers CI |
| Stripe products/prices | `PlanGenerator` + `ApplyEngine` | **Keep in PHP** (for Phase 1; Stripe provider evaluation later) |
| OpenRouter keys | `PlanGenerator` + `ApplyEngine` | **Keep in PHP** (no OpenTofu provider exists) |

---

## 5. Infrastructure Inventory

### 5.1 Cloudflare — Managed by OpenTofu

| Resource | OpenTofu Resource Type | Current wrangler/PHP Method |
|---|---|---|
| Worker Script | `cloudflare_worker_script` | `wrangler deploy` / `upload_worker_script()` |
| KV Namespace | `cloudflare_workers_kv_namespace` | `wrangler kv:namespace create` / `create_kv_namespace()` |
| D1 Database | `cloudflare_d1_database` | `wrangler d1 create` / `create_d1_database()` |
| AI Gateway | `cloudflare_ai_gateway` | `create_ai_gateway()` |
| Worker Routes | `cloudflare_worker_route` | `wrangler.toml` `[[routes]]` |
| Worker Secrets | `cloudflare_workers_secret` | Wrangler secrets / PHP uploads |
| DNS Records | `cloudflare_record` | Manual (for custom domain routing) |

### 5.2 Stripe — Decision: Keep in PHP (for Phase 1)

OpenTofu has a Stripe provider (`stripe/stripe`), but the SaaS Controller's Stripe integration is tightly coupled to billing workflow logic and dynamic product/price management. **Keep Stripe product/price management in PHP** for Phase 1; evaluate migration in a future phase.

### 5.3 OpenRouter — Keep in PHP

No OpenTofu provider exists for OpenRouter. Key management stays in PHP permanently.

---

## 6. OpenTofu Directory Structure

```
mcp-ai-wpoos/
├── infra/                              # NEW — Infrastructure as Code
│   ├── main.tf                         # Provider config, backend, encryption
│   ├── variables.tf                    # Input variable declarations
│   ├── outputs.tf                      # Output values (Worker URLs, KV IDs, etc.)
│   ├── terraform.tfvars                # Non-sensitive default values
│   ├── workers.tf                      # Cloudflare Worker Scripts
│   ├── kv.tf                           # KV Namespaces
│   ├── d1.tf                           # D1 Databases
│   ├── ai-gateway.tf                   # AI Gateway configuration
│   ├── routes.tf                       # Worker Routes / DNS records
│   ├── secrets.tf                      # Worker secrets (encrypted in state)
│   ├── environments/
│   │   ├── staging.tfvars              # Staging overrides
│   │   └── production.tfvars           # Production overrides
│   ├── modules/                        # Reusable modules
│   │   └── tenant-worker/              # Reusable tenant Worker module
│   │       ├── main.tf
│   │       ├── variables.tf
│   │       └── outputs.tf
│   └── .terraform.lock.hcl            # Provider version lockfile (committed to git)
├── .github/
│   └── workflows/
│       └── opentofu.yml               # NEW — CI/CD for tofu plan/apply/drift
```

### 6.1 Example `infra/main.tf`

```hcl
terraform {
  required_version = ">= 1.9.0"

  required_providers {
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 5.0"
    }
  }

  # State stored in Cloudflare R2 (S3-compatible, zero egress fees)
  backend "s3" {
    bucket         = "nvoos-tfstate"
    key            = "production/terraform.tfstate"
    region         = "auto"
    endpoint       = "https://${var.cloudflare_account_id}.r2.cloudflarestorage.com"
    skip_credentials_validation = true
    skip_region_validation      = true
    skip_requesting_account_id  = true
    skip_s3_checksum            = true
  }

  # Client-side state encryption (OpenTofu 1.7+)
  # State is encrypted BEFORE it leaves the CI runner
  encryption {
    key_provider "pbkdf2" "mykey" {
      passphrase = var.state_encryption_passphrase
      key_length = 32
      iterations = 600000
    }

    method "aes_gcm" "default" {
      key_provider = key_provider.pbkdf2.mykey
    }

    state {
      method   = method.aes_gcm.default
      enforced = true
    }
  }
}

provider "cloudflare" {
  api_token  = var.cloudflare_api_token
  account_id = var.cloudflare_account_id
}
```

### 6.2 Example `infra/workers.tf` — Tenant Router Worker

```hcl
resource "cloudflare_worker_script" "tenant_router" {
  account_id          = var.cloudflare_account_id
  name                = var.tenant_router_worker_name
  content             = file("${path.module}/../addons/tenant-router/dist/index.js")
  module              = true
  compatibility_date  = "2025-06-01"
  logpush             = true

  # Bind KV namespace for tenant→origin lookups
  kv_namespace_binding {
    name         = "TENANT_KV"
    namespace_id = cloudflare_workers_kv_namespace.tenant_kv.id
  }

  # Bind D1 database for tenant data
  d1_database_binding {
    name        = "TENANT_DB"
    database_id = cloudflare_d1_database.tenant_db.id
  }

  # Environment variables (non-secret)
  plain_text_binding {
    name = "PLATFORM_ORIGIN"
    text = var.platform_origin
  }
}

# Worker secrets are managed separately — see secrets.tf

# Route *.scheduleanything.com → tenant-router Worker
resource "cloudflare_worker_route" "tenant_wildcard" {
  zone_id     = var.cloudflare_zone_id
  pattern     = "*.${var.tenant_domain}/*"
  script_name = cloudflare_worker_script.tenant_router.name
}

# KV namespace for tenant→origin mapping
resource "cloudflare_workers_kv_namespace" "tenant_kv" {
  account_id = var.cloudflare_account_id
  title      = "SA_TENANT_KV"
}

# D1 database for tenant data
resource "cloudflare_d1_database" "tenant_db" {
  account_id = var.cloudflare_account_id
  name       = "saas-tenant-db"
}
```

### 6.3 Example `infra/secrets.tf`

```hcl
# SAAS_API_KEY — internal API key for platform REST calls
# The value comes from var.saas_api_key (marked sensitive)
resource "cloudflare_workers_secret" "saas_api_key" {
  account_id  = var.cloudflare_account_id
  script_name = cloudflare_worker_script.tenant_router.name
  name        = "SAAS_API_KEY"
  secret_text = var.saas_api_key
}
```

### 6.4 Example `infra/variables.tf`

```hcl
variable "cloudflare_account_id" {
  description = "Cloudflare Account ID"
  type        = string
  sensitive   = false
}

variable "cloudflare_api_token" {
  description = "Cloudflare API Token with Workers, KV, D1, AI Gateway permissions"
  type        = string
  sensitive   = true
}

variable "cloudflare_zone_id" {
  description = "Cloudflare Zone ID for DNS/routing"
  type        = string
}

variable "tenant_router_worker_name" {
  description = "Name of the tenant router Cloudflare Worker"
  type        = string
  default     = "sa-tenant-router"
}

variable "tenant_domain" {
  description = "Base domain for multi-tenant routing (e.g., scheduleanything.com)"
  type        = string
}

variable "platform_origin" {
  description = "WordPress platform origin URL for KV fallback"
  type        = string
}

variable "saas_api_key" {
  description = "Internal API key for platform REST calls"
  type        = string
  sensitive   = true
}

variable "state_encryption_passphrase" {
  description = "Passphrase for client-side state encryption"
  type        = string
  sensitive   = true
}
```

---

## 7. State Management

### 7.1 Backend: Cloudflare R2

State files are stored in a Cloudflare R2 bucket (S3-compatible API). R2 is chosen because:
- **Zero egress fees** (unlike AWS S3)
- Already in the Cloudflare ecosystem (single vendor for Workers + state)
- S3-compatible API works with OpenTofu's `s3` backend
- R2 bucket has versioning enabled for state file recovery

### 7.2 State Encryption (Client-Side)

OpenTofu 1.7+ supports **client-side state encryption**. Even though R2 encrypts at rest, client-side encryption means the state file is encrypted **before it leaves the CI runner**. The encryption passphrase is stored as a GitHub Actions secret.

Key providers available if PBKDF2 is insufficient:
- AWS KMS
- GCP KMS
- OpenBao (open-source HashiCorp Vault fork)

### 7.3 State Locking

R2 doesn't natively support DynamoDB-style locking, so we use **GitHub Actions concurrency control**:

```yaml
concurrency:
  group: opentofu-${{ github.ref }}
  cancel-in-progress: false
```

This prevents concurrent `tofu apply` runs from the same branch/environment.

### 7.4 Workspaces vs. Directory Per Environment

**Decision: File-based environment separation** (not OpenTofu workspaces).

- `infra/environments/staging.tfvars`
- `infra/environments/production.tfvars`

Reasons:
- Keeps state files completely separate (`staging/terraform.tfstate` vs `production/terraform.tfstate`)
- Avoids workspace-related footguns (different backends, different credentials)
- Each environment can use different Cloudflare accounts if needed
- Simpler mental model for the team

---

## 8. CI/CD Integration

### 8.1 New Workflow: `.github/workflows/opentofu.yml`

```yaml
name: OpenTofu IaC

on:
  pull_request:
    paths:
      - 'infra/**'
      - 'addons/tenant-router/src/**'
      - 'addons/saas-controller/worker/**'
      - '.github/workflows/opentofu.yml'
  push:
    branches: [main]
    paths:
      - 'infra/**'
      - 'addons/tenant-router/src/**'
      - 'addons/saas-controller/worker/**'
  schedule:
    - cron: '0 * * * *'  # Hourly drift check

concurrency:
  group: opentofu-${{ github.ref }}
  cancel-in-progress: false

jobs:
  plan:
    name: OpenTofu Plan (Staging)
    if: github.event_name == 'pull_request'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup OpenTofu
        uses: opentofu/setup-opentofu@v1
        with:
          tofu_version: '1.9.0'

      - name: Build Tenant Router Worker
        run: |
          cd addons/tenant-router
          npm ci
          npm run build

      - name: OpenTofu Init
        working-directory: infra
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.R2_ACCESS_KEY_ID }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.R2_SECRET_ACCESS_KEY }}
        run: tofu init

      - name: OpenTofu Format Check
        working-directory: infra
        run: tofu fmt -check -recursive

      - name: OpenTofu Validate
        working-directory: infra
        run: tofu validate

      - name: OpenTofu Plan
        id: plan
        working-directory: infra
        env:
          CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN_STAGING }}
          CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
          TF_VAR_saas_api_key: ${{ secrets.SAAS_API_KEY_STAGING }}
          TF_VAR_state_encryption_passphrase: ${{ secrets.TF_STATE_ENCRYPTION_PASSPHRASE }}
        run: |
          tofu plan \
            -var-file="environments/staging.tfvars" \
            -detailed-exitcode \
            -out=tfplan-staging.binary \
            2>&1 | tee plan-output.txt
        continue-on-error: true

      - name: Post Plan Result to PR
        if: always()
        uses: actions/github-script@v7
        with:
          script: |
            const fs = require('fs');
            const planOutput = fs.readFileSync('infra/plan-output.txt', 'utf8');

            // Truncate to avoid comment size limits
            const truncated = planOutput.length > 60000
              ? planOutput.substring(0, 60000) + '\n\n... (plan truncated)'
              : planOutput;

            const body = `## 🏗️ OpenTofu Plan — Staging\n\n\`\`\`\n${truncated}\n\`\`\``;
            await github.rest.issues.createComment({
              owner: context.repo.owner,
              repo: context.repo.repo,
              issue_number: context.issue.number,
              body
            });

  apply:
    name: OpenTofu Apply (Production)
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    environment: production  # Requires manual approval via GitHub Environments
    steps:
      - uses: actions/checkout@v4

      - name: Setup OpenTofu
        uses: opentofu/setup-opentofu@v1
        with:
          tofu_version: '1.9.0'

      - name: Build Tenant Router Worker
        run: |
          cd addons/tenant-router
          npm ci
          npm run build

      - name: OpenTofu Init
        working-directory: infra
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.R2_ACCESS_KEY_ID }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.R2_SECRET_ACCESS_KEY }}
        run: tofu init

      - name: OpenTofu Apply
        working-directory: infra
        env:
          CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
          TF_VAR_saas_api_key: ${{ secrets.SAAS_API_KEY }}
          TF_VAR_state_encryption_passphrase: ${{ secrets.TF_STATE_ENCRYPTION_PASSPHRASE }}
        run: |
          tofu apply \
            -var-file="environments/production.tfvars" \
            -auto-approve

      - name: Post-Apply Smoke Test
        run: |
          # Verify tenant-router Worker is responsive
          curl -sf "https://test.${TF_VAR_tenant_domain}/health" || \
            echo "::warning::Smoke test failed — check Cloudflare dashboard"

  drift-check:
    name: Drift Detection (Production)
    if: github.event_name == 'schedule'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup OpenTofu
        uses: opentofu/setup-opentofu@v1
        with:
          tofu_version: '1.9.0'

      - name: OpenTofu Init
        working-directory: infra
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.R2_ACCESS_KEY_ID }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.R2_SECRET_ACCESS_KEY }}
        run: tofu init

      - name: OpenTofu Plan (Drift Check)
        id: drift
        working-directory: infra
        env:
          CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
          TF_VAR_state_encryption_passphrase: ${{ secrets.TF_STATE_ENCRYPTION_PASSPHRASE }}
        run: |
          tofu plan \
            -var-file="environments/production.tfvars" \
            -detailed-exitcode \
            -out=tfplan-drift.binary \
            2>&1 | tee drift-output.txt
        continue-on-error: true  # Exit code 2 = drift detected (not a failure)

      - name: Alert on Drift
        if: steps.drift.outcome == 'failure'
        uses: actions/github-script@v7
        with:
          script: |
            const fs = require('fs');
            const driftOutput = fs.readFileSync('infra/drift-output.txt', 'utf8');

            // Create a GitHub Issue for drift
            await github.rest.issues.create({
              owner: context.repo.owner,
              repo: context.repo.repo,
              title: '🚨 Infrastructure Drift Detected — Production Cloudflare',
              body: `OpenTofu detected drift in production.\n\n\`\`\`\n${driftOutput}\n\`\`\`\n\n**Resolution:** Review and either run \`tofu apply\` to reconcile, or update \`infra/*.tf\` to accept the drift.`,
              labels: ['drift', 'infrastructure', 'production']
            });
```

### 8.2 HITL (Human-in-the-Loop) Approval

The SaaS Controller's current HITL model uses PHP-generated tokens stored in transients. With OpenTofu:

1. **PR-based approval**: Changes to `infra/` require PR review and approval (GitHub branch protection rules on `main`)
2. **Environment protection**: The `production` environment in GitHub Actions requires manual approval before `tofu apply` runs
3. **WP Admin integration**: The SaaS Controller admin UI can:
   - Display CI run status via the GitHub API
   - Trigger plan-only runs via `repository_dispatch`
   - Show the latest plan output

---

## 9. Phased Migration Plan

### Phase 1: Shadow Mode (Weeks 1–2) 🟢 Lowest Risk

**Goal**: Run OpenTofu in parallel with the existing PHP engine, without applying changes.

- [ ] Install OpenTofu CLI on CI runners (via `opentofu/setup-opentofu@v1` action)
- [ ] Create `infra/` directory with all `.tf` files
- [ ] Set up R2 bucket for state storage + enable versioning
- [ ] Add `.github/workflows/opentofu.yml` with **plan-only** on PRs (no apply job yet)
- [ ] Import existing Cloudflare resources into OpenTofu state:
  ```bash
  tofu import cloudflare_worker_script.tenant_router <account_id>/<script_name>
  tofu import cloudflare_workers_kv_namespace.tenant_kv <account_id>/<namespace_id>
  tofu import cloudflare_d1_database.tenant_db <account_id>/<database_id>
  tofu import cloudflare_worker_route.tenant_wildcard <zone_id>/<route_id>
  ```
- [ ] Run `tofu plan` against production — verify "No changes. Infrastructure is up-to-date."
- [ ] Add state encryption
- [ ] **SaaS Controller runs completely unchanged** — OpenTofu is read-only

**Success criteria**: `tofu plan` against production shows zero changes (perfect import). PR CI shows plan output in PR comment.

### Phase 2: Tenant Router Migration (Week 3) 🟡 Low Risk

**Goal**: Migrate the simplest resource (tenant-router Worker + KV namespace) to OpenTofu-managed, proving the pipeline end-to-end.

- [ ] `wrangler deploy` for tenant-router is deprecated in favor of `tofu apply`
- [ ] OpenTofu manages `cloudflare_worker_script.tenant_router`
- [ ] OpenTofu manages `cloudflare_workers_kv_namespace.tenant_kv`
- [ ] OpenTofu manages `cloudflare_worker_route.tenant_wildcard`
- [ ] OpenTofu manages `cloudflare_d1_database.tenant_db`
- [ ] Enable the `apply` job in `opentofu.yml` (gated on `environment: production`)
- [ ] Remove `CloudflareMutatingClient::upload_worker_script()` and related methods
- [ ] First real `tofu apply` runs on merge to `main`
- [ ] **SaaS Controller** still uses PHP for Plan/Apply of shared infra (D1, KV, AI Gateway)

**Success criteria**: A PR changing tenant-router source → `tofu plan` shows diff in PR comment → merge → `tofu apply` deploys the updated Worker automatically. Rollback via `git revert` works.

### Phase 3: Full Cloudflare Migration (Weeks 4–5) 🟠 Medium Risk

**Goal**: All Cloudflare resources managed by OpenTofu. No more PHP-driven Cloudflare mutations.

- [ ] Migrate all remaining D1 databases, KV namespaces, AI Gateways to `.tf`
- [ ] Migrate Worker routes for all Workers
- [ ] Add Worker secrets management via `cloudflare_workers_secret`
- [ ] **Delete** `class-nvoos-saas-controller-plan-generator.php` (~522 lines)
- [ ] **Delete** `class-nvoos-saas-controller-drift-detector.php` (~374 lines)
- [ ] **Delete** `class-nvoos-saas-controller-cloudflare-mutating-client.php` (~573 lines)
- [ ] **Slim** `class-nvoos-saas-controller-apply-engine.php` — remove Cloudflare apply methods; keep Stripe/OpenRouter only (~1,400 → ~500 lines)
- [ ] **Slim** `class-nvoos-saas-controller-cloudflare-client.php` — keep read-only `list_*()` methods for admin UI; remove `create_*()`/`update_*()`/`delete_*()` (~400 → ~200 lines)
- [ ] **Refactor** `class-nvoos-saas-controller-apply-job.php` — invoke `tofu apply` via CI webhook instead of PHP mutations
- [ ] Update SaaS Controller admin UI to read CI status from GitHub API
- [ ] Remove the PHP Plan/Apply UI code from admin pages

**Success criteria**: All Cloudflare mutations go through `tofu apply` in CI. PHP no longer writes to Cloudflare. Drift detection runs hourly via CI and creates GitHub Issues on drift.

### Phase 4: Admin UI & Polish (Week 6) 🟢 Polish

**Goal**: Make the WP Admin experience seamless — wizards, deployments view, drift status.

- [ ] "Wizard" tab edits `infra/environments/production.tfvars` via PHP file writes
- [ ] "Deployments" tab shows recent OpenTofu CI runs (fetched via GitHub API)
- [ ] "Drift" tab shows last drift-check result (fetched from CI artifact or GitHub Issue)
- [ ] Add "Plan Preview" button that triggers a CI plan run via `repository_dispatch`
- [ ] Add "Apply" button (capability-gated + nonce-protected) that triggers CI apply
- [ ] Remove legacy Plan/Apply UI code from SaaS Controller admin
- [ ] Update `addons/saas-controller/README.md` to reflect new architecture

### Phase 5: Stripe Provider Evaluation (Future, Optional) 🔵

Evaluate migrating Stripe product/price management to OpenTofu's Stripe provider (`stripe/stripe`). Only if the tradeoffs (declarative config vs dynamic WP Admin management) favor OpenTofu. Low priority.

---

## 10. Secret Management

### 10.1 Current State (PHP Engine)

| Secret | Storage | Access |
|---|---|---|
| Cloudflare API Token | `CredentialStore` (encrypted WP option) | PHP reads at runtime |
| Stripe Secret Key | `CredentialStore` (encrypted WP option) | PHP reads at runtime |
| OpenRouter Provisioning Key | `CredentialStore` (encrypted WP option) | PHP reads at runtime |
| Worker secrets (SAAS_API_KEY) | Cloudflare Worker secrets (set via wrangler/PHP) | Worker runtime |

### 10.2 Target State (OpenTofu + PHP)

| Secret | Storage | Access |
|---|---|---|
| Cloudflare API Token (prod) | GitHub Actions Secret | CI runner env var → OpenTofu provider |
| Cloudflare API Token (staging) | GitHub Actions Secret | CI runner env var → OpenTofu provider |
| R2 Access Key (state backend) | GitHub Actions Secret | CI runner env var → OpenTofu backend |
| State Encryption Passphrase | GitHub Actions Secret | CI runner env var → OpenTofu encryption block |
| Worker secrets (SAAS_API_KEY) | GitHub Actions Secret → `cloudflare_workers_secret` | OpenTofu sets on apply |
| Stripe Secret Key | `CredentialStore` (unchanged) | PHP (unchanged) |
| OpenRouter Provisioning Key | `CredentialStore` (unchanged) | PHP (unchanged) |
| GitHub PAT (for admin UI) | `CredentialStore` (encrypted WP option) | PHP reads CI status |

### 10.3 `CredentialStore` — What Stays, What Goes

**Stays in CredentialStore:**
- Stripe secret key (still used by PHP Stripe client)
- OpenRouter provisioning key (still used by PHP OpenRouter client)
- License keys
- GitHub PAT with `repo` scope (for admin UI to read CI status)

**Moves to GitHub Secrets:**
- Cloudflare API tokens
- R2 access keys
- State encryption passphrase
- Worker secrets (SAAS_API_KEY, etc.)

---

## 11. Rollback Strategy

### 11.1 Per-Apply Rollback

```bash
# If a bad apply was just done:
git revert <bad-commit>     # Revert the .tf change
git push origin main        # Triggers tofu apply to rollback

# Or, if the state needs manual intervention:
tofu state list                              # See what was changed
tofu state rm <resource_address>             # Remove from state
tofu apply                                   # Re-create old config
```

### 11.2 Full Rollback to PHP Engine

If OpenTofu proves unworkable:
1. Stop the `opentofu.yml` workflow (disable in GitHub Actions)
2. Revert the file deletions from Phase 3 — all deleted classes are in git history:
   ```bash
   git revert <commits-that-deleted-classes>
   ```
3. Cloudflare resources exist independently of OpenTofu state — nothing is "locked in" to the tool
4. Re-enable `wrangler deploy` workflow for tenant-router

### 11.3 State File Corruption Recovery

1. R2 bucket has versioning enabled — restore previous version via Cloudflare Dashboard or API
2. Or: delete the state file entirely and `tofu import` all resources fresh (they still exist on Cloudflare)

---

## 12. Testing Strategy

### 12.1 OpenTofu Validation (CI)

```bash
# Syntax validation — catches HCL errors
tofu validate

# Format check — enforces consistent style
tofu fmt -check -recursive

# Plan against staging — dry run, no side effects
tofu plan -var-file="environments/staging.tfvars" -detailed-exitcode
```

### 12.2 CI Workflow Testing

A lightweight test workflow runs on every PR that touches `infra/`:
- `tofu init` with an empty/ephemeral backend (no state lock contention)
- `tofu validate`
- `tofu fmt -check`

### 12.3 Smoke Tests After Apply

The existing `SmokeTester` class (or a simplified version) runs after `tofu apply`:
```bash
# Verify tenant-router Worker responds
curl -sf "https://test.${TF_VAR_tenant_domain}/health"

# Verify KV namespace exists and is accessible
# (via Cloudflare API or tofu output values)
```

### 12.4 PHPUnit Tests — Impact

| Test File | Disposition |
|---|---|
| `PlanGenerator` tests | **DELETE** (class deleted) |
| `ApplyEngine` tests (Cloudflare) | **DELETE** (methods deleted) |
| `ApplyEngine` tests (Stripe/OpenRouter) | **KEEP** (methods kept) |
| `DriftDetector` tests | **DELETE** (class deleted) |
| `CloudflareMutatingClient` tests | **DELETE** (class deleted) |
| `CloudflareClient` read-only tests | **KEEP** (methods kept) |
| New: GitHub API client tests | **ADD** (for admin UI to read CI status) |

---

## 13. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| State file corruption | Low | High | R2 bucket versioning; documented recovery; state encryption |
| Cloudflare API rate limiting during plan | Medium | Medium | `tofu plan -parallelism=5`; Cloudflare API is generous (1,200 req/5 min) |
| Credential leak via state file | Low | Critical | Client-side state encryption; passphrase in GH Secrets; encrypted in transit (TLS) |
| CI runner compromise | Low | High | OIDC-based Cloudflare auth (no long-lived tokens); short-lived CI tokens |
| Merge conflicts on `.tf` files | Medium | Low | Standard git resolution; `.tf` files are declarative and merge-friendly |
| OpenTofu learning curve for team | Medium | Medium | Phase 1 shadow mode is zero-risk; team learns by reading plan output |
| OpenTofu project abandoned | Very Low | High | Linux Foundation stewardship; 23,600+ modules; MPL-2.0 license prevents BUSL-style rug-pull; drop-in Terraform compatibility |
| Import mismatch (state != reality) | Medium | Medium | `tofu import` each resource manually; verify with `tofu plan` (should show "No changes") |
| Worker build failure breaks apply | Medium | Medium | Build Worker step is separate; `tofu plan` catches missing dist files before apply |

---

## 14. Success Metrics

- [ ] `tofu plan` runs in under 2 minutes on GitHub Actions CI
- [ ] Drift detection runs hourly and creates a GitHub Issue within 5 minutes of detected drift
- [ ] PR-based plan preview appears as a PR comment within 3 minutes of PR creation
- [ ] Zero production Cloudflare mutations happen outside of `tofu apply`
- [ ] ~2,500 lines of PHP deleted from the SaaS Controller addon
- [ ] SaaS Controller admin UI shows CI deployment status within 10 seconds of page load
- [ ] Rollback from any OpenTofu apply completes in under 5 minutes

---

## 15. Open Questions

1. **R2 vs S3 for state backend**: R2 is preferred (already in Cloudflare ecosystem, no egress fees). But does the S3-compatible API handle locking correctly? If not, fall back to GitHub Actions concurrency control (already in the plan).

2. **GitHub Environments approval**: Does the team want manual approval gates on production applies, or is branch protection on `main` sufficient? The plan uses `environment: production` which requires a reviewer.

3. **Stripe migration**: Should Stripe products/prices eventually move to OpenTofu? The `stripe/stripe` provider exists, but the SaaS Controller's dynamic billing workflow may not translate well to declarative config. Deferred to Phase 5.

4. **Multi-tenant provisioning**: When a new tenant is created via the SaaS Controller, who generates the `.tfvars` entry? Current plan: SaaS Controller appends to `environments/production.tfvars` and triggers CI via `repository_dispatch`. This needs more design.

5. **Cloudflare provider version**: Pin to `~> 5.0` (latest major). The Cloudflare provider was recently rewritten — ensure the resources we need (Workers, KV, D1, AI Gateway, Routes) are stable in v5.

---

## 16. References

- [OpenTofu Documentation](https://opentofu.org/docs/)
- [OpenTofu Registry — Cloudflare Provider](https://search.opentofu.org/provider/cloudflare/cloudflare/latest)
- [OpenTofu State Encryption](https://opentofu.org/docs/v1.7/introduction/state-encryption/)
- [OpenTofu S3 Backend](https://opentofu.org/docs/v1.12/language/settings/backends/s3/)
- [GitHub Actions — setup-opentofu](https://github.com/opentofu/setup-opentofu)
- [Cloudflare Workers — Terraform Provider Docs](https://registry.terraform.io/providers/cloudflare/cloudflare/latest/docs/resources/worker_script)
- [OpenTofu `plan` Command](https://opentofu.org/docs/v1.12/cli/commands/plan/)
- NV oOS SaaS Controller: `addons/saas-controller/README.md`
- NV oOS Tenant Router: `addons/tenant-router/README.md`
- NV oOS Architecture Docs: `docs/developer/architecture/`
