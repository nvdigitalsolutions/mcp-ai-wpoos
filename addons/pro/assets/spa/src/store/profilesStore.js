/**
 * Profiles Store — Agent profile state.
 */

import { create } from 'zustand';

export const useProfilesStore = create((set) => ({
	profiles: [],
	activeProfile: 'write',

	setProfiles: (profiles) => set({ profiles }),
	setActiveProfile: (name) => set({ activeProfile: name }),
}));
