/// <reference types="astro/client" />

interface ImportMetaEnv {
	/** Full base URL of the NV commerce contract (build-time fetches). */
	readonly NVOOS_API_BASE?: string;
	/** Currency for price formatting and JSON-LD (default USD). */
	readonly NVOOS_CURRENCY?: string;
	/** Public API base for client-side islands (default same-origin /api). */
	readonly PUBLIC_API_BASE?: string;
}

interface ImportMeta {
	readonly env: ImportMetaEnv;
}
