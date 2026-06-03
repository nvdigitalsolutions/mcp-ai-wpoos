# Screenshot Inventory

Canonical map of every admin page slug → expected screenshot.
Run `node bin/check-screenshot-coverage.js` to verify coverage.

Status: ✅ captured | 🔧 needs 3rd-party plugin | 📦 needs addon | 🔑 needs API key | ❓ page not found / needs investigation

---

## Base Plugin — Captured

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| wp-mcp-ai-dashboard | admin/settings-general.png | ✅ |
| wp-mcp-ai-cron-manager | admin/cron-manager.png | ✅ |
| wp-mcp-ai-token-manager | admin/token-manager.png | ✅ |
| wp-mcp-ai-crawl4ai-monitor | admin/crawl4ai-monitor.png | ✅ |
| wp-mcp-ai-measurement | admin/measurement-dashboard.png | ✅ |
| wp-mcp-ai-dlq-manager | admin/dlq-manager.png | ✅ |
| wp-mcp-ai-markup-telemetry | admin/markup-telemetry.png | ✅ |
| wp-mcp-ai-skill-settings | admin/skill-settings.png | ✅ |
| wp-mcp-ai-getting-started | admin/getting-started.png | ✅ |
| wp-mcp-ai-workflow-editor | admin/workflow-editor.png | ✅ |
| wp-mcp-ai-remote-sites | admin/remote-sites.png | ✅ |
| wp-mcp-ai-mcp-diagnostic | admin/mcp-diagnostic.png | ✅ |

## Base Plugin — Needs Investigation (returned WP error page)

| Page slug | Expected screenshot | Status |
|-----------|-------------------|--------|
| wp-mcp-ai-ext-cognition | admin/ext-cognition.png | ❓ |
| wp-mcp-ai-settings | admin/settings-legacy.png | ❓ |
| wp-mcp-ai-plugins | admin/plugins-integration.png | ❓ |
| wp-mcp-ai-mesh-settings | admin/mesh-settings.png | ❓ |
| wp-mcp-ai-content-assistant | admin/content-assistant.png | ❓ |
| wp-mcp-ai-diagnostics | admin/diagnostics.png | ❓ |
| wp-mcp-ai-hf-datasets | admin/hf-datasets.png | ❓ |
| wp-mcp-ai-onboarding | admin/onboarding-wizard.png | ❓ |
| wp-mcp-ai-system-status | admin/system-status.png | ❓ |
| wp-mcp-ai-tools-manager | admin/tools-manager.png | ❓ |
| wp-mcp-ai-tool-presets | admin/tool-presets.png | ❓ |
| wp-mcp-ai-simple-settings | admin/simple-settings.png | ❓ |

## Base Plugin — Needs 3rd-Party Plugin

| Page slug | Screenshot | Requires | Status |
|-----------|-----------|----------|--------|
| wp-mcp-ai-elementor | admin/elementor-settings.png | Elementor | 🔧 |
| wp-mcp-ai-jetengine | admin/jetengine-settings.png | JetEngine | 🔧 |
| wp-mcp-ai-woocommerce | admin/woocommerce-settings.png | WooCommerce | 🔧 |
| wp-mcp-ai-gmail-crawl4ai | admin/gmail-crawl4ai.png | Gmail API | 🔧 |
| wp-mcp-ai-auth0-setup | admin/auth0-setup.png | Auth0 | 🔧 |

## Pro Addon — Captured

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

## Pro Addon — Needs Investigation

| Page slug | Expected screenshot | Status |
|-----------|-------------------|--------|
| wp-mcp-ai-webllm-settings | dashboard/webllm-settings.png | ❓ |

## CRM Toolkit — Captured

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| nvoos-crm-command-center | dashboard/crm-command-center.png | ✅ |
| nvoos-crm-blueprints | dashboard/crm-blueprints.png | ✅ |
| research-company | dashboard/company-research.png | ✅ |
| research-deal | dashboard/deal-research.png | ✅ |
| research-lead | dashboard/lead-research.png | ✅ |
| wp-mcp-ai-company-settings | dashboard/company-settings.png | ✅ |
| wp-mcp-ai-deal-settings | dashboard/deal-settings.png | ✅ |
| wp-mcp-ai-lead-settings | dashboard/lead-settings.png | ✅ |

