/**
 * Pro SPA v2 — CheckpointBar.
 *
 * Shows after agent edits with "Restore" and "Review Changes" buttons.
 * Renders nothing when no checkpoint is active.
 *
 * Accessibility:
 *   - Buttons carry explicit aria-labels that include the checkpoint label.
 *   - The bar container uses role="status" for live-region announcement.
 *
 * @since 2.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX } from 'react';

export interface Checkpoint {
	id: string | number;
	label?: string;
}

export interface CheckpointBarProps {
	checkpoint: Checkpoint | null;
	onRestore?: ( id: string | number ) => void;
	onReview?: ( id: string | number ) => void;
}

/**
 * Displays a bar with Restore and Review Changes actions for a checkpoint.
 *
 * @param props - Component properties.
 * @param props.checkpoint - The checkpoint to display, or null.
 * @param props.onRestore - Callback to restore the checkpoint.
 * @param props.onReview  - Callback to review changes in the checkpoint.
 *
 * @returns The rendered component, or null if no checkpoint.
 */
export function CheckpointBar( {
	checkpoint,
	onRestore,
	onReview,
}: CheckpointBarProps ): JSX.Element | null {
	if ( ! checkpoint ) {
		return null;
	}

	const label = checkpoint.label || __( 'Checkpoint', 'nvoos-pro-spa' );

	return (
		<div className="nvoos-pro-spa-checkpoint-bar" role="status">
			<span className="nvoos-pro-spa-checkpoint-bar__icon" aria-hidden="true">
				&#128190;
			</span>
			<span className="nvoos-pro-spa-checkpoint-bar__label">{ label }</span>
			<div className="nvoos-pro-spa-checkpoint-bar__actions">
				<button
					type="button"
					onClick={ () => onReview?.( checkpoint.id ) }
					className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
					aria-label={ sprintf(
						/* translators: %s: checkpoint label */
						__( 'Review changes for %s', 'nvoos-pro-spa' ),
						label
					) }
				>
					{ __( 'Review Changes', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					onClick={ () => onRestore?.( checkpoint.id ) }
					className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small nvoos-pro-spa-btn--danger"
					aria-label={ sprintf(
						/* translators: %s: checkpoint label */
						__( 'Restore %s', 'nvoos-pro-spa' ),
						label
					) }
				>
					{ __( 'Restore', 'nvoos-pro-spa' ) }
				</button>
			</div>
		</div>
	);
}
