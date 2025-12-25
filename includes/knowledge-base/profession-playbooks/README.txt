Profession Playbooks (Authorable Base Knowledge)

This folder is intended to be edited by humans. The plugin can assemble a profession’s Base Knowledge
from three layers:


Author: NV Digital Solutions
Website: https://nvdigitalsolutions.com

1) global.txt
2) categories/<category>.txt
3) professions/<slug>.txt

Editing workflow
- Improve a profession by editing professions/<slug>.txt
- Improve an entire category by editing categories/<category>.txt
- Improve everything by editing global.txt
- Then run the plugin’s “Sync Profession Playbooks” (or the reseed/sync routine) to regenerate attachments.

Guidelines
- Avoid duplicating the JSON seed metadata (title, expertise list, etc.).
- Add concrete SOPs: intake questions, workflows, checklists, templates, and examples.
- Include “red flags” and escalation triggers.
- Keep the playbook stable and deterministic; prefer evergreen guidance over news.

File naming
- Profession files must match the profession slug exactly: professions/<slug>.txt
- Category files must match the stored category: categories/<category>.txt
