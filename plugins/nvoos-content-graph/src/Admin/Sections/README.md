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
| `NvoosContentGraph\Admin\Sections\GeneralSection` | `GeneralSection.php` | `SettingsPage` |
| `NvoosContentGraph\Admin\Sections\BuildSection` | `BuildSection.php` | `SettingsPage` |
| `NvoosContentGraph\Admin\Sections\EmbeddingsSection` | `EmbeddingsSection.php` | `SettingsPage` |
| `NvoosContentGraph\Admin\Sections\ContentSection` | `ContentSection.php` | `SettingsPage` |
| `NvoosContentGraph\Admin\Sections\ExportSection` | `ExportSection.php` | `SettingsPage` |
| `NvoosContentGraph\Admin\Sections\AnalysisSection` | `AnalysisSection.php` | `SettingsPage` |
| `NvoosContentGraph\Admin\Sections\DisplaySection` | `DisplaySection.php` | `SettingsPage` |

## Neighbors

- Parent: [`../`](../) — Admin directory
- Collaborators: [`SettingsPage.php`](../SettingsPage.php)
