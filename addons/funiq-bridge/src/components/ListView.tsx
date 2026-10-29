/**
 * Generic list/table view for any collection.
 *
 * Table conventions follow the W3C APG table pattern: a native `<table>`
 * (no ARIA grid), sortable headers as buttons carrying `aria-sort` on the
 * `<th>`, and exactly one column ascending/descending at a time. The pager
 * follows the console DataTable contract — nav landmark, aria-current,
 * keep-previous-data busy state with aria-busy, and a "Showing X–Y of Z"
 * live region.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useCollection, useDocumentDelete } from '../hooks/useCollection';
import { isCollectionWritable } from '../auth/permissions';
import type { CollectionConfig } from '../config/collections';

interface ListViewProps {
	config: CollectionConfig;
	onEdit: (id: number) => void;
	onCreate: () => void;
}

interface SortState {
	field: string;
	direction: 'asc' | 'desc';
}

export function ListView({ config, onEdit, onCreate }: ListViewProps) {
	const writable = isCollectionWritable(config.slug);
	const [page, setPage] = useState(1);
	const [sort, setSort] = useState<SortState | null>(null);
	const [searchTerm, setSearchTerm] = useState('');
	const [debouncedSearch, setDebouncedSearch] = useState('');

	// Debounce the search box so every keystroke doesn't hit the backend
	// (300ms — the standard admin-table trade-off between latency and load).
	useEffect(() => {
		const timer = setTimeout(() => {
			setDebouncedSearch(searchTerm.trim());
			setPage(1);
		}, 300);
		return () => clearTimeout(timer);
	}, [searchTerm]);

	const where = useMemo(
		() =>
			debouncedSearch ? { name: { like: debouncedSearch } } : undefined,
		[debouncedSearch]
	);

	const sortParam = sort ? `${sort.direction === 'desc' ? '-' : ''}${sort.field}` : undefined;
	const { data, isLoading, isFetching, error } = useCollection<any>(config.slug, {
		page,
		limit: 20,
		sort: config.sortable?.length ? sortParam : undefined,
		where: config.searchable ? where : undefined,
	});
	const deleteMutation = useDocumentDelete(config.slug);

	if (isLoading) {
		return <p>Loading {config.pluralLabel}...</p>;
	}

	if (error) {
		return <p style={{ color: 'red' }}>Error loading {config.pluralLabel}.</p>;
	}

	const docs = data?.docs ?? [];
	const totalDocs = data?.totalDocs ?? docs.length;
	const totalPages = data?.totalPages ?? 1;
	const busy = isFetching;
	const rangeStart = docs.length === 0 ? 0 : (page - 1) * 20 + 1;
	const rangeEnd = (page - 1) * 20 + docs.length;

	const handleDelete = async (id: number) => {
		if (!confirm('Are you sure you want to delete this item?')) return;
		deleteMutation.mutate(id);
	};

	/**
	 * Cycle a sortable column: none → ascending → descending → none.
	 * `aria-sort` reflects the active state on the column header only.
	 */
	const toggleSort = (field: string) => {
		setPage(1);
		setSort((current) => {
			if (!current || current.field !== field) {
				return { field, direction: 'asc' };
			}
			if (current.direction === 'asc') {
				return { field, direction: 'desc' };
			}
			return null;
		});
	};

	const sortAria = (field: string): 'ascending' | 'descending' | 'none' => {
		if (!sort || sort.field !== field) {
			return 'none';
		}
		return sort.direction === 'asc' ? 'ascending' : 'descending';
	};

	const getCellValue = (doc: any, col: string): string => {
		const val = doc[col];
		if (val === null || val === undefined) return '—';
		if (typeof val === 'boolean') return val ? 'Yes' : 'No';
		if (typeof val === 'object') return val.name ?? val.title ?? val.code ?? String(val);
		return String(val);
	};

	const renderHeader = (col: string) => {
		if (!config.sortable?.includes(col)) {
			return (
				<th key={col} scope="col">
					{col}
				</th>
			);
		}
		const aria = sortAria(col);
		return (
			<th key={col} scope="col" aria-sort={aria}>
				<button
					type="button"
					className="button-link"
					onClick={() => toggleSort(col)}
					style={{ fontWeight: 'inherit' }}
				>
					{col}
					{aria === 'ascending' ? ' ▲' : aria === 'descending' ? ' ▼' : ''}
				</button>
			</th>
		);
	};

	return (
		<div>
			<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
				<h2>{config.pluralLabel}</h2>
				<div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
					{config.searchable && (
						<label className="screen-reader-text" htmlFor={`${config.slug}-search`}>
							Search {config.pluralLabel}
						</label>
					)}
					{config.searchable && (
						<input
							id={`${config.slug}-search`}
							type="search"
							className="regular-text"
							placeholder={`Search ${config.pluralLabel}...`}
							value={searchTerm}
							onChange={(event) => setSearchTerm((event.target as HTMLInputElement).value)}
							style={{ width: 240 }}
						/>
					)}
					{writable && (
						<Button variant="primary" onClick={onCreate}>
							Add {config.label}
						</Button>
					)}
				</div>
			</div>

			{!writable && (
				<p style={{ color: '#666', marginTop: -8 }}>
					This collection is managed by WooCommerce and is read-only.
				</p>
			)}

			<div
				aria-busy={busy}
				style={{ opacity: busy ? 0.55 : 1, pointerEvents: busy ? 'none' : 'auto', transition: 'opacity 120ms' }}
			>
				<table className="wp-list-table widefat fixed striped" style={{ width: '100%' }}>
					<thead>
						<tr>
							<th style={{ width: 50 }} scope="col">
								ID
							</th>
							{config.defaultColumns.map(renderHeader)}
							{writable && (
								<th style={{ width: 120 }} scope="col">
									Actions
								</th>
							)}
						</tr>
					</thead>
					<tbody>
						{docs.map((doc: any) => (
							<tr key={doc.id}>
								<td>{doc.id}</td>
								{config.defaultColumns.map((col) => (
									<td key={col}>{getCellValue(doc, col)}</td>
								))}
								{writable && (
									<td>
										<Button variant="secondary" onClick={() => onEdit(doc.id)} style={{ marginRight: 4 }}>
											Edit
										</Button>
										<Button variant="tertiary" isDestructive onClick={() => handleDelete(doc.id)}>
											Delete
										</Button>
									</td>
								)}
							</tr>
						))}
						{docs.length === 0 && (
							<tr>
								<td colSpan={config.defaultColumns.length + (writable ? 2 : 1)} style={{ textAlign: 'center', padding: 24 }}>
									{debouncedSearch
										? `No ${config.pluralLabel} match your search.`
										: `No ${config.pluralLabel} found. Click "Add ${config.label}" to create one.`}
								</td>
							</tr>
						)}
					</tbody>
				</table>
			</div>

			{totalPages > 1 && (
				<nav aria-label={`${config.pluralLabel} pagination`} style={{ marginTop: 16, display: 'flex', gap: 8, alignItems: 'center' }}>
					<Button
						variant="secondary"
						disabled={page <= 1 || busy}
						onClick={() => setPage((p) => Math.max(1, p - 1))}
					>
						Previous
					</Button>
					<span aria-current="page">
						Page {page} of {totalPages}
					</span>
					<Button
						variant="secondary"
						disabled={page >= totalPages || busy}
						onClick={() => setPage((p) => p + 1)}
					>
						Next
					</Button>
					<span aria-live="polite">
						Showing {rangeStart}–{rangeEnd} of {totalDocs}
					</span>
				</nav>
			)}
		</div>
	);
}
