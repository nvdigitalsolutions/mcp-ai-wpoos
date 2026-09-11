/**
 * NV oOS Comic Reader — Help Dialog
 *
 * Lists the reader's keyboard shortcuts (Komga's context-aware help dialog;
 * this reader shows the full set).
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

import { useEffect, useRef } from 'react';
import { t } from '../utils/i18n';

interface HelpDialogProps {
	onClose: () => void;
}

const SHORTCUTS: Array<{ keys: string; labelKey: string }> = [
	{ keys: '← / →', labelKey: 'previousPage' },
	{ keys: 'A / D', labelKey: 'nextPage' },
	{ keys: '+ / −', labelKey: 'zoomIn' },
	{ keys: 'W', labelKey: 'fitWidth' },
	{ keys: 'H', labelKey: 'fitHeight' },
	{ keys: 'P', labelKey: 'doublePage' },
	{ keys: 'R', labelKey: 'readingLtr' },
	{ keys: 'G', labelKey: 'thumbnails' },
	{ keys: 'F', labelKey: 'fullscreen' },
	{ keys: '?', labelKey: 'help' },
];

export function HelpDialog({ onClose }: HelpDialogProps) {
	const dialogRef = useRef<HTMLDivElement>(null);

	useEffect(() => {
		const previouslyFocused = document.activeElement as HTMLElement | null;
		dialogRef.current?.focus();

		const handleKeyDown = (e: KeyboardEvent) => {
			if (e.key === 'Escape') {
				e.preventDefault();
				onClose();
			}
		};
		document.addEventListener('keydown', handleKeyDown);

		return () => {
			document.removeEventListener('keydown', handleKeyDown);
			previouslyFocused?.focus();
		};
	}, [onClose]);

	return (
		<div className="nvoos-cr-overlay" role="dialog" aria-modal="true" aria-label={t('help')}>
			<div className="nvoos-cr-overlay-header">
				<h2 className="nvoos-cr-overlay-title">{t('help')}</h2>
				<button className="nvoos-cr-btn" onClick={onClose} aria-label={t('close')}>
					×
				</button>
			</div>
			<div className="nvoos-cr-help" ref={dialogRef} tabIndex={-1}>
				{SHORTCUTS.map((shortcut) => (
					<div className="nvoos-cr-help-row" key={shortcut.keys}>
						<kbd className="nvoos-cr-help-keys">{shortcut.keys}</kbd>
						<span>{t(shortcut.labelKey)}</span>
					</div>
				))}
			</div>
		</div>
	);
}
