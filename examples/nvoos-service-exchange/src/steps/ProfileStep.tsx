import { useWizardStore } from '../state/wizardStore';
import { Field } from '../components/Field';

export function ProfileStep() {
	const profile = useWizardStore( ( state ) => state.profile );
	const patch = useWizardStore( ( state ) => state.patchProfile );

	return (
		<section aria-labelledby="step-profile">
			<h2 id="step-profile">Who is deploying?</h2>
			<p className="lede">
				The expert is the SaaS. This identity becomes your estate label, catalogue
				entry and console branding.
			</p>
			<div className="grid grid--2">
				<Field label="Your name" htmlFor="profile-name">
					<input
						id="profile-name"
						value={ profile.name }
						onChange={ ( event ) => patch( { name: event.target.value } ) }
						placeholder="Dr. Amara Silva"
						autoComplete="name"
					/>
				</Field>
				<Field label="Brand / practice name" htmlFor="profile-brand">
					<input
						id="profile-brand"
						value={ profile.brand }
						onChange={ ( event ) => patch( { brand: event.target.value } ) }
						placeholder="Silva Family Clinic"
						autoComplete="organization"
					/>
				</Field>
			</div>
			<Field label="Contact email" htmlFor="profile-email" hint="Used for provisioning notifications only.">
				<input
					id="profile-email"
					type="email"
					value={ profile.email }
					onChange={ ( event ) => patch( { email: event.target.value } ) }
					placeholder="you@example.com"
					autoComplete="email"
				/>
			</Field>
		</section>
	);
}
