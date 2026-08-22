---
type: Skill
name: design-campaign-orchestration
description: Produces complete monthly marketing campaign plans — themes, product selection, weekly content structures, recurring posts, promotions, and presentation decks. Use when the task asks to "plan this month's content", "orchestrate a campaign", "build a marketing plan", "select products for promotion", "create a campaign calendar", or "monthly SOP". Covers the full 7-step campaign lifecycle from theme selection to presentation delivery.
license: Proprietary. See LICENSE.txt
metadata:
  type: Skill
---

# Campaign Orchestration

Use this skill when planning monthly marketing campaigns, structuring content calendars, selecting products for promotion, or preparing campaign presentations.

## Available Tools

| Tool | Purpose |
|---|---|
| `remote_wp_connection` | Pull live WooCommerce product stock, orders, and site content for campaign planning |
| `schedule_social_post` | Schedule campaign posts to social platforms |
| `generate_gemini_image_validated` | Create campaign visuals, reel covers, and promo graphics |
| `paper_store_write` / `paper_store_read` | Persist and retrieve campaign plans, product selections, and tracking sheets |
| `create_post` | Publish campaign landing pages and announcements to WordPress |
| `web_search_validated` | Research seasonal trends, competitor campaigns, and industry hooks |
| `toolkit_cpt` | Access pro toolkit CPTs — `mcp_ai_event` (campaign events), `mcp_ai_project` (campaign projects), `mcp_ai_task` (campaign tasks) |
| `create_chart_validated` | Generate campaign performance charts for presentation decks |

## WP-CLI Commands

Key `mcp-ai` WP-CLI commands for campaign workflows. Run via `terminal`:

| Command | Use for |
|---|---|
| `wp mcp-ai bulk` | Batch operations for campaign asset creation |
| `wp mcp-ai cron` | Verify campaign schedules are registered |
| `wp mcp-ai content` | Check existing content before planning new campaigns |

## When to use this skill

Trigger when ANY of the following is true:

- Planning a monthly or seasonal marketing campaign.
- The task asks to "plan this month's content", "build a campaign", or "orchestrate marketing".
- Selecting products to feature based on stock, season, and rotation rules.
- Structuring weekly content (reels, static posts, stories).
- Setting up recurring posts (delivery notices, gift wrapping, vouchers).
- Preparing a campaign presentation for approval.
- The task mentions "monthly SOP", "campaign planning", or "content strategy".
- Integrating product launches, slow movers, or seasonal items into a plan.

## The Monthly Campaign Cycle

```
┌─────────────────────────────────────────────────────────────────┐
│                     MONTHLY CAMPAIGN CYCLE                       │
├───────────┬───────────┬───────────┬───────────┬─────────────────┤
│  Week 1   │  Week 2   │  Week 3   │  Week 4   │  Week 5 (bonus) │
│  PLAN     │  EXECUTE  │  AMPLIFY  │  CONVERT  │  REVIEW         │
├───────────┼───────────┼───────────┼───────────┼─────────────────┤
│ ● Theme   │ ● Reel 1  │ ● Reel 2  │ ● Reel 3  │ ● Analytics     │
│ ● Products│ ● Posts   │ ● Posts   │ ● Posts   │ ● Next month    │
│ ● Visuals │ ● Stories │ ● Stories │ ● Promos  │   prep          │
│ ● Schedule│           │ ● Engage  │ ● Urgency │                 │
└───────────┴───────────┴───────────┴───────────┴─────────────────┘
```

### Step 1 — Recurring Posts (Every Month, Non-Negotiable)

These three posts go out every single month regardless of theme:

| Recurring Post | Platforms | Frequency | Content |
|---|---|---|---|
| **Islandwide Delivery** | Instagram, Facebook | 1×/month | Delivery areas, shipping rates, TAT |
| **Complimentary Gift Wrapping** | Instagram, Facebook | 1×/month | Show wrapping, mention it's free |
| **E-Gift Vouchers** | Instagram, Facebook | 1×/month | How to purchase, denominations, instant delivery |

These should be templated — create once, reuse monthly with minor updates. Schedule them at the start of the month so they're never forgotten.

