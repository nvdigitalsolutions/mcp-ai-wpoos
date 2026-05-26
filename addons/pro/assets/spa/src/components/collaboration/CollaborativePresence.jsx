/**
 * CollaborativePresence — Shows other users editing the same content.
 */

import { useState, useEffect } from '@wordpress/element';
import { apiPost } from '../../services/api';

export default function CollaborativePresence({ postId, threadId }) {
	const [presence, setPresence] = useState([]);
	const [active, setActive] = useState(false);

	useEffect(() => {
		if (!postId) return;

		let interval;
		const updatePresence = async () => {
			try {
				const res = await apiPost('/mcp-ai-pro/v1/collaboration/presence', {
					post_id: postId,
					thread_id: threadId,
					activity: 'active',
				});
				if (res.success && res.data?.presence) {
					const others = res.data.presence.filter(
						(u) => u.user_id !== window.wpMcpAiPro?.userId
					);
					setPresence(others);
					setActive(others.length > 0);
				}
			} catch { /* non-critical */ }
		};

		updatePresence();
		interval = setInterval(updatePresence, 15000);
		return () => clearInterval(interval);
	}, [postId, threadId]);

	if (!active || presence.length === 0) return null;

	return (
		<div className="nvoos-collab-presence">
			<div className="nvoos-collab-presence__avatars">
				{presence.slice(0, 5).map((user) => (
					<img key={user.user_id} src={user.avatar_url} alt={user.display_name}
						className="nvoos-collab-presence__avatar"
						title={`${user.display_name} — ${user.activity}`} />
				))}
				{presence.length > 5 && (
					<span className="nvoos-collab-presence__more">+{presence.length - 5}</span>
				)}
			</div>
			<span className="nvoos-collab-presence__label">
				{presence.length === 1 ? `${presence[0].display_name} is also here` : `${presence.length} others here`}
			</span>
		</div>
	);
}
