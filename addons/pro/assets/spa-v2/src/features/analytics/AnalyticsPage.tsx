/**
 * AnalyticsPage — Usage analytics placeholder.
 *
 * Displays a "Coming soon" message with a description of the upcoming
 * usage tracking dashboard that will show API consumption, tool usage
 * statistics, cost estimates, and performance metrics.
 */

import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { MarkdownContent } from '../../components/shared/MarkdownContent';

const COMING_SOON_CONTENT = `### ${ __( 'Usage Analytics', 'nvoos-pro-spa' ) }

${ __(
	'The Analytics dashboard provides insights into your AI assistant usage. Track API consumption across providers, monitor tool execution patterns, review cost estimates, and measure response quality over time.',
	'nvoos-pro-spa'
) }

**${ __( 'Planned features:', 'nvoos-pro-spa' ) }**

- ${ __( 'API call volume and token usage charts', 'nvoos-pro-spa' ) }
- ${ __( 'Per-provider cost estimation and billing alerts', 'nvoos-pro-spa' ) }
- ${ __( 'Tool usage heatmaps and frequency analysis', 'nvoos-pro-spa' ) }
- ${ __( 'Response latency and error rate monitoring', 'nvoos-pro-spa' ) }
- ${ __( 'User activity logs and session replays', 'nvoos-pro-spa' ) }
- ${ __( 'Exportable reports (CSV, PDF)', 'nvoos-pro-spa' ) }
- ${ __( 'Configurable date ranges and filters', 'nvoos-pro-spa' ) }
`;

export function AnalyticsPage(): JSX.Element {
	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-analytics-page">
			<header className="nvoos-pro-spa-analytics-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Analytics', 'nvoos-pro-spa' ) }
				</h2>
			</header>

			<div
				className="nvoos-pro-spa-analytics-page__placeholder"
				role="status"
				aria-label={ __(
					'Analytics dashboard is coming soon',
					'nvoos-pro-spa'
				) }
			>
				<div className="nvoos-pro-spa-analytics-page__icon" aria-hidden="true">
					<svg
						width="64"
						height="64"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="1.5"
					>
						<path d="M3 20h18" />
						<rect
							x="5"
							y="10"
							width="3"
							height="10"
							rx="0.5"
						/>
						<rect
							x="10.5"
							y="6"
							width="3"
							height="14"
							rx="0.5"
						/>
						<rect
							x="16"
							y="2"
							width="3"
							height="18"
							rx="0.5"
						/>
					</svg>
				</div>

				<h3 className="nvoos-pro-spa-analytics-page__coming-soon">
					{ __( 'Coming Soon', 'nvoos-pro-spa' ) }
				</h3>

				<MarkdownContent
					content={ COMING_SOON_CONTENT }
					className="nvoos-pro-spa-analytics-page__description"
				/>

				<p className="nvoos-pro-spa-analytics-page__note">
					{ __(
						'We are actively building this feature. Stay tuned for updates!',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		</div>
	);
}
