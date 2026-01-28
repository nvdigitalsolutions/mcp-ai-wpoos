# Google Site Kit Integration - Quick Start Guide

## Overview

NV oOS integrates with Google Site Kit to give your AI assistants access to powerful analytics, SEO, and performance data. Ask questions in plain English and get instant insights about your website's performance.

**Integration Status:** ✅ Included in Base Plugin  
**Setup Time:** 5 minutes  
**Requirements:** Google Site Kit plugin (free)

---

## What You Can Do

### Ask Natural Language Questions

Instead of navigating dashboards, just ask:

- "How is my website traffic this month?"
- "What are my top performing blog posts?"
- "Which keywords are driving the most traffic?"
- "How fast is my homepage loading?"
- "What's my bounce rate on mobile devices?"
- "Which pages need SEO improvement?"

### Get AI-Powered Insights

The AI doesn't just retrieve data—it analyzes and explains it:

```
You: "How's my website doing?"

AI: "Your website is performing well this month! Here's what I found:

📈 Traffic: 15,234 sessions (↑12% vs last month)
👥 Users: 12,890 unique visitors (↑8%)
⏱️ Avg. Session: 3m 24s (↑5%)
📉 Bounce Rate: 42.3% (↓3.2%)

Top Pages:
1. /blog/wordpress-tips - 3,421 views
2. /services - 2,156 views  
3. /about - 1,843 views

The traffic increase is primarily from organic search. Your SEO work 
on 'wordpress plugins' is paying off—you're now ranking in position 
3.2 (up from 8.5 last month)."
```

---

## Setup Instructions

### Step 1: Install Google Site Kit (if not already installed)

1. Go to **WordPress Admin → Plugins → Add New**
2. Search for "Google Site Kit"
3. Click **Install Now**, then **Activate**
4. Or install from: https://wordpress.org/plugins/google-site-kit/

### Step 2: Configure Google Site Kit

1. Go to **WordPress Admin → Site Kit → Dashboard**
2. Click **Start Setup**
3. Sign in with your Google account
4. Grant permissions for:
   - Search Console
   - Analytics
   - PageSpeed Insights
   - AdSense (optional)
5. Complete the setup wizard

**Note:** This is a one-time setup. You need a Google account that has access to your site's analytics.

### Step 3: Enable Site Kit Integration in NV oOS

1. Go to **WordPress Admin → Settings → NV oOS**
2. Navigate to **Integrations** tab
3. Find **Google Site Kit** section
4. Check **"Enable Site Kit Integration"**
5. Configure settings (optional):
   - **Cache Duration:** 15 minutes (recommended)
   - **Default Date Range:** Last 28 days
   - **Enable Detailed Logging:** Only for debugging
6. Click **Save Changes**

### Step 4: Test the Integration

Go to your NV oOS chat interface and try these commands:

```
"Show me my analytics for this month"
"What are my top search keywords?"
"How fast is my site loading?"
```

If everything is set up correctly, the AI will retrieve and explain your data.

---

## Available Tools

When Site Kit is integrated, your AI assistants gain access to 4 powerful tools:

### 1. **Analytics Data** (`sitekit_get_analytics`)
Access Google Analytics metrics:
- Sessions & pageviews
- Bounce rate & avg session duration  
- User demographics
- Traffic sources
- Top performing pages

**Example Queries:**
- "How many visitors did I have last week?"
- "What's my bounce rate?"
- "Show me traffic trends for the last 90 days"

---

### 2. **Search Console Data** (`sitekit_get_search_console`)
Access Google Search Console metrics:
- Search impressions & clicks
- Click-through rate (CTR)
- Average position in search results
- Top keywords & queries
- Top performing pages in search

**Example Queries:**
- "What keywords am I ranking for?"
- "Which posts get the most organic traffic?"
- "Show me my search performance this month"
- "What's my average position in Google?"

---

### 3. **PageSpeed Insights** (`sitekit_get_pagespeed`)
Access site performance metrics:
- Performance scores (mobile & desktop)
- Core Web Vitals
- Speed recommendations
- Loading metrics

**Example Queries:**
- "How fast is my homepage?"
- "What's my mobile performance score?"
- "Give me speed optimization recommendations"

---

### 4. **AdSense Data** (`sitekit_get_adsense`)
Access monetization metrics (if you use AdSense):
- Earnings & revenue
- Impressions & clicks
- RPM & CTR
- Top earning pages

**Example Queries:**
- "How much did I earn from ads this month?"
- "What's my AdSense RPM?"
- "Which pages generate the most ad revenue?"

---

## Use Cases & Examples

### Use Case 1: Content Performance Analysis

**Goal:** Find which blog posts perform best and need updates

