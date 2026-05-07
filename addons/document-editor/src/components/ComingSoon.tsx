/**
 * NV oOS Document Editor — "Coming soon" stub for unfinished modes.
 *
 * @since 0.1.0
 */

interface ComingSoonProps {
	mode: string;
	label: string;
	note: string;
}

export function ComingSoon( { mode, label, note }: ComingSoonProps ) {
	return (
		<div className="nvoos-de-coming-soon" data-mode={ mode } role="status">
			<p>
				<strong>{ label }</strong> — coming soon.
			</p>
			<p>{ note }</p>
		</div>
	);
}
