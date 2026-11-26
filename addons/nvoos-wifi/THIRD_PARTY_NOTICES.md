# Third-Party Notices — NV oOS WiFi

This addon ships **no third-party code**. It has no Composer runtime
dependencies beyond PHP itself, and no npm runtime dependencies.

What follows records (a) the external work this design *learned from*, and
(b) the licences that constrain what this addon may ever bundle.

---

## Adopted patterns (no code copied)

| Project | Licence | What was adopted |
|---|---|---|
| [`@subashgautam/router-mcp`](https://www.npmjs.com/package/@subashgautam/router-mcp) | MIT | The **adapter + mock backend** structure. Our `Class_Mock_Controller_Adapter` follows the same idea so tests, demos and CI never need hardware. |
| [`@ruvnet/rvagent`](https://www.npmjs.com/package/@ruvnet/rvagent) | Apache-2.0 | Dual-transport MCP (stdio + Streamable HTTP) and evidence-gated outputs, both of which inform the Phase 3 cognition contract. |
| [`@ruvnet/ruview`](https://www.npmjs.com/package/@ruvnet/ruview) | MIT | The evidence-gated sensing concept. Planned as a Phase 4 **integration** target, not a replacement. |

No source code from these projects is present in this addon. They are listed
because the architectural debt is real and should be traceable.

## Standards and specifications referenced

| Specification | Constraint on this addon |
|---|---|
| IEEE 802.11bh-2024 | Device ID is the identity primitive; MAC addresses are not identity. |
| IEEE 802.11bi-2026 | Minimise collection at the radio layer. |
| IEEE 802.11bf-2025 | Sensing measurement is standardised; inference is ours and must be labelled. |
| IETF RFC 8952 / 8908 / 8910 (CAPPORT) | Portal API shape and DHCP/RA identification (Phase 1). |
| IETF RFC 9724 / 9797 (RCM) | Randomised MAC addresses are the norm, not an edge case. |
| Wi-Fi Alliance Data Elements v2.1 | The KPI vocabulary is modelled on these; `de_name` is the export name. |
| Jamaica Data Protection Act 2020 | Per-purpose consent, withdrawal, cross-border handling. |

## ⚠️ Licence boundary — do not bundle

The following are **GPL-2.0**. They may be invoked as separate processes only and
must never be `require`d or `include`d into an NV oOS WiFi bundle:

| Project | Licence | Risk |
|---|---|---|
| `listwificlients` | GPL-2.0 | Strong copyleft |
| `ubus-websocket-communicator` | GPL-2.0 | Strong copyleft |
| `iopsys-ws-communicator` | GPL-2.0 | Strong copyleft |

This matters because the hostapd/ubus tooling that would make OpenWrt support
possible sits in GPL-2.0 territory. The **process boundary** is what keeps the
addon's licensing options open.

Enforcement: Phase 2 adds a test that greps for `require`/`include` of these paths.

## Services

| Service | Relationship |
|---|---|
| FreeRADIUS | **External authority.** NV oOS WiFi configures and observes RADIUS; it never implements 802.1X/EAP itself. |

## Red-team tooling

[`@adams-404/ghostlink`](https://www.npmjs.com/package/@adams-404/ghostlink) is an
attack tool. It is named here because Phase 1 uses it to derive a **test suite**
(walled-garden escape, session inheritance, DNS leak). It is not a dependency and
no part of it ships in this addon.
