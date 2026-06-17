/**
 * Admin Model Selector — TypeScript edition.
 *
 * Handles conditional model dropdown based on provider selection in admin
 * screens (Assistant, Profession, Team editors).  Uses jQuery for DOM
 * manipulation and AJAX.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── jQuery global (set by WordPress admin) ───────────────────────────

// Minimal jQuery interface — enough for this module without @types/jquery.
interface JQuery {
	length: number;
	attr( name: string ): string | undefined;
	attr( name: string, value: string ): this;
	val(): string | string[] | number | undefined;
	val( value: string ): this;
	is( selector: string ): boolean;
	prop( name: string, value: unknown ): this;
	data( key: string ): unknown;
	text( value: string ): this;
	append( content: string | JQuery ): this;
	after( content: string ): this;
	replaceWith( newContent: string | JQuery ): this;
	parent(): JQuery;
	find( selector: string ): JQuery;
	filter( callback: ( this: HTMLElement, index: number, element: HTMLElement ) => boolean ): JQuery;
	on( event: string, handler: ( event: Event ) => void ): this;
	each( callback: ( this: HTMLElement, index: number, element: HTMLElement ) => void ): void;
	remove(): this;
	ready( handler: () => void ): void;
}

interface JQueryXHR {
	done( callback: ( ...args: unknown[] ) => void ): this;
	fail( callback: ( jqXHR: JQueryXHR, textStatus: string, errorThrown: string ) => void ): this;
}

interface JQueryStatic {
	( selector: string | Element | Document | JQuery | ( () => void ) ): JQuery;
	ajax( settings: Record< string, unknown > ): JQueryXHR;
}

declare const jQuery: JQueryStatic;
const $ = jQuery;

// ── Types ────────────────────────────────────────────────────────────

interface ModelSelectorGlobal {
	ajaxUrl: string;
	nonce: string;
	selectModelText: string;
	errorMessage: string;
}

interface ModelsResponse {
	success: boolean;
	data?: {
		models?: Record< string, string >;
		message?: string;
	};
}

// ── Module ───────────────────────────────────────────────────────────

export const ModelSelector = {
	/**
	 * Re-select a model field from the DOM by its ID.
	 */
	getModelFieldById( fieldId: string ): JQuery {
		return $( '#' + fieldId );
	},

	/**
	 * Initialize model selector functionality on all provider selects.
	 */
	init(): void {
		$( '.wp-mcp-ai-provider-select' ).each( function ( this: HTMLElement ) {
			const $providerSelect = $( this );
			const targetSelector = $providerSelect.data( 'model-target' ) as string | undefined;
			if ( ! targetSelector ) { return; }
			const $modelField = $( targetSelector );

			if ( $modelField.length ) {
				ModelSelector.initModelField( $providerSelect );

				$providerSelect.on( 'change', () => {
					ModelSelector.handleProviderChange( $providerSelect );
				} );

				const initialProvider = $providerSelect.val() as string;
				if ( initialProvider && ModelSelector.needsModelsLoad( $modelField ) ) {
					ModelSelector.loadModels( initialProvider, $modelField );
				}
			}
		} );
	},

	initModelField( _$providerSelect: JQuery ): void {
		// No-op — the field will be replaced on first model load.
	},

	needsModelsLoad( $modelField: JQuery ): boolean {
		if ( $modelField.is( 'input[type="text"]' ) ) { return true; }
		if ( $modelField.is( 'select' ) ) {
			const optionCount = $modelField.find( 'option' ).filter( function ( this: HTMLElement ) {
				return $( this ).val() !== '';
			} ).length;
			return optionCount === 0;
		}
		return true;
	},

	handleProviderChange( $providerSelect: JQuery ): void {
		const provider = $providerSelect.val() as string;
		const targetSelector = $providerSelect.data( 'model-target' ) as string | undefined;
		if ( ! targetSelector ) { return; }

		const $modelField = $( targetSelector );
		if ( ! $modelField.length ) { return; }

		if ( ! provider ) {
			ModelSelector.convertToTextInput( $modelField );
			return;
		}
		ModelSelector.loadModels( provider, $modelField );
	},

	loadModels( provider: string, $modelField: JQuery ): void {
		if ( ! provider ) { return; }

		const currentValue = $modelField.val() as string;
		const fieldId = $modelField.attr( 'id' ) || '';
		const fieldName = $modelField.attr( 'name' ) || '';
		const fieldClasses = $modelField.attr( 'class' ) || '';

		ModelSelector.showLoadingState( $modelField );

		const global = ( window as unknown as Record< string, unknown > ).wpMcpAiModelSelector as ModelSelectorGlobal | undefined;
		if ( ! global ) { return; }

		$.ajax( {
			url: global.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_get_models_for_provider',
				nonce: global.nonce,
				provider,
			},
			success: ( response: ModelsResponse ) => {
				const $current = ModelSelector.getModelFieldById( fieldId );
				if ( response.success && response.data?.models ) {
					ModelSelector.convertToSelect( $current, response.data.models, currentValue, fieldId, fieldName, fieldClasses );
				} else {
					const errorMsg = response.data?.message || global.errorMessage;
					ModelSelector.showError( $current, errorMsg );
					ModelSelector.convertToTextInput( $current, currentValue, fieldId, fieldName, fieldClasses );
				}
			},
			error: () => {
				const $current = ModelSelector.getModelFieldById( fieldId );
				ModelSelector.showError( $current, global.errorMessage );
				ModelSelector.convertToTextInput( $current, currentValue, fieldId, fieldName, fieldClasses );
			},
		} );
	},

	convertToSelect(
		$modelField: JQuery,
		models: Record< string, string >,
		currentValue: string,
		fieldId: string,
		fieldName: string,
		fieldClasses: string,
	): void {
		const $container = $modelField.parent();
		const $select = $( '<select></select>' )
			.attr( 'id', fieldId )
			.attr( 'name', fieldName )
			.attr( 'class', fieldClasses );

		$select.append( $( '<option></option>' ).val( '' ).text(
			( ( window as unknown as Record< string, unknown > ).wpMcpAiModelSelector as ModelSelectorGlobal )?.selectModelText || 'Select a model'
		) );

		for ( const [ modelId, modelName ] of Object.entries( models ) ) {
			const $option = $( '<option></option>' ).val( modelId ).text( modelName );
			if ( modelId === currentValue ) { $option.prop( 'selected', true ); }
			$select.append( $option );
		}

		if ( currentValue && ! ( currentValue in models ) ) {
			$select.append(
				$( '<option></option>' ).val( currentValue ).text( currentValue + ' (custom)' ).prop( 'selected', true )
			);
		}

		$modelField.replaceWith( $select );
		$container.find( '.wp-mcp-ai-model-loading' ).remove();
		$container.find( '.wp-mcp-ai-model-error' ).remove();
	},

	convertToTextInput(
		$modelField: JQuery,
		currentValue?: string,
		fieldId?: string,
		fieldName?: string,
		fieldClasses?: string,
	): void {
		if ( $modelField.is( 'input[type="text"]' ) ) {
			if ( currentValue !== undefined ) { $modelField.val( currentValue ); }
			$modelField.parent().find( '.wp-mcp-ai-model-loading' ).remove();
			$modelField.parent().find( '.wp-mcp-ai-model-error' ).remove();
			return;
		}

		const cv = currentValue !== undefined ? currentValue : ( $modelField.val() as string );
		const id = fieldId || $modelField.attr( 'id' ) || '';
		const name = fieldName || $modelField.attr( 'name' ) || '';
		const classes = fieldClasses || $modelField.attr( 'class' ) || '';
		const $container = $modelField.parent();

		const $input = $( '<input type="text" />' )
			.attr( 'id', id )
			.attr( 'name', name )
			.attr( 'class', classes )
			.val( cv );

		$modelField.replaceWith( $input );
		$container.find( '.wp-mcp-ai-model-loading' ).remove();
		$container.find( '.wp-mcp-ai-model-error' ).remove();
	},

	showLoadingState( $modelField: JQuery ): void {
		$modelField.prop( 'disabled', true );
		$modelField.parent().find( '.wp-mcp-ai-model-error' ).remove();
		if ( ! $modelField.parent().find( '.wp-mcp-ai-model-loading' ).length ) {
			$modelField.after(
				'<span class="wp-mcp-ai-model-loading spinner is-active" style="float: none; margin: 0 5px;"></span>'
			);
		}
	},

	showError( $modelField: JQuery, message: string ): void {
		$modelField.parent().find( '.wp-mcp-ai-model-loading' ).remove();
		$modelField.prop( 'disabled', false );
		$modelField.parent().find( '.wp-mcp-ai-model-error' ).remove();
		$modelField.after(
			'<p class="wp-mcp-ai-model-error description" style="color: #dc3232; margin-top: 5px;">' + message + '</p>'
		);
	},
};

// ── Auto-init ────────────────────────────────────────────────────────

$( document ).ready( () => {
	if ( typeof ( window as unknown as Record< string, unknown > ).wpMcpAiModelSelector !== 'undefined' ) {
		ModelSelector.init();
	}
} );
