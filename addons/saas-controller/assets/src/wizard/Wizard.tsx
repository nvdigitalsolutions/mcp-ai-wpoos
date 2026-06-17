/**
 * Three-step credentials wizard.
 *
 * Step 1 — Credentials: operator pastes Cloudflare / Stripe / OpenRouter
 *          values into masked text controls.
 * Step 2 — Validate: live preflight calls hit `/connections/test` and the
 *          per-provider result (latency + message) is rendered.
 * Step 3 — Save: encrypt-at-rest via `POST /credentials`.
 *
 * The wizard never reads back plaintext from the server; on mount it
 * fetches the masked snapshot only, and treats already-configured fields
 * as "leave blank to keep". This matches the operator runbook in
 * `docs/SAAS_SETUP_GUIDE.md` §4.3.
 *
 * @package NV_oOS_SaaS_Controller
 */

import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	useClearCredentials,
	useMaskedCredentials,
	useSaveCredentials,
	useTestConnections,
} from './useCredentials';
import {
	cloudflareSchema,
	openrouterSchema,
	stripeSchema,
	type CredentialValues,
	type PreflightResult,
} from './validation';

const TEXT_DOMAIN = 'nvoos-saas-controller';

type Step = 1 | 2 | 3;

interface FieldState {
	value: string;
	error?: string;
}

const emptyField = (): FieldState => ( { value: '' } );

/**
 * Initial empty state for every credential field.
 *
 * Centralised to keep the wizard state shape consistent if the credential
 * key allowlist changes in future phases.
 */
const buildEmptyFields = (): Record< string, FieldState > => ( {
	cloudflare_account_id: emptyField(),
	cloudflare_api_token: emptyField(),
	stripe_secret_key: emptyField(),
	stripe_webhook_secret: emptyField(),
	openrouter_api_key: emptyField(),
} );

/**
 * Aggregate validation across the three provider schemas.
 *
 * Empty fields are *allowed* on update so an operator can rotate one
 * credential without retyping the rest. The connection tester will
 * fall back to the stored value for any field left blank.
 */
function validate( values: CredentialValues ): Record< string, string > {
	const errors: Record< string, string > = {};

	const tryParse = (
		schema:
			| typeof cloudflareSchema
			| typeof stripeSchema
			| typeof openrouterSchema,
		fields: string[]
	) => {
		const subset: Record< string, string > = {};
		fields.forEach( ( f ) => {
			subset[ f ] = ( values as Record< string, string > )[ f ] ?? '';
		} );
		const allEmpty = fields.every( ( f ) => '' === subset[ f ] );
		if ( allEmpty ) {
			return;
		}
		const result = schema.safeParse( subset );
		if ( ! result.success ) {
			result.error.errors.forEach( ( issue ) => {
				const path = issue.path.join( '.' );
				if ( path && ! errors[ path ] ) {
					errors[ path ] = issue.message;
				}
			} );
		}
	};

	tryParse( cloudflareSchema, [
		'cloudflare_account_id',
		'cloudflare_api_token',
	] );
	tryParse( stripeSchema, [
		'stripe_secret_key',
		'stripe_webhook_secret',
	] );
	tryParse( openrouterSchema, [ 'openrouter_api_key' ] );

	return errors;
}

interface ResultRowProps {
	label: string;
	result?: PreflightResult;
}

function ResultRow( { label, result }: ResultRowProps ): JSX.Element {
	if ( ! result ) {
		return (
			<tr>
				<td>{ label }</td>
				<td>—</td>
				<td>—</td>
			</tr>
		);
	}
	return (
		<tr>
			<td>{ label }</td>
			<td>
				<span
					style={ {
						color: result.ok ? '#2e7d32' : '#c62828',
						fontWeight: 600,
					} }
				>
					{ result.ok
						? __( '✓ OK', TEXT_DOMAIN )
						: __( '✗ Failed', TEXT_DOMAIN ) }
				</span>{ ' ' }
				<span style={ { color: '#666' } }>
					({ result.latency_ms } ms)
				</span>
			</td>
			<td>
				<code style={ { wordBreak: 'break-word' } }>
					{ result.message }
				</code>
			</td>
		</tr>
	);
}

