/**
 * ScheduleManagerPage — Pro schedule + preset management
 * (Proposal 084 Phase 3b, gap P9).
 *
 * Consumes the existing mcp-ai-pro/v1 schedule REST surface (list/create/
 * get/update/delete/trigger/toggle/history) and the SA presets REST
 * (/presets, /presets/{id}/install) — no new controller. The wp-admin page
 * stays behind its kill-switch until parity is verified.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useScheduleManager } from '../../hooks/useScheduleManager';
import { useBootstrap } from '../../hooks/useBootstrap';

const SCHEDULE_TYPES = [
	{ value: 'assistant_run', label: __( 'Assistant Run', 'nvoos-pro-spa' ) },
	{ value: 'channel_broadcast', label: __( 'Channel Broadcast', 'nvoos-pro-spa' ) },
	{ value: 'workflow_builder', label: __( 'Workflow Builder', 'nvoos-pro-spa' ) },
];

function Badge( { label, ok }: { label: string; ok: boolean } ): JSX.Element {
	return (
		<span className={ `nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--${ ok ? 'ok' : 'warning' }` }>
			{ label }
		</span>
	);
}

export function ScheduleManagerPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		schedules,
		presets,
		loading,
		error,
		schedulesError,
		actionError,
		refresh,
		createSchedule,
		removeSchedule,
		triggerSchedule,
		toggleSchedule,
		installPreset,
	} = useScheduleManager(
		runtime
			? { endpoint: runtime.endpoints.scheduleManager ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ tab, setTab ] = useState< 'schedules' | 'presets' >( 'schedules' );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ showForm, setShowForm ] = useState< boolean >( false );
	const [ form, setForm ] = useState( {
		name: '',
		schedule_type: 'assistant_run',
		schedule: '',
		description: '',
	} );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Schedule Manager', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading schedules…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Schedule Manager', 'nvoos-pro-spa' ) }</h2>
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
		const created = await createSchedule( {
			name: form.name.trim(),
			description: form.description.trim(),
			schedule_type: form.schedule_type,
			schedule: form.schedule.trim(),
		} );
		setBusy( null );
		if ( created ) {
			setForm( { name: '', schedule_type: 'assistant_run', schedule: '', description: '' } );
			setShowForm( false );
		}
	};

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Schedule Manager', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Recurring assistant runs, channel broadcasts, and workflow schedules.', 'nvoos-pro-spa' ) }
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

			<div className="nvoos-pro-spa-token-manager__stats" role="tablist" aria-label={ __( 'Schedule Manager tabs', 'nvoos-pro-spa' ) }>
				<button
					type="button"
					role="tab"
					aria-selected={ tab === 'schedules' }
					className="nvoos-pro-spa-token-manager__action"
					onClick={ () => setTab( 'schedules' ) }
				>
					{ __( 'Schedules', 'nvoos-pro-spa' ) } ({ schedules.length })
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ tab === 'presets' }
					className="nvoos-pro-spa-token-manager__action"
					onClick={ () => setTab( 'presets' ) }
				>
					{ __( 'Presets', 'nvoos-pro-spa' ) } ({ presets.length })
				</button>
			</div>

			{ ( error || actionError ) && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ actionError ?? error }</p>
				</div>
			) }

			{ tab === 'schedules' && (
				<section className="nvoos-pro-spa-diagnostics__card">
					<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Schedules', 'nvoos-pro-spa' ) }</h3>
					<p className="nvoos-pro-spa-diagnostics__muted">
						<button
							type="button"
							className="nvoos-pro-spa-token-manager__action"
							onClick={ () => setShowForm( ( value ) => ! value ) }
						>
							{ showForm ? __( 'Hide form', 'nvoos-pro-spa' ) : __( 'New schedule', 'nvoos-pro-spa' ) }
						</button>
					</p>

					{ showForm && (
						<div className="nvoos-pro-spa-diagnostics__card" role="form" aria-label={ __( 'New schedule form', 'nvoos-pro-spa' ) }>
							<div className="nvoos-pro-spa-settings-form__row">
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
									{ __( 'Type', 'nvoos-pro-spa' ) }
									<select
										className="nvoos-pro-spa-assistants-form__select"
										value={ form.schedule_type }
										onChange={ ( event ) => setForm( { ...form, schedule_type: event.target.value } ) }
									>
										{ SCHEDULE_TYPES.map( ( option ) => (
											<option key={ option.value } value={ option.value }>
												{ option.label }
											</option>
										) ) }
									</select>
								</label>
							</div>
							<label className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'Cron expression (e.g. * * * * *)', 'nvoos-pro-spa' ) }
								<input
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.schedule }
									onChange={ ( event ) => setForm( { ...form, schedule: event.target.value } ) }
								/>
							</label>
							<label className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'Description', 'nvoos-pro-spa' ) }
								<input
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.description }
									onChange={ ( event ) => setForm( { ...form, description: event.target.value } ) }
								/>
							</label>
							<p>
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ busy !== null || ! form.name.trim() }
									onClick={ () => void submitForm() }
								>
									{ __( 'Create schedule', 'nvoos-pro-spa' ) }
								</button>
							</p>
						</div>
					) }

					{ schedulesError && schedules.length === 0 && (
						<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
							<p>{ schedulesError }</p>
						</div>
					) }

					{ schedules.length === 0 ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'No schedules yet.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Schedules', 'nvoos-pro-spa' ) }>
							{ schedules.map( ( schedule ) => {
								const key = `schedule-${ schedule.id }`;
								return (
									<div key={ key } className="nvoos-pro-spa-tools-list__row" role="listitem">
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<strong className="nvoos-pro-spa-tools-list__name">{ schedule.name }</strong>
											<code className="nvoos-pro-spa-tools-list__slug">
												{ schedule.schedule_type || '—' } · { schedule.schedule || '—' }
											</code>
										</div>
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<Badge
												label={ schedule.enabled ? __( 'enabled', 'nvoos-pro-spa' ) : __( 'disabled', 'nvoos-pro-spa' ) }
												ok={ Boolean( schedule.enabled ) }
											/>
										</div>
										<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
											{ schedule.next_run && (
												<>
													{ __( 'Next', 'nvoos-pro-spa' ) }: { schedule.next_run }
													<br />
												</>
											) }
											{ schedule.last_run && (
												<>
													{ __( 'Last', 'nvoos-pro-spa' ) }: { schedule.last_run }
												</>
											) }
										</div>
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<button
												type="button"
												className="nvoos-pro-spa-token-manager__action"
												disabled={ busy !== null }
												onClick={ () => {
													setBusy( key );
													void triggerSchedule( schedule.id ).finally( () => setBusy( null ) );
												} }
											>
												{ __( 'Run now', 'nvoos-pro-spa' ) }
											</button>
											<button
												type="button"
												className="nvoos-pro-spa-token-manager__action"
												disabled={ busy !== null }
												onClick={ () => {
													setBusy( key );
													void toggleSchedule( schedule.id ).finally( () => setBusy( null ) );
												} }
											>
												{ schedule.enabled ? __( 'Disable', 'nvoos-pro-spa' ) : __( 'Enable', 'nvoos-pro-spa' ) }
											</button>
											<button
												type="button"
												className="nvoos-pro-spa-token-manager__action nvoos-pro-spa-token-manager__action--danger"
												disabled={ busy !== null }
												onClick={ () => {
													// eslint-disable-next-line no-alert -- parity with the wp-admin confirm().
													if ( window.confirm( __( 'Delete this schedule permanently?', 'nvoos-pro-spa' ) ) ) {
														setBusy( key );
														void removeSchedule( schedule.id ).finally( () => setBusy( null ) );
													}
												} }
											>
												{ __( 'Delete', 'nvoos-pro-spa' ) }
											</button>
										</div>
									</div>
								);
							} ) }
						</div>
					) }
				</section>
			) }

			{ tab === 'presets' && (
				<section className="nvoos-pro-spa-diagnostics__card">
					<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Presets', 'nvoos-pro-spa' ) }</h3>
					{ presets.length === 0 ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'No presets available.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Schedule presets', 'nvoos-pro-spa' ) }>
							{ presets.map( ( preset ) => {
								const key = `preset-${ preset.id }`;
								return (
									<div key={ key } className="nvoos-pro-spa-tools-list__row" role="listitem">
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<strong className="nvoos-pro-spa-tools-list__name">{ preset.name }</strong>
											<code className="nvoos-pro-spa-tools-list__slug">
												{ [ preset.category, preset.toolkit ].filter( Boolean ).join( ' · ' ) || '—' }
											</code>
										</div>
										<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
											{ preset.description || '—' }
										</div>
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<button
												type="button"
												className="nvoos-pro-spa-token-manager__action"
												disabled={ busy !== null }
												onClick={ () => {
													setBusy( key );
													void installPreset( preset.id ).finally( () => setBusy( null ) );
												} }
											>
												{ __( 'Install', 'nvoos-pro-spa' ) }
											</button>
										</div>
									</div>
								);
							} ) }
						</div>
					) }
				</section>
			) }
		</div>
	);
}
