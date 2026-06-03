# Screenshot Inventory

Canonical map of every admin page slug → expected screenshot.
Run `node bin/check-screenshot-coverage.js` to verify coverage.

Status legend: ✅ captured | ⚠️ needs Pro addon | 🔧 needs toolkit enabled | 📦 needs addon | 🔑 needs API key | ⏳ pending

---

## Base Plugin — No Extra Setup Required

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| wp-mcp-ai-dashboard | admin/settings-general.png | ✅ |
| wp-mcp-ai-cron-manager | admin/cron-manager.png | ✅ |
| wp-mcp-ai-token-manager | admin/token-manager.png | ✅ |
| wp-mcp-ai-crawl4ai-monitor | admin/crawl4ai-monitor.png | ✅ |
| wp-mcp-ai-measurement | admin/measurement-dashboard.png | ✅ |
| wp-mcp-ai-dlq-manager | admin/dlq-manager.png | ⏳ |
| wp-mcp-ai-markup-telemetry | admin/markup-telemetry.png | ⏳ |
| wp-mcp-ai-ext-cognition | admin/ext-cognition.png | ⏳ |
| wp-mcp-ai-skill-manager | admin/skill-manager.png | ⏳ |
| wp-mcp-ai-skill-settings | admin/skill-settings.png | ⏳ |
| wp-mcp-ai-getting-started | admin/getting-started.png | ⏳ |
| wp-mcp-ai-settings | admin/settings-legacy.png | ⏳ |
| wp-mcp-ai-simple-settings | admin/simple-settings.png | ⏳ |
| wp-mcp-ai-plugins | admin/plugins-integration.png | ⏳ |
| wp-mcp-ai-workflow-editor | admin/workflow-editor.png | ⏳ |

## Base Plugin — Needs Specific Plugin Active

| Page slug | Screenshot | Requires | Status |
|-----------|-----------|----------|--------|
| wp-mcp-ai-elementor | admin/elementor-settings.png | Elementor | ⏳ |
| wp-mcp-ai-jetengine | admin/jetengine-settings.png | JetEngine | ⏳ |
| wp-mcp-ai-woocommerce | admin/woocommerce-settings.png | WooCommerce | ⏳ |
| wp-mcp-ai-gmail-crawl4ai | admin/gmail-crawl4ai.png | Gmail API | ⏳ |
| wp-mcp-ai-auth0-setup | admin/auth0-setup.png | Auth0 | ⏳ |

## Pro Addon — No Extra Toolkits Required

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| nvoos-pro-dashboard | dashboard/pro-dashboard-overview.png | ✅ |
| nvoos-pro-dashboard-diagnostic | dashboard/pro-dashboard-diagnostic.png | ✅ |
| nvoos-pro-settings | dashboard/pro-settings.png | ✅ |
| nvoos-pro-agent-command-center | dashboard/agent-command-center.png | ✅ |
| nvoos-pro-blueprints | dashboard/blueprints.png | ✅ |
| nvoos-pro-schedule-manager | dashboard/schedule-manager.png | ✅ |
| nvoos-pro-toolkit-mcp-servers | dashboard/toolkit-mcp-servers.png | ✅ |
| nvoos-pro-webhook-status | dashboard/webhook-status.png | ✅ |
| nvoos-pro-workflow-builder | dashboard/workflow-builder.png | ✅ |
| nvoos-pro-dashboard-audits | dashboard/security-audits.png | ✅ |
| nvoos-pro-dashboard-training | dashboard/security-training.png | ✅ |
| nvoos-pro-dashboard-suppliers | dashboard/security-suppliers.png | ✅ |
| nvoos-pro-dashboard-assets | dashboard/asset-inventory.png | ✅ |
| wp-mcp-ai-webllm-settings | dashboard/webllm-settings.png | ⏳ |

## CRM Toolkit — enable_crm_toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| nvoos-crm-command-center | dashboard/crm-command-center.png | ✅ |
| nvoos-crm-blueprints | dashboard/crm-blueprints.png | ⏳ |
| research-company | dashboard/company-research.png | ✅ |
| research-deal | dashboard/deal-research.png | ✅ |
| research-lead | dashboard/lead-research.png | ⏳ |
| wp-mcp-ai-company-settings | dashboard/company-settings.png | ⏳ |
| wp-mcp-ai-deal-settings | dashboard/deal-settings.png | ⏳ |
| wp-mcp-ai-lead-settings | dashboard/lead-settings.png | ⏳ |

## E-commerce Toolkit — enable_ecommerce_toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-product | dashboard/product-research.png | ⏳ |
| product-consolidate | dashboard/product-consolidate.png | ⏳ |

## Event Toolkit — enable_*_toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-event | dashboard/event-research.png | ⏳ |
| event-consolidate | dashboard/event-consolidate.png | ⏳ |

