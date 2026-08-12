# NV oOS Skills Pack for Hermes Agent

Skills that teach [Hermes Agent](https://hermes-agent.nousresearch.com) how to
operate NV oOS WordPress sites through scoped operator credentials.

## Install

```bash
hermes skills tap add nvdigitalsolutions/nvoos-hermes-skills
hermes skills install nvoos-operations
hermes skills install nvoos-approvals
hermes skills install nvoos-site-context
```

Alternatively, point Hermes at this repository's existing skills directory
(the layout is compatible):

```yaml
# ~/.hermes/config.yaml
skills:
  external_dirs:
    - /path/to/mcp-ai-wpoos/.agents/skills
```

## Skills

| Skill | Purpose |
|---|---|
| `nvoos/nvoos-operations` | Tool groups per domain (content, e-commerce, CRM, PM, media, docs) and canonical task recipes. |
| `nvoos/nvoos-approvals` | How NV oOS approval gates work and how to phrase requests so the human can adjudicate quickly. |
| `nvoos/nvoos-site-context` | Per-site fact template (store URL, brand, active campaigns) the human fills in once. |
