---
type: Skill
name: gws-drive
version: 1.0.0
description: Google Drive skill for managing files, folders, and shared drives via the gws CLI. Covers listing, searching, uploading, downloading, sharing, and organising Drive content.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Google Drive (gws-drive)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Manage Google Drive files, folders, and shared drives using the `gws` CLI.

```bash
gws drive <resource> <method> [flags]
```

## Quick Examples

```bash
# List the 10 most recent files
gws drive files list --params '{"pageSize": 10, "orderBy": "modifiedTime desc"}'

# Search for files by name
gws drive files list --params '{"q": "name contains '\''invoice'\''", "pageSize": 20}'

# Search for PDFs only
gws drive files list --params '{"q": "mimeType = '\''application/pdf'\''", "pageSize": 10}'

# Get file metadata
gws drive files get --params '{"fileId": "FILE_ID", "fields": "id,name,mimeType,size,modifiedTime"}'

# Create a folder
gws drive files create --json '{"name": "Q1 Reports", "mimeType": "application/vnd.google-apps.folder"}'

# Upload a file
gws drive files create --upload /path/to/file.pdf --json '{"name": "report.pdf"}'

# Download a file
gws drive files get --params '{"fileId": "FILE_ID", "alt": "media"}' -o /tmp/file.pdf

# Export a Google Doc as PDF
gws drive files export --params '{"fileId": "DOC_ID", "mimeType": "application/pdf"}' -o /tmp/doc.pdf

# Share a file (grant viewer access)
gws drive permissions create \
  --params '{"fileId": "FILE_ID"}' \
  --json '{"role": "reader", "type": "user", "emailAddress": "user@example.com"}'

# Move file to a folder
gws drive files update \
  --params '{"fileId": "FILE_ID", "addParents": "FOLDER_ID", "removeParents": "root"}'

# Trash a file
gws drive files update --params '{"fileId": "FILE_ID"}' --json '{"trashed": true}'

# Delete permanently
gws drive files delete --params '{"fileId": "FILE_ID"}'

# List shared drives
gws drive drives list

# Auto-paginate all files as NDJSON
gws drive files list --params '{"pageSize": 100}' --page-all | jq -r '.files[].name'
```

## Key API Resources

### files
`list`, `get`, `create`, `update`, `copy`, `delete`, `export`, `download`

### permissions
`list`, `get`, `create`, `update`, `delete`

### drives (Shared Drives)
`list`, `get`, `create`, `update`, `hide`, `unhide`

### comments
`list`, `get`, `create`, `update`, `delete`

### changes
`list`, `watch`, `getStartPageToken`

## Common Query Syntax

```bash
# Folders only
"mimeType = 'application/vnd.google-apps.folder'"

# Files in a specific folder
"'FOLDER_ID' in parents and trashed = false"

# Recently modified
"modifiedTime > '2026-01-01T00:00:00'"

# Shared with me
"sharedWithMe = true"

# By file type
"mimeType = 'image/png'"
```

## Discovering More

```bash
gws drive --help
gws schema drive.files.list
gws schema drive.permissions.create
```
