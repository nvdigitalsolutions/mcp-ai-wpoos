// ---------------------------------------------------------------------------
// UiCombobox.tsx — autocomplete combobox island built on the Zag.js combobox
// state machine (@zag-js/combobox + @zag-js/react). Zag owns the a11y
// behavior: listbox ARIA, arrow-key highlighting, Enter selection, live
// region announcements. Styling is ours (ADS tokens) — headless evaluation.
// ---------------------------------------------------------------------------

import { useEffect, useId, useState } from 'react';
import * as combobox from '@zag-js/combobox';
import { normalizeProps, useMachine } from '@zag-js/react';

const ALL_OPTIONS = ['Acacia', 'Maple', 'Oak', 'Teak', 'Walnut'];

interface Props {
	label?: string;
	placeholder?: string;
}

export default function UiCombobox({ label = 'Wood', placeholder = 'Choose a wood…' }: Props) {
	const [items, setItems] = useState(ALL_OPTIONS);
	// The repo's hydration marker convention (HomeStats/StackExplorer): tests
	// and scripts wait on data-hydrated before interacting, so an input event
	// can never land on an un-hydrated controlled input under load.
	const [hydrated, setHydrated] = useState(false);
	useEffect(() => {
		setHydrated(true);
	}, []);
	const service = useMachine(combobox.machine, {
		id: useId(),
		// The composite (roving-tabindex) default omits role="listbox" from
		// the list — axe rejects option-role items without a listbox parent.
		// Classic listbox ARIA (composite: false) is the a11y-clean shape.
		composite: false,
		collection: combobox.collection({ items }),
		onInputValueChange: ({ inputValue }: combobox.InputValueChangeDetails) => {
			const needle = inputValue.toLowerCase();
			setItems(ALL_OPTIONS.filter((option) => option.toLowerCase().includes(needle)));
		},
	});
	const api = combobox.connect(service, normalizeProps);

	return (
		<div {...api.getRootProps()} className="ui-combobox" data-hydrated={hydrated ? '' : undefined}>
			<label {...api.getLabelProps()} className="ui-combobox-label">
				{label}
			</label>
			<div {...api.getControlProps()} className="ui-combobox-control">
				<input {...api.getInputProps()} placeholder={placeholder} className="ui-combobox-input" />
				<button {...api.getTriggerProps()} className="ui-combobox-trigger" aria-hidden="true">
					▾
				</button>
			</div>
			<div {...api.getPositionerProps()} className="ui-combobox-positioner">
				<ul {...api.getListProps()} className="ui-combobox-list">
					{items.map((item) => (
						<li key={item} {...api.getItemProps({ item })} className="ui-combobox-item">
							{item}
						</li>
					))}
				</ul>
			</div>
		</div>
	);
}
