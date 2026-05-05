import { useQuery } from '@tanstack/react-query';
import { wpApi } from '../services/wpApi';

export interface SkoteApp {
	slug: string;
	label: string;
	icon: string;
	route: string;
	enabled: boolean;
	capability: string;
	requires?: string;
}

interface Envelope<T> {
	success: boolean;
	data: T;
	errors: unknown[];
	meta: Record<string, unknown>;
}

export function useApps() {
	return useQuery<SkoteApp[]>({
		queryKey: ['nvoos-skote', 'apps'],
		queryFn: async () => {
			const response = await wpApi.get<Envelope<SkoteApp[]>>('apps');
			return response.data?.data ?? [];
		},
	});
}
