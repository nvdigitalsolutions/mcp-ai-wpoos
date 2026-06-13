/**
 * ProfileSelector — Dropdown for Write/Ask/Minimal/Custom profiles.
 * Zed equivalent: Agent Profiles selector.
 */

import { useProfilesStore } from '../../store/profilesStore';

export default function ProfileSelector({ threadId, onProfileChange }) {
	const { profiles, activeProfile, setActiveProfile } = useProfilesStore();

	const handleChange = (e) => {
		const name = e.target.value;
		setActiveProfile(name);
		onProfileChange?.(name);
	};

	return (
		<div className="nvoos-profile-selector">
			<select
				value={activeProfile}
				onChange={handleChange}
				className="nvoos-profile-selector__select"
			>
				{profiles.map((p) => (
					<option key={p.name} value={p.name}>
						{p.label || p.name}
					</option>
				))}
			</select>
		</div>
	);
}