export default function Wizard(): JSX.Element {
	const [ step, setStep ] = useState< Step >( 1 );
	const [ fields, setFields ] = useState< Record< string, FieldState > >(
		buildEmptyFields
	);
	const [ formErrors, setFormErrors ] = useState< Record< string, string > >(
		{}
	);
	const [ savedNotice, setSavedNotice ] = useState< string >( '' );

	const masked = useMaskedCredentials();
	const test = useTestConnections();
	const save = useSaveCredentials();
	const clear = useClearCredentials();

	const setField = ( key: string, value: string ) => {
		setFields( ( prev ) => ( {
			...prev,
			[ key ]: { value },
		} ) );
	};

	const collectValues = (): CredentialValues => {
		const out: CredentialValues = {};
		Object.entries( fields ).forEach( ( [ key, state ] ) => {
			if ( '' !== state.value ) {
				out[ key as keyof CredentialValues ] = state.value;
			}
		} );
		return out;
	};

	const goValidate = () => {
		const values = collectValues();
		const errors = validate( values );
		setFormErrors( errors );
		if ( 0 !== Object.keys( errors ).length ) {
			return;
		}
		setStep( 2 );
		test.mutate( values );
	};

	const goSave = () => {
		const values = collectValues();
		if ( 0 === Object.keys( values ).length ) {
			setSavedNotice(
				__(
					'Nothing to save — all fields were left blank.',
					TEXT_DOMAIN
				)
			);
			return;
		}
		save.mutate( values, {
			onSuccess: () => {
				setSavedNotice(
					__(
						'Credentials saved (encrypted at rest).',
						TEXT_DOMAIN
					)
				);
				// Reset the typed values so they don't linger in DOM.
				setFields( buildEmptyFields() );
				setStep( 3 );
			},
		} );
	};

	const renderStatus = ( key: string ): string => {
		const m = masked.data?.credentials?.[ key as keyof CredentialValues ];
		if ( ! m ) {
			return '';
		}
		return m.configured
			? `${ __( 'Stored:', TEXT_DOMAIN ) } ${ m.masked }`
			: __( 'Not configured.', TEXT_DOMAIN );
	};

	return (
		<Card style={ { maxWidth: 780, marginBottom: 24 } }>
			<CardHeader>
				<h2 style={ { margin: 0 } }>
					{ __( 'Credentials Wizard', TEXT_DOMAIN ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Paste each provider credential below. Empty fields are left untouched (use the Disconnect button to clear all). Live preflight is run before save.',
						TEXT_DOMAIN
					) }
				</p>

				{ savedNotice && (
					<Notice
						status="success"
						isDismissible
						onRemove={ () => setSavedNotice( '' ) }
					>
						{ savedNotice }
					</Notice>
				) }

				{ 1 === step && (
					<>
						<h3>{ __( 'Cloudflare', TEXT_DOMAIN ) }</h3>
						<TextControl
							label={ __( 'Account ID', TEXT_DOMAIN ) }
							help={
								formErrors.cloudflare_account_id ||
								renderStatus( 'cloudflare_account_id' )
							}
							value={ fields.cloudflare_account_id.value }
							onChange={ ( v ) =>
								setField( 'cloudflare_account_id', v )
							}
							autoComplete="off"
						/>
						<TextControl
							label={ __( 'API token', TEXT_DOMAIN ) }
							help={
								formErrors.cloudflare_api_token ||
								renderStatus( 'cloudflare_api_token' )
							}
							value={ fields.cloudflare_api_token.value }
							onChange={ ( v ) =>
								setField( 'cloudflare_api_token', v )
							}
							type="password"
							autoComplete="off"
						/>

						<h3>{ __( 'Stripe', TEXT_DOMAIN ) }</h3>
						<TextControl
							label={ __( 'Secret key', TEXT_DOMAIN ) }
							help={
								formErrors.stripe_secret_key ||
								renderStatus( 'stripe_secret_key' )
							}
							value={ fields.stripe_secret_key.value }
							onChange={ ( v ) =>
								setField( 'stripe_secret_key', v )
							}
							type="password"
							autoComplete="off"
						/>
						<TextControl
							label={ __( 'Webhook signing secret', TEXT_DOMAIN ) }
							help={
								formErrors.stripe_webhook_secret ||
								renderStatus( 'stripe_webhook_secret' )
							}
							value={ fields.stripe_webhook_secret.value }
							onChange={ ( v ) =>
								setField( 'stripe_webhook_secret', v )
							}
							type="password"
							autoComplete="off"
						/>

						<h3>{ __( 'OpenRouter', TEXT_DOMAIN ) }</h3>
						<TextControl
							label={ __( 'API key', TEXT_DOMAIN ) }
							help={
								formErrors.openrouter_api_key ||
								renderStatus( 'openrouter_api_key' )
							}
							value={ fields.openrouter_api_key.value }
							onChange={ ( v ) =>
								setField( 'openrouter_api_key', v )
							}
							type="password"
							autoComplete="off"
						/>

						<div
							style={ {
								marginTop: 16,
								display: 'flex',
								gap: 8,
							} }
						>
							<Button
								variant="primary"
								onClick={ goValidate }
								disabled={ test.isPending }
							>
								{ __( 'Validate', TEXT_DOMAIN ) }
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => clear.mutate() }
								disabled={ clear.isPending }
							>
								{ __( 'Disconnect (clear all)', TEXT_DOMAIN ) }
							</Button>
						</div>
					</>
				) }

				{ 2 === step && (
					<>
						<h3>{ __( 'Preflight results', TEXT_DOMAIN ) }</h3>
						{ test.isPending && (
							<p>
								<Spinner />{ ' ' }
								{ __(
									'Running preflight against Cloudflare, Stripe, OpenRouter…',
									TEXT_DOMAIN
								) }
							</p>
						) }
						{ test.isError && (
							<Notice status="error" isDismissible={ false }>
								{ test.error.message }
							</Notice>
						) }
						{ test.data && (
							<table className="widefat striped">
								<thead>
									<tr>
										<th>
											{ __( 'Provider', TEXT_DOMAIN ) }
										</th>
										<th>
											{ __( 'Result', TEXT_DOMAIN ) }
										</th>
										<th>
											{ __( 'Detail', TEXT_DOMAIN ) }
										</th>
									</tr>
								</thead>
								<tbody>
									<ResultRow
										label="Cloudflare"
										result={ test.data.results.cloudflare }
									/>
									<ResultRow
										label="Stripe"
										result={ test.data.results.stripe }
									/>
									<ResultRow
										label="OpenRouter"
										result={ test.data.results.openrouter }
									/>
								</tbody>
							</table>
						) }
						<div
							style={ {
								marginTop: 16,
								display: 'flex',
								gap: 8,
							} }
						>
							<Button
								variant="secondary"
								onClick={ () => setStep( 1 ) }
							>
								{ __( 'Back', TEXT_DOMAIN ) }
							</Button>
							<Button
								variant="primary"
								onClick={ goSave }
								disabled={
									save.isPending || ! test.data?.ok
								}
							>
								{ test.data?.ok
									? __( 'Save credentials', TEXT_DOMAIN )
									: __(
											'Fix errors before saving',
											TEXT_DOMAIN
									  ) }
							</Button>
						</div>
					</>
				) }

				{ 3 === step && (
					<>
						<Notice status="success" isDismissible={ false }>
							{ __(
								'Credentials saved. The static status table below now reflects them.',
								TEXT_DOMAIN
							) }
						</Notice>
						<Button
							variant="secondary"
							onClick={ () => setStep( 1 ) }
							style={ { marginTop: 16 } }
						>
							{ __( 'Edit credentials again', TEXT_DOMAIN ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}
