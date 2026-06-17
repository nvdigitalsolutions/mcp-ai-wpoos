/**
 * PageFooter — displays last-modified date and an "Edit on GitHub" link.
 *
 * The edit URL is resolved from (in order of preference):
 *  1. `remoteUrl`   — already the GitHub blob URL for remote-sourced pages.
 *  2. `githubBase`  — the "Edit on GitHub base URL" setting, combined with
 *                     `relativePath` (e.g. docs/getting-started.md).
 *
 * If neither produces a URL, the edit link is not rendered.
 *
 * @since 0.3.8
 */

interface PageFooterProps {
	/** Unix timestamp (seconds) of the file's last modification. */
	lastModified: number;
	/** Repo-relative file path for constructing the edit URL. */
	relativePath?: string;
	/** Full GitHub blob URL (set for remote-sourced pages). */
	remoteUrl?: string;
	/** Base URL from the "Edit on GitHub" setting (e.g. https://github.com/org/repo/blob/main). */
	githubBase?: string;
}

declare const NVOOS_DOCS_HUB: {
	githubRepoUrl?: string;
	[ key: string ]: unknown;
};

/**
 * Build the edit-on-GitHub URL.
 *
 * - Remote pages already have a full blob URL → use it directly.
 * - Local pages: combine the admin-configured base with the relative path.
 */
function buildEditUrl( remoteUrl?: string, githubBase?: string, relativePath?: string ): string | null {
	if ( remoteUrl ) {
		return remoteUrl;
	}
	const base = githubBase ?? ( typeof NVOOS_DOCS_HUB !== 'undefined' ? NVOOS_DOCS_HUB.githubRepoUrl : undefined );
	if ( base && relativePath ) {
		return base.replace( /\/$/, '' ) + '/' + relativePath.replace( /^\//, '' );
	}
	return null;
}

/** Format a Unix timestamp as a human-readable date (locale-aware). */
function formatDate( unixSeconds: number ): string {
	if ( ! unixSeconds ) {
		return '';
	}
	return new Date( unixSeconds * 1000 ).toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	} );
}

export default function PageFooter( { lastModified, relativePath, remoteUrl, githubBase }: PageFooterProps ) {
	const editUrl = buildEditUrl( remoteUrl, githubBase, relativePath );
	const dateStr = formatDate( lastModified );

	if ( ! dateStr && ! editUrl ) {
		return null;
	}

	return (
		<footer className="dh-page-footer">
			{ dateStr && (
				<span className="dh-page-footer__modified">
					Last updated: <time dateTime={ new Date( lastModified * 1000 ).toISOString() }>{ dateStr }</time>
				</span>
			) }
			{ editUrl && (
				<a
					href={ editUrl }
					className="dh-page-footer__edit"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="Edit this page on GitHub (opens in a new tab)"
				>
					✏ Edit on GitHub
				</a>
			) }
		</footer>
	);
}
