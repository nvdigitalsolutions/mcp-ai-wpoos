/**
 * Callout — styled alert block for note/tip/warning/danger directives.
 *
 * Consumed by the `:::note`, `:::tip`, `:::warning`, `:::danger` remark
 * directive syntax which remark-directive parses into container directives
 * where `node.name` is the directive label.
 *
 * @since 1.0.0
 */

import type { ReactNode } from 'react';

type CalloutVariant = 'note' | 'tip' | 'warning' | 'danger';

interface CalloutProps {
	variant?: CalloutVariant;
	title?: string;
	children?: ReactNode;
}

const ICONS: Record<CalloutVariant, string> = {
	note: 'ℹ️',
	tip: '💡',
	warning: '⚠️',
	danger: '🚨',
};

const LABELS: Record<CalloutVariant, string> = {
	note: 'Note',
	tip: 'Tip',
	warning: 'Warning',
	danger: 'Danger',
};

function isValidVariant( v: string ): v is CalloutVariant {
	return [ 'note', 'tip', 'warning', 'danger' ].indexOf( v ) !== -1;
}

export default function Callout( { variant = 'note', title, children }: CalloutProps ) {
	const safeVariant: CalloutVariant = isValidVariant( variant ) ? variant : 'note';
	const icon = ICONS[ safeVariant ];
	const defaultTitle = LABELS[ safeVariant ];

	return (
		<div
			className={ `dh-callout dh-callout-${ safeVariant }` }
			role="note"
			aria-label={ `${ defaultTitle } callout` }
		>
			<div className="dh-callout-header">
				<span role="img" aria-hidden="true">{ icon }</span>
				{ title ?? defaultTitle }
			</div>
			<div className="dh-callout-body">
				{ children }
			</div>
		</div>
	);
}
