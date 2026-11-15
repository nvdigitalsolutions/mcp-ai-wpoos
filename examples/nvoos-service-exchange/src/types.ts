export type Industry =
	| 'healthcare'
	| 'food_beverage'
	| 'professional_services'
	| 'ecommerce'
	| 'retail'
	| 'education'
	| 'real_estate'
	| 'hospitality'
	| 'other';

export interface BundleAssistant {
	title: string;
	slug?: string;
	status?: string;
	content?: string;
	excerpt?: string;
	meta?: Record<string, unknown>;
	[ key: string ]: unknown;
}

export interface AssistantBundle {
	format: 'nvoos-assistant';
	format_version: 1;
	plugin_version?: string;
	assistants: BundleAssistant[];
	[ key: string ]: unknown;
}

export interface Blueprint {
	blueprint_version: 1;
	name: string;
	slug: string;
	tagline: string;
	industry: Industry;
	icon?: string;
	bundle: AssistantBundle;
	toolkits?: string[];
	mcp_apps?: Array<{ connection_ref: string; label?: string; position?: number }>;
	site: {
		region: string;
		size_hint?: 'small' | 'medium' | 'large';
		php?: string;
		domain?: string | null;
		wp_manager?: boolean;
	};
	console?: { enable: boolean; template?: string | null };
	storefront?: { enable: boolean; template?: string | null };
	gateway: {
		visibility: 'public' | 'private';
		tool_allowlist?: string[];
		rate_limit_profile?: string;
	};
	commerce?: {
		stripe_price_id?: string | null;
		trial_days?: number;
		metered?: boolean;
	};
	listing: {
		summary: string;
		use_cases?: string[];
		pricing_note?: string;
		connect_snippets?: boolean;
	};
	_meta?: Record<string, unknown>;
	[ key: string ]: unknown;
}

export interface EstateStep {
	step: string;
	label: string;
	evidence: string;
	ok: boolean;
}

export interface EstateSummary {
	slug: string;
	name: string;
	mode: string;
	state: string;
	steps: EstateStep[];
	nextStep: string | null;
	complete: boolean;
	visibility: string;
	entitlement: string;
	advertisable: boolean;
	lastError: null | { code: string; message: string; tier?: string | null; remediation?: string | null };
	createdAt: string;
	updatedAt: string;
}

export interface EstateDetail extends EstateSummary {
	blueprint: Blueprint;
	urls: Record<string, string>;
	secrets: { operatorTokenFingerprint: string | null; gatewayKeyPresent: boolean };
	snippets: null | {
		bridge: unknown;
		chatgpt: { url: string; steps: string[] };
		claudeCode: string[];
		curl: string;
	};
	gateway: null | { notes: string[]; verifiedAt: string };
}

export interface CatalogueEntry {
	slug: string;
	name: string;
	tagline: string;
	industry: Industry;
	summary: string;
	useCases: string[];
	pricingNote: string;
	visibility: string;
}
