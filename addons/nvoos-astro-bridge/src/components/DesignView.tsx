/**
 * Design System tab — Proposal 095 §4.1 (the /manage MCP client).
 *
 * Talks to the storefront's /mcp Streamable HTTP endpoint on the same
 * origin that serves this SPA, so fetch's default same-origin credentials
 * carry the manager session cookie. Write auth is enforced server-side
 * (fail closed) — a lost session surfaces as a 401 and renders inline.
 */
import { useEffect, useState } from '@wordpress/element';
import { mcpCall } from '../lib/mcp-client';

/** tokens_get result — the resolved semantic layer for the active theme. */
interface TokensResult {
	active_theme: string;
	semantic: Record<string, string>;
	groups?: unknown;
}

/** One theme_list entry. */
interface ThemeDescriptor {
	name: string;
	description?: string | null;
	tokens?: Record<string, string>;
}

/** theme_list result. */
interface ThemeListResult {
	active: string;
	themes: ThemeDescriptor[];
}

/**
 * Transactional write result (tokens_set / tokens_import_dtcg /
 * theme_apply / tokens_rollback share this shape family).
 */
interface WriteResult {
	applied?: boolean;
	diff?: Array<{ name: string; before: string; after: string }>;
	report?: { errors?: string[]; warnings?: string[] };
	snapshot_id?: string | null;
	ok?: boolean;
	message?: string;
}

/** contrast_check result (WCAG 2.2). */
interface ContrastResult {
	ratio: number;
	aaNormal: boolean;
	aaLarge: boolean;
	aaaNormal: boolean;
}

/** nds_export result — the NDS DTCG document. */
interface NdsExportResult {
	nds_version: string;
	doc: Record<string, Record<string, { $type: string; $value: string }>>;
	imported: string[];
	unmapped: Array<{ ads: string; reason: string }>;
}

/** Hex-ish token values get a color swatch. */
const HEX_RE = /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i;

const BUTTON_STYLE: Record<string, string | number> = {
	padding: '6px 12px',
	border: '1px solid #007cba',
	borderRadius: 3,
	background: '#007cba',
	color: '#fff',
	cursor: 'pointer',
	fontSize: 13,
};

const DISABLED_STYLE: Record<string, string | number> = {
	...BUTTON_STYLE,
	opacity: 0.6,
	cursor: 'default',
};

/** Inline write report — applied flag plus validation errors. */
function ReportLine({ result }: { result: WriteResult }) {
	const errors = result.report?.errors ?? [];
	return (
		<span style={{ fontSize: 12, color: result.applied ? '#1e7e34' : '#b32d2e' }}>
			Applied: {result.applied === true ? 'yes' : 'no'}
			{errors.length > 0 ? ` — ${errors.join('; ')}` : ''}
		</span>
	);
}

/** Small pass/fail pill for one contrast level. */
function Badge({ pass, label }: { pass: boolean; label: string }) {
	return (
		<span
			style={{
				display: 'inline-block',
				padding: '2px 8px',
				marginRight: 4,
				borderRadius: 10,
				fontSize: 11,
				color: '#fff',
				background: pass ? '#1e7e34' : '#b32d2e',
			}}
		>
			{label}: {pass ? 'pass' : 'fail'}
		</span>
	);
}

interface TokenRowProps {
	name: string;
	value: string;
	/** Re-resolve the semantic layer after an applied override. */
	onSaved: () => void;
}

/**
 * One semantic token row — name, resolved value (+ swatch), and an inline
 * override input. `tokens_set` takes a change map; a row sends one entry.
 */
