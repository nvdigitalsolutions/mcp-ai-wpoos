/**
 * AssistantSelector — Dropdown to pick which assistant handles the
 * current conversation. Scopes both the conversations sidebar and
 * the chat transport to the selected assistant.
 */

import { useSettingsStore } from '../../store/settingsStore';

// Sourced from the bootstrap data — the settings store holds the
// list of available assistants under settings.assistants (if provided
// by the server), or we fall back to a static label.
export default function AssistantSelector() {
	const { settings, selectedAssistantId, setAssistantId } = useSettingsStore();
	const assistants = settings?.assistants || [];
	const userAssistantId = settings?.user?.assistant_id || 0;

	if (assistants.length === 0) {
		return null;
	}

	return (
		<div className="nvoos-profile-selector">
			<select
				value={selectedAssistantId || userAssistantId || 0}
				onChange={(e) => setAssistantId(Number(e.target.value))}
				className="nvoos-profile-selector__select"
				aria-label="Select assistant"
			>
				{assistants.map((a) => (
					<option key={a.id || a.ID} value={a.id || a.ID}>
						{a.name || a.post_title || `Assistant #${a.id || a.ID}`}
					</option>
				))}
			</select>
		</div>
	);
}
