/**
 * CheckpointBar — Shows after agent edits with "Restore" and "Review Changes".
 * Zed equivalent: Restore Checkpoint button.
 */

export default function CheckpointBar({ checkpoint, onRestore, onReview }) {
	if (!checkpoint) return null;

	return (
		<div className="nvoos-checkpoint-bar">
			<span className="nvoos-checkpoint-bar__icon">&#128190;</span>
			<span className="nvoos-checkpoint-bar__label">
				{checkpoint.label || 'Checkpoint'}
			</span>
			<div className="nvoos-checkpoint-bar__actions">
				<button
					onClick={() => onReview?.(checkpoint.id)}
					className="nvoos-btn nvoos-btn--small"
				>
					Review Changes
				</button>
				<button
					onClick={() => onRestore?.(checkpoint.id)}
					className="nvoos-btn nvoos-btn--small nvoos-btn--danger"
				>
					Restore
				</button>
			</div>
		</div>
	);
}