## Toolkit Research Pages — Needs Investigation (returned WP error)

| Page slug | Expected screenshot | Status |
|-----------|-------------------|--------|
| research-product | dashboard/product-research.png | ❓ |
| product-consolidate | dashboard/product-consolidate.png | ❓ |
| design-media | dashboard/design-media.png | ❓ |
| research-eca | dashboard/eca-research.png | ❓ |
| healthcare-imaging-viewer | dashboard/healthcare-imaging.png | ❓ |
| health-records-consolidate | dashboard/health-records-consolidate.png | ❓ |
| health-wellness-dashboard | dashboard/health-wellness-dashboard.png | ❓ |
| medical-vitals-dashboard | dashboard/medical-vitals.png | ❓ |
| research-law-firm | dashboard/law-firm-research.png | ❓ |
| law-firm-dashboard | dashboard/law-firm-dashboard.png | ❓ |
| wp-mcp-ai-reg-document-research | dashboard/reg-document-research.png | ❓ |
| wp-mcp-ai-registration-research | dashboard/reg-registration-research.png | ❓ |
| wp-mcp-ai-reg-product-research | dashboard/reg-product-research.png | ❓ |
| research-place | dashboard/place-research.png | ❓ |
| research-policy | dashboard/policy-research.png | ❓ |
| research-post | dashboard/post-research.png | ❓ |
| research-project | dashboard/project-research.png | ❓ |
| research-task | dashboard/task-research.png | ❓ |
| page-research-page | dashboard/page-research.png | ❓ |

## Toolkit Research Pages — Captured

| Page slug | Screenshot | Status |
|-----------|-----------|--------|
| research-image-template | dashboard/image-template-research.png | ✅ |
| research-document-template | dashboard/document-template-research.png | ✅ |
| research-appointment | dashboard/appointment-research.png | ✅ |
| research-financial-account | dashboard/financial-account-research.png | ✅ |
| research-cre-debt | dashboard/cre-debt-research.png | ✅ |
| cre-debt-dashboard | dashboard/cre-debt-dashboard.png | ✅ |
| eca-dashboard | dashboard/eca-dashboard.png | ✅ |
| architectural-drawing-research | dashboard/arch-drawing-research.png | ✅ |
| architectural-project-research | dashboard/arch-project-research.png | ✅ |
| architectural-specification-research | dashboard/arch-spec-research.png | ✅ |
| research-pro-schedule | dashboard/pro-schedule-research.png | ✅ |
| research-quiz | dashboard/quiz-research.png | ✅ |
| research-skill | dashboard/skill-research.png | ✅ |
| research-team | dashboard/team-research.png | ✅ |
| research-profession | dashboard/profession-research.png | ✅ |

## CPT List Pages — Captured

| Post type | Screenshot | Status |
|-----------|-----------|--------|
| mcp_ai_company | dashboard/companies-list.png | ✅ |
| mcp_ai_deal | dashboard/deals-list.png | ✅ |
| mcp_ai_lead | dashboard/leads-list.png | ✅ |

## Addons — Separate Activation Required

| Page slug | Screenshot | Requires | Status |
|-----------|-----------|----------|--------|
| nvoos-graphify | dashboard/graphify-settings.png | Graphify addon | 📦 |
| research-fantasy-football | dashboard/fantasy-football-research.png | Fantasy Football addon | 📦 |
| nvoos-saas-controller | dashboard/saas-controller.png | SaaS Controller addon | 📦 |

## Chat — Needs AI Provider API Key

| Feature | Screenshot | Status |
|---------|-----------|--------|
| Chat shortcode | chat/frontend-shortcode.png | 🔑 |
| Chat conversation | chat/chat-conversation-example.png | 🔑 |
| Chat attachments | chat/chat-with-attachments.png | 🔑 |
| Chat tool execution | chat/chat-tool-execution.png | 🔑 |
| Chat streaming | chat/chat-streaming-response.png | 🔑 |
| Chat shortcuts | chat/chat-shortcuts-buttons.png | 🔑 |
| Chat error state | chat/chat-error-handling.png | 🔑 |
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

**Status:** 60 captured + 5 need 3rd-party plugins + 3 need addons + 16 need API key + 30 need investigation = 114 total tracked
