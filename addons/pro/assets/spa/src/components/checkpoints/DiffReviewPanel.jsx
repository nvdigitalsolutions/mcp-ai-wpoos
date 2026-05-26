/**
 * DiffReviewPanel — Accept/reject individual change hunks.
 * Zed equivalent: Multi-buffer diff review.
 */

export default function DiffReviewPanel({ diff, onClose }) {
	if (!diff?.changes || diff.changes.length === 0) {
		return (
			<div className="nvoos-diff-panel">
				<div className="nvoos-diff-panel__header">
					<h3>Review Changes</h3>
					<button onClick={onClose} className="nvoos-btn nvoos-btn--icon">&times;</button>
				</div>
				<p className="nvoos-diff-panel__empty">No changes detected.</p>
			</div>
		);
	}

	return (
		<div className="nvoos-diff-panel">
			<div className="nvoos-diff-panel__header">
				<h3>Review Changes ({diff.changes.length} {diff.changes.length === 1 ? 'change' : 'changes'})</h3>
				<button onClick={onClose} className="nvoos-btn nvoos-btn--icon">&times;</button>
			</div>
			<div className="nvoos-diff-panel__list">
				{diff.changes.map((change, i) => (
					<div key={i} className="nvoos-diff-panel__hunk">
						<div className="nvoos-diff-panel__hunk-header">
							<span className="nvoos-diff-panel__hunk-type">{change.type}</span>
							<span className="nvoos-diff-panel__hunk-name">{change.name || change.id}</span>
						</div>
						{change.before !== change.after && (
							<div className="nvoos-diff-panel__hunk-diff">
								<div className="nvoos-diff-panel__hunk-before">
									<strong>Before:</strong>
									<pre>{JSON.stringify(change.before, null, 2)}</pre>
								</div>
								<div className="nvoos-diff-panel__hunk-after">
									<strong>After:</strong>
									<pre>{JSON.stringify(change.after, null, 2)}</pre>
								</div>
							</div>
						)}
					</div>
				))}
			</div>
		</div>
	);
}