function TokenRow({ name, value, onSaved }: TokenRowProps) {
	const [draft, setDraft] = useState(value);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [result, setResult] = useState<WriteResult | null>(null);

	// Track external re-resolution (override applied, theme switch,
	// rollback) without clobbering an in-progress edit.
	useEffect(() => {
		setDraft(value);
	}, [value]);

	const handleSave = async () => {
		setSaving(true);
		setError(null);
		setResult(null);
		try {
			const applied = await mcpCall<WriteResult>('tokens_set', {
				changes: { [name]: draft },
			});
			setResult(applied);
			if (applied.applied === true) {
				onSaved();
			}
		} catch (err) {
			setError((err as Error).message);
		} finally {
			setSaving(false);
		}
	};

	return (
		<tr>
			<td style={{ fontWeight: 600 }}>{name}</td>
			<td>
				{value}
				{HEX_RE.test(value) && (
					<span
						aria-hidden="true"
						style={{
							display: 'inline-block',
							width: 14,
							height: 14,
							marginLeft: 8,
							verticalAlign: 'middle',
							background: value,
							border: '1px solid #ccc',
							borderRadius: 2,
						}}
					/>
				)}
			</td>
			<td>
				<div style={{ display: 'flex', gap: 8 }}>
					<input
						type="text"
						aria-label={`Value for ${name}`}
						className="regular-text"
						value={draft}
						onChange={(event) => setDraft((event.target as HTMLInputElement).value)}
						disabled={saving}
						style={{ flex: 1, maxWidth: 220 }}
					/>
					<button
						type="button"
						onClick={handleSave}
						disabled={saving}
						style={saving ? DISABLED_STYLE : BUTTON_STYLE}
					>
						{saving ? 'Saving…' : 'Save'}
					</button>
				</div>
				{result && (
					<div style={{ marginTop: 4 }}>
						<ReportLine result={result} />
					</div>
				)}
				{error && (
					<div style={{ marginTop: 4, color: 'red', fontSize: 12 }}>{error}</div>
				)}
			</td>
		</tr>
	);
}

