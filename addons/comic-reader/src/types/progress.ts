/**
 * NV oOS Comic Reader — Reading Progress
 *
 * Local progress records stored per comic under `nvoos_cr_progress_{id}`.
 * Phase 2 layers server-side sync on top of this module.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

export interface ProgressRecord {
	page: number;
	total: number;
	completed: boolean;
	ts: number;
}

export function progressKey(comicId: number): string {
	return `nvoos_cr_progress_${comicId}`;
}

/** Save a progress record locally. Never throws. */
export function saveLocalProgress(
	comicId: number,
	record: ProgressRecord
): void {
	try {
		localStorage.setItem(progressKey(comicId), JSON.stringify(record));
	} catch {
		// Storage unavailable.
	}
}

/** Read a single progress record, accepting the legacy bare-page format. */
export function readLocalProgress(comicId: number): ProgressRecord | null {
	try {
		const raw = localStorage.getItem(progressKey(comicId));
		if (!raw) return null;
		try {
			const parsed = JSON.parse(raw) as Partial<ProgressRecord>;
			if (parsed && typeof parsed.page === 'number') {
				return {
					page: parsed.page,
					total: parsed.total ?? 0,
					completed: parsed.completed ?? false,
					ts: parsed.ts ?? 0,
				};
			}
		} catch {
			// Legacy format: bare page number.
		}
		const page = parseInt(raw, 10);
		if (!Number.isNaN(page)) {
			return { page, total: 0, completed: false, ts: 0 };
		}
		return null;
	} catch {
		return null;
	}
}

/** Scan localStorage for every comic progress record. Never throws. */
export function readAllLocalProgress(): Map<number, ProgressRecord> {
	const map = new Map<number, ProgressRecord>();
	try {
		for (let i = 0; i < localStorage.length; i++) {
			const key = localStorage.key(i);
			if (!key || !key.startsWith('nvoos_cr_progress_')) continue;
			const id = parseInt(key.slice('nvoos_cr_progress_'.length), 10);
			if (Number.isNaN(id)) continue;
			const record = readLocalProgress(id);
			if (record) {
				map.set(id, record);
			}
		}
	} catch {
		// Storage unavailable.
	}
	return map;
}

/** Percent read for a record (0 when the total is unknown). */
export function progressPercent(record: ProgressRecord): number {
	if (!record.total || record.completed) {
		return record.completed ? 100 : 0;
	}
	return Math.min(100, Math.max(0, Math.round((record.page / record.total) * 100)));
}
