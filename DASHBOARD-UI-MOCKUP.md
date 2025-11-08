# WP oOS Dashboard Overview - UI Mockup

```
┌─────────────────────────────────────────────────────────────────────────────────────┐
│  WP oOS Settings                                                                     │
├─────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                      │
│  ┌─────────┬──────────┬───────────┬────────┬──────────┬────────┬──────────┐       │
│  │Overview │ General  │Providers  │  Auth  │  Tools   │Integr..│ Security │       │
│  │  🎛      │   ⚙     │   🔧      │  🔒    │   🛠    │   🔌   │   🛡     │       │
│  └─────────┴──────────┴───────────┴────────┴──────────┴────────┴──────────┘       │
│                                                                                      │
│  System Overview                                                                    │
│  Quick overview of your WP Open Operator System configuration and status.          │
│                                                                                      │
│  ┌──────────────────────────┬──────────────────────────┬──────────────────────────┐│
│  │ 🔒 Auth0 Authentication  │ 🔧 AI Providers          │ 🛠 Features & Integrations││
│  ├──────────────────────────┼──────────────────────────┼──────────────────────────┤│
│  │                          │                          │                          ││
│  │ Domain                   │ OpenAI                   │ Debug Logging            ││
│  │         [✓ Configured]   │              [✓ Config.] │         [✓ Enabled]      ││
│  │                          │                          │                          ││
│  │ API Audience             │ Google Gemini            │ Federation               ││
│  │         [✓ Configured]   │              [✗ Not Set] │         [✗ Disabled]     ││
│  │                          │                          │                          ││
│  │ GitHub Bridge            │ Ollama (Local)           │ Mesh Network             ││
│  │         [✓ Enabled]      │              [✗ Not Set] │         [✗ Disabled]     ││
│  │                          │                          │                          ││
│  │ Management API           │ LM Studio                │ Service Connectors       ││
│  │         [✓ Configured]   │              [✗ Not Set] │         3 of 8 configured││
│  │                          │                          │                          ││
│  │                          │ Default Provider         │                          ││
│  │                          │         OpenAI           │                          ││
│  └──────────────────────────┴──────────────────────────┴──────────────────────────┘│
│                                                                                      │
│  Quick Links                                                                        │
│                                                                                      │
│  ┌──────────────────────┬──────────────────────┬──────────────────────┐            │
│  │  🔒 Authentication   │  🛠 Auth0 Setup      │  🔧 AI Providers     │            │
│  │     Settings         │     Wizard           │                       │            │
│  │                      │                      │                       │            │
│  │  Configure Auth0,    │  1-click Auth0       │  Configure OpenAI,   │            │
│  │  JWT, and guest      │  configuration       │  Gemini, Ollama      │            │
│  │  tokens              │                      │                       │            │
│  └──────────────────────┴──────────────────────┴──────────────────────┘            │
│                                                                                      │
│  ┌──────────────────────┬──────────────────────┬──────────────────────┐            │
│  │  🛠 Tools &          │  👥 Manage           │  🛡 Security         │            │
│  │     Features         │     Assistants       │     Settings         │            │
│  │                      │                      │                       │            │
│  │  Enable and          │  Create and          │  Monitor and         │            │
│  │  configure tools     │  configure AI        │  configure security  │            │
│  │                      │  assistants          │                       │            │
│  └──────────────────────┴──────────────────────┴──────────────────────┘            │
│                                                                                      │
└─────────────────────────────────────────────────────────────────────────────────────┘

Legend:
- [✓ Configured] = Green badge (#d4edda background, #155724 text)
- [✓ Enabled] = Blue badge (#d1ecf1 background, #0c5460 text)
- [✗ Not Set] = Red badge (#f8d7da background, #721c24 text)
- [✗ Disabled] = Gray badge (#ccc background, #666 text)
```

## Color Scheme

### Status Badges
- **Configured** (Green): `background: #d4edda; color: #155724;`
- **Enabled** (Blue): `background: #d1ecf1; color: #0c5460;`
- **Not Configured** (Red): `background: #f8d7da; color: #721c24;`
- **Disabled** (Gray): `background: #ccc; color: #666;`

### Cards
- **Background**: `#fff`
- **Border**: `1px solid #ddd`
- **Border Radius**: `4px`
- **Shadow**: `0 1px 3px rgba(0,0,0,0.05)`

### Quick Link Cards
- **Hover Effect**: 
  - Border color: `#2271b1`
  - Shadow: `0 2px 6px rgba(0,0,0,0.1)`
  - Transform: `translateY(-2px)`

## Responsive Behavior

### Status Cards Row
```css
display: grid;
grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
gap: 20px;
```

### Quick Links Grid
```css
display: grid;
grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
gap: 15px;
```

Both grids automatically adjust to screen width:
- **Desktop (>1200px)**: 3 columns
- **Tablet (768-1200px)**: 2 columns
- **Mobile (<768px)**: 1 column

## Interactive Elements

### "Setup Auth0" Button
Appears below the Auth0 card when domain OR audience is not configured:
```
┌──────────────────────────┐
│ 🔒 Auth0 Authentication  │
├──────────────────────────┤
│ Domain                   │
│         [✗ Not Set]      │
│                          │
│ API Audience             │
│         [✗ Not Set]      │
│                          │
│  [Setup Auth0]           │ ← Blue button linking to wizard
└──────────────────────────┘
```

### Tab Navigation
Active tab styling:
- Background: WordPress blue
- Text: White
- Bottom border: 2px blue accent

### Quick Link Cards
Each card shows:
1. Dashicon (24px)
2. Bold title (14px)
3. Description text (12px, gray)

Hover state:
- Lifts up 2px
- Border becomes blue
- Shadow increases

## Sample States

### All Configured
When everything is set up:
- All status badges show "Configured" or "Enabled"
- No "Setup Auth0" button
- Service connectors: "8 of 8 configured"

### Fresh Installation
When nothing is configured:
- Most badges show "Not Set" or "Disabled"
- "Setup Auth0" button visible
- Service connectors: "0 of 8 configured"
- May show admin notice suggesting configuration

### Partial Configuration
Typical state during setup:
- Auth0 configured (green badges)
- OpenAI configured (green)
- Other providers not set (red)
- Some features enabled, others disabled
- Service connectors: "2 of 8 configured"
