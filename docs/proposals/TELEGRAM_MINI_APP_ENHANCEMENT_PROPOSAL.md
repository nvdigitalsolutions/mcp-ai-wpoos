# Telegram Mini App Enhancement Proposal

**Date:** March 2, 2026
**Version:** 1.1
**Status:** 🚧 Implementation In Progress
**Priority:** HIGH
**Estimated Effort:** 160–240 hours across 4 phases

---

## Implementation Status (v1.1.3)

### ✅ Completed Features

| Feature | Status | Description |
|---------|--------|-------------|
| **Content Tab Inline Editing** | ✅ Done | Create/edit posts of any CPT directly within the Mini App via an inline editor overlay |
| **Content Visibility Settings** | ✅ Done | Per-CPT enable/disable toggles in Settings → Content Display |
| **Tool Execution** | ✅ Done | Execute any tool from the Mini App with dynamic parameter forms generated from `get_parameters_schema()` |
| **Shop Tab** | ✅ Done | Stars balance display, 4 pricing tiers, purchase flow, recent transaction history |
| **Inline Query Handler** | ✅ Done | `@botname` searches published posts and returns inline article results |
| **Payment Handlers** | ✅ Done | `pre_checkout_query` validation + `successful_payment` balance crediting with history |
| **Deep Linking** | ✅ Done | `/start tool_SLUG`, `/start content_TYPE`, `/start shop`, `/start balance` |
| **New Bot Commands** | ✅ Done | `/tools` (list tools), `/balance` (show credits), `/app` (open Mini App button) |
| **6 Mini App Tabs** | ✅ Done | Home, Content, Tools, Media, Shop, Settings |

### 🔲 Remaining (Future Work)

- Per-toolkit sub-views (drill into toolkit → CPTs → records)
- Telegram Stars invoice creation flow (via bot API `createInvoiceLink`)
- Subscription management
- Third-party payment provider integration
- Full-screen Mini App mode
- Toolkit-specific widgets (e.g. CRM contact cards, WooCommerce order detail)

---

## Executive Summary

This proposal outlines a comprehensive enhancement of the NV oOS Telegram Mini App to fully leverage the Telegram Bot Platform capabilities—including payments (Stars & third-party providers), inline mode, deep linking, subscriptions, and full-screen Mini App features—while surfacing **all 23 Pro plugin toolkits** through a rich, native-feeling mobile interface.

The current Mini App serves as a content/analytics dashboard with five tabs (Home, Content, Tools, Media, Settings). This proposal transforms it into a **complete WordPress command center inside Telegram**, enabling users to manage their entire WordPress site—from e-commerce orders to CRM contacts to document generation—without ever leaving the Telegram app.

**Design Principle:** Telegram *is* the chat interface. The Mini App deliberately does **not** duplicate chat functionality. Instead, it provides the visual, interactive, and transactional features that a chat interface cannot—dashboards, forms, media galleries, payment flows, and rich data management. All conversational AI interactions happen natively in Telegram's chat.

### Expected Impact

- 🎯 **Full Toolkit Access** — All 23 Pro toolkits accessible via Mini App (currently 0 are actionable)
- 💰 **Monetization** — Telegram Stars payments, subscriptions, and third-party payment integration
- 📈 **User Engagement** — 3–5× increase in tool utilization via visual discovery
- 🌍 **Reach** — Tap into Telegram's 1B+ user base with zero app-store friction
- ⚡ **Reduced Support Load** — Visual interfaces replace complex slash-command syntax

---

## Table of Contents