### Step 2 — Monthly Theme & Focus

Choose a seasonal or emotional theme that anchors the entire month:

```
Theme formula: [Emotion/Season] + [Product Category Hook]

Examples:
  "Scents of September" → New fragrance arrivals
  "August Refresh" → Skincare & body care focus
  "Gift Season" → Jewelry, gift sets, vouchers
  "Island Glow" → Summer skincare, light fragrances
  "Monsoon Moments" → Cozy scents, self-care
```

**Rotate product priorities each month:**

| Priority | Examples |
|---|---|
| New fragrance launches | Latest arrivals, exclusive drops |
| Brand spotlights | Feature one brand deeply (Conatural, Swarovski, etc.) |
| Slow movers | Push products with stagnant inventory |
| Category deep-dives | All jewelry, all skincare, all gift sets |
| Cross-category bundles | "Date Night Set" (fragrance + jewelry) |

### Step 3 — Weekly Content Structure

**Standard weekly cadence:**

| Day | Content Type | Purpose |
|---|---|---|
| Monday | Static Post | Educational / brand story |
| Tuesday | Story (series) | Behind-the-scenes, polls, Q&A |
| Wednesday | Static Post or Carousel | Product spotlight |
| Thursday | Story (series) | Engagement — quizzes, "this or that" |
| Friday | **REEL** | High-reach content (see Reel Themes below) |
| Saturday | Static Post | Lifestyle / aspirational |
| Sunday | Story | Recap, "swipe up" / link |

**Volume targets per week:**
- 1 Reel (Friday)
- 2–3 Static Posts
- Stories daily or every other day

### Step 4 — Reel Themes

Rotate through these themes weekly to avoid repetition:

| Theme | Description | Example |
|---|---|---|
| **Product Spotlight** | Close-up of a single product, unboxing, texture | "This bottle. That scent. 😮‍💨 #CHGoodGirl" |
| **Demo / Tutorial** | How to apply, layer, or use the product | "3 ways to layer your fragrance for all-day wear" |
| **Gifting Story** | Gift wrapping process, recipient reaction | "POV: You just made someone's birthday" |
| **Testimonial / UGC** | Customer review or repost | "She said YES to this scent 💍" |
| **Store / Lifestyle** | In-store ambiance, location B-roll, island vibes | "Your happy place in Colombo" |
| **Before & After** | Transformation, comparison | "Cheap mist → Luxury EDP. Feel the difference." |
| **Trend / Seasonal** | Tie to current events, weather, holidays | "Monsoon-proof scents that last through the rain 🌧️" |

### Step 5 — Product Selection Rules

When picking products for the month, apply these filters in order:

```
1. IN STOCK ONLY — Eliminate anything with stock_status ≠ "instock"
                    (Check live via remote_wp_connection)

2. SEASONAL RELEVANCE — Does this product fit the monthly theme?
                         (Summer = light/fresh, Monsoon = cozy/warm, etc.)

3. SLOW MOVERS FIRST — Prioritize products with high stock but low
                        engagement or sales velocity.

4. BRAND ROTATION — Don't feature the same brand two months in a row.
                     Spread across fragrance, jewelry, and skincare.

5. PRICE DIVERSITY — Mix price points:
                     - 1–2 affordable (entry-level, impulse buy)
                     - 2–3 mid-range (core catalog)
                     - 1 premium (aspirational, halo product)

6. AVOID OVERUSED SKUs — Track which products were featured recently.
                          Use paper_store or a tracking sheet.
```

**Selection output format:**

```
| Priority | SKU | Product | Brand | Price | Stock | Why |
|---|---|---|---|---|---|---|
| Hero     | —   | —       | —     | —     | —     | —   |
| Support  | —   | —       | —     | —     | —     | —   |
| Slow Mvr | —   | —       | —     | —     | —     | —   |
```

### Step 6 — Campaigns & Promotions

Plan promotions that create urgency and reward engagement:

