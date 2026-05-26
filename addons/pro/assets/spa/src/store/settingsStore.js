import { create } from 'zustand';

export const useSettingsStore = create((set) => ({
	settings: {},
	user: null,
	mentionTypes: [],

	setSettings: (data) => set({
		settings: data,
		user: data.user || null,
		mentionTypes: data.mentionTypes || [],
	}),
}));
