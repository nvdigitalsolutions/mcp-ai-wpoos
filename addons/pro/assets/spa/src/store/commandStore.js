// SPDX-License-Identifier: LicenseRef-Proprietary
import { create } from 'zustand';

export const useCommandStore = create((set) => ({
	commands: [],
	setCommands: (commands) => set({ commands }),
}));
