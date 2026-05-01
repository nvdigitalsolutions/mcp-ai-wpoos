# Third-Party Notices — Bundled Agent Skills (Pro)

The Pro bundled-skills directory contains plugin-ecosystem skills sourced from
[`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills),
alongside Pro-exclusive Google Workspace CLI skills authored in-house.

All third-party skills in this directory are redistributed under their original
license. Each individual `SKILL.md` carries `source:` and `license:` frontmatter
fields pointing back to its upstream copy.

## Skills sourced from `Lonsdale201/wp-agent-skills`

**Upstream repository:** https://github.com/Lonsdale201/wp-agent-skills
**Pinned commit:** `8684fef5b4c33bc0cd783f9fff7770b1f7f59c57`
**License:** MIT (see below)
**Original author:** Soczó Kristóf (Lonsdale201)

Frontmatter normalised for NV oOS's parser; Markdown body byte-for-byte identical
to upstream.

### WooCommerce family

- `wc-coupon-dynamic`, `wc-customer-and-sessions`, `wc-emails-classic`,
  `wc-hpos-compatibility`, `wc-payment-gateway`, `wc-product-search-select`,
  `wc-rest-api-v4`, `wc-shipping-method`, `wc-shipping-providers`,
  `wc-stripe-add-payment-method`, `wc-variations-data`,
  `wc-variations-pricing-filters`, `wcm-access-discounts`,
  `wcm-data-model-subscriptions-link`, `wcm-membership-hooks`,
  `wcs-data-model-switching-gifting`, `wcs-renewal-scheduler`,
  `wcs-subscription-hooks` — sourced from `woocommerce/`

### JetEngine

- `je-dynamic-visibility-condition`, `je-listings-callback`,
  `je-query-builder-custom-type` — sourced from `jet-engine/`

### JetFormBuilder

- `jfb-action-events`, `jfb-action-external-api`, `jfb-action-item-decorator`,
  `jfb-action-messages`, `jfb-form-action`, `jfb-form-sidebar-panel`,
  `jfb-settings-tab` — sourced from `jetformbuilder/`

### WP Rocket

- `wp-rocket-cache-invalidation`, `wp-rocket-cache-rejection-and-filters` —
  sourced from `wp-rocket/`

### Upstream MIT license text

```
MIT License

Copyright (c) 2026 Lonsdale201 and wp-agent-skills contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Pro-exclusive in-house skills

The Google Workspace CLI skills (`gws-calendar`, `gws-docs`, `gws-drive`,
`gws-gmail`, `gws-gmail-send`, `gws-meet`, `gws-shared`, `gws-sheets`,
`gws-tasks`, `gws-workflow`, `gws-workflow-standup-report`) are authored by
NV Digital Solutions and are part of the proprietary Pro add-on; they are not
covered by this notice.
