// ---------------------------------------------------------------------------
// UiDialog.tsx — modal dialog island built on the Zag.js dialog state
// machine (@zag-js/dialog + @zag-js/react). Zag owns the hard a11y behavior:
// focus trap, Escape-to-close, backdrop click outside, aria-modal wiring.
// Styling is ours (ADS tokens via the page stylesheet) — the headless
// evaluation companion to the adapted Incluud components.
// ---------------------------------------------------------------------------

import { useEffect, useId, useState, type ReactNode } from 'react';
import * as dialog from '@zag-js/dialog';
import { normalizeProps, useMachine } from '@zag-js/react';

interface Props {
	triggerLabel?: string;
	title?: string;
	children?: ReactNode;
}

export default function UiDialog({ triggerLabel = 'Open dialog', title = 'Dialog', children }: Props) {
	const [open, setOpen] = useState(false);
	// Hydration marker (the repo convention) — see UiCombobox.
	const [hydrated, setHydrated] = useState(false);
	useEffect(() => {
		setHydrated(true);
	}, []);
	const service = useMachine(dialog.machine, {
		id: useId(),
		open,
		onOpenChange: (details: dialog.OpenChangeDetails) => setOpen(details.open),
	});
	const api = dialog.connect(service, normalizeProps);

	return (
		<>
			<button {...api.getTriggerProps()} className="btn" data-hydrated={hydrated ? '' : undefined}>
				{triggerLabel}
			</button>
			<div {...api.getBackdropProps()} className="ui-dialog-backdrop" />
			<div {...api.getPositionerProps()} className="ui-dialog-positioner">
				<div {...api.getContentProps()} className="ui-dialog-content" aria-label={title}>
					<h2 {...api.getTitleProps()} className="ui-dialog-title">
						{title}
					</h2>
					<div {...api.getDescriptionProps()} className="ui-dialog-description">
						{children ?? (
							<p>
								This dialog is rendered by the Zag.js dialog machine: focus is trapped
								while open, Escape and backdrop clicks close it, and focus returns to
								the trigger on close.
							</p>
						)}
					</div>
					<button {...api.getCloseTriggerProps()} className="btn">
						Close
					</button>
				</div>
			</div>
		</>
	);
}
