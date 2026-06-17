/**
 * ThreadsSidebar — Left sidebar with agent threads grouped by scope.
 *
 * Zed equivalent: Threads Sidebar.
 */

import { useNavigate, useParams } from 'react-router-dom';
import { useThreadsStore } from '../../store/threadsStore';
import { useModelStore } from '../../store/modelStore';
import { useProfilesStore } from '../../store/profilesStore';

export default function ThreadsSidebar() {
	const navigate = useNavigate();
	const { threadId } = useParams();
	const { threads, activeThreadId, setActiveThread, createThread, archiveThread } = useThreadsStore();
	const { model } = useModelStore();
	const { activeProfile } = useProfilesStore();

	const handleSelect = (id) => {
		setActiveThread(id);
		navigate(`/chat/${id}`);
	};

	const handleNew = async () => {
		try {
			const thread = await createThread(0, model, activeProfile, {});
			navigate(`/chat/${thread.id}`);
		} catch (err) {
			// Error displayed via toast or inline.
		}
	};

	// Group by scope.
	const groups = {};
	threads.forEach((t) => {
		const scope = t.scope_type || 'General';
		if (!groups[scope]) groups[scope] = [];
		groups[scope].push(t);
	});

	return (
		<aside className="nvoos-threads-sidebar">
			<div className="nvoos-threads-sidebar__header">
				<h2>Threads</h2>
				<button onClick={handleNew} className="nvoos-btn nvoos-btn--icon" title="New Thread">+</button>
			</div>

			<div className="nvoos-threads-sidebar__list">
				{Object.entries(groups).map(([scope, scopeThreads]) => (
					<div key={scope} className="nvoos-threads-sidebar__group">
						<h3 className="nvoos-threads-sidebar__group-title">{scope}</h3>
						{scopeThreads.map((thread) => (
							<div
								key={thread.id}
								className={`nvoos-threads-sidebar__item ${thread.id === activeThreadId ? 'nvoos-threads-sidebar__item--active' : ''}`}
								onClick={() => handleSelect(thread.id)}
								role="button"
								tabIndex={0}
								onKeyDown={(e) => e.key === 'Enter' && handleSelect(thread.id)}
							>
								<span className="nvoos-threads-sidebar__item-status" data-status={thread.status} />
								<span className="nvoos-threads-sidebar__item-title">{thread.title}</span>
								<span className="nvoos-threads-sidebar__item-model">{thread.model_name || 'Default'}</span>
							</div>
						))}
					</div>
				))}

				{threads.length === 0 && (
					<div className="nvoos-threads-sidebar__empty">
						<p>No threads yet.</p>
						<button onClick={handleNew} className="nvoos-btn nvoos-btn--primary">New Thread</button>
					</div>
				)}
			</div>
		</aside>
	);
}
