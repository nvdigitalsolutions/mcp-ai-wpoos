/**
 * Sidebar — collapsible tree navigation.
 *
 * Renders the manifest `tree` as a grouped list of plugin sections,
 * each containing page links. Active page is highlighted.
 *
 * @since 1.0.0
 */

import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import type { Manifest } from '../api/manifest-client';

interface SidebarProps {
	manifest: Manifest;
	onNavClose?: () => void;
}

export default function Sidebar( { manifest, onNavClose }: SidebarProps ) {
	const params = useParams<{ '*': string }>();
	const currentSlug = params[ '*' ] ?? '';

	// Track which groups are collapsed. All open by default.
	const [ collapsed, setCollapsed ] = useState<Record<string, boolean>>( {} );

	function toggleGroup( key: string ) {
		setCollapsed( ( prev ) => ( { ...prev, [ key ]: ! prev[ key ] } ) );
	}

	return (
		<nav className="dh-sidebar" aria-label="Documentation navigation">
			{ manifest.tree.map( ( group ) => {
				const groupKey = `${ group.source }-${ group.plugin_name }`;
				const isCollapsed = !! collapsed[ groupKey ];

				return (
					<div key={ groupKey } className="dh-sidebar-group">
						<button
							type="button"
							className="dh-sidebar-group-label"
							aria-expanded={ ! isCollapsed }
							onClick={ () => toggleGroup( groupKey ) }
						>
							<span>{ group.plugin_name }</span>
							<span aria-hidden="true">{ isCollapsed ? '▶' : '▼' }</span>
						</button>

						{ ! isCollapsed && (
							<ul>
								{ group.pages.map( ( page ) => {
									const isActive = currentSlug === page.slug;
									const depth = page.slug.split( '/' ).length - 1;
									const indentClass = depth >= 2
										? 'dh-sidebar-indent-2'
										: depth === 1
											? 'dh-sidebar-indent-1'
											: '';

									return (
										<li key={ page.slug }>
											<Link
												to={ `/${ page.slug }` }
												className={ `dh-sidebar-link ${ indentClass }${ isActive ? ' dh-active' : '' }` }
												aria-current={ isActive ? 'page' : undefined }
												onClick={ onNavClose }
											>
												{ page.title }
											</Link>
										</li>
									);
								} ) }
							</ul>
						) }
					</div>
				);
			} ) }

			{ manifest.tree.length === 0 && (
				<p style={ { padding: '1rem', color: 'var(--dh-text-muted)', fontSize: 'var(--dh-font-size-sm)' } }>
					No documents indexed yet.
				</p>
			) }
		</nav>
	);
}
