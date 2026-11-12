import { useWizardStore } from '../state/wizardStore';
import { Field } from '../components/Field';

export function GatewayStep() {
	const gateway = useWizardStore( ( state ) => state.gateway );
	const patch = useWizardStore( ( state ) => state.patchGateway );
	const allowlistEmpty = gateway.allowlist.trim() === '';

	return (
		<section aria-labelledby="step-gateway">
			<h2 id="step-gateway">Bind the MCP gateway</h2>
			<p className="lede">
				The gateway shares and meters your service. It binds a slug to your estate and
				exposes namespaced tools (<code>{ '<slug>.<tool>' }</code>) behind a key you
				control.
			</p>
			<div className="grid grid--2">
				<Field label="Visibility" htmlFor="gateway-visibility">
					<select
						id="gateway-visibility"
						value={ gateway.visibility }
						onChange={ ( event ) =>
							patch( { visibility: event.target.value as 'public' | 'private' } )
						}
					>
						<option value="private">Private — key-holder only</option>
						<option value="public">Public — listed on the gateway health page</option>
					</select>
				</Field>
				<Field
					label="Tool allowlist"
					htmlFor="gateway-allowlist"
					hint="Comma-separated tool names. Least privilege by default; a public estate requires at least one."
				>
					<input
						id="gateway-allowlist"
						value={ gateway.allowlist }
						onChange={ ( event ) => patch( { allowlist: event.target.value } ) }
						placeholder="get_environment_status, deep_research"
					/>
				</Field>
			</div>
			{ gateway.visibility === 'public' && allowlistEmpty ? (
				<p className="error" role="alert">
					A public estate needs a non-empty tool allowlist.
				</p>
			) : null }
		</section>
	);
}
