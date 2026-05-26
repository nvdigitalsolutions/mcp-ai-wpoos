/**
 * Layout — Three-column SPA layout: ThreadsSidebar | MainContent | RightPanel.
 */

import { useUIStore } from '../../store/uiStore';
import ThreadsSidebar from './ThreadsSidebar';
import RightPanel from './RightPanel';
import StatusBar from './StatusBar';
import AppRouter from '../../router';

export default function Layout() {
	const { sidebarOpen } = useUIStore();

	return (
		<div className="nvoos-layout">
			{sidebarOpen && <ThreadsSidebar />}
			<main className="nvoos-layout__main">
				<AppRouter />
			</main>
			<RightPanel />
			<StatusBar />
		</div>
	);
}
