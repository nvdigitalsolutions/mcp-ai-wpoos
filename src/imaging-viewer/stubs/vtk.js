/**
 * VTK.js Stub – Imaging Viewer
 *
 * Replaces every @kitware/vtk.js subpath import in the webpack bundle with a
 * lightweight no-op object.  The 2D Stack viewer never exercises any actual
 * VTK rendering path, but several Cornerstone3D core modules (e.g.
 * vtkStreamingOpenGLTexture, vtkStreamingOpenGLRenderWindow) call
 * `macro.newInstance( extend, className )` at module-initialisation time
 * (i.e. at the top level of the module, not inside a function).  Without a
 * usable `newInstance` here those calls throw
 *   "GA(...).newInstance is not a function"
 * at bundle-load time, preventing `window.nvCs` from being set and triggering
 * the CDN fallback.
 *
 * All VTK subpaths—the macros barrel, class modules, constants, etc.—are
 * redirected to this single stub via NormalModuleReplacementPlugin.  The stub
 * is intentionally minimal: it satisfies the factory patterns that execute at
 * import time without actually performing any rendering work.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

'use strict';

/* eslint-disable jsdoc/require-jsdoc */

function noopFn() {}

/**
 * VTK-style class factory.
 *
 * `macro.newInstance( extend, className )` returns a factory *function* whose
 * call signature is `factory( initialValues? ) → instanceObject`.
 *
 * @param {Function} _extend    The extend function (ignored – stubs return {}).
 * @param {string}   _className The VTK class name (ignored – stubs return {}).
 * @returns {Function} A callable factory that creates empty instance objects.
 */
function newInstance( _extend, _className ) {
	var factory = function createVtkInstance() {
		return {};
	};
	factory.newInstance = factory;
	factory.extend      = noopFn;
	return factory;
}

/**
 * Stub that satisfies both:
 *   – `import macro from '@kitware/vtk.js/macros'`  (default import)
 *   – `import vtkCamera from '@kitware/vtk.js/Rendering/Core/Camera'`
 *
 * For class modules the consumer typically calls `vtkCamera.newInstance({})`,
 * so `newInstance` must be present on the exported object.
 *
 * For constants modules (e.g. VolumeMapper/Constants) the consumer just
 * reads named properties; returning an empty-ish object is fine since those
 * values are only used when the 3-D volume path is active.
 */
var vtkStub = {
	// VTK macro module -------------------------------------------------------
	newInstance:          newInstance,
	extend:               noopFn,
	obj:                  noopFn,
	algo:                 noopFn,
	event:                noopFn,
	chain:                noopFn,
	proxy:                noopFn,
	keystore:             noopFn,
	setGet:               noopFn,
	set:                  noopFn,
	get:                  noopFn,
	setArray:             noopFn,
	getArray:             noopFn,
	setGetArray:          noopFn,
	moveToProtected:      noopFn,
	newTypedArray:        noopFn,
	newTypedArrayFrom:    noopFn,
	traverseInstanceTree: noopFn,
	proxyPropertyMapping: noopFn,
	proxyPropertyState:   noopFn,
	vtkDebugMacro:        noopFn,
	vtkErrorMacro:        noopFn,
	vtkWarningMacro:      noopFn,
	vtkInfoMacro:         noopFn,
	vtkLogMacro:          noopFn,
	vtkOnceErrorMacro:    noopFn,
	isVtkObject:          function() { return false; },
	capitalize:           function( s ) { return s; },
	uncapitalize:         function( s ) { return s; },
	formatBytesToProperUnit: function() { return ''; },
	formatNumbersWithThousandSeparator: function( n ) { return n; },
	normalizeWheel:       function() { return {}; },
	debounce:             function( fn ) { return fn || noopFn; },
	throttle:             function( fn ) { return fn || noopFn; },
	setImmediateVTK:      function( fn ) { return fn; },
	measurePromiseExecution: function() { return Promise.resolve( 0 ); },
	setLoggerFunction:    noopFn,
	EVENT_ABORT:          'EVENT_ABORT',
	VOID:                 'VOID',
	TYPED_ARRAYS:         {},
	// HalfFloat utilities (used in OpenGL texture upload code) ---------------
	toHalf:               function( v ) { return v; },
	toFloat:              function( v ) { return v; },
};

// Support both default and named imports so that webpack's CJS→ESM interop
// layers (I.n / I.d) work correctly:
//   import macro          from '@kitware/vtk.js/macros'  → vtkStub
//   import { newInstance} from '@kitware/vtk.js/macros'  → vtkStub.newInstance
module.exports         = vtkStub;
module.exports.default = vtkStub;
