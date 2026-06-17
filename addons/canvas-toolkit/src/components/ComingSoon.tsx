/**
 * NV oOS Canvas Toolkit — "Coming soon" stub for unfinished modes.
 *
 * Renders a friendly message for canvas modes that have been declared in
 * the shortcode contract but whose implementation is deferred to a
 * follow-up PR. This lets the manifest / shortcode surface stay stable
 * while individual modes ship incrementally.
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
		<div className="nvoos-canvas-toolkit-coming-soon" data-mode={ mode } role="status">
			<p>
				<strong>{ label }</strong> { __( '— coming soon.', 'nvoos-canvas-toolkit' ) }
			</p>
			<p>{ note }</p>
		</div>
	);
}
