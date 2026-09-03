/**
 * Pro SPA v2 — typed runtime config reader.
 *
 * Reads `window.NVOOS_PRO_SPA` localized by the PHP admin page enqueue.
 * Keep this file the single source of truth for the SPA's runtime config shape.
 */

export interface ProSpaPerInstanceConfig {
	assistantId?: number;
	theme?: 'auto' | 'light' | 'dark' | string;
	allowSensitiveTools?: boolean;
	/** Vector store ID (mirrors legacy chat.js preloading). */
	vectorStoreId?: number | string;
	/**
	 * Render mode.
	 * - `admin`: full three-column layout with router (default).
	 * - `embedded`: chat-first, router-free surface for the [nvoos_pro_spa]
	 *   shortcode.
	 */
	mode?: 'admin' | 'embedded';
	/** CSS height for embedded instances (e.g. "720px"). */
	height?: string;
	/** Guest mode — anonymous visitors authenticated via a guest token. */
	guest?: boolean;
	/** Server-minted guest access token sent via X-WP-MCP-AI-Guest. */
	guestToken?: string;
	/** Whether embedded mode renders the transcripts sidebar. */
	showSidebar?: boolean;
	/** Route allowlist for the instance (embedded mode: chat only). */
	routes?: string[];
}

export interface ProSpaEndpoints {
	chat: string;
	chatClient: string;
	transcripts: string;
	memory: string;
	threads: string;
	tools: string;
	/** WordPress media upload endpoint (v0.9.0). */
	upload: string;
	assistants: string;
	settings: string;
	workflows: string;
	analytics: string;
	approvals: string;
	shortcuts: string;
	slashCommands: string;
	/** OKF Skills & Knowledge browse endpoint (v2.1.1). */
	okf: string;
}

export interface RuntimeAssistantSummary {
	id: number;
	title: string;
	provider?: string;
	model?: string;
}

export interface ProSpaRuntime {
	apiUrl: string;
	proApi: string;
	nonce: string;
	config: ProSpaPerInstanceConfig;
	endpoints: ProSpaEndpoints;
	user: ProSpaUser;
	mentionTypes: MentionType[];
	/** Pre-loaded assistants from the server — avoids a separate REST round-trip. */
	assistants?: RuntimeAssistantSummary[];
}

export interface ProSpaUser {
	id: number;
	login: string;
	displayName: string;
	capabilities: string[];
	assistant_id?: number;
}

export interface MentionType {
	type: string;
	label: string;
	icon?: string;
}

export type ProSpaConfig = ProSpaPerInstanceConfig;

/**
 * Overlay per-instance overrides (the shortcode's data-config JSON) onto the
 * global runtime and store the result back on `window.NVOOS_PRO_SPA`.
 *
 * Every existing consumer (useBootstrap, Layout, ChatPage, useChatSpoke)
 * reads the runtime through readProSpaConfig(), so writing the merged value
 * back to the global makes per-instance config transparent to the whole app.
 *
 * NOTE: the global is shared — this intentionally supports a single embedded
 * instance per page (documented shortcode constraint for v1).
 */
export function applyPerInstanceConfig( perInstance: unknown ): ProSpaRuntime | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const runtime = readProSpaConfig();
	if ( ! runtime ) {
		return null;
	}
	if ( ! perInstance || typeof perInstance !== 'object' || Array.isArray( perInstance ) ) {
		return runtime;
	}
	const overrides = perInstance as Partial< ProSpaPerInstanceConfig >;
	const next: ProSpaRuntime = {
		...runtime,
		config: {
			...runtime.config,
			...( overrides as ProSpaPerInstanceConfig ),
		},
	};
	( window as unknown as { NVOOS_PRO_SPA?: ProSpaRuntime } ).NVOOS_PRO_SPA = next;
	return next;
}

/**
 * Read the localized runtime config emitted by `wp_localize_script()`.
 *
 * Returns `null` when the global is missing or malformed so the App can render
 * a graceful fallback instead of throwing.
 */
export function readProSpaConfig(): ProSpaRuntime | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const g = ( window as unknown as { NVOOS_PRO_SPA?: Partial< ProSpaRuntime > } )
		.NVOOS_PRO_SPA;
	if ( ! g || typeof g.apiUrl !== 'string' || ! g.endpoints ) {
		return null;
	}
	const e = g.endpoints as Partial< ProSpaEndpoints >;
	if (
		typeof e.chatClient !== 'string' ||
		typeof e.chat !== 'string' ||
		typeof e.transcripts !== 'string' ||
		typeof e.threads !== 'string' ||
		typeof e.tools !== 'string' ||
		typeof e.assistants !== 'string' ||
		typeof e.settings !== 'string'
	) {
		return null;
	}
	return {
		apiUrl: g.apiUrl,
		proApi: typeof g.proApi === 'string' ? g.proApi : '',
		nonce: typeof g.nonce === 'string' ? g.nonce : '',
		config: ( g.config ?? {} ) as ProSpaPerInstanceConfig,
		endpoints: {
			chat: e.chat,
			chatClient: e.chatClient,
			transcripts: e.transcripts,
			memory: typeof e.memory === 'string' ? e.memory : '',
			threads: e.threads,
			tools: e.tools,
			upload: typeof e.upload === 'string' ? e.upload : '',
			assistants: e.assistants,
			settings: e.settings,
			workflows: typeof e.workflows === 'string' ? e.workflows : '',
			analytics: typeof e.analytics === 'string' ? e.analytics : '',
			approvals: typeof e.approvals === 'string' ? e.approvals : '',
			shortcuts: typeof e.shortcuts === 'string' ? e.shortcuts : '',
			slashCommands: typeof e.slashCommands === 'string' ? e.slashCommands : '',
			okf: typeof e.okf === 'string' ? e.okf : '',
		},
		user: ( g.user ?? { id: 0, login: '', displayName: '', capabilities: [] } ) as ProSpaUser,
		mentionTypes: Array.isArray( g.mentionTypes ) ? g.mentionTypes : [],
		assistants: Array.isArray( g.assistants ) ? g.assistants : undefined,
	};
}
