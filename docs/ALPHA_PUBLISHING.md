# Quick Reference: Publishing Alpha Versions to NPM

## TL;DR

```bash
# Quick publish an alpha version
./bin/publish-alpha.sh 0.1.0-alpha.2
```

## What Gets Published

Three NPM packages under `@nvdigitalsolutions` organization:
- `@nvdigitalsolutions/nvoos-storage`
- `@nvdigitalsolutions/nvoos-markdown`
- `@nvdigitalsolutions/nvoos-events`

## Prerequisites

1. Add `NPM_TOKEN` secret to GitHub repository settings
2. Have publish permissions for @nvdigitalsolutions NPM organization

## Version Format

`X.Y.Z-alpha.N` (e.g., `0.1.0-alpha.1`, `0.1.0-alpha.2`)

## Installation (After Publishing)

```bash
# Install latest alpha
npm install @nvdigitalsolutions/nvoos-storage@alpha

# Install specific version
npm install @nvdigitalsolutions/nvoos-storage@0.1.0-alpha.2
```

## Full Documentation

See [docs/npm-alpha-publishing.md](docs/npm-alpha-publishing.md) for complete guide.

## Workflow

1. Run `./bin/publish-alpha.sh <version>`
2. Script updates versions, builds packages, creates tag
3. Confirm push when prompted
4. GitHub Actions automatically publishes to NPM
5. Monitor at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions

## Manual Trigger

Go to: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/npm-publish-alpha.yml
Click "Run workflow", enter version, click "Run workflow"

## Support

- Documentation: [docs/npm-alpha-publishing.md](docs/npm-alpha-publishing.md)
- Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Email: hello@nvdigitalsolutions.com
