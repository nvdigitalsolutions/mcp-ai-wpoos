/**
 * Workflow Versioning Manager
 *
 * Manages workflow versions and history.
 *
 * @package WP_MCP_AI
 * @since 2.1.0
 */

/**
 * Create a version from current workflow state
 */
export const createVersion = ( workflowData, versionNote = '' ) => {
	return {
		id: `v-${Date.now()}`,
		timestamp: Date.now(),
		name: workflowData.name,
		description: workflowData.description,
		nodes: JSON.parse( JSON.stringify( workflowData.nodes ) ),
		edges: JSON.parse( JSON.stringify( workflowData.edges ) ),
		note: versionNote,
		nodeCount: workflowData.nodes.length,
		edgeCount: workflowData.edges.length,
	};
};

/**
 * Compare two versions
 */
export const compareVersions = ( version1, version2 ) => {
	const changes = {
		nodesAdded: 0,
		nodesRemoved: 0,
		nodesModified: 0,
		edgesAdded: 0,
		edgesRemoved: 0,
	};

	// Compare nodes
	const v1NodeIds = new Set( version1.nodes.map( ( n ) => n.id ) );
	const v2NodeIds = new Set( version2.nodes.map( ( n ) => n.id ) );

	// Nodes added
	version2.nodes.forEach( ( node ) => {
		if ( ! v1NodeIds.has( node.id ) ) {
			changes.nodesAdded++;
		}
	} );

	// Nodes removed
	version1.nodes.forEach( ( node ) => {
		if ( ! v2NodeIds.has( node.id ) ) {
			changes.nodesRemoved++;
		}
	} );

	// Nodes modified (check data changes)
	version1.nodes.forEach( ( v1Node ) => {
		const v2Node = version2.nodes.find( ( n ) => n.id === v1Node.id );
		if ( v2Node ) {
			if ( JSON.stringify( v1Node.data ) !== JSON.stringify( v2Node.data ) ) {
				changes.nodesModified++;
			}
		}
	} );

	// Compare edges
	const v1EdgeIds = new Set( version1.edges.map( ( e ) => `${e.source}-${e.target}` ) );
	const v2EdgeIds = new Set( version2.edges.map( ( e ) => `${e.source}-${e.target}` ) );

	version2.edges.forEach( ( edge ) => {
		if ( ! v1EdgeIds.has( `${edge.source}-${edge.target}` ) ) {
			changes.edgesAdded++;
		}
	} );

	version1.edges.forEach( ( edge ) => {
		if ( ! v2EdgeIds.has( `${edge.source}-${edge.target}` ) ) {
			changes.edgesRemoved++;
		}
	} );

	return changes;
};

/**
 * Format version for display
 */
export const formatVersion = ( version ) => {
	const date = new Date( version.timestamp );
	return {
		...version,
		formattedDate: date.toLocaleString(),
		summary: `${version.nodeCount} nodes, ${version.edgeCount} connections`,
	};
};

/**
 * Save version to localStorage
 */
export const saveVersionToLocal = ( workflowId, version ) => {
	try {
		const key = `workflow_versions_${workflowId}`;
		const versions = getVersionsFromLocal( workflowId );
		versions.push( version );

		// Keep only last 10 versions
		if ( versions.length > 10 ) {
			versions.shift();
		}

		localStorage.setItem( key, JSON.stringify( versions ) );
		return true;
	} catch ( error ) {
		console.error( 'Error saving version:', error );
		return false;
	}
};

/**
 * Get versions from localStorage
 */
export const getVersionsFromLocal = ( workflowId ) => {
	try {
		const key = `workflow_versions_${workflowId}`;
		const data = localStorage.getItem( key );
		return data ? JSON.parse( data ) : [];
	} catch ( error ) {
		console.error( 'Error getting versions:', error );
		return [];
	}
};

/**
 * Restore version
 */
export const restoreVersion = ( version ) => {
	return {
		nodes: JSON.parse( JSON.stringify( version.nodes ) ),
		edges: JSON.parse( JSON.stringify( version.edges ) ),
		name: version.name,
		description: version.description,
	};
};
