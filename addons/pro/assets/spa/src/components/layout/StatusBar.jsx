import CollaborativePresence from '../collaboration/CollaborativePresence';

export default function StatusBar() {
	return (
		<div className="nvoos-statusbar">
			<span className="nvoos-statusbar__agent">Ready</span>
			<CollaborativePresence />
		</div>
	);
}
