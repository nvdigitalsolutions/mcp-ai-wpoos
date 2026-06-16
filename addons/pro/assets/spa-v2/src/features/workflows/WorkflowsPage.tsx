/**
 * WorkflowsPage — Workflow builder placeholder.
 *
 * Displays a "Coming soon" message with a descriptive text about the
 * workflow builder feature that will allow users to compose multi-step
 * AI automation pipelines visually.
 */

import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { MarkdownContent } from '../../components/shared/MarkdownContent';

const COMING_SOON_CONTENT = `### ${ __( 'Workflow Builder', 'nvoos-pro-spa' ) }

${ __(
	'The Workflow Builder lets you create multi-step AI automation pipelines using a visual drag-and-drop interface. Chain tools, model calls, conditional logic, and human approval steps together to automate complex WordPress workflows.',
	'nvoos-pro-spa'
) }

**${ __( 'Planned features:', 'nvoos-pro-spa' ) }**

- ${ __( 'Visual drag-and-drop workflow canvas', 'nvoos-pro-spa' ) }
- ${ __( 'Multi-step tool chaining with data passthrough', 'nvoos-pro-spa' ) }
- ${ __( 'Conditional branching and loops', 'nvoos-pro-spa' ) }
- ${ __( 'Human-in-the-loop approval gates', 'nvoos-pro-spa' ) }
- ${ __( 'Workflow templates library', 'nvoos-pro-spa' ) }
- ${ __( 'Execution history and debugging', 'nvoos-pro-spa' ) }
- ${ __( 'Scheduled and event-triggered workflows', 'nvoos-pro-spa' ) }
`;

export function WorkflowsPage(): JSX.Element {
	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-workflows-page">
			<header className="nvoos-pro-spa-workflows-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Workflows', 'nvoos-pro-spa' ) }
				</h2>
			</header>

			<div
				className="nvoos-pro-spa-workflows-page__placeholder"
				role="status"
				aria-label={ __(
					'Workflow builder is coming soon',
					'nvoos-pro-spa'
				) }
			>
				<div className="nvoos-pro-spa-workflows-page__icon" aria-hidden="true">
					<svg
						width="64"
						height="64"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="1.5"
					>
						<rect
							x="3"
							y="3"
							width="7"
							height="7"
							rx="1"
						/>
						<rect
							x="14"
							y="3"
							width="7"
							height="7"
							rx="1"
						/>
						<rect
							x="8.5"
							y="14"
							width="7"
							height="7"
							rx="1"
						/>
						<path d="M10 10v3.5" />
						<path d="M17.5 6.5H14v6" />
					</svg>
				</div>

				<h3 className="nvoos-pro-spa-workflows-page__coming-soon">
					{ __( 'Coming Soon', 'nvoos-pro-spa' ) }
				</h3>

				<MarkdownContent
					content={ COMING_SOON_CONTENT }
					className="nvoos-pro-spa-workflows-page__description"
				/>

				<p className="nvoos-pro-spa-workflows-page__note">
					{ __(
						'We are actively building this feature. Stay tuned for updates!',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		</div>
	);
}