## Comic / Design Toolkit — enable_*_toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-comic | dashboard/comic-research.png | ⏳ |
| consolidate-comic | dashboard/comic-consolidate.png | ⏳ |
| research-document-template | dashboard/document-template-research.png | ⏳ |
| research-image-template | dashboard/image-template-research.png | ⏳ |
| design-media | dashboard/design-media.png | ⏳ |

## Calendar / Booking Toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-appointment | dashboard/appointment-research.png | ⏳ |

## Financial Toolkit — enable_financial_planner_toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-financial-account | dashboard/financial-account-research.png | ⏳ |
| research-cre-debt | dashboard/cre-debt-research.png | ⏳ |
| cre-debt-dashboard | dashboard/cre-debt-dashboard.png | ⏳ |

## ECA Toolkit — enable_analytics_toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-eca | dashboard/eca-research.png | ⏳ |
| eca-dashboard | dashboard/eca-dashboard.png | ✅ |

## Healthcare / Wellness Toolkits

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| healthcare-imaging-viewer | dashboard/healthcare-imaging.png | ⏳ |
| health-records-consolidate | dashboard/health-records-consolidate.png | ⏳ |
| health-wellness-dashboard | dashboard/health-wellness-dashboard.png | ⏳ |
| medical-vitals-dashboard | dashboard/medical-vitals.png | ⏳ |

## Architectural Design Toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| architectural-drawing-research | dashboard/arch-drawing-research.png | ⏳ |
| architectural-project-research | dashboard/arch-project-research.png | ⏳ |
| architectural-specification-research | dashboard/arch-spec-research.png | ⏳ |

## Schedule / Pro Schedule Toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-pro-schedule | dashboard/pro-schedule-research.png | ⏳ |

## Law Firm Toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-law-firm | dashboard/law-firm-research.png | ⏳ |
| law-firm-dashboard | dashboard/law-firm-dashboard.png | ⏳ |

## Regulatory Registration Toolkit

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| wp-mcp-ai-reg-document-research | dashboard/reg-document-research.png | ⏳ |
| wp-mcp-ai-registration-research | dashboard/reg-registration-research.png | ⏳ |
| wp-mcp-ai-reg-product-research | dashboard/reg-product-research.png | ⏳ |

## Other Research Pages (various CPTs)

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-place | dashboard/place-research.png | ⏳ |
| research-policy | dashboard/policy-research.png | ⏳ |
| research-post | dashboard/post-research.png | ⏳ |
| research-profession | dashboard/profession-research.png | ⏳ |
| research-project | dashboard/project-research.png | ⏳ |
| research-quiz | dashboard/quiz-research.png | ⏳ |
| research-skill | dashboard/skill-research.png | ⏳ |
| research-task | dashboard/task-research.png | ⏳ |
| research-team | dashboard/team-research.png | ⏳ |
| page-research-page | dashboard/page-research.png | ⏳ |

## Addons — Separate Plugin Activation Required

| Page slug | Screenshot | Requires | Status |
|-----------|-----------|----------|--------|
| nvoos-graphify | dashboard/graphify-settings.png | Graphify addon | ⏳ |
| research-fantasy-football | dashboard/fantasy-football-research.png | Fantasy Football addon | ⏳ |
| nvoos-saas-controller | dashboard/saas-controller.png | SaaS Controller addon | ⏳ |

## Needs AI Provider API Key

| Feature | Screenshot | Status |
|---------|-----------|--------|
| Chat shortcode | chat/frontend-shortcode.png | 🔑 |
| Chat conversation | chat/chat-conversation-example.png | 🔑 |
| Chat attachments | chat/chat-with-attachments.png | 🔑 |
| Chat tool exec | chat/chat-tool-execution.png | 🔑 |
| Chat streaming | chat/chat-streaming-response.png | 🔑 |
| Chat shortcuts | chat/chat-shortcuts-buttons.png | 🔑 |
| Chat error | chat/chat-error-handling.png | 🔑 |
| Chat mobile portrait | chat/chat-mobile-portrait.png | 🔑 |
| Chat mobile landscape | chat/chat-mobile-landscape.png | 🔑 |
| Chat guest mode | chat/frontend-guest-mode.png | 🔑 |
| Chat localStorage | chat/chat-history-localstorage.png | 🔑 |
| Chat history restore | chat/chat-history-restoration.png | 🔑 |
| Elementor chat widget | chat/elementor-chat-widget.png | 🔑 |
| Elementor widget frontend | chat/elementor-chat-widget-frontend.png | 🔑 |
| Elementor dashboard widgets | chat/elementor-dashboard-widgets.png | 🔑 |
| Elementor chat intro | chat/elementor-chat-intro-widget.png | 🔑 |

---

**Total tracked:** 79 page slugs + 16 chat screenshots
**Captured:** 37 base/Pro pages
**Pending:** 42 base/Pro pages + 16 chat screenshots
