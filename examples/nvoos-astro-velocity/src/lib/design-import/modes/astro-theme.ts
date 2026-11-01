// ---------------------------------------------------------------------------
// modes/astro-theme.ts — Astro theme source mode (Proposal 098 Phase 1.2).
//
// Astro themes are GitHub repos — clone, don't scrape. This module reads the
// repo through the GitHub REST trees API (unauthenticated, rate-limited) and
// raw.githubusercontent.com: token JSON files (DTCG), CSS files (incl.
// Tailwind v4 @theme blocks), and the LICENSE file. No git execution, no
// scraping, no private hosts — plain guarded HTTP only. Capped fan-out (file
// count + bytes) keeps the rate-limit footprint small.
// ---------------------------------------------------------------------------

import { fetchGuarded, type FetchGuardOptions } from '../fetcher';

export interface AstroThemeAssets {
	/** CSS bodies from the repo (styles, @theme blocks). */
	stylesheets: string[];
	/** Parsed token documents (DTCG-ish JSON). */
	tokenDocuments: Array<{ path: string; json: unknown }>;
	/** LICENSE text when the repo ships one, else null. */
	license: string | null;
	/** The default branch actually read. */
	ref: string;
}

const MAX_FILES = 24;
const MAX_FILE_BYTES = 512 * 1024;
const TOKEN_PATH_RE = /(^|\/)(tokens\.json|theme\.json|src\/tokens\/.*\.json|tokens\/.*\.json)$/i;
const STYLE_PATH_RE = /(^|\/)(astro\.config\.mjs|.*\.css|.*\.scss)$/i;

/** Parse a github.com URL into { owner, repo, ref } (ref optional). */
export function parseGitHubUrl(url: string): { owner: string; repo: string; ref: string | null } | null {
	const match = url.match(/github\.com\/([^/\s]+)\/([^/\s#?]+)(?:\/tree\/([^/\s#?]+))?/i);
	if (!match) {
		return null;
	}
	return { owner: match[1], repo: match[2].replace(/\.git$/, ''), ref: match[3] ?? null };
}

interface TreeEntry {
	path?: string;
	type?: string;
}

/** Read the repo's default branch + recursive tree through the GitHub API. */
export async function fetchAstroThemeAssets(githubUrl: string, options: FetchGuardOptions = {}): Promise<AstroThemeAssets> {
	const parsed = parseGitHubUrl(githubUrl);
	if (!parsed) {
		return { stylesheets: [], tokenDocuments: [], license: null, ref: '' };
	}
	const { owner, repo } = parsed;
	const apiBase = 'https://api.github.com';

	// 1. Default branch (repo metadata).
	let ref = parsed.ref ?? '';
	if (!ref) {
		try {
			const meta = await fetchGuarded(`${apiBase}/repos/${owner}/${repo}`, {
				...options,
				maxBytes: 128 * 1024,
				accept: ['application/json'],
			});
			const defaultBranch = (JSON.parse(meta.body) as { default_branch?: string }).default_branch;
			if (defaultBranch) {
				ref = defaultBranch;
			}
		} catch {
			ref = 'HEAD'; // Degrade to HEAD rather than fail the analysis.
		}
	}

	// 2. Recursive tree, truncated → the fan-out cap still bounds the work.
	let paths: string[];
	try {
		const tree = await fetchGuarded(
			`${apiBase}/repos/${owner}/${repo}/git/trees/${encodeURIComponent(ref)}?recursive=1`,
			{ ...options, maxBytes: 512 * 1024, accept: ['application/json'] }
		);
		const entries = ((JSON.parse(tree.body) as { tree?: TreeEntry[] }).tree ?? []) as TreeEntry[];
		paths = entries.filter((entry) => entry.type === 'blob' && entry.path).map((entry) => entry.path as string);
	} catch {
		paths = []; // Tree fetch failure degrades to an empty theme read.
	}

	const tokenPaths = paths.filter((path) => TOKEN_PATH_RE.test(path)).slice(0, MAX_FILES);
	const stylePaths = paths.filter((path) => STYLE_PATH_RE.test(path) && !TOKEN_PATH_RE.test(path)).slice(0, MAX_FILES);
	const licensePath = paths.find((path) => /^(license|copying)(\.md|\.txt)?$/i.test(path));

	const stylesheets: string[] = [];
	const tokenDocuments: Array<{ path: string; json: unknown }> = [];
	let license: string | null = null;

	for (const path of stylePaths) {
		try {
			const file = await fetchGuarded(
				`https://raw.githubusercontent.com/${owner}/${repo}/${encodeURIComponent(ref)}/${path}`,
				{ ...options, maxBytes: MAX_FILE_BYTES, accept: ['text/css', 'text/plain', 'application/javascript'] }
			);
			stylesheets.push(file.body);
		} catch {
			// A single missing file never fails the theme read.
		}
	}

	for (const path of tokenPaths) {
		try {
			const file = await fetchGuarded(
				`https://raw.githubusercontent.com/${owner}/${repo}/${encodeURIComponent(ref)}/${path}`,
				{ ...options, maxBytes: MAX_FILE_BYTES, accept: ['application/json', 'text/plain'] }
			);
			try {
				tokenDocuments.push({ path, json: JSON.parse(file.body) });
			} catch {
				// Malformed token file degrades silently.
			}
		} catch {
			// A single missing file never fails the theme read.
		}
	}

	if (licensePath) {
		try {
			const file = await fetchGuarded(
				`https://raw.githubusercontent.com/${owner}/${repo}/${encodeURIComponent(ref)}/${licensePath}`,
				{ ...options, maxBytes: 64 * 1024, accept: ['text/plain', 'application/octet-stream'] }
			);
			license = file.body;
		} catch {
			license = null;
		}
	}

	return { stylesheets, tokenDocuments, license, ref };
}
