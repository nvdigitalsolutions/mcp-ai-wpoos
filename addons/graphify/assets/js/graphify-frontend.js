/**
 * NV oOS Graphify — Frontend Graph Renderer
 *
 * @package NV_oOS_Graphify
 * @since   0.4.0
 */
( function() {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function() {
		var containers = document.querySelectorAll( '.nvoos-graphify-frontend' );

		containers.forEach( function( container ) {
			var dataEl = container.querySelector( '.nvoos-graphify-data' );
			if ( ! dataEl ) {
				return;
			}

			var data;
			try {
				data = JSON.parse( dataEl.textContent );
			} catch ( e ) {
				return;
			}

			var canvas = container.querySelector( '.nvoos-graphify-canvas' );
			if ( ! canvas || typeof cytoscape === 'undefined' ) {
				return;
			}

			var cy = cytoscape( {
				container: canvas,
				elements: {
					nodes: data.nodes || [],
					edges: data.edges || [],
				},
				style: [
					{
						selector: 'node',
						style: {
							'label': 'data(label)',
							'background-color': function( ele ) {
								var colors = [
									'#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd',
									'#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf',
								];
								return colors[ ( ele.data( 'community' ) || 0 ) % 10 ];
							},
							'width': 'mapData(degree, 0, 30, 15, 40)',
							'height': 'mapData(degree, 0, 30, 15, 40)',
							'font-size': '10px',
							'color': '#e0e0e0',
							'text-outline-width': 1,
							'text-outline-color': '#333',
						},
					},
					{
						selector: 'edge',
						style: {
							'width': 1,
							'line-color': '#555',
							'target-arrow-color': '#555',
							'target-arrow-shape': 'triangle',
							'curve-style': 'bezier',
							'opacity': 0.6,
						},
					},
				],
				layout: {
					name: typeof cytoscapeFcose !== 'undefined' ? 'fcose' : 'cose',
					animate: true,
					randomize: true,
					idealEdgeLength: 100,
					nodeRepulsion: 4500,
				},
				userZoomingEnabled: true,
				userPanningEnabled: true,
				boxSelectionEnabled: false,
			} );

			// Click node to show info
			cy.on( 'tap', 'node', function( evt ) {
				var node = evt.target;
				var url = node.data( 'source_url' );
				if ( url ) {
					window.open( url, '_blank' );
				}
			} );
		} );
	} );
} )();
