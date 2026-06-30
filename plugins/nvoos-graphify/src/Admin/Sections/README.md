# Admin Sections

## Purpose

Settings-page section renderers — each section is a self-contained class registered via `SettingsPage::addSection()`. Each section declares its tab label, renders its admin UI, and (optionally) enqueues section-specific assets.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Admin\Sections\GeneralSection` | `GeneralSection.php` | `SettingsPage` |
| `NvoosGraphify\Admin\Sections\BuildSection` | `BuildSection.php` | `SettingsPage` |
| `NvoosGraphify\Admin\Sections\EmbeddingsSection` | `EmbeddingsSection.php` | `SettingsPage` |
| `NvoosGraphify\Admin\Sections\ContentSection` | `ContentSection.php` | `SettingsPage` |
| `NvoosGraphify\Admin\Sections\ExportSection` | `ExportSection.php` | `SettingsPage` |
| `NvoosGraphify\Admin\Sections\AnalysisSection` | `AnalysisSection.php` | `SettingsPage` |
| `NvoosGraphify\Admin\Sections\DisplaySection` | `DisplaySection.php` | `SettingsPage` |

## Neighbors

- Parent: [`../`](../) — Admin directory
- Collaborators: [`SettingsPage.php`](../SettingsPage.php)
