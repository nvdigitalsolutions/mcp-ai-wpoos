/**
 * Zod schemas for the credentials wizard.
 *
 * Centralizing the validation rules here keeps the React form components
 * thin and ensures the same rules ship in the JSDoc tooltip text shown
 * to the operator.
 *
 * @package NV_oOS_SaaS_Controller
 */

import { z } from 'zod';

/**
 * Cloudflare account IDs are typically 32 hex chars but legacy/test
 * accounts can return shorter or longer IDs. Accept 16–64 hex chars
 * defensively. Mirrors the server-side regex in
 * NVOOS_SaaS_Controller_Connection_Tester::test_cloudflare().
 */
export const cloudflareSchema = z.object({
	cloudflare_account_id: z
		.string()
		.trim()
		.regex(
			/^[a-f0-9]{16,64}$/i,
			'Cloudflare account ID must be 16–64 hex characters.'
		),
	cloudflare_api_token: z
		.string()
		.trim()
		.min( 20, 'Cloudflare API token looks too short.' ),
} );

/**
 * Stripe secret keys start with sk_live_ or sk_test_.
 */
export const stripeSchema = z.object({
	stripe_secret_key: z
		.string()
		.trim()
		.regex(
			/^sk_(live|test)_[A-Za-z0-9]+$/,
			'Stripe secret key must start with sk_live_ or sk_test_.'
		),
	stripe_webhook_secret: z
		.string()
		.trim()
		.regex(
			/^whsec_[A-Za-z0-9]+$/,
			'Stripe webhook secret must start with whsec_.'
		),
} );

/**
 * OpenRouter keys are a single bearer string. We only assert non-emptiness
 * and a sensible minimum length — OpenRouter does not publish a public
 * format spec.
 */
export const openrouterSchema = z.object({
	openrouter_api_key: z
		.string()
		.trim()
		.min( 20, 'OpenRouter API key looks too short.' ),
} );

export type CredentialKey =
	| 'cloudflare_account_id'
	| 'cloudflare_api_token'
	| 'stripe_secret_key'
	| 'stripe_webhook_secret'
	| 'openrouter_api_key';

export type CredentialValues = Partial<Record<CredentialKey, string>>;

export interface PreflightResult {
	ok: boolean;
	latency_ms: number;
	status: number;
	message: string;
}

export interface PreflightResponse {
	ok: boolean;
	results: {
		cloudflare: PreflightResult;
		stripe: PreflightResult;
		openrouter: PreflightResult;
	};
}

export interface MaskedCredential {
	configured: boolean;
	masked: string;
}

export interface MaskedCredentialsResponse {
	credentials: Record<CredentialKey, MaskedCredential>;
}
