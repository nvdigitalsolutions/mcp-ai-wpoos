/**
 * Managers section — promote/demote WordPress users with CMS manager access.
 *
 * Read-only for site owners (protected) and for yourself (the server
 * enforces the same rules; the buttons are disabled client-side for clarity).
 */
import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import {
	useManagers,
	useManagerSearch,
	useManagerMutation,
} from '../hooks/useCollection';
import type { Manager } from '../types';

export function ManagersView() {
	const [term, setTerm] = useState('');
	const { data, isLoading, error } = useManagers();
	const { data: candidates } = useManagerSearch(term);
	const mutation = useManagerMutation();

	const handleDemote = (manager: Manager) => {
		if (!confirm(`Remove manager access from ${manager.name}?`)) return;
		mutation.mutate({ id: manager.id, manage: false });
	};

	const handlePromote = (candidate: Manager) => {
		mutation.mutate({ id: candidate.id, manage: true });
		setTerm('');
	};

	const candidatesList = candidates?.docs ?? [];
	const managers = data?.docs ?? [];

	return (
		<div>
			<div
				style={{
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					marginBottom: 16,
				}}
			>
				<h2>Managers</h2>
			</div>

			<p style={{ marginTop: 0 }}>
				Managers can edit the catalog from this CMS. Site owners are
				managed from WordPress and can't be changed here.
			</p>

			<TextControl
				label="Add a manager"
				placeholder="Search by name or email…"
				value={term}
				onChange={setTerm}
			/>

			{term.trim().length >= 2 && (
				<div style={{ marginBottom: 24 }}>
					{candidatesList.length === 0 && <p>No users found.</p>}
					{candidatesList.map((candidate) => (
						<div
							key={candidate.id}
							style={{
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'center',
								padding: '8px 0',
								borderBottom: '1px solid #e0e0e0',
							}}
						>
							<span>
								{candidate.name} ({candidate.email})
								{candidate.isManager && <em> — already a manager</em>}
								{candidate.protected && <em> — site owner</em>}
							</span>
							<Button
								variant="secondary"
								disabled={candidate.isManager || candidate.protected}
								onClick={() => handlePromote(candidate)}
							>
								Add as manager
							</Button>
						</div>
					))}
				</div>
			)}

			{mutation.isError && (
				<p style={{ color: 'red' }}>
					{(mutation.error as Error)?.message || 'Could not update the manager.'}
				</p>
			)}

			{isLoading && <p>Loading managers…</p>}
			{error && <p style={{ color: 'red' }}>Error loading managers.</p>}

			<table
				className="wp-list-table widefat fixed striped"
				style={{ width: '100%' }}
			>
				<thead>
					<tr>
						<th style={{ width: 50 }}>ID</th>
						<th>Name</th>
						<th>Email</th>
						<th style={{ width: 160 }}>Actions</th>
					</tr>
				</thead>
				<tbody>
					{managers.map((manager) => (
						<tr key={manager.id}>
							<td>{manager.id}</td>
							<td>
								{manager.name}
								{manager.isSelf && <em> (you)</em>}
							</td>
							<td>{manager.email}</td>
							<td>
								<Button
									variant="tertiary"
									isDestructive
									disabled={manager.protected || manager.isSelf}
									onClick={() => handleDemote(manager)}
								>
									Remove access
								</Button>
							</td>
						</tr>
					))}
					{managers.length === 0 && !isLoading && (
						<tr>
							<td
								colSpan={4}
								style={{ textAlign: 'center', padding: 24 }}
							>
								No managers found. Use the search box above to add one.
							</td>
						</tr>
					)}
				</tbody>
			</table>
		</div>
	);
}