export function DesignView() {
	const [semantic, setSemantic] = useState<Record<string, string> | null>(null);
	const [activeTheme, setActiveTheme] = useState<string | null>(null);
	const [themes, setThemes] = useState<ThemeDescriptor[]>([]);
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState<string | null>(null);

	const [applying, setApplying] = useState<string | null>(null);
	const [applyError, setApplyError] = useState<string | null>(null);
	const [applyMessage, setApplyMessage] = useState<string | null>(null);

	const [importText, setImportText] = useState('');
	const [importing, setImporting] = useState(false);
	const [importResult, setImportResult] = useState<WriteResult | null>(null);
	const [importError, setImportError] = useState<string | null>(null);

	const [rollingBack, setRollingBack] = useState(false);
	const [rollbackError, setRollbackError] = useState<string | null>(null);
	const [rollbackMessage, setRollbackMessage] = useState<string | null>(null);

	const [fg, setFg] = useState('#ffffff');
	const [bg, setBg] = useState('#1a1a1a');
	const [checking, setChecking] = useState(false);
	const [contrast, setContrast] = useState<ContrastResult | null>(null);
	const [contrastError, setContrastError] = useState<string | null>(null);

	const [exporting, setExporting] = useState(false);
	const [ndsExport, setNdsExport] = useState<NdsExportResult | null>(null);
	const [exportError, setExportError] = useState<string | null>(null);

	/** Parallel read of the resolved semantic layer and the theme list. */
	const fetchData = async () => {
		const [tokensResult, themesResult] = await Promise.all([
			mcpCall<TokensResult>('tokens_get', { include_groups: false }),
			mcpCall<ThemeListResult>('theme_list', {}),
		]);
		setSemantic(tokensResult.semantic ?? {});
		setActiveTheme(tokensResult.active_theme ?? themesResult.active ?? null);
		setThemes(themesResult.themes ?? []);
	};

	useEffect(() => {
		let cancelled = false;
		setLoading(true);
		setLoadError(null);
		fetchData()
			.catch((error) => {
				if (!cancelled) {
					setLoadError((error as Error).message);
				}
			})
			.finally(() => {
				if (!cancelled) {
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, []);

	/** Re-resolve tokens after a state-changing write succeeded. */
	const refresh = async () => {
		try {
			await fetchData();
		} catch (error) {
			setLoadError((error as Error).message);
		}
	};

	const handleApplyTheme = async (name: string) => {
		setApplying(name);
		setApplyError(null);
		setApplyMessage(null);
		try {
			const result = await mcpCall<WriteResult>('theme_apply', { name });
			setApplyMessage(
				result.message ?? (result.ok ? `Active theme is now ${name}.` : 'Theme not applied.')
			);
			await refresh();
		} catch (error) {
			setApplyError((error as Error).message);
		} finally {
			setApplying(null);
		}
	};

	const handleImport = async () => {
		setImporting(true);
		setImportError(null);
		setImportResult(null);
		try {
			const result = await mcpCall<WriteResult>('tokens_import_dtcg', {
				json: importText,
			});
			setImportResult(result);
			if (result.applied === true) {
				await refresh();
			}
		} catch (error) {
			setImportError((error as Error).message);
		} finally {
			setImporting(false);
		}
	};

	const handleRollback = async () => {
		setRollingBack(true);
		setRollbackError(null);
		setRollbackMessage(null);
		try {
			const result = await mcpCall<WriteResult>('tokens_rollback', {});
			setRollbackMessage(
				result.message ??
					(result.ok ? 'Rolled back to the latest snapshot.' : 'Rollback failed.')
			);
			await refresh();
		} catch (error) {
			setRollbackError((error as Error).message);
		} finally {
			setRollingBack(false);
		}
	};

	const handleContrast = async () => {
		setChecking(true);
		setContrastError(null);
		try {
			const result = await mcpCall<ContrastResult>('contrast_check', { fg, bg });
			setContrast(result);
		} catch (error) {
			setContrast(null);
			setContrastError((error as Error).message);
		} finally {
			setChecking(false);
		}
	};

	const handleExport = async () => {
		setExporting(true);
		setExportError(null);
		try {
			const result = await mcpCall<NdsExportResult>('nds_export', {});
			setNdsExport(result);
		} catch (error) {
			setExportError((error as Error).message);
		} finally {
			setExporting(false);
		}
	};

	const handleDownload = () => {
		if (!ndsExport) {
			return;
		}
		const blob = new Blob([JSON.stringify(ndsExport, null, 2)], {
			type: 'application/json',
		});
		const url = URL.createObjectURL(blob);
		const anchor = document.createElement('a');
		anchor.href = url;
		anchor.download = 'nds-tokens.json';
		anchor.click();
		URL.revokeObjectURL(url);
	};

	if (loading) {
		return <p>Loading design tokens…</p>;
	}

	const tokenEntries = semantic ? Object.entries(semantic) : [];

	return (
		<div>
			<h2 style={{ marginTop: 0 }}>Design System</h2>

			{loadError && <p style={{ color: 'red' }}>{loadError}</p>}

			<div style={{ marginBottom: 24 }}>
				<h3 style={{ marginBottom: 8 }}>Theme</h3>
				<p style={{ marginTop: 0 }}>
					Active theme: <strong>{activeTheme ?? 'unknown'}</strong>
				</p>
				{themes.length === 0 && <p>No themes listed.</p>}
				<div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
					{themes.map((theme) => (
						<button
							key={theme.name}
							type="button"
							onClick={() => handleApplyTheme(theme.name)}
							disabled={applying !== null}
							style={
								theme.name === activeTheme
									? { ...BUTTON_STYLE, background: '#1e7e34', borderColor: '#1e7e34' }
									: applying !== null
										? DISABLED_STYLE
										: BUTTON_STYLE
							}
						>
							{applying === theme.name ? 'Applying…' : theme.name}
						</button>
					))}
				</div>
				{applyMessage && <p style={{ color: '#1e7e34', fontSize: 13 }}>{applyMessage}</p>}
				{applyError && <p style={{ color: 'red', fontSize: 13 }}>{applyError}</p>}
			</div>

			<div style={{ marginBottom: 24 }}>
				<h3 style={{ marginBottom: 8 }}>Token controls</h3>
				<div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', alignItems: 'flex-start' }}>
					<div style={{ minWidth: 280, flex: 1 }}>
						<label
							style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}
							htmlFor="dtcg-import"
						>
							Import DTCG JSON
						</label>
						<textarea
							id="dtcg-import"
							className="regular-text"
							rows={4}
							style={{ width: '100%', boxSizing: 'border-box' }}
							placeholder='{"groups": {...}, "themes": {...}}'
							value={importText}
							onChange={(event) =>
								setImportText((event.target as HTMLTextAreaElement).value)
							}
						/>
						<div style={{ marginTop: 8, display: 'flex', gap: 8, alignItems: 'center' }}>
							<button
								type="button"
								onClick={handleImport}
								disabled={importing || importText.trim() === ''}
								style={
									importing || importText.trim() === ''
										? DISABLED_STYLE
										: BUTTON_STYLE
								}
							>
								{importing ? 'Importing…' : 'Import'}
							</button>
							{importResult && <ReportLine result={importResult} />}
							{importError && (
								<span style={{ color: 'red', fontSize: 12 }}>{importError}</span>
							)}
						</div>
					</div>

					<div>
						<label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
							Rollback
						</label>
						<button
							type="button"
							onClick={handleRollback}
							disabled={rollingBack}
							style={rollingBack ? DISABLED_STYLE : BUTTON_STYLE}
						>
							{rollingBack ? 'Rolling back…' : 'Rollback to last snapshot'}
						</button>
						{rollbackMessage && (
							<p style={{ color: '#1e7e34', fontSize: 12, marginTop: 8 }}>
								{rollbackMessage}
							</p>
						)}
						{rollbackError && (
							<p style={{ color: 'red', fontSize: 12, marginTop: 8 }}>{rollbackError}</p>
						)}
					</div>

					<div>
						<label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
							Contrast checker
						</label>
						<div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
							<input
								type="text"
								aria-label="Foreground color"
								className="regular-text"
								style={{ width: 90 }}
								value={fg}
								onChange={(event) =>
									setFg((event.target as HTMLInputElement).value)
								}
							/>
							<input
								type="text"
								aria-label="Background color"
								className="regular-text"
								style={{ width: 90 }}
								value={bg}
								onChange={(event) =>
									setBg((event.target as HTMLInputElement).value)
								}
							/>
							<button
								type="button"
								onClick={handleContrast}
								disabled={checking}
								style={checking ? DISABLED_STYLE : BUTTON_STYLE}
							>
								{checking ? 'Checking…' : 'Check'}
							</button>
						</div>
						{contrast && (
							<div style={{ marginTop: 8, fontSize: 13 }}>
								Ratio: <strong>{contrast.ratio}</strong>{' '}
								<Badge pass={contrast.aaNormal} label="AA" />
								<Badge pass={contrast.aaLarge} label="AA large" />
								<Badge pass={contrast.aaaNormal} label="AAA" />
							</div>
						)}
						{contrastError && (
							<p style={{ color: 'red', fontSize: 12, marginTop: 8 }}>{contrastError}</p>
						)}
					</div>

					<div>
						<label style={{ display: 'block', marginBottom: 4, fontWeight: 600 }}>
							NDS export
						</label>
						<button
							type="button"
							onClick={handleExport}
							disabled={exporting}
							style={exporting ? DISABLED_STYLE : BUTTON_STYLE}
						>
							{exporting ? 'Exporting…' : 'Export NDS tokens'}
						</button>
						{exportError && (
							<p style={{ color: 'red', fontSize: 12, marginTop: 8 }}>{exportError}</p>
						)}
					</div>
				</div>

				{ndsExport && (
					<div style={{ marginTop: 16 }}>
						<textarea
							readOnly
							className="regular-text"
							rows={10}
							style={{
								width: '100%',
								boxSizing: 'border-box',
								fontFamily: 'monospace',
								fontSize: 12,
							}}
							value={JSON.stringify(ndsExport, null, 2)}
						/>
						<div style={{ marginTop: 8 }}>
							<button type="button" onClick={handleDownload} style={BUTTON_STYLE}>
								Download nds-tokens.json
							</button>
						</div>
					</div>
				)}
			</div>

			<h3 style={{ marginBottom: 8 }}>Semantic tokens</h3>
			{tokenEntries.length === 0 && !loadError && <p>No semantic tokens resolved.</p>}
			{tokenEntries.length > 0 && (
				<table
					className="wp-list-table widefat fixed striped"
					style={{ width: '100%' }}
				>
					<thead>
						<tr>
							<th style={{ width: 200 }}>Name</th>
							<th>Value</th>
							<th style={{ width: 380 }}>Override</th>
						</tr>
					</thead>
					<tbody>
						{tokenEntries.map(([name, value]) => (
							<TokenRow
								key={name}
								name={name}
								value={value}
								onSaved={() => {
									void refresh();
								}}
							/>
						))}
					</tbody>
				</table>
			)}
		</div>
	);
}