```
You: "Which of my blog posts are getting the most traffic?"

AI: [Analyzes Analytics + Search Console]
"Here are your top 5 performing blog posts by sessions:

1. 'Ultimate WordPress Security Guide' - 3,892 sessions
   - Avg. position: 3.2
   - CTR: 8.4% 
   - Keywords: 'wordpress security', 'secure wordpress site'
   
2. 'Page Speed Optimization' - 2,641 sessions
   - Avg. position: 5.8
   - CTR: 4.2%
   - Keywords: 'wordpress speed', 'optimize wordpress'
   
3. 'SEO Best Practices 2025' - 2,103 sessions
   - Avg. position: 9.3
   - CTR: 2.1%
   - Keywords: 'wordpress seo', 'seo tips'

Recommendation: Post #3 has high traffic but low CTR (position 9.3). 
Consider updating the title and meta description to improve click-through 
from search results."

You: "Draft an improved title and meta for the SEO post"

AI: [Generates optimized metadata based on insights]
```

---

### Use Case 2: SEO Opportunity Discovery

**Goal:** Find keywords to target and content gaps to fill

```
You: "What search queries am I getting impressions for but not ranking well?"

AI: [Analyzes Search Console]
"I found 8 keyword opportunities where you have high impressions but low clicks:

High Opportunity Keywords (many impressions, low clicks):
1. 'wordpress hosting comparison' - 5,234 impressions, position 12.4
2. 'best contact form plugin' - 3,891 impressions, position 15.2
3. 'wordpress backup solutions' - 2,456 impressions, position 18.1

These keywords are on page 2 of Google. With some optimization, you could 
move to page 1 and significantly increase traffic.

Would you like me to:
1. Create new blog posts targeting these keywords?
2. Optimize existing content that partially covers these topics?
3. Generate content outlines for each opportunity?"
```

---

### Use Case 3: Performance Monitoring

**Goal:** Monitor site speed and get optimization recommendations

```
You: "Check my site's performance"

AI: [Uses PageSpeed Insights]
"Site Performance Analysis:

Homepage (/)
- Mobile Score: 87/100 ✅ Good
- Desktop Score: 95/100 ✅ Excellent
- LCP: 1.8s ✅ Good
- FID: 45ms ✅ Good
- CLS: 0.08 ✅ Good

Blog Page (/blog)
- Mobile Score: 68/100 ⚠️ Needs Improvement
- Desktop Score: 82/100 ✅ Good
- Issues detected:
  * Large images not optimized (savings: 1.2s)
  * Render-blocking resources (savings: 0.8s)
  * Unused CSS (savings: 0.3s)

Recommendations:
1. Optimize images on blog page (use WebP format)
2. Defer non-critical CSS
3. Enable lazy loading for images below the fold

Would you like me to help implement these optimizations?"
```

---

### Use Case 4: Traffic Analysis & Alerts

**Goal:** Understand traffic changes and get alerts for anomalies

```
You: "Why did my traffic drop last week?"

AI: [Analyzes multi-day Analytics + Search Console data]
"Traffic Analysis for Last Week:

Overall: 2,341 sessions (↓23% vs previous week)

Breakdown by Source:
- Organic Search: 1,234 sessions (↓35%) ⚠️ Major drop
- Direct: 567 sessions (↑5%)
- Referral: 342 sessions (↓8%)
- Social: 198 sessions (↑12%)

Root Cause Analysis:
The drop is primarily in organic search. Checking Search Console data:
- Average position: 8.2 (was 5.4) ⚠️
- Impressions: Down 28%
- CTR: Stable at 5.2%

Likely causes:
1. Google algorithm update on Jan 18 (confirmed)
2. Competitor content outranking your 'WordPress tips' post
3. Seasonal decrease in search volume

Recommendations:
1. Update top-performing posts with fresh content
2. Build backlinks to key pages
3. Monitor rankings daily for next 2 weeks

I can set up automated monitoring and alert you to ranking changes."
```

---

## AI Assistant Configuration

### Pre-Built Assistant Templates

#### Analytics Insights Assistant
```yaml
Name: Analytics Assistant
System Prompt: |
  You are a website analytics expert. Help users understand their 
  website performance through Google Analytics and Search Console data. 
  Provide clear explanations, identify trends, and make actionable 
  recommendations.

Enabled Tools:
  - sitekit_get_analytics
  - sitekit_get_search_console
  - sitekit_get_pagespeed

Example Prompts:
  - "Analyze my website traffic trends"
  - "What content performs best?"
  - "Give me my weekly analytics report"
```

#### SEO Advisor Assistant
```yaml
Name: SEO Advisor  
System Prompt: |
  You are an SEO specialist. Analyze search performance, identify 
  keyword opportunities, and provide optimization recommendations 
  based on real Search Console data.

Enabled Tools:
  - sitekit_get_search_console
  - sitekit_get_analytics
  - get_posts (to analyze existing content)

Example Prompts:
  - "Find keyword opportunities"
  - "Which posts need SEO optimization?"
  - "Analyze my search rankings"
```

