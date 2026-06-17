/**
 * UI Store — UI state: sidebar, modals, toasts.
 */

import { create } from 'zustand';

export const useUIStore = create((set) => ({
	sidebarOpen: true,
	rightPanelOpen: false,
	toasts: [],

	toggleSidebar: () => set((s) => ({ sidebarOpen: !s.sidebarOpen })),
	toggleRightPanel: () => set((s) => ({ rightPanelOpen: !s.rightPanelOpen })),

	addToast: (message, variant = 'info') => {
		const id = Date.now();
		set((s) => ({ toasts: [...s.toasts, { id, message, variant }] }));
		setTimeout(() => {
			set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) }));
		}, 6000);
	},
}));
