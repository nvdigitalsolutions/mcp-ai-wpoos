## Cluster state board

Completed clusters (merged or open against `alpha-working`): #6084 CRM
toolkit, #6085 CRM data store, #6086 Huggingface, #6087 Veo, #6088 transcript
mining, #6089 quiz admin pages, #6090 document template pages, #6091
multi-agent AJAX, #6092 chart JS enqueue, #6093 admin hook suffixes, #6094
orchestration modes, #6095 REST tools controller, #6096 multi-agent
orchestration, #6097 NPM notice, #6098 multi-agent dashboard, #6099 slash
command integration, #6100 profession media vector, #6101 base knowledge
seeder, #6102 pro dashboard diagnostic, #6103 chat conversation CCT, #6104
admin test model, #6105 profession team CPT sanitization, #6106 Graphify admin
classes, #6107 toolkit + hooks registry, #6108 test-suite skill, #6109 Shopify
sync CCT manager, #6110 semantic compressor, #6111 usage tracker pricing
drift, #6112 docs catch-up, #6113 Content Graph AI credential encryption,
#6114 WP 7.1 Woo role resync, #6115 create post taxonomy application, #6116
site creator tools drift, #6117 toolkit registry singleton drift, #6118
PHPUnit loader excludes, #6119 credential resolver cache invalidation, #6120
capability flags model drift, #6121 settings suite test drift, #6122 slash
command tool mode contract, #6124 crawler job contract, #6125 Crawl4AI tool
failures, #6126 transcript mining job cluster, #6127 save post content drift,
#6128 tool slug integrity drift, #6129 tool coverage manifest, #6130 bulk
auto dispatch inline, #6131 TPM fallback model drift, #6132 async registry
construct errors, #6133 base version polluters, #6134 cache helper cluster,
#6135 transcript recorder repository, #6136 translation loading timing,
#6137 profession playbook seeder, #6138 site health tool, #6139 settings
dashboard, #6140 slash command sync docs, #6141 REST assistant directory,
#6143 Pro test autoload, #6144 new regressions, #6145 Graphify connectors,
#6146 rate limit backoff, #6147 provider subtab settings, #6148 orchestration
slider settings, #6149 MCP client configuration, #6150 federation test,
#6153 Google Chat fields (open).

Newer merged clusters (post-#6153, discovered via the PR merge log):
#6249 Hermes dashboard fleet extensions, #6251 Auth0 toggle, #6252 Auth0
menu, #6253 docs-hub emoji DOM crash, #6254 auto-categorize, #6255 provider
defaults, #6256 Pro SPA v2 shortcode, #6257 chart tiers, #6258 theme
sortable compat, #6259 chat attachments, #6260 count-tokens params, #6262
continuation seam, #6263 chat SSE handler, #6264 cache helper, #6265
nefarious rate limiter + chat hook tolerance (open).

Session 2026-09-04/05 wave-5 clusters (runs 91006542428 + 91771001271,
all merged): #6280 chat transcripts + attachments, #6281 charts + Pro
dashboard, #6282 professions/teams, #6283 presets/remote connections,
#6284 Cloudflare, #6285 mesh networking, #6286 memory auto-capture,
#6287 AI CPT management integration, #6288 PayHere vs Remote
Connections, #6289 Mubert music contract, #6290 OpenAI classic Images
API, #6291/#6297 composer metadata drift, #6292 Content Graph AI
ecosystem, #6293 pro schedule AJAX + curriculum exporter, #6294
cluster-board docs, #6295 exec-disabled host hardening, #6296
conversation-import CCT gate, #6298 model routing/complexity routing,
#6299 Gemini, #6300 JetEngine/Graphify stub isolation + gates, #6301
OAuth/credentials encryption, #6302 REST + skill-pack registry, #6303
admin/settings drift, #6304 K10 Elementor, #6305 K11 permission gates
(acting-user capability checks), #6306 K12 HTTP/provider clients, #6307
K13 orchestration budget, #6308 K14 logging/events (recent-activity
allowlist + trace durations), #6311 K16 misc singles (webhook
round-trip, settings-repository blob fallback, chart HTML attachment
gating, site-builder heading tags, PSO keyword inflections,
scheduled-post publish date reset + 16 suite contract updates), #6312
K15 skill-suite upload isolation (pack normalisation + install action
landed via direct commit d452a125bf).

Session 2026-09-05 cluster (run 91942465749): #6327 post-K16 singles
(merged) — chat transcript session-key mock restore, MemPalace wing-scope
graph leak (production fix in `wake_up_context`), OKF Pro tools lost to
`clear_tools()` on the shared registry, Site Health provider-key
isolation, transcript-mining logging cache reset.

Remaining candidates change quickly; re-triage from the latest CI log rather
than trusting an old list.

