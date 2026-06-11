/**
 * NV oOS LibreChat — Gutenberg Block Editor Script
 *
 * Registers the block in the Gutenberg editor with an inspector panel
 * for configuring assistant_id, theme, height, and guest mode.
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement, Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const {
		PanelBody,
		SelectControl,
		TextControl,
		ToggleControl,
	} = wp.components;
	const { __ } = wp.i18n;

	registerBlockType( 'nvoos/librechat', {
		edit: function ( { attributes, setAttributes } ) {
			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Chat Settings', 'nvoos-librechat' ), initialOpen: true },
						createElement( TextControl, {
							label: __( 'Assistant ID', 'nvoos-librechat' ),
							type: 'number',
							value: attributes.assistant_id,
							onChange: function ( value ) {
								setAttributes( { assistant_id: parseInt( value, 10 ) || 0 } );
							},
							help: __( 'Leave 0 to use the default assistant configured in LibreChat settings.', 'nvoos-librechat' ),
						} ),
						createElement( SelectControl, {
							label: __( 'Theme', 'nvoos-librechat' ),
							value: attributes.theme,
							options: [
								{ label: __( 'Dark', 'nvoos-librechat' ), value: 'dark' },
								{ label: __( 'Light', 'nvoos-librechat' ), value: 'light' },
								{ label: __( 'Auto (system)', 'nvoos-librechat' ), value: 'auto' },
							],
							onChange: function ( value ) {
								setAttributes( { theme: value } );
							},
						} ),
						createElement( TextControl, {
							label: __( 'Height', 'nvoos-librechat' ),
							value: attributes.height,
							onChange: function ( value ) {
								setAttributes( { height: value } );
							},
							placeholder: '600px',
							help: __( 'CSS value (e.g. 600px, 80vh). Leave empty for auto height.', 'nvoos-librechat' ),
						} ),
						createElement( ToggleControl, {
							label: __( 'Guest Mode', 'nvoos-librechat' ),
							checked: attributes.guest,
							onChange: function ( value ) {
								setAttributes( { guest: value } );
							},
							help: __( 'Allow unauthenticated users to chat.', 'nvoos-librechat' ),
						} )
					)
				),
				createElement(
					'div',
					{
						className: 'nvoos-librechat-block-placeholder',
						style: {
							background: '#1e1e2e',
							color: '#cdd6f4',
							padding: '20px',
							borderRadius: '8px',
							textAlign: 'center',
							border: '1px solid #313244',
						},
					},
					createElement( 'strong', null, 'NV oOS LibreChat' ),
					createElement( 'p', { style: { marginTop: '8px', opacity: 0.7 } },
						__( 'A React chat UI backed by NV oOS REST endpoints. Visible on the front-end.', 'nvoos-librechat' )
					)
				)
			);
		},

		save: function () {
			// Dynamic block — rendered server-side via render_callback.
			return null;
		},
	} );
} )( window.wp );
