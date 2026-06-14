/**
 * Model Store — Tracks the currently selected AI model across the SPA.
 */

import { create } from 'zustand';

const DEFAULT_MODEL = { provider: 'openai', model: 'gpt-4o' };

export const useModelStore = create((set) => ({
	model: { ...DEFAULT_MODEL },

	setModel: (model) => set({ model }),
}));
