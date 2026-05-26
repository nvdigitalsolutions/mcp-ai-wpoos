/**
 * ModelSelector — Provider + model dropdown with favorites support.
 * Zed equivalent: Model selector in Agent Panel.
 */

import { useState } from '@wordpress/element';

// Hardcoded model list — will be replaced by dynamic API in Phase 8.
const DEFAULT_MODELS = [
	{ provider: 'openai', model: 'gpt-4o', label: 'GPT-4o' },
	{ provider: 'openai', model: 'gpt-4o-mini', label: 'GPT-4o Mini' },
	{ provider: 'anthropic', model: 'claude-sonnet-4-5', label: 'Claude Sonnet 4.5' },
	{ provider: 'google', model: 'gemini-2.5-pro', label: 'Gemini 2.5 Pro' },
	{ provider: 'google', model: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' },
];

export default function ModelSelector({ value, onChange }) {
	const [selected, setSelected] = useState(value || DEFAULT_MODELS[0]);

	const handleChange = (e) => {
		const key = e.target.value;
		const [provider, model] = key.split('|');
		const item = { provider, model };
		setSelected(item);
		onChange?.(item);
	};

	const selectedKey = `${selected.provider}|${selected.model}`;

	return (
		<div className="nvoos-model-selector">
			<select
				value={selectedKey}
				onChange={handleChange}
				className="nvoos-model-selector__select"
			>
				{DEFAULT_MODELS.map((m) => (
					<option key={`${m.provider}|${m.model}`} value={`${m.provider}|${m.model}`}>
						{m.label}
					</option>
				))}
			</select>
		</div>
	);
}
