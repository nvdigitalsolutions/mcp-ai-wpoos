import { create } from 'zustand';

export const useToolsStore = create((set) => ({
	tools: [],
	setTools: (tools) => set({ tools }),
}));
