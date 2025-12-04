# Profession Knowledge Base Review

## What was reviewed
- Seeded JSON knowledge base in `includes/knowledge-base/professions/*.json`
- Focus on ensuring assistants have clear safety, privacy, and compliance guidance

## Gaps identified
- Cross-profession safety guidance is scattered across individual roles and lacks a centralized set of guardrails
- Privacy handling and data minimization reminders are limited, leaving assistants without explicit prompts to avoid collecting sensitive information
- Escalation guidance for crisis or emergency cues is minimal, creating risk for mishandling safety-critical conversations

## Enhancements added
- Added `includes/knowledge-base/professions/safety-guardrails.json` with two reusable guardrail-focused roles:
  - **Safety & Compliance Advisor**: refusal patterns, escalation steps, and transparent capability limits
  - **Data Privacy Steward**: data minimization, secret handling, and privacy regulation reminders
- Guardrail knowledge bases emphasize refusal language, safer alternatives, and when to direct users to licensed professionals or emergency services

## Next steps to deepen coverage
- Thread guardrail roles into preset configurations or templates so assistants inherit the safety defaults automatically
- Add more domain-specific safety notes (e.g., child safety, security testing boundaries, content moderation standards)
- Periodically audit new profession entries to ensure they include privacy prompts, escalation language, and disclaimers about licensure
