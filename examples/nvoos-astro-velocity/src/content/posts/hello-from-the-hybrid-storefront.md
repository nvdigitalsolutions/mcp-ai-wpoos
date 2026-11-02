---
title: 'Hello from the hybrid storefront'
description: 'The first local post — rendered with no envelope doc at all.'
date: 2026-10-28
draft: false
---

This post lives in `src/content/posts/` and renders **without any backend
connection** — the hybrid-local layer (Proposal 106) fills the gaps whenever
the envelope has no matching doc.

Posts merge two sources: the bridge's `content/posts` envelope wins on slug
collision, and local markdown entries like this one fill in. The blog listing
sorts everything date-descending.

## What this proves

- Local-first rendering works end to end (`/blog/0/hello-from-the-hybrid-storefront/`).
- The L2 merge keeps envelope content authoritative.
- The demo remains fully runnable with no WordPress backend.