1. [Current State Analysis](#1-current-state-analysis)
2. [Telegram Platform Features Assessment](#2-telegram-platform-features-assessment)
3. [Proposed Architecture](#3-proposed-architecture)
4. [Mini App Tab Enhancements](#4-mini-app-tab-enhancements)
5. [Pro Toolkit Integration Matrix](#5-pro-toolkit-integration-matrix)
6. [Monetization Strategy](#6-monetization-strategy)
7. [Bot Feature Enhancements](#7-bot-feature-enhancements)
8. [New REST API Endpoints](#8-new-rest-api-endpoints)
9. [New Tools](#9-new-tools)
10. [Implementation Roadmap](#10-implementation-roadmap)
11. [Success Metrics](#11-success-metrics)
12. [Risk Analysis](#12-risk-analysis)
13. [Technical Specifications](#13-technical-specifications)

---

## 1. Current State Analysis

### 1.1 What Exists Today

| Component | Status | Files |
|-----------|--------|-------|
| Mini App Controller | ✅ Implemented | `addons/pro/includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php` |
| Webhook Controller | ✅ Implemented | `addons/pro/includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php` |
| Telegram Login | ✅ Implemented | `includes/rest/class-wp-mcp-ai-telegram-login-controller.php` |
| Bot Tools (5) | ✅ Implemented | `addons/pro/includes/src/Tools/ChatChannels/` |
| Elementor Widget | ✅ Implemented | `includes/elementor/class-wp-mcp-ai-elementor-telegram-login-widget.php` |
| Tests | ✅ Implemented | `tests/test-telegram-mini-app-settings.php`, `tests/test-telegram-connection.php` |

### 1.2 Current Mini App Tabs

| Tab | Purpose | Limitation |
|-----|---------|-----------|
| **Home** | Analytics dashboard (Chart.js) | Read-only; 7-day window only |
| **Content** | Browse WordPress CPT posts | Read-only; no create/edit |
| **Tools** | List available tools | Display-only; cannot execute |
| **Media** | Browse media library | Read-only; no upload |
| **Settings** | User preferences | Basic; no payment/subscription settings |

### 1.3 Current Bot Capabilities

| Feature | Status |
|---------|--------|
| Private chat AI replies | ✅ Working |
| Group/supergroup mentions | ✅ Working |
| Channel post processing | ✅ Working |
| Slash commands (/help, /start, /status, /settings, /cancel) | ✅ Working |
| Conversation history (transient-based) | ✅ Working |
| Human takeover/resume keywords | ✅ Working |
| Assistant @slug mentions | ✅ Working |
| Inline mode | ❌ Not implemented |
| Deep linking | ❌ Not implemented |
| Payments (Stars) | ❌ Not implemented |
| Subscriptions | ❌ Not implemented |
| Pre-checkout/shipping queries | ❌ Not implemented |
| Sticker/game integration | ❌ Not implemented |

### 1.4 Gap Summary

1. **No tool execution** from Mini App—tools are listed but not actionable
2. **No payment/monetization** infrastructure
3. **No inline mode** for sharing content across Telegram chats
4. **No deep linking** for referrals, onboarding, or tool shortcuts
5. **No media upload** capability from Mini App
6. **No content creation/editing** from Mini App
7. **No Pro toolkit integration**—none of the 23 toolkits are accessible via Mini App
8. **No subscription management** UI
9. **No offline support** or service worker

---

## 2. Telegram Platform Features Assessment

### 2.1 Features to Integrate

Based on [Telegram Bot API](https://core.telegram.org/bots), [Bot Features](https://core.telegram.org/bots/features), and [Monetization](https://core.telegram.org/bots#monetize-your-service):

| Telegram Feature | Priority | NV oOS Integration |
|-----------------|----------|-------------------|
| **Mini App (Web App)** | 🔴 Critical | Enhanced with toolkit tabs, forms, payment UI |
| **Telegram Stars Payments** | 🔴 Critical | Pay for Pro tools, document generation, AI credits |
| **Third-Party Payments** | 🟡 High | WooCommerce checkout via Telegram Payments API |
| **Subscriptions (Stars)** | 🟡 High | Recurring Pro access, toolkit subscriptions |
| **Inline Mode** | 🟡 High | Share content, tools, and results across chats |
| **Deep Linking** | 🟡 High | Referral codes, tool shortcuts, onboarding |
| **Full-Screen Mode** | 🟢 Medium | Immersive tool UIs (e.g., document editor, media production) |
| **Home Screen Shortcuts** | 🟢 Medium | Quick launch for specific toolkits |
| **DeviceStorage / SecureStorage** | 🟢 Medium | Persist preferences, cache data offline |
| **Push Notifications** | 🟢 Medium | Tool completion alerts, payment confirmations |
| **Media Sharing (sendData)** | 🟢 Medium | Share generated documents/images back to chat |
| **Emoji Status** | 🟠 Low | Show assistant status (busy/available) |
| **Ad Revenue Sharing** | 🟠 Low | Passive income for high-traffic bots |

### 2.2 Why No Chat Tab

Telegram **is** the chat interface. Adding a chat tab inside the Mini App would:
- Duplicate functionality Telegram already provides natively
- Create a worse chat experience than Telegram's own UI
- Add unnecessary complexity and maintenance burden
- Confuse users about where to send messages

Instead, the Mini App focuses on what chat **cannot** do well:
- Visual dashboards and analytics
- Form-based data entry (CRM, orders, bookings)
- Media galleries and file management
- Payment flows and checkout experiences
- Rich data tables and CRUD operations
- Interactive tool configuration UIs

The bot's chat interface and the Mini App are **complementary**: chat handles conversation and quick commands; the Mini App handles visual and transactional workflows.

---

## 3. Proposed Architecture

### 3.1 Enhanced Tab Structure

```
┌──────────────────────────────────────────┐
│  NV oOS Mini App                         │
│  ┌─────────────────────────────────────┐ │
│  │  [Home] [Content] [Toolkits] [Shop] [⚙] │
│  └─────────────────────────────────────┘ │
│                                          │
│  HOME TAB                                │
│  ┌─────────────────────────────────────┐ │
│  │  📊 Dashboard Cards                 │ │
│  │  ├─ AI Usage / Credits Remaining    │ │
│  │  ├─ Recent Activity Feed            │ │
│  │  ├─ Quick Actions (6 favorites)     │ │
│  │  └─ Subscription Status             │ │
│  │                                     │ │
│  │  🔧 Pinned Toolkits (3 favorites)  │ │
│  │  └─ [Content] [CRM] [E-Commerce]   │ │
│  │                                     │ │
│  │  📈 Analytics Summary              │ │
│  │  └─ Token usage, costs, top tools   │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  CONTENT TAB (✅ IMPLEMENTED)            │
│  ┌─────────────────────────────────────┐ │
│  │  📝 CPT Type Bar (scrollable)      │ │
│  │  └─ [Posts] [Pages] [CRM Contacts]  │ │
│  │                              [+ New] │ │
│  │                                     │ │
│  │  📋 Post List (editable)           │ │
│  │  ├─ Title, status, date, excerpt    │ │
│  │  ├─ [✏️ Edit] [Open ›] per card    │ │
│  │  └─ Pagination                      │ │
│  │                                     │ │
│  │  ✏️ Inline Editor (overlay)        │ │
│  │  ├─ Title input                     │ │
│  │  ├─ Content textarea                │ │
│  │  ├─ Status select (Draft/Pub/Pend)  │ │
│  │  └─ [Cancel] [Save]                │ │
│  │                                     │ │
│  │  ⚙️ Visibility controlled from     │ │
│  │  Settings > Content Display toggles │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  TOOLKITS TAB                            │
│  ┌─────────────────────────────────────┐ │
│  │  🔍 Search toolkits...             │ │
│  │                                     │ │
│  │  📂 Toolkit Categories             │ │
│  │  ├─ 📝 Content & Publishing        │ │
│  │  ├─ 🛒 E-Commerce                  │ │
│  │  ├─ 👥 CRM & Email Marketing       │ │
│  │  ├─ 📊 Analytics                   │ │
│  │  ├─ 🖼️ Media Production           │ │
│  │  ├─ 🎬 Video Production            │ │
│  │  ├─ 📄 Document Generation         │ │
│  │  ├─ 🌐 Social Media Management     │ │
│  │  ├─ 🏗️ Site Creator               │ │
│  │  ├─ 💰 Financial Planner           │ │
│  │  ├─ 📅 Calendar & Booking          │ │
│  │  ├─ 💬 Chat Channels               │ │
│  │  ├─ 🌍 Multilingual                │ │
│  │  ├─ 🏛️ Architectural Design       │ │
│  │  ├─ 🏈 Fantasy Football            │ │
│  │  ├─ 🎵 DJ Management               │ │
│  │  ├─ 🏥 Health & Wellness           │ │
│  │  ├─ 📋 Project Management          │ │
│  │  ├─ 🔐 Password Vault              │ │
│  │  ├─ 📜 Regulatory Registration     │ │
│  │  ├─ ⚡ ECA Automation              │ │
│  │  ├─ 🤖 Architect Agent             │ │
│  │  └─ 🛠️ AI Tool Builder            │ │
│  │                                     │ │
│  │  → Tap to open toolkit sub-view    │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  SHOP TAB                                │
│  ┌─────────────────────────────────────┐ │
│  │  ⭐ AI Credits Balance: 1,250      │ │
│  │  [Buy Credits]  [Manage Sub]        │ │
│  │                                     │ │
│  │  📦 WooCommerce Products           │ │
│  │  ├─ Featured products grid          │ │
│  │  ├─ Cart & checkout                 │ │
│  │  └─ Order history                   │ │
│  │                                     │ │
│  │  ⭐ Premium Toolkits               │ │
│  │  ├─ Per-toolkit pricing             │ │
│  │  └─ Bundle deals                    │ │
│  │                                     │ │
│  │  🔄 Active Subscriptions           │ │
│  │  └─ Status, renewal, cancel         │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  SETTINGS TAB                            │
│  ┌─────────────────────────────────────┐ │
│  │  👤 Account                         │ │
│  │  ├─ WordPress link status           │ │
│  │  ├─ Link / Unlink account           │ │
│  │  └─ Telegram user info              │ │
│  │                                     │ │
│  │  🎨 Preferences                    │ │
│  │  ├─ Language                        │ │
│  │  ├─ Notifications toggle            │ │
│  │  ├─ Compact mode                    │ │
│  │  └─ Default assistant               │ │
│  │                                     │ │
│  │  📄 Content Display (✅ IMPL)      │ │
│  │  ├─ Per-CPT on/off toggles          │ │
│  │  ├─ Shows toolkit label per CPT     │ │
│  │  └─ Filters Content tab CPT bar     │ │
│  │                                     │ │
│  │  🤖 Bot Configuration              │ │
│  │  ├─ Group/channel settings          │ │
│  │  ├─ Auto-reply toggle               │ │
│  │  └─ Human takeover keywords         │ │
│  │                                     │ │
│  │  📊 Usage & Billing                │ │
│  │  └─ Stars balance, API costs        │ │
│  └─────────────────────────────────────┘ │
│                                          │
│  ┌─────────────────────────────────────┐ │
│  │  [🏠] [📝] [🧰] [🛒] [⚙️]         │ │
│  └─────────────────────────────────────┘ │
└──────────────────────────────────────────┘
```

### 3.2 Communication Flow

```
User in Telegram Chat                Mini App (Web View)
       │                                    │
       │  "Generate a sales report"         │
       ├──────► Bot Webhook ────►           │
       │        AI processes                │
       │        ◄── Reply with text ────    │
       │                                    │
       │  Opens Mini App                    │
       │  ──────────────────────►           │
       │                                    ├── REST: /analytics
       │                                    ├── REST: /toolkits/document-generation/execute
       │                                    ├── REST: /shop/checkout (Stars)
       │                                    │
       │  ◄── sendData (file share) ────    │
       │  ◄── switchInlineQuery ────────    │
       │                                    │
       │  Deep Link: t.me/bot?start=tool_X  │
       ├──────► Opens specific toolkit ──►  │
       │                                    │
       │  Inline: @bot search query         │
       ├──────► Inline results ────►        │
       │  ◄── Selected result ──────        │
```

### 3.3 Authentication Enhancement

```
Current:  initData → validate → cookie + TMA token → REST calls
Enhanced: initData → validate → cookie + TMA token → REST calls
                                                   → Stars payment session
                                                   → DeviceStorage persistence
                                                   → SecureStorage for credentials
```

---

## 4. Mini App Tab Enhancements

### 4.1 Home Tab — Smart Dashboard

**Current:** Static analytics with Chart.js (token usage, cost breakdown, top tools).

**Enhanced:**

| Feature | Description | Priority |
|---------|-------------|----------|
| **Quick Actions Grid** | 6 configurable shortcut buttons (e.g., "New Post", "View Orders", "Generate Doc") | 🔴 Critical |
| **AI Credits Widget** | Stars balance, API cost summary, buy-credits CTA | 🔴 Critical |
| **Subscription Status Card** | Active plan, renewal date, upgrade CTA | 🟡 High |
| **Recent Activity Feed** | Last 10 actions across all toolkits | 🟡 High |
| **Pinned Toolkits** | User-configurable 3 favorite toolkit shortcuts | 🟡 High |
| **Extended Date Range** | 7/30/90-day analytics with date picker | 🟢 Medium |
| **Notification Center** | Unread alerts, tool completion notices | 🟢 Medium |

### 4.2 Toolkits Tab — Unified Toolkit Browser

**Current:** Flat list of tool names and descriptions (display only).

**Enhanced:** Hierarchical toolkit browser with execution capability.

#### Toolkit Sub-View Architecture

Each toolkit opens a **sub-view** with consistent structure:

```
┌──────────────────────────────────────┐
│  ← Back    📝 Content & Publishing   │
│  ─────────────────────────────────── │
│                                      │
│  📊 Toolkit Dashboard               │
│  ├─ Posts: 142  |  Pages: 28        │
│  └─ Drafts: 7   |  Scheduled: 3    │
│                                      │
│  🔧 Available Tools                 │
│  ┌────────────────────────────────┐  │
│  │ 📝 Create Post                 │  │
│  │ Create WordPress posts and...  │  │
│  │ [Execute]                      │  │
│  ├────────────────────────────────┤  │
│  │ 🔍 Search Content              │  │
│  │ Find posts by keyword, date... │  │
│  │ [Execute]                      │  │
│  ├────────────────────────────────┤  │
│  │ 📋 Manage Categories           │  │
│  │ Create and organize post...    │  │
│  │ [Execute]                      │  │
│  └────────────────────────────────┘  │
│                                      │
│  📁 Recent Items                    │
│  └─ [Draft: Q1 Report] [Pub: ...]   │
└──────────────────────────────────────┘
```

#### Tool Execution Flow

```
User taps [Execute] on a tool
        │
        ▼
┌──────────────────────────────────────┐
│  ← Back    📝 Create Post            │
│  ─────────────────────────────────── │
│                                      │
│  Title *                             │
│  ┌────────────────────────────────┐  │
│  │ My New Blog Post               │  │
│  └────────────────────────────────┘  │
│                                      │
│  Content                             │
│  ┌────────────────────────────────┐  │
│  │ Write your post content...     │  │
│  │                                │  │
│  └────────────────────────────────┘  │
│                                      │
│  Status: [Draft ▾]                   │
│  Category: [Select... ▾]            │
│                                      │
│  ┌────────────────────────────────┐  │
│  │      ✨ Execute with AI        │  │
│  └────────────────────────────────┘  │
│  Uses MainButton for primary action  │
└──────────────────────────────────────┘
```

The form fields are **auto-generated from the tool's `get_parameters_schema()`**, rendering appropriate input types:
- `string` → text input
- `string` with `enum` → select dropdown
- `integer` → number input
- `boolean` → toggle switch
- `array` → multi-select or tag input
- `object` → nested fieldset

### 4.3 Shop Tab — Monetization Hub

**New tab** integrating Telegram Stars and WooCommerce.

| Feature | Telegram API | Description |
|---------|-------------|-------------|
| **Buy AI Credits** | `createInvoiceLink` (Stars) | Purchase token bundles via Stars |
| **Toolkit Subscriptions** | Stars Subscriptions | Monthly access to premium toolkits |
| **WooCommerce Products** | Third-party Payments API | Browse and buy products via Stripe/Apple Pay/Google Pay |
| **Order Management** | — | View order history and status |
| **Gift Credits** | `createInvoiceLink` + inline | Buy and send credits to other users |

#### Payment Flows

**Stars Payment (Digital Goods):**
```
User taps "Buy 1000 Credits" (500 ⭐)
    │
    ▼
Mini App calls REST: POST /shop/create-invoice
    │
    ▼
Server calls Telegram Bot API: createInvoiceLink()
    │
    ▼
Returns invoice link → WebApp.openInvoice(url)
    │
    ▼
Telegram native payment sheet appears
    │
    ▼
User confirms → Telegram sends pre_checkout_query to webhook
    │
    ▼
Server validates → answerPreCheckoutQuery(ok=true)
    │
    ▼
Payment completes → successful_payment message received
    │
    ▼
Server credits user account → sends confirmation
```

**Third-Party Payment (Physical Goods / WooCommerce):**
```
User browses WooCommerce products in Shop tab
    │
    ▼
Taps "Buy" → Mini App calls REST: POST /shop/woo-invoice
    │
    ▼
Server creates Telegram invoice with Stripe provider token
    │
    ▼
WebApp.openInvoice(url) → Stripe payment sheet
    │
    ▼
shipping_query → server calculates shipping
    │
    ▼
pre_checkout_query → server validates stock
    │
    ▼
successful_payment → server creates WooCommerce order
```

### 4.4 Settings Tab — Enhanced Configuration

**Current:** Basic preferences (language, notifications, compact mode) and account linking.

**Enhanced:**

| Section | New Features |
|---------|-------------|
| **Account** | Full link/unlink flow with visual verification, Telegram user card |
| **Preferences** | Default assistant picker, pinned toolkits, quick action configuration |
| **Bot Configuration** | Group/channel toggles, auto-reply settings, human takeover keyword editor |
| **Billing & Usage** | Stars balance, API cost breakdown by toolkit, subscription management |
| **Notifications** | Per-toolkit notification toggles, quiet hours |
| **Data & Privacy** | Export data, clear history, GDPR controls |

---

## 5. Pro Toolkit Integration Matrix

How each of the 23 Pro toolkits maps to Mini App features:

| # | Toolkit | Mini App Integration | Tab | Priority |
|---|---------|---------------------|-----|----------|
| 1 | **Content & Publishing** | Create/edit/schedule posts, manage categories/tags | Toolkits | 🔴 Critical |
| 2 | **E-Commerce** | WooCommerce product browser, order management, Telegram Payments checkout | Shop + Toolkits | 🔴 Critical |
| 3 | **CRM & Email Marketing** | Contact cards, lead pipeline view, quick email compose | Toolkits | 🔴 Critical |
| 4 | **Analytics** | Enhanced dashboard widgets, custom date ranges, export to chat | Home + Toolkits | 🔴 Critical |
| 5 | **Document Generation** | PDF/Word/Excel preview and generation, share via sendData | Toolkits | 🟡 High |
| 6 | **Media Toolkit** | Upload from camera/gallery, template browser, collection management | Toolkits | 🟡 High |
| 7 | **Image Production** | AI image generation with preview, prompt builder, style gallery | Toolkits | 🟡 High |
| 8 | **Social Media Management** | Cross-post scheduler, engagement dashboard, platform connect | Toolkits | 🟡 High |
| 9 | **Chat Channels** | Unified inbox view, channel stats, broadcast composer | Toolkits | 🟡 High |
| 10 | **Site Creator** | Page builder lite, template gallery, section picker | Toolkits | 🟡 High |
| 11 | **Financial Planner** | Account overview, budget tracker, goal progress | Toolkits | 🟢 Medium |
| 12 | **Calendar & Booking** | Calendar view, appointment list, availability editor | Toolkits | 🟢 Medium |
| 13 | **Video Production** | Video library, generation queue, preview player | Toolkits | 🟢 Medium |
| 14 | **Multilingual** | Translation status matrix, quick-translate action | Toolkits | 🟢 Medium |
| 15 | **Project Management** | Task board (kanban), project overview, quick task creation | Toolkits | 🟢 Medium |
| 16 | **Architect Agent** | File browser, shell output viewer, git status | Toolkits | 🟢 Medium |
| 17 | **Architectural Design** | Floor plan gallery, project status, cost estimates | Toolkits | 🟠 Low |
| 18 | **Health & Wellness** | Health metrics dashboard, daily log, goal tracking | Toolkits | 🟠 Low |
| 19 | **Password Vault** | Credential list (read-only, SecureStorage), copy-to-clipboard | Toolkits | 🟠 Low |
| 20 | **Regulatory Registration** | Registration status tracker, document checklist | Toolkits | 🟠 Low |
| 21 | **ECA Automation** | Rule list, enable/disable toggles, execution log | Toolkits | 🟠 Low |
| 22 | **Fantasy Football** | Team roster, matchup view, player search | Toolkits | 🟠 Low |
| 23 | **DJ Management** | Equipment list, playlist browser, event calendar | Toolkits | 🟠 Low |
| — | **AI Tool Builder** | Tool scaffolding wizard, schema editor, test runner | Toolkits | 🟠 Low |

### 5.1 Toolkit-Specific Inline Mode Integration

Each toolkit can contribute **inline query results** for cross-chat sharing:

```
@your_bot latest posts       → Returns recent WordPress posts as inline articles
@your_bot product shoes      → Returns WooCommerce products with Buy buttons
@your_bot contact John       → Returns CRM contact card
@your_bot invoice 500 stars  → Creates shareable payment invoice
@your_bot document Q1 report → Returns generated document link
@your_bot image sunset       → Returns AI-generated image
```

---

## 6. Monetization Strategy

### 6.1 Revenue Streams

| Stream | Telegram Feature | Implementation |
|--------|-----------------|----------------|
| **AI Credit Packs** | Stars Payments | Users buy token bundles (e.g., 500⭐ = 10,000 tokens) |
| **Toolkit Subscriptions** | Stars Subscriptions | Monthly per-toolkit access (e.g., 200⭐/month for CRM) |
| **Pro Bundle** | Stars Subscriptions | All toolkits access (e.g., 800⭐/month) |
| **WooCommerce Sales** | Third-Party Payments | Physical/digital product checkout via Stripe |
| **Document Generation** | Stars Payments | Per-document fee for PDF/Word generation |
| **AI Image Generation** | Stars Payments | Per-image fee for DALL-E/Midjourney generation |
| **Referral Program** | Deep Linking | Earn credits for referred users who purchase |
| **Ad Revenue Share** | Telegram Ads API | Passive income from high-traffic bot channels |

### 6.2 Pricing Model (Stars)

| Product | Stars Price | Approximate USD |
|---------|-------------|-----------------|
| 1,000 AI Tokens | 100 ⭐ | ~$1.50 |
| 10,000 AI Tokens | 500 ⭐ | ~$7.50 |
| 100,000 AI Tokens | 2,500 ⭐ | ~$37.50 |
| Single Toolkit (monthly) | 200 ⭐ | ~$3.00 |
| Pro Bundle (monthly) | 800 ⭐ | ~$12.00 |
| Document Generation (per doc) | 50 ⭐ | ~$0.75 |
| AI Image (per image) | 25 ⭐ | ~$0.38 |

### 6.3 Subscription Management

WordPress stores subscription state via user meta:
- `_wp_mcp_ai_tg_stars_balance` — Current Stars credit balance
- `_wp_mcp_ai_tg_subscription_plan` — Active plan slug
- `_wp_mcp_ai_tg_subscription_expires` — Expiry timestamp
- `_wp_mcp_ai_tg_payment_history` — Array of completed payments

---

## 7. Bot Feature Enhancements

### 7.1 Inline Mode

**New capability:** Users type `@bot_username query` in any Telegram chat to get instant results.

**Implementation:**
- New webhook handler for `inline_query` update type
- Query routed to relevant toolkit based on keyword prefix or AI classification
- Results returned as `InlineQueryResult` objects (articles, photos, documents, etc.)
- Each result can include an invoice button for Stars payment

**New file:** `addons/pro/includes/rest/` — extend `WP_MCP_AI_Telegram_Webhook_Controller` with `process_inline_query()` method.

### 7.2 Deep Linking

**New capability:** Parameterized URLs that open the bot or Mini App with context.

**URL patterns:**
```
t.me/bot_name?start=toolkit_crm         → Opens Mini App to CRM toolkit
t.me/bot_name?start=tool_create_post    → Opens Mini App to Create Post form
t.me/bot_name?start=shop_product_123    → Opens Mini App to product detail
t.me/bot_name?start=ref_USER123         → Referral tracking
t.me/bot_name?start=invoice_INV456      → Opens specific invoice
t.me/bot_name?startapp=toolkit_crm      → Opens Mini App directly to CRM
```

**Implementation:**
- Parse `start` parameter in existing `/start` command handler
- Route to Mini App with appropriate query parameters
- Track referral codes for affiliate program

### 7.3 New Bot Commands

| Command | Description | Implementation |
|---------|-------------|----------------|
| `/tools` | List available toolkits | Returns toolkit list with Mini App deep links |
| `/buy` | Purchase AI credits | Creates Stars invoice link |
| `/balance` | Check credit balance | Returns current Stars/token balance |
| `/subscribe` | Manage subscription | Returns subscription options with payment links |
| `/share` | Share content inline | Triggers inline mode switch |
| `/quick <tool> <args>` | Execute tool from chat | Runs tool and returns result |
| `/history` | Recent tool executions | Returns last 10 actions |
| `/export <format>` | Export data | Generates and sends document file |

### 7.4 Pre-Checkout & Payment Webhooks

**New webhook handlers:**

```php
// In WP_MCP_AI_Telegram_Webhook_Controller:
process_pre_checkout_query( $update )    // Validate payment before charging
process_successful_payment( $message )   // Credit user account after payment
process_shipping_query( $update )        // Calculate shipping for physical goods
```

### 7.5 Callback Query Enhancements

Extend existing callback handling for:
- Inline keyboard actions (approve/reject orders, toggle settings)
- Payment confirmation flows
- Toolkit navigation from chat messages
- Quick tool execution confirmations

---

## 8. New REST API Endpoints

### 8.1 Toolkit Execution

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/telegram-mini-app/toolkits` | GET | List all available toolkits with tool counts |
| `/telegram-mini-app/toolkits/{slug}` | GET | Get toolkit detail with tools list |
| `/telegram-mini-app/toolkits/{slug}/execute` | POST | Execute a tool within a toolkit |
| `/telegram-mini-app/toolkits/{slug}/dashboard` | GET | Get toolkit-specific dashboard data |

### 8.2 Shop & Payments

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/telegram-mini-app/shop/products` | GET | List purchasable products (WooCommerce + credit packs) |
| `/telegram-mini-app/shop/create-invoice` | POST | Create Telegram Stars invoice link |
| `/telegram-mini-app/shop/create-woo-invoice` | POST | Create third-party payment invoice for WooCommerce product |
| `/telegram-mini-app/shop/subscriptions` | GET | List available subscription plans |
| `/telegram-mini-app/shop/subscribe` | POST | Create Stars subscription |
| `/telegram-mini-app/shop/balance` | GET | Get user's credit/Stars balance |
| `/telegram-mini-app/shop/history` | GET | Get payment/purchase history |

### 8.3 Enhanced Content

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/telegram-mini-app/content` | POST | Create new content (post, page, CPT) |
| `/telegram-mini-app/content/{id}` | PUT | Update existing content |
| `/telegram-mini-app/media/upload` | POST | Upload media from Mini App |

### 8.4 Inline Mode

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/telegram-mini-app/inline/search` | GET | Search across toolkits for inline results |

---

## 9. New Tools

### 9.1 Payment Tools

| Tool Slug | Description | Capability |
|-----------|-------------|------------|
| `create_telegram_invoice` | Create Stars or third-party payment invoice | pro, write, external-api |
| `manage_telegram_subscription` | Create/cancel/check subscription status | pro, write, external-api |
| `check_telegram_payment` | Verify payment status and user balance | pro, read, external-api |
| `refund_telegram_payment` | Process Stars refund via `refundStarPayment` | pro, write, external-api |

### 9.2 Inline Mode Tools

| Tool Slug | Description | Capability |
|-----------|-------------|------------|
| `answer_telegram_inline_query` | Respond to inline queries with results | pro, write, external-api |
| `create_telegram_inline_result` | Build inline result objects for sharing | pro, write |

### 9.3 Enhanced Bot Tools

| Tool Slug | Description | Capability |
|-----------|-------------|------------|
| `manage_telegram_menu_button` | Set/get bot menu button configuration | pro, write, external-api |
| `send_telegram_media_group` | Send multiple photos/videos as an album | pro, write, external-api |
| `manage_telegram_chat` | Pin messages, set chat title/description/photo | pro, write, external-api |
| `create_telegram_deep_link` | Generate parameterized deep links | pro, write |

---

## 10. Implementation Roadmap

### Phase 1: Foundation (Weeks 1–4) — 60 hours

| Task | Hours | Priority |
|------|-------|----------|
| Add toolkit listing REST endpoints (`/toolkits`, `/toolkits/{slug}`) | 8 | 🔴 |
| Build Toolkits tab UI with category browser and sub-views | 16 | 🔴 |
| Implement dynamic form generation from `get_parameters_schema()` | 12 | 🔴 |
| Add tool execution endpoint (`/toolkits/{slug}/execute`) | 8 | 🔴 |
| Enhance Home tab with Quick Actions grid and pinned toolkits | 8 | 🔴 |
| Deep linking support (`?start=` and `?startapp=` routing) | 4 | 🟡 |
| Unit tests for new endpoints and form generation | 4 | 🔴 |

**Deliverable:** Users can browse all 23 toolkits and execute tools via forms in the Mini App.

### Phase 2: Monetization (Weeks 5–8) — 60 hours

| Task | Hours | Priority |
|------|-------|----------|
| Implement Stars invoice creation (`createInvoiceLink`) | 8 | 🔴 |
| Build Shop tab UI (credit packs, subscriptions, products) | 12 | 🔴 |
| Add `pre_checkout_query` and `successful_payment` webhook handlers | 8 | 🔴 |
| User credit/subscription storage and validation | 8 | 🔴 |
| WooCommerce third-party payment integration | 8 | 🟡 |
| Subscription management (create, cancel, renew) | 8 | 🟡 |
| Payment tools (`create_telegram_invoice`, `manage_telegram_subscription`, etc.) | 4 | 🟡 |
| Unit tests for payment flows | 4 | 🔴 |

**Deliverable:** Users can purchase AI credits and toolkit subscriptions via Telegram Stars; WooCommerce products available for checkout.

### Phase 3: Inline Mode & Sharing (Weeks 9–11) — 40 hours

| Task | Hours | Priority |
|------|-------|----------|
| Implement `inline_query` webhook handler | 8 | 🟡 |
| Build toolkit-specific inline result generators | 12 | 🟡 |
| Add `sendData` integration for sharing generated files to chat | 6 | 🟡 |
| New bot commands (`/tools`, `/buy`, `/balance`, `/subscribe`, `/quick`) | 8 | 🟡 |
| Inline mode tools (`answer_telegram_inline_query`, etc.) | 4 | 🟡 |
| Unit tests for inline mode and new commands | 2 | 🟡 |

**Deliverable:** Users can query content, products, and tools inline across any Telegram chat; generated documents/images shareable back to chat.

### Phase 4: Polish & Advanced Features (Weeks 12–14) — 40 hours

| Task | Hours | Priority |
|------|-------|----------|
| Full-screen mode for immersive tool UIs (document editor, media) | 6 | 🟢 |
| DeviceStorage/SecureStorage for offline preferences and credentials | 6 | 🟢 |
| Home screen shortcut support via `addToHomeScreen` | 4 | 🟢 |
| Referral program via deep linking | 6 | 🟢 |
| Enhanced bot commands with callback keyboard navigation | 6 | 🟢 |
| Media upload from Mini App (camera/gallery) | 6 | 🟢 |
| Comprehensive integration testing | 4 | 🟢 |
| Documentation and setup guide | 2 | 🟢 |

**Deliverable:** Full-featured Telegram Mini App with offline support, home screen shortcuts, referral system, and media uploads.

---

## 11. Success Metrics

### 11.1 Quantitative

| Metric | Baseline | 3 Months | 6 Months | Target |
|--------|----------|----------|----------|--------|
| **Toolkits Accessible via Mini App** | 0 / 23 | 15 / 23 | 23 / 23 | 100% |
| **Tool Execution from Mini App** | 0 / day | 50 / day | 200 / day | 200+ |
| **Stars Revenue** | $0 | $500 / mo | $2,000 / mo | Growing |
| **Mini App MAU** | Baseline | +100% | +300% | 3× growth |
| **Inline Query Usage** | 0 | 100 / day | 500 / day | Growing |
| **Avg Session Duration** | 30s | 2 min | 5 min | 5+ min |
| **Support Tickets (Telegram)** | Baseline | -30% | -50% | Reduced |

### 11.2 Qualitative

- ✅ Users can manage their WordPress site entirely from Telegram
- ✅ Payment flows feel native and frictionless
- ✅ Tool execution via forms is faster than slash commands for complex tools
- ✅ Inline sharing drives organic user acquisition
- ✅ Deep links enable contextual onboarding

---

## 12. Risk Analysis

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| **Telegram Stars withdrawal rates** fluctuate | Revenue uncertainty | Medium | Diversify with third-party payments; build credit buffer |
| **Mini App WebView limitations** on older devices | Poor UX for subset of users | Low | Progressive enhancement; test on Telegram Desktop/iOS/Android |
| **Telegram API rate limits** on high-traffic bots | Throttled responses | Medium | Implement request queuing; use webhook (not polling) |
| **Payment disputes/refunds** via Stars | Revenue loss | Low | Clear purchase descriptions; automated refund for failed tool executions |
| **Complex form rendering** from tool schemas | Broken UIs for complex tools | Medium | Schema-to-form mapping with fallback to chat-based execution |
| **SecureStorage API** not available on all platforms | Credential storage gap | Low | Fallback to server-side session; graceful degradation |
| **Inline mode abuse** (spam/scraping) | Bot reputation damage | Low | Per-user rate limiting; cache results; report abuse |

---

## 13. Technical Specifications

### 13.1 Mini App SDK Integration

```javascript
// Required Telegram WebApp SDK methods to integrate:
Telegram.WebApp.openInvoice(url, callback)        // Stars & third-party payments
Telegram.WebApp.switchInlineQuery(query, choose)   // Trigger inline mode from Mini App
Telegram.WebApp.sendData(data)                     // Send data back to bot chat
Telegram.WebApp.requestFullscreen()                // Immersive tool UIs
Telegram.WebApp.addToHomeScreen()                  // Home screen shortcut
Telegram.WebApp.DeviceStorage.setItem(key, value)  // Persistent local storage
Telegram.WebApp.SecureStorage.setItem(key, value)  // Encrypted credential storage
Telegram.WebApp.HapticFeedback.impactOccurred()    // Already integrated
Telegram.WebApp.BackButton.show()                  // Already integrated
Telegram.WebApp.MainButton.setText()               // Already integrated
Telegram.WebApp.CloudStorage.setItem(key, value)   // Cross-device settings sync
```

### 13.2 Webhook Update Types to Handle

```php
// New update types in WP_MCP_AI_Telegram_Webhook_Controller:
'inline_query'        => 'process_inline_query',        // Inline mode
'chosen_inline_result'=> 'process_chosen_inline_result', // Analytics tracking
'pre_checkout_query'  => 'process_pre_checkout_query',   // Payment validation
'shipping_query'      => 'process_shipping_query',       // Shipping calculation
// Existing (already handled):
'message'             => 'process_message',
'channel_post'        => 'process_channel_post',
'my_chat_member'      => 'process_membership_update',
```

### 13.3 Database Schema Additions

```php
// User meta keys (new):
'_wp_mcp_ai_tg_stars_balance'          // int: Current credits balance
'_wp_mcp_ai_tg_subscription_plan'      // string: Active plan slug
'_wp_mcp_ai_tg_subscription_expires'   // int: Unix timestamp
'_wp_mcp_ai_tg_payment_history'        // array: Payment records
'_wp_mcp_ai_tg_referral_code'          // string: Unique referral identifier
'_wp_mcp_ai_tg_referred_by'            // int: Referrer user ID
'_wp_mcp_ai_tg_pinned_toolkits'        // array: Up to 3 toolkit slugs
'_wp_mcp_ai_tg_quick_actions'          // array: Up to 6 action shortcuts

// Options (new):
'wp_mcp_ai_tg_stars_pricing'           // array: Credit pack and subscription pricing
'wp_mcp_ai_tg_inline_mode_enabled'     // bool: Enable/disable inline mode
'wp_mcp_ai_tg_payment_provider_token'  // string: Stripe/payment provider token
'wp_mcp_ai_tg_referral_bonus'          // int: Credits awarded per referral
```

### 13.4 Filter and Action Hooks

```php
// Filters (new):
'wp_mcp_ai_tg_mini_app_tabs'                // Customize available tabs
'wp_mcp_ai_tg_toolkit_visibility'            // Control which toolkits appear
'wp_mcp_ai_tg_inline_results'               // Modify inline query results
'wp_mcp_ai_tg_payment_amount'               // Adjust pricing dynamically
'wp_mcp_ai_tg_tool_form_fields'             // Customize auto-generated form fields
'wp_mcp_ai_tg_deep_link_params'             // Process custom deep link parameters

// Actions (new):
'wp_mcp_ai_tg_payment_completed'            // After successful Stars/third-party payment
'wp_mcp_ai_tg_subscription_activated'       // When subscription starts
'wp_mcp_ai_tg_subscription_cancelled'       // When subscription ends
'wp_mcp_ai_tg_tool_executed_via_mini_app'   // When tool run from Mini App
'wp_mcp_ai_tg_inline_result_chosen'         // When inline result selected
'wp_mcp_ai_tg_referral_credited'            // When referral bonus awarded
```

### 13.5 Settings Schema

```php
// New settings under wp_mcp_ai_settings['telegram']:
array(
    'stars_enabled'          => true,           // Enable Stars payments
    'stars_pricing'          => array(          // Credit pack configuration
        array( 'credits' => 1000,  'stars' => 100 ),
        array( 'credits' => 10000, 'stars' => 500 ),
        array( 'credits' => 100000,'stars' => 2500 ),
    ),
    'subscriptions_enabled'  => true,           // Enable subscription plans
    'subscription_plans'     => array(          // Plan configuration
        array( 'slug' => 'single_toolkit', 'stars' => 200, 'period' => 'monthly' ),
        array( 'slug' => 'pro_bundle',     'stars' => 800, 'period' => 'monthly' ),
    ),
    'inline_mode_enabled'    => true,           // Enable inline queries
    'inline_cache_time'      => 300,            // Inline result cache (seconds)
    'deep_linking_enabled'   => true,           // Enable deep link routing
    'referral_enabled'       => false,          // Enable referral program
    'referral_bonus_credits' => 500,            // Credits per referral
    'payment_provider_token' => '',             // Third-party payment provider
    'fullscreen_toolkits'    => array(          // Toolkits that open in full-screen
        'document_generation',
        'image_production',
        'video_production',
        'architectural_design',
    ),
);
```

---

## Related Documents

- [Chat Channels Toolkit Documentation](../../addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md)
- [Toolkit Enhancement Proposal](TOOLKIT_ENHANCEMENT_PROPOSAL.md)
- [Pro Plugin Enhancement Checklist](PRO_PLUGIN_ENHANCEMENT_CHECKLIST.md)
- [WordPress Integration Enhancement Proposal](WORDPRESS_INTEGRATION_ENHANCEMENT_PROPOSAL.md)

## External References

- [Telegram Bot API](https://core.telegram.org/bots/api)
- [Telegram Bot Features](https://core.telegram.org/bots/features)
- [Telegram Bot Monetization](https://core.telegram.org/bots#monetize-your-service)
- [Telegram Mini Apps (Web Apps)](https://core.telegram.org/bots/webapps)
- [Telegram Payments API (Stars)](https://core.telegram.org/bots/payments-stars)
- [Telegram Payments API (Third-Party)](https://core.telegram.org/bots/payments)
- [Telegram Inline Mode](https://core.telegram.org/bots/inline)
- [Telegram Deep Linking](https://core.telegram.org/api/links#bot-links)

---

**Document Version:** 1.0
**Last Updated:** March 2, 2026
**Author:** NV oOS Development Team
**Next Review:** March 16, 2026
