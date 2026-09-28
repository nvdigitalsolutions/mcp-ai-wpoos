/**
 * Minimal TypeScript transformer for jest (no Babel dependency).
 *
 * @babel/core in this addon's tree resolves to v8 (required by
 * @wordpress/eslint-plugin@21), which babel-jest 29 cannot drive. The
 * addon's jest suites are plain TypeScript (no JSX), so
 * typescript.transpileModule is sufficient and keeps the test stack
 * independent of the Babel version in the tree.
 *
 * @param {string} src      Source text.
 * @param {string} filename Absolute path of the file being transformed.
 * @return {{code: string}} Transpiled CommonJS code.
 */
const ts = require( 'typescript' );

module.exports = {
	process( src, filename ) {
		if ( ! /\.[jt]sx?$/.test( filename ) ) {
			return { code: src };
		}
		const { outputText } = ts.transpileModule( src, {
			compilerOptions: {
				module: ts.ModuleKind.CommonJS,
				target: ts.ScriptTarget.ES2022,
				esModuleInterop: true,
				jsx: ts.JsxEmit.React,
			},
			fileName: filename,
			reportDiagnostics: false,
		} );
		return { code: outputText };
	},
};
