/**
 * Bootstrap Service — Initial data loader for SPA.
 *
 * Fetches the /spa/bootstrap endpoint and populates all Zustand stores
 * with threads, profiles, tools, commands, settings, and user data.
 */

import { apiGet } from './api';
import { useThreadsStore } from '../store/threadsStore';
import { useProfilesStore } from '../store/profilesStore';
import { useToolsStore } from '../store/toolsStore';
import { useSettingsStore } from '../store/settingsStore';
import { useCommandStore } from '../store/commandStore';

let bootstrapPromise = null;

/**
 * Load all bootstrap data once and populate stores.
 *
 * @returns {Promise<void>}
 */
export async function loadBootstrap() {
	if (bootstrapPromise) {
		return bootstrapPromise;
	}

	bootstrapPromise = (async () => {
		const response = await apiGet('/mcp-ai-pro/v1/spa/bootstrap');

		if (!response.success) {
			throw new Error(response.message || 'Bootstrap failed');
		}

		const { threads, profiles, tools, commands, settings, user, mention_types } = response.data;

		// Populate stores.
		useThreadsStore.getState().setThreads(threads?.threads || [], threads?.total || 0);
		useProfilesStore.getState().setProfiles(profiles || []);
		useToolsStore.getState().setTools(tools || []);
		useCommandStore.getState().setCommands(commands || []);
		useSettingsStore.getState().setSettings({ ...settings, user, mentionTypes: mention_types || [] });
	})();

	return bootstrapPromise;
}
