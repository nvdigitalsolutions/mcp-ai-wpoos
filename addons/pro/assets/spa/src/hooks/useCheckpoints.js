/**
 * useCheckpoints — Hook for checkpoint operations.
 */

import { useState, useCallback } from '@wordpress/element';
import { apiGet, apiPost } from '../services/api';

export function useCheckpoints(threadId) {
	const [checkpoints, setCheckpoints] = useState([]);
	const [lastCheckpoint, setLastCheckpoint] = useState(null);
	const [diff, setDiff] = useState(null);
	const [loading, setLoading] = useState(false);

	const fetchCheckpoints = useCallback(async () => {
		if (!threadId) return;
		setLoading(true);
		try {
			const res = await apiGet(`/mcp-ai/v1/threads/${threadId}/checkpoints`);
			if (res.success && res.data?.checkpoints) {
				setCheckpoints(res.data.checkpoints);
				setLastCheckpoint(res.data.checkpoints[0] || null);
			}
		} catch (err) {
			/* silent */
		} finally {
			setLoading(false);
		}
	}, [threadId]);

	const createCheckpoint = useCallback(async (affectedIds = [], label = '') => {
		if (!threadId) return;
		const res = await apiPost(`/mcp-ai/v1/threads/${threadId}/checkpoints`, {
			affected_ids: affectedIds,
			label,
		});
		if (res.success) {
			fetchCheckpoints();
		}
		return res;
	}, [threadId, fetchCheckpoints]);

	const restoreCheckpoint = useCallback(async (checkpointId) => {
		if (!threadId) return;
		const res = await apiPost(
			`/mcp-ai/v1/threads/${threadId}/checkpoints/${checkpointId}/restore`
		);
		if (res.success) {
			fetchCheckpoints();
			setLastCheckpoint(null);
		}
		return res;
	}, [threadId, fetchCheckpoints]);

	const fetchDiff = useCallback(async (checkpointId) => {
		if (!threadId) return;
		const res = await apiGet(
			`/mcp-ai/v1/threads/${threadId}/checkpoints/${checkpointId}/diff`
		);
		if (res.success) {
			setDiff(res.data);
		}
		return res;
	}, [threadId]);

	const clearDiff = useCallback(() => setDiff(null), []);

	return {
		checkpoints,
		lastCheckpoint,
		diff,
		loading,
		fetchCheckpoints,
		createCheckpoint,
		restoreCheckpoint,
		fetchDiff,
		clearDiff,
	};
}
