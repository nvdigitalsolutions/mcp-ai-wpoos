# Memory entry template

Persist one file per durable fact to `~/.hermes/memories/` (kebab-case filename) when a session learns something worth keeping.

```yaml
---
fact: (one-sentence durable truth)
context: (when it applies / where it was discovered)
date: YYYY-MM-DD
confidence: high | medium | low
---
```

Rules:
- Short and factual; respect Hermes memory limits (2200 chars/entry, 1375 user profile).
- No secrets, no PII, no transient chatter — only durable gotchas, decisions, and environment quirks.
