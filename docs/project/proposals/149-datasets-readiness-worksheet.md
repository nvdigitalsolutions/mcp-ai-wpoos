# Proposal 149 — `datasets` readiness worksheet (track P1)

**Parent:** [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §46
**State:** open. **No code in this track** — it is a classification decision, not a page.
**Owner:** unassigned. **Unblocks:** order item 18 (`datasets`, contract state `planned`).

## 1. Why a page cannot be built first

`datasets` has looked free twice and been blocked twice (§32, §35). The reason is in
`lib/core/resources/tool-readiness.php`: eleven HuggingFace tools exist, and all eleven
sit at:

```php
'readiness' => 'unverified',
'reason'    => 'Auto-added by the auditor; not classified yet.',
```

`unverified` means **constructible, but not served**. `ManifestToolRegistrar` registers
only servable tools, so no assistant can call one and no dataset can be fetched. A
browser built now would list datasets the runtime cannot use — a catalogue of things
the user cannot have.

## 2. The eleven slugs

All eleven extend `AbstractHuggingFaceTool` and share one API
(`https://datasets-server.huggingface.co`). The decision is therefore **family-wide**:
one classification reason covers all eleven, even though the manifest records a row
per slug.

| Slug | Class (`lib/core/src/Tool/`) |
|---|---|
| `huggingface_dataset_filter` | `HuggingFaceDatasetFilterTool` |
| `huggingface_dataset_get_info` | `HuggingFaceDatasetGetInfoTool` |
| `huggingface_dataset_get_parquet` | `HuggingFaceDatasetGetParquetTool` |
| `huggingface_dataset_get_rows` | `HuggingFaceDatasetGetRowsTool` |
| `huggingface_dataset_get_size` | `HuggingFaceDatasetGetSizeTool` |
| `huggingface_dataset_get_statistics` | `HuggingFaceDatasetGetStatisticsTool` |
| `huggingface_dataset_is_valid` | `HuggingFaceDatasetIsValidTool` |
| `huggingface_dataset_list_splits` | `HuggingFaceDatasetListSplitsTool` |
| `huggingface_dataset_preview_rows` | `HuggingFaceDatasetPreviewRowsTool` |
| `huggingface_dataset_search` | `HuggingFaceDatasetSearchTool` |
| `huggingface_recommended_datasets` | `HuggingFaceRecommendedDatasetsTool` |

Dependency: `HttpClientInterface` (bound). The token is optional — the public
datasets-server answers unauthenticated for public datasets; a token raises rate
limits.

## 3. The decision to make

The readiness vocabulary is closed (`tool-readiness.php` header):

| Value | Meaning | Served? |
|---|---|---|
| `ready` | real implementation, constructor satisfiable, **evidence recorded** | yes |
| `pure` | no I/O by nature | yes |
| `delegated` | the client performs the work | yes |
| `unverified` | constructible, no evidence | **no** |
| `quarantined` | describes work it does not perform | **no** |
| `unavailable` | needs a service this runtime does not bind | **no** |

Three defensible outcomes, and only three:

1. **`ready`** — record evidence (a probe against the API, or a `FakeHttpClient`
   fixture per tool) and register the eleven. This is the only option that makes a
   page honest, and it is a **network-implicating** decision: CI must not depend on
   datasets-server being up, so evidence must be fixtures plus one opt-in live probe.
2. **`unavailable`** — decide this runtime does not bind an HF service. Then
   `datasets` becomes a **new surface**, not a port, and the contract row changes to
   `absent` with that reason. No further work.
3. **`unverified`** — the status quo. It is not a decision; it is the default that
   keeps the page blocked.

Recommendation: **1**, because the tools are read-only and the API needs no credential —
but it needs the fixture work first, and that is the whole of the "unblock".

## 4. Acceptance criteria for the unblock

- [ ] All eleven slugs carry a hand-written `reason` (not the auditor's text).
- [ ] The chosen readiness value is uniform and justified once (family-wide).
- [ ] `php bin/audit-tool-readiness.php` passes.
- [ ] `ManifestToolRegistrar` registers the eleven; a test constructs each and asserts
      a typed result against a `FakeHttpClient` fixture.
- [ ] No test makes a live network call by default; a live probe is opt-in.
- [ ] The decision is recorded on §46 (and the contract row updated if the answer is
      `unavailable`).

## 5. What the page looks like *after* the unblock

Small (S): search + preview rows + copy-a-config affordance, over
`huggingface_recommended_datasets` / `_search` / `_preview_rows`. Contract row
`datasets`: `planned` → `full` or `read-only`, `writer: none` (browsing never writes).
The page is the cheap half; §35 was right that the blocker was never the screen.

## 6. Non-goals

- Dataset **download** quotas or a local dataset store. That needs a storage decision
  this project has not made.
- A token field on the page. If a token is wanted, it belongs in platform settings
  (`/ops/settings`, operator scope) with the existing secret handling.
