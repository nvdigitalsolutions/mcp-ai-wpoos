import { describe, expect, it } from 'vitest';
import { buildAdminConfig } from '../../src/lib/manage-config';
import type { Customer } from '../../src/lib/commerce/types';

describe('buildAdminConfig', () => {
	it('hands the server-authored session and nonce to the admin bundle', () => {
		const customer: Customer = {
			id: 7,
			email: 'manager@example.com',
			name: 'Mgr',
			canManage: true,
			adminUrl: '',
			capabilities: ['manage_astro', 'edit_posts'],
			readOnlyCollections: ['statuses'],
		};

		expect(buildAdminConfig(customer, 'nonce-abc', '/api')).toEqual({
			root: '/api',
			mediaBase: '/media/',
			nonce: 'nonce-abc',
			user: {
				canManage: true,
				capabilities: ['manage_astro', 'edit_posts'],
				readOnlyCollections: ['statuses'],
			},
		});
	});

	it('defaults to a read-only-free, capability-free user for a null session', () => {
		expect(buildAdminConfig(null, '', '/api').user).toEqual({
			canManage: false,
			capabilities: [],
			readOnlyCollections: [],
		});
	});
});
