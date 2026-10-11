# Schedule Anything SPA

React single-page application for the Schedule Anything SaaS platform. Provides tenant admin dashboard, visual schedule builder (React Flow via `@xyflow/react` v12), preset library, run history, analytics, and public booking portal.

**0.2.0 stack:** Tailwind CSS v4 (CSS-first config, NDS design-token aliases),
a shadcn-style in-repo UI kit (`src/components/ui/` — Radix primitives +
CVA + tailwind-merge + sonner), route-level code splitting for the builder
and analytics surfaces, `@wordpress/i18n` across the app chrome, and
jsx-a11y/axe lint gates.

## Quick Start

```bash
cd addons/schedule-anything-spa
npm install
npm run dev       # Vite dev server on port 3000
npm run build     # Production build to assets/dist/
npm run typecheck # TypeScript check
npm test          # Vitest (jsdom + Testing Library)
```

## Testing

Vitest + jsdom + Testing Library (`vitest.config.ts`, `src/test-setup.ts`
with Radix polyfills). Suites live next to their subjects:

- `src/lib/__tests__/` — `cn()` utility, i18n bootstrap
- `src/components/ui/__tests__/` — shadcn-style primitives
- `src/components/shared/__tests__/` — ErrorBoundary
- `src/components/layout/__tests__/` — AppLayout (API client mocked)
- `src/components/builder/__tests__/` — ToolNode / TriggerNode smoke

Note: Radix Select is integration-tested form-free; inside a native
`<form>` its hidden BubbleSelect misbehaves under jsdom (see
`addons/toolkit-shell/src/components/__tests__/FormView.test.tsx` for the
documented stand-in pattern).

## Architecture

```
src/
├── api/              # REST client layer
│   └── client.ts     # @wordpress/api-fetch + nonce auth
├── components/
│   ├── layout/       # AppLayout (sidebar + content)
│   ├── builder/      # @xyflow/react editor
│   │   ├── FlowCanvas.tsx
│   │   ├── ToolNode.tsx
│   │   ├── TriggerNode.tsx
│   │   └── PropertyPanel.tsx
│   ├── ui/           # shadcn-style primitives (Radix + CVA)
│   └── shared/       # ErrorBoundary, Skeleton
├── contexts/
│   ├── AuthContext.tsx    # WP nonce + user state
│   └── TenantContext.tsx  # Tenant config from subdomain
├── hooks/
│   ├── useSchedules.ts    # Schedule CRUD hooks
│   └── usePresets.ts      # Preset + toolkit hooks
├── lib/
│   ├── i18n.ts            # @wordpress/i18n re-exports
│   └── utils.ts           # cn() — clsx + tailwind-merge
├── pages/
│   ├── DashboardPage.tsx  # Overview + stats
│   ├── SchedulesPage.tsx  # List + toggle + delete
│   ├── BuilderPage.tsx    # Visual workflow editor (lazy)
│   ├── PresetsPage.tsx    # Preset browser + install (lazy)
│   ├── HistoryPage.tsx    # Run history (lazy)
│   ├── AnalyticsPage.tsx  # Usage metrics (lazy)
│   ├── SettingsPage.tsx   # Toolkit toggles
│   └── BookingPage.tsx    # Public booking portal
└── styles/
    └── global.css         # Tailwind v4 + NDS token aliases
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