#### Content Strategy Assistant
```yaml
Name: Content Strategist
System Prompt: |
  You help create data-driven content strategies. Use analytics and 
  search data to identify what content works, what needs improvement, 
  and what to create next.

Enabled Tools:
  - sitekit_get_analytics
  - sitekit_get_search_console
  - get_posts
  - create_post (for content creation)

Example Prompts:
  - "What should I write about next?"
  - "Analyze my content performance"
  - "Create a content calendar based on data"
```

---

## Troubleshooting

### Issue: "Google Site Kit is not active or configured"

**Solution:**
1. Verify Site Kit plugin is installed and activated
2. Complete Site Kit setup wizard (connect Google account)
3. Enable integration in NV oOS settings

---

### Issue: "You do not have permission to access Google Analytics data"

**Solution:**
1. Make sure you're logged in as Administrator
2. Verify your Google account has access to the site's Analytics
3. Reconnect Site Kit if needed (Settings → Site Kit → Settings → Disconnect)

---

### Issue: "Failed to fetch analytics data"

**Solution:**
1. Check that Site Kit is properly connected (green checkmarks in Site Kit dashboard)
2. Clear Site Kit cache: Settings → NV oOS → Integrations → Clear Site Kit Cache
3. Try disconnecting and reconnecting your Google account in Site Kit
4. Enable detailed logging to see specific error messages

---

### Issue: Data seems outdated

**Solution:**
- Site Kit data is cached for 15 minutes by default
- To force fresh data: Clear Site Kit cache in NV oOS settings
- Or wait 15 minutes for cache to expire automatically

---

### Issue: Tools not appearing for AI

**Solution:**
1. Verify Site Kit integration is enabled in NV oOS settings
2. Make sure your assistant has the tools enabled
3. Check that you have `manage_options` capability
4. Reload the assistant editor page

---

## Best Practices

### 1. **Use Specific Date Ranges**
```
❌ "Show me traffic"  
✅ "Show me traffic for the last 7 days"
```

### 2. **Combine Multiple Data Sources**
```
✅ "Compare my search rankings with actual traffic for top posts"
```
AI will intelligently combine Search Console + Analytics data

### 3. **Ask Follow-Up Questions**
```
You: "What are my top keywords?"
AI: [Shows data]
You: "Which of those keywords have the best conversion rate?"
AI: [Combines Search Console + Analytics]
```

### 4. **Request Actionable Insights**
```
❌ "Show me pageviews"
✅ "Analyze my pageviews and tell me what actions I should take"
```

### 5. **Set Up Regular Check-Ins**
```
"Give me a weekly performance summary every Monday"
```
Configure an assistant to provide automated reports

---

## Privacy & Security

### Data Handling
- ✅ **No data storage:** Analytics data is not stored in WordPress database
- ✅ **Cached temporarily:** Data cached for 15 minutes to reduce API calls
- ✅ **User permissions:** Only administrators can access analytics tools
- ✅ **Google auth:** All authentication handled by Site Kit (no separate login)

### GDPR Compliance
- Analytics data access respects Site Kit's privacy settings
- No additional tracking implemented by NV oOS
- Data retrieved on-demand, not continuously collected

---

## Performance Optimization

### Caching Strategy
- Default cache: 15 minutes (configurable)
- Reduces API calls to Google services
- Prevents rate limiting issues
- Automatic cache invalidation

### Recommended Settings
- **Cache Duration:** 15 minutes for most sites, 30 minutes for high-traffic
- **Default Date Range:** Last 28 days (good balance of data vs. performance)
- **Detailed Logging:** Disable unless troubleshooting

---

## What's Next?

### Try These Commands
1. "Analyze my website performance this month"
2. "What are my top 10 keywords?"
3. "Which pages have the worst bounce rate?"
4. "Compare this month's traffic to last month"
5. "Give me SEO recommendations based on my data"

### Advanced Features (Coming Soon)
- Automated weekly/monthly reports
- Predictive analytics and forecasting
- Multi-site analytics aggregation
- Custom dashboard widgets
- Client report generation

---

## Resources

- [Google Site Kit Documentation](https://sitekit.withgoogle.com/documentation/)
- [Full Integration Documentation](./google-site-kit-integration.md)
- [Benefits & Use Cases](./google-site-kit-benefits.md)
- [NV oOS Main Documentation](../../README.md)

---

## Need Help?

- **Installation Issues:** Check Site Kit installation guide
- **Integration Issues:** Review troubleshooting section above
- **Feature Requests:** Open GitHub issue
- **Questions:** Join our community forum

---

**Happy analyzing! 📊🚀**

Transform your website analytics from complex dashboards to simple conversations.
