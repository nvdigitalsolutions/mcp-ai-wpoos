# EU AI Act Compliance Mapping

**Version:** 1.1.51
**Last Updated:** August 11, 2026
**Status:** Self-assessment — not a legal certification
**Regulation:** EU AI Act (Regulation 2024/1689), high-risk obligations effective August 2, 2026

## Overview

This document maps NV oOS features to EU AI Act requirements for high-risk AI systems. NV oOS is an AI orchestration platform — the compliance burden for specific use cases rests with the deployer, not the platform. However, the platform provides the infrastructure needed for deployers to meet their obligations.

## Article 13 — Transparency & Provision of Information

> "High-risk AI systems shall be designed and developed in such a way... that their operation is sufficiently transparent."

| Requirement | NV oOS Implementation | Status |
|---|---|---|
| Users informed they are interacting with AI | Chat transparency badges showing model name + provider | ✅ |
| AI-generated content labeled | `chat-transparency.css` visual indicators, SGI compliance infrastructure | ✅ |
| Model capabilities & limitations disclosed | Assistant system prompts support transparency instructions | ✅ |
| Provider identity disclosed | All AI responses include provider metadata | ✅ |
| Decision provenance traceable | Audit logger records model, version, timestamp per interaction | ✅ |

## Article 14 — Human Oversight

> "High-risk AI systems shall be designed... to be effectively overseen by natural persons."

| Requirement | NV oOS Implementation | Status |
|---|---|---|
| Human can intervene | HITL Approval Queue with REST API, admin UI, CLI | ✅ |
| Human can override AI decisions | `WP_MCP_AI_Approval_Queue` supports approve/reject/modify | ✅ |
| Human can stop system | Necessity Gate escalation after 3 consecutive / 20 total denials | ✅ |
| Oversight proportional to risk | Destructive Ops Gate gates irreversible operations behind confirmation | ✅ |
| "Human-on-the-loop" capability | Background job monitoring with inline-async-tick consumers | ✅ |

## Article 15 — Accuracy, Robustness & Cybersecurity

> "High-risk AI systems shall... achieve an appropriate level of accuracy, robustness, and cybersecurity."

| Requirement | NV oOS Implementation | Status |
|---|---|---|
| Appropriate accuracy | Retrieval Harness with citation requirements; self-refine loop | ✅ |
| Resilience to errors | Dead Letter Queue with retry/dismiss; Circuit Breaker (Pro) | ✅ |
| Resilience to manipulation | Guardrails (Layer I) — prompt injection, jailbreak, off-topic detection | ⚠️ Input only |
| Fallback plans | Exponential backoff in Rate Limit Manager; model fallback in ProviderRouter | ✅ |
| Cybersecurity | 10 security classes, 21 posture signals A-F, CSP headers, API key encryption | ✅ |
| Adversarial testing | Automated red teaming infrastructure (v1.1.51 — `composer run security:red-team`) | ✅ NEW |
| Output validation | Output guardrail for sensitive info, unsafe content (v1.1.51) | ✅ NEW |

## Article 50 — Transparency for Certain AI Systems

> "Providers shall ensure that AI systems intended to interact with natural persons are designed and developed in such a way that natural persons are informed that they are interacting with an AI system."

| Requirement | NV oOS Implementation | Status |
|---|---|---|
| AI interaction disclosure | Guest token consent gate on public chat surfaces | ✅ |
| AI-generated content labeling | Chat transparency CSS + metadata | ✅ |
| Deep fake / synthetic media labeling | C2PA placeholder infrastructure (v1.1.51 roadmap) | ⚠️ Roadmap |
| Emotion recognition disclosure | Not applicable (NV oOS does not perform emotion recognition) | N/A |
| Biometric categorization disclosure | Not applicable | N/A |

## Article 52 — High-Risk AI System Classification

NV oOS is a **general-purpose AI orchestration platform**, not a specific high-risk AI system. However, it provides the infrastructure to support deployers whose use cases may fall under high-risk categories:

| High-Risk Area | NV oOS Support |
|---|---|
| Education / vocational training | Profession system, curriculum export (Layer H) |
| Employment / worker management | CRM toolkit, team orchestration |
| Essential services (healthcare) | OpenMed healthcare tools, DICOM PHI auto-redaction |
| Law enforcement | Not intended for this use |
| Migration / border control | Not intended for this use |
| Democratic processes | Not intended for this use |

## Gap Remediation Roadmap

| Gap | Status | Target |
|---|---|---|
| Output content safety (input-only guardrails) | ✅ Fixed in v1.1.51 | Done |
| Automated adversarial testing | ✅ Fixed in v1.1.51 | Done |
| C2PA content provenance | ⚠️ Placeholder created | v1.1.52 |
| Model supply chain verification | ✅ Fixed in v1.1.51 | Done |
| Risk classification framework | ⚠️ Documentation placeholder | v1.1.52 |

## References

- [EU AI Act full text](https://eur-lex.europa.eu/eli/reg/2024/1689)
- [SGI Transparency & Compliance](../features/sgi-transparency-compliance.md)
- [Security Posture](../operations/security/SECURITY_POSTURE.md)
- [Compliance Traceability](../operations/compliance/TRACEABILITY.md)
- [Orchestration & Harness Reference](../reference/orchestration/orchestration-harness-reference.md)
