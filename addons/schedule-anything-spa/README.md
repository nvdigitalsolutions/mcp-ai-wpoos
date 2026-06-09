# Schedule Anything SPA

React single-page application for the Schedule Anything SaaS platform. Provides tenant admin dashboard, visual schedule builder (React Flow), preset library, run history, analytics, and public booking portal.

## Quick Start

```bash
cd addons/schedule-anything-spa
npm install
npm run dev       # Vite dev server on port 3000
npm run build     # Production build to assets/dist/
npm run typecheck # TypeScript check
npm test          # Vitest
```

## Architecture

```
src/
├── api/              # REST client layer
│   └── client.ts     # @wordpress/api-fetch + nonce auth
├── components/
│   ├── layout/       # AppLayout (sidebar + content)
│   ├── builder/      # React Flow editor
│   │   ├── FlowCanvas.tsx
│   │   ├── ToolNode.tsx
│   │   ├── TriggerNode.tsx
│   │   └── PropertyPanel.tsx
│   └── shared/       # ErrorBoundary, Skeleton
├── contexts/
│   ├── AuthContext.tsx    # WP nonce + user state
│   └── TenantContext.tsx  # Tenant config from subdomain
├── hooks/
│   ├── useSchedules.ts    # Schedule CRUD hooks
│   └── usePresets.ts      # Preset + toolkit hooks
├── pages/
│   ├── DashboardPage.tsx  # Overview + stats
│   ├── SchedulesPage.tsx  # List + toggle + delete
│   ├── BuilderPage.tsx    # Visual workflow editor
│   ├── PresetsPage.tsx    # Preset browser + install
│   ├── HistoryPage.tsx    # Run history
│   ├── AnalyticsPage.tsx  # Usage metrics
│   ├── SettingsPage.tsx   # Toolkit toggles
│   └── BookingPage.tsx    # Public booking portal
└── styles/
    └── global.css         # Tailwind + custom styles
```

## Pages

| Route | Auth | Description |
|---|---|---|
| `/dashboard` | Required | Tenant overview with stats |
| `/schedules` | Required | Schedule CRUD with filter/toggle/delete/trigger |
| `/builder` | Required | Visual React Flow schedule editor |
| `/presets` | Required | Browse + install pre-built presets |
| `/history` | Required | Execution history for selected schedule |
| `/analytics` | Required | Usage metrics + charts |
| `/settings` | Required | Toolkit toggles + AI provider config |
| `/book/:tenant` | None | Public booking portal |
