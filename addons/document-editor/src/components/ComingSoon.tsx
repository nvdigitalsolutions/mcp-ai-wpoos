/**
 * NV oOS Document Editor — "Coming soon" stub for unfinished modes.
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';

interface ComingSoonProps {
	mode: string;
	label: string;
	note: string;
}

export function ComingSoon( { mode, label, note }: ComingSoonProps ) {
	return (
		<div className="nvoos-de-coming-soon" data-mode={ mode } role="status">
			<p>
				<strong>{ label }</strong> { __( '— coming soon.', 'nvoos-document-editor' ) }
			</p>
			<p>{ note }</p>
		</div>
	);
}
