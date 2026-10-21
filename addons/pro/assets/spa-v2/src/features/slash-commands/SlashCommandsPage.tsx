/**
 * SlashCommandsPage — slash-command catalogue + executor.
 *
 * Lists the commands visible to the current user (GET
 * /mcp-ai/v1/slash-command/list) and runs one through the executor
 * (POST /mcp-ai/v1/slash-command). Replaces the wp-admin Slash Commands
 * dashboard in the console (Proposal 084 Phase 1); the wp-admin page stays
 * behind the wp_mcp_ai_admin_page_enabled_slash_commands filter until parity
 * is verified.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useSlashCommands } from '../../hooks/useSlashCommands';
import { useBootstrap } from '../../hooks/useBootstrap';

export function SlashCommandsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { commands, loading, error, lastResult, executing, fetchCommands, executeCommand } =
		useSlashCommands(
			runtime
				? { endpoint: runtime.endpoints.slashCommands, nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
		);

	const [ command, setCommand ] = useState< string >( '' );

	if ( bootstrapLoading || ( loading && commands.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Slash Commands', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading commands…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Slash Commands', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Slash Commands', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Commands available in the chat composer for this site.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			<div
				className="nvoos-pro-spa-tools-page__controls"
				role="search"
				aria-label={ __( 'Run a command', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-tools-page__search">
					<label htmlFor="nvoos-pro-spa-slash-input" className="nvoos-pro-spa-tools-page__label">
						{ __( 'Run a command', 'nvoos-pro-spa' ) }
					</label>
					<input
						id="nvoos-pro-spa-slash-input"
						type="text"
						className="nvoos-pro-spa-tools-page__input"
						value={ command }
						onChange={ ( e ) => setCommand( e.target.value ) }
						placeholder={ __( '/command arguments…', 'nvoos-pro-spa' ) }
					/>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						disabled={ executing || ! command.trim() }
						onClick={ () => void executeCommand( command ) }
					>
						{ executing ? __( 'Running…', 'nvoos-pro-spa' ) : __( 'Run', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</div>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void fetchCommands() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ lastResult && (
				<div
					className="nvoos-pro-spa-tools-page__controls"
					role="status"
				>
					<h3 className="nvoos-pro-spa-tools-page__label">
						{ __( 'Result', 'nvoos-pro-spa' ) }
					</h3>
					<pre className="nvoos-pro-spa-slash__result">
						{ typeof lastResult.result === 'string'
							? lastResult.result
							: typeof lastResult.message === 'string'
							? lastResult.message
							: JSON.stringify( lastResult, null, 2 ) }
					</pre>
				</div>
			) }

			<div
				className="nvoos-pro-spa-tools-list"
				role="list"
				aria-label={ __( 'Available commands', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-tools-list__header" role="row">
					<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
						{ __( 'Command', 'nvoos-pro-spa' ) }
					</div>
					<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
						{ __( 'Category', 'nvoos-pro-spa' ) }
					</div>
					<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
						{ __( 'Description', 'nvoos-pro-spa' ) }
					</div>
				</div>
				{ commands.map( ( item ) => (
					<div key={ item.command } className="nvoos-pro-spa-tools-list__row" role="row">
						<div className="nvoos-pro-spa-tools-list__cell" role="cell">
							<button
								type="button"
								className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
								onClick={ () => setCommand( ( item.usage as string ) ?? `/${ item.command }` ) }
							>
								{ item.usage ?? `/${ item.command }` }
							</button>
						</div>
						<div className="nvoos-pro-spa-tools-list__cell" role="cell">
							{ item.category ? (
								<code className="nvoos-pro-spa-tools-list__capability">{ item.category }</code>
							) : (
								<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--empty">
									—
								</span>
							) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
							{ item.description || '—' }
						</div>
					</div>
				) ) }
			</div>

			<footer className="nvoos-pro-spa-tools-page__footer">
				<p>
					{ __( 'Showing', 'nvoos-pro-spa' ) } { commands.length }{ ' ' }
					{ __( 'commands', 'nvoos-pro-spa' ) }
				</p>
			</footer>
		</div>
	);
}
