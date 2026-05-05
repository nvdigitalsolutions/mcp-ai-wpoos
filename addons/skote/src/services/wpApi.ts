/**
 * Axios client bound to the WordPress REST API exposed by the addon.
 *
 * This is the file that replaces Skote's bundled `fakebackend_helper.js` /
 * `axios-mock-adapter`. Every Skote saga or hook should call into one of the
 * `services/*` modules, which in turn use this client.
 */

import axios, { AxiosInstance, AxiosRequestConfig } from 'axios';

function getSettings() {
	const settings = window.nvoosSkote;
	if (!settings) {
		throw new Error('window.nvoosSkote is not initialized');
	}
	return settings;
}

export function createWpApiClient(config?: AxiosRequestConfig): AxiosInstance {
	const settings = getSettings();
	const instance = axios.create({
		baseURL: settings.restUrl,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': settings.restNonce,
		},
		withCredentials: true,
		...config,
	});

	instance.interceptors.response.use(
		(response) => {
			if (response.data && typeof response.data === 'object' && 'success' in response.data) {
				return response;
			}
			return response;
		},
		(error) => {
			// Normalise WP REST `WP_Error` payloads into something the
			// upstream Skote saga error handlers expect.
			if (error?.response?.data) {
				const data = error.response.data;
				const message =
					typeof data === 'object' && data !== null && 'message' in data
						? String((data as { message: unknown }).message)
						: error.message;
				return Promise.reject({
					...error,
					message,
					code: typeof data === 'object' && data !== null && 'code' in data ? (data as { code: unknown }).code : undefined,
				});
			}
			return Promise.reject(error);
		}
	);

	return instance;
}

export const wpApi = createWpApiClient();