| Promo Type | When | Example |
|---|---|---|
| **Weekend Flash Sale** | Friday–Sunday | "20% off all skincare — this weekend only" |
| **GWP (Gift with Purchase)** | Mid-month | "Free mini fragrance with orders over Rs. X" |
| **Voucher Promo** | Start of month | "Buy a Rs. 5,000 voucher, get Rs. 500 free" |
| **Bundle Deal** | Any week | "Date Night Set: perfume + jewelry, 15% off" |
| **SMS/Email Blast** | 24h before promo | "Tomorrow only — you saw it here first 📱" |
| **Abandoned Cart** | Ongoing (automated) | "Your fragrance is waiting... 👀" |

**Promo checklist:**
- [ ] Creative assets ready (image, story graphic)
- [ ] Copy approved (caption, CTA, terms)
- [ ] Scheduled in content calendar
- [ ] SMS/Email reminder queued
- [ ] Landing page or collection page live
- [ ] Stock confirmed (enough to meet demand)

### Step 7 — Presentation Deadline

By the **1st week of each month**, the final plan should include:

```
Monthly Campaign Deck:
│ 1. Theme & Rationale (why this theme now?)
├── 2. Product Selection (table with SKUs, prices, stock, rationale)
├── 3. Weekly Content Calendar (posts, reels, stories by day)
├── 4. Reel Storyboards (3–4 reel concepts with shot lists)
├── 5. Recurring Post Schedule (delivery, gift wrap, vouchers)
├── 6. Campaign & Promo Calendar (sales, GWP, bundles, emails)
├── 7. Asset Checklist (images needed, copy needed, status)
├── 8. Success Metrics (reach, engagement, sales targets)
└── 9. Project & Task Tracking (link to `mcp_ai_project` / `mcp_ai_task` CPTs via `toolkit_cpt`)
```

## Recurring Post Templates

### Islandwide Delivery

```
Caption: "From Colombo to Jaffna, Galle to Trinco — your signature
scent ships free across the entire island. 🚚🇱🇰

Order by 2 PM for same-day dispatch. Tap the link in bio to shop."

Visual: Map of Sri Lanka with delivery zones marked, or a parcel
        being handed over with a smile.
```

### Complimentary Gift Wrapping

```
Caption: "Every order comes wrapped like a gift — because it probably
is one. 🎁✨

Complimentary gift wrapping on ALL orders. Just tell us at checkout
if it's for someone special (or yourself — we don't judge)."

Visual: Close-up of elegantly wrapped box with ribbon, The Parfumerie
        branding visible.
```

### E-Gift Vouchers

```
Caption: "Can't decide? Let them choose. 💌

E-gift vouchers available in Rs. 2,500 / 5,000 / 10,000 / 25,000.
Instant delivery via email. Perfect for last-minute gifting.

Link in bio → Gift Vouchers."

Visual: Digital voucher mockup with elegant design.
```

## Tracking & Iteration

After each campaign, capture what worked:

```
Track per post:
  - Platform, format (reel/static/story), reach, engagement rate
  - Link clicks, saves, shares
  - Sales attributed (if trackable)

Track per product:
  - Stock before campaign → stock after
  - Which products sold fastest
  - Which products got engagement but no sales

Feed into next month:
  - Best-performing formats → repeat
  - Best-performing products → rotate back in 2–3 months
  - Underperforming products → try different angle or demote
```

## Multi-Brand Integration

When managing multiple brands, ensure regular rotation:

```
Fragrance brands:  Banderas, Benetton, Carolina Herrera, Dolce & Gabana,
                   Ferrari, Foamous, Givenchy, Jeanne Arthes,
                   Jean Paul Gaultier, Kenzo, L'occitane, Nina Ricci,
                   Rabanne, Prada, Pepe Jeans, Valentino

Jewelry & gifts:   Swarovski, SIF Jakobs, Buckley London, Coeur de Lion

Skincare:          L'occitane, Conatural, Beauty by Rosh
```

**Rotation rule:** No brand appears as hero more than once per quarter. A "hero" feature means a dedicated reel or static post, not just a mention in a roundup.

## Campaign Calendar Format

