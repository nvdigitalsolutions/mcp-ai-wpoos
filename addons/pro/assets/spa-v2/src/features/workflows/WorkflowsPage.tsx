/**
 * WorkflowsPage — workflow list + canvas shell (Proposal 084 Phase 3b,
 * W10.2a).
 *
 * Replaces the "Coming Soon" placeholder with the first builder increment:
 * full workflow CRUD over the existing base REST
 * (mcp-ai/v1/orchestration/workflows) plus a canvas shell that renders the
 * selected workflow's graph metadata. The visual ReactFlow canvas itself
 * is W10.2b — the shell reserves the canvas region for it.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useWorkflowBuilder } from '../../hooks/useWorkflowBuilder';
import { useBootstrap } from '../../hooks/useBootstrap';

function Badge( { label }: { label: string } ): JSX.Element {
	return (
		<span className="nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--ok">
			{ label }
		</span>
	);
}

export function WorkflowsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		workflows,
		selected,
		loading,
		error,
		actionError,
		refresh,
		selectWorkflow,
		createWorkflow,
		removeWorkflow,
		executeWorkflow,
	} = useWorkflowBuilder(
		runtime
			? { endpoint: runtime.endpoints.workflowBuilder ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ busy, setBusy ] = useState< string | null >( null );
	const [ showForm, setShowForm ] = useState< boolean >( false );
	const [ form, setForm ] = useState( { name: '', tags: '' } );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading workflows…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h2>
				{ error && <p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">{ error }</p> }
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	const submitForm = async (): Promise< void > => {
		setBusy( 'create' );
		const tags = form.tags
			.split( ',' )
			.map( ( tag ) => tag.trim() )
			.filter( Boolean );
		const created = await createWorkflow( form.name.trim(), tags );
		setBusy( null );
		if ( created ) {
			setForm( { name: '', tags: '' } );
			setShowForm( false );
		}
	};

	const nodes = selected?.graph?.nodes ?? [];
	const edges = selected?.graph?.edges ?? [];

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Compose multi-step AI automation pipelines.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ ( error || actionError ) && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ actionError ?? error }</p>
				</div>
			) }

			<div className="nvoos-pro-spa-workflows-page__layout">
				<aside className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Workflow list', 'nvoos-pro-spa' ) }>
					<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Workflows', 'nvoos-pro-spa' ) }</h3>
					<p>
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							onClick={ () => setShowForm( ( value ) => ! value ) }
						>
							{ showForm ? __( 'Hide form', 'nvoos-pro-spa' ) : __( 'New workflow', 'nvoos-pro-spa' ) }
						</button>
					</p>

					{ showForm && (
						<div role="form" aria-label={ __( 'New workflow form', 'nvoos-pro-spa' ) }>
							<label className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'Name', 'nvoos-pro-spa' ) }
								<input
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.name }
									onChange={ ( event ) => setForm( { ...form, name: event.target.value } ) }
								/>
							</label>
							<label className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'Tags (comma-separated)', 'nvoos-pro-spa' ) }
								<input
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.tags }
									onChange={ ( event ) => setForm( { ...form, tags: event.target.value } ) }
								/>
							</label>
							<p>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ busy !== null || ! form.name.trim() }
									onClick={ () => void submitForm() }
								>
									{ __( 'Create workflow', 'nvoos-pro-spa' ) }
								</button>
							</p>
						</div>
					) }

					{ workflows.length === 0 ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'No workflows yet.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Workflows', 'nvoos-pro-spa' ) }>
							{ workflows.map( ( workflow ) => (
								<div key={ workflow.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
									<div className="nvoos-pro-spa-tools-list__cell" role="cell">
										<button
											type="button"
											className="nvoos-pro-spa-token-manager__action"
											onClick={ () => void selectWorkflow( workflow.id ) }
										>
											<strong className="nvoos-pro-spa-tools-list__name">{ workflow.title }</strong>
										</button>
										<code className="nvoos-pro-spa-tools-list__slug">v{ workflow.version }</code>
									</div>
								</div>
							) ) }
						</div>
					) }
				</aside>

				<main className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Workflow canvas', 'nvoos-pro-spa' ) }>
					{ ! selected ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'Select a workflow to inspect its graph.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<>
							<div className="nvoos-pro-spa-tools-page__header">
								<h3 className="nvoos-pro-spa-diagnostics__card-title">{ selected.title }</h3>
								<Badge label={ `v${ selected.version }` } />
								{ selected.tags.map( ( tag ) => (
									<code key={ tag } className="nvoos-pro-spa-tools-list__slug">
										{ tag }
									</code>
								) ) }
							</div>

							{ selected.description && (
								<p className="nvoos-pro-spa-diagnostics__muted">{ selected.description }</p>
							) }

							<div className="nvoos-pro-spa-token-manager__stats" role="list" aria-label={ __( 'Graph stats', 'nvoos-pro-spa' ) }>
								<div className="nvoos-pro-spa-token-manager__stat" role="listitem">
									<div className="nvoos-pro-spa-token-manager__stat-label">{ __( 'Nodes', 'nvoos-pro-spa' ) }</div>
									<div className="nvoos-pro-spa-token-manager__stat-value">{ nodes.length }</div>
								</div>
								<div className="nvoos-pro-spa-token-manager__stat" role="listitem">
									<div className="nvoos-pro-spa-token-manager__stat-label">{ __( 'Edges', 'nvoos-pro-spa' ) }</div>
									<div className="nvoos-pro-spa-token-manager__stat-value">{ edges.length }</div>
								</div>
							</div>

							{ nodes.length > 0 && (
								<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Graph nodes', 'nvoos-pro-spa' ) }>
									{ nodes.map( ( node ) => (
										<div key={ node.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
											<div className="nvoos-pro-spa-tools-list__cell" role="cell">
												<strong className="nvoos-pro-spa-tools-list__name">
													{ typeof node.label === 'string' && node.label ? node.label : node.id }
												</strong>
												<code className="nvoos-pro-spa-tools-list__slug">
													{ typeof node.type === 'string' ? node.type : 'node' }
												</code>
											</div>
										</div>
									) ) }
								</div>
							) }

							<p className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'The visual drag-and-drop canvas ships in the next increment (Phase 3b W10.2b).', 'nvoos-pro-spa' ) }
							</p>

							<p>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ busy !== null }
									onClick={ () => {
										setBusy( `run-${ selected.id }` );
										void executeWorkflow( selected.id ).finally( () => setBusy( null ) );
									} }
								>
									{ __( 'Run now', 'nvoos-pro-spa' ) }
								</button>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action nvoos-pro-spa-token-manager__action--danger"
									disabled={ busy !== null }
									onClick={ () => {
										// eslint-disable-next-line no-alert -- parity with the wp-admin confirm().
										if ( window.confirm( __( 'Delete this workflow?', 'nvoos-pro-spa' ) ) ) {
											setBusy( `delete-${ selected.id }` );
											void removeWorkflow( selected.id ).finally( () => setBusy( null ) );
										}
									} }
								>
									{ __( 'Delete', 'nvoos-pro-spa' ) }
								</button>
							</p>
						</>
					) }
				</main>
			</div>
		</div>
	);
}