```
MONTH: [Name]          THEME: [Theme Name]
──────────────────────────────────────────────────────────────────
WEEK 1 (1st–7th)
  Mon: Recurring Post — Islandwide Delivery
  Wed: Static — Theme Introduction
  Fri: REEL — [Theme + Product]
  Stories: Behind-the-scenes of planning

WEEK 2 (8th–14th)
  Mon: Static — Educational [Brand/Category]
  Wed: Carousel — Product Deep-Dive
  Fri: REEL — [Theme 2]
  Sat: Recurring Post — Gift Wrapping
  Stories: Polls, Q&A

WEEK 3 (15th–21st)
  Mon: Static — Customer Story / UGC
  Wed: [CAMPAIGN LAUNCH — Weekend Sale Teaser]
  Thu: SMS Blast — Sale reminder
  Fri: REEL — [Theme 3] + Sale Callout
  Sat–Sun: Weekend Flash Sale Posts

WEEK 4 (22nd–30th)
  Mon: Static — Sale Recap / Social Proof
  Wed: Recurring Post — E-Gift Vouchers
  Fri: REEL — [Theme 4]
  Stories: Monthly wrap-up, sneak peek at next month
```

## Critical Rules

- **Plan 80%, leave 20%** — Reserve space for trending topics, last-minute promos, and reactive content.
- **In stock only** — never feature a product that's out of stock on the live site; verify with `remote_wp_connection`.
- **Rotate brands and categories** — prevent audience fatigue and give every brand visibility.
- **Slow movers get priority** — actively push stagnant inventory before it becomes dead stock.
- **Recurring posts are non-negotiable** — schedule them first, build the rest around them.
- **Templates save time** — create reusable post frameworks for recurring content instead of starting from scratch each month.
- **Present by Week 1** — the full plan must be ready for review by the first week of the month.
- **Track what works** — feed performance data back into next month's planning.

## Common Mistakes

```
WRONG:
- Featuring the same hero product two months in a row
- Planning a campaign around an out-of-stock product
- Forgetting to schedule the three recurring posts
- Overloading one week and leaving another empty
- Using the same reel format every week (audience fatigue)
- No promo or urgency mechanism in the plan
- Presenting the plan in Week 3 (too late to execute)

RIGHT:
- Rotating hero products across brands and categories
- Verifying stock via WooCommerce API before finalizing
- Scheduling recurring posts first, building around them
- Balanced weekly cadence: 1 reel + 2–3 statics + daily stories
- Alternating reel themes (spotlight → tutorial → gifting → lifestyle)
- At least one promo or urgency driver per month
- Final plan ready by the 1st of the month
```

## What This Skill Does NOT Cover

- **Writing individual social media captions** — use **`design-social-content`**.
- **Publishing or scheduling individual social posts** — use `schedule_social_post` via **`design-social-publishing`**.
- **Researching products before adding them to a campaign** — use **`design-product-research`**.
- **Creating individual images or visuals** — use **`design-image-generation`**.
- **Deep research on market trends** — use `deep_research` via **`design-deep-research`**.
- **Running analytics reports on past campaigns** — use **`design-analytics-reporting`**.
- **CRM pipeline management (leads, deals, contacts)** — use **`design-crm`**.

## Cross-references

- Run **`design-content-calendar`** to build the detailed daily/weekly posting schedule.
- Run **`design-social-content`** to write the actual captions for planned posts.
- Run **`design-social-publishing`** to schedule and publish via `schedule_social_post`.
- Run **`design-product-research`** to research products before adding them to the campaign.
- Run **`design-image-generation`** to create campaign visuals — check your tool list for the Gemini image tool.
- Run **`design-brand-kit`** to ensure campaign visuals align with brand guidelines.
- Run **`design-project-management`** to track campaign projects, tasks, and sprints via `toolkit_cpt` → `mcp_ai_project`, `mcp_ai_task`.
- Run **`design-crm`** to manage campaign-related leads, deals, and customer activity via `toolkit_cpt` → `mcp_ai_company`, `mcp_ai_deal`.
- Run **`mcp-ai-wpoos-plugin`** for `remote_wp_connection` — verify product stock before featuring.
- Use **`paper_store_*`** tools with `connection_id` to persist campaign plans to the target site's Paper Store.
- Use **`create_post`** to directly publish campaign-related content (landing pages, announcements) to WordPress.
