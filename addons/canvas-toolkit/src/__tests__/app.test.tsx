/**
 * canvas-toolkit — unit tests.
 *
 * Tests App mode dispatch logic and the ComingSoon stub component.
 * All heavy canvas libraries (@xyflow/react, tldraw, bpmn-js, mermaid)
 * are kept out of the test runner by mocking the component modules that
 * import them.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Mock the four canvas surfaces so their heavy imports never load.
// ---------------------------------------------------------------------------
vi.mock( '../components/FlowCanvas',       () => ( { FlowCanvas:       () => null } ) );
vi.mock( '../components/WhiteboardCanvas', () => ( { WhiteboardCanvas: () => null } ) );
vi.mock( '../components/BpmnCanvas',       () => ( { BpmnCanvas:       () => null } ) );
vi.mock( '../components/MermaidCanvas',    () => ( { MermaidCanvas:    () => null } ) );

import { App, type CanvasMode } from '../App';
import { ComingSoon } from '../components/ComingSoon';

// ---------------------------------------------------------------------------
// App — mode dispatch
// ---------------------------------------------------------------------------
describe( 'App', () => {
	it( 'defaults to flow mode when no mode prop is supplied', () => {
		const { container } = render( <App config={ {} } /> );
		expect( container.querySelector( '[data-mode="flow"]' ) ).not.toBeNull();
	} );

	it( 'sets data-mode="flow" when mode="flow" is given', () => {
		const { container } = render( <App config={ { mode: 'flow' } } /> );
		expect( container.querySelector( '[data-mode="flow"]' ) ).not.toBeNull();
	} );

	it( 'sets data-mode="whiteboard" when mode="whiteboard" is given', () => {
		const { container } = render( <App config={ { mode: 'whiteboard' } } /> );
		expect( container.querySelector( '[data-mode="whiteboard"]' ) ).not.toBeNull();
	} );

	it( 'sets data-mode="bpmn" when mode="bpmn" is given', () => {
		const { container } = render( <App config={ { mode: 'bpmn' } } /> );
		expect( container.querySelector( '[data-mode="bpmn"]' ) ).not.toBeNull();
	} );

	it( 'falls back to flow when an unknown mode is supplied', () => {
		const { container } = render( <App config={ { mode: 'invalid' as CanvasMode } } /> );
		expect( container.querySelector( '[data-mode="flow"]' ) ).not.toBeNull();
	} );

	it( 'applies the data-theme attribute from config', () => {
		const { container } = render( <App config={ { theme: 'dark' } } /> );
		expect( container.querySelector( '[data-theme="dark"]' ) ).not.toBeNull();
	} );

	it( 'defaults data-theme to "auto" when no theme is supplied', () => {
		const { container } = render( <App config={ {} } /> );
		expect( container.querySelector( '[data-theme="auto"]' ) ).not.toBeNull();
	} );
} );

// ---------------------------------------------------------------------------
// ComingSoon stub
// ---------------------------------------------------------------------------
describe( 'ComingSoon', () => {
	it( 'renders the mode label text', () => {
		render( <ComingSoon mode="bpmn" label="BPMN Diagram" note="Shipping next release." /> );
		expect( screen.getByText( 'BPMN Diagram' ) ).toBeInTheDocument();
	} );

	it( 'contains "coming soon" copy', () => {
		render( <ComingSoon mode="bpmn" label="BPMN Diagram" note="Shipping next release." /> );
		expect( screen.getByText( /coming soon/i ) ).toBeInTheDocument();
	} );

	it( 'renders the note text', () => {
		render( <ComingSoon mode="whiteboard" label="Whiteboard" note="This feature ships in v0.3." /> );
		expect( screen.getByText( 'This feature ships in v0.3.' ) ).toBeInTheDocument();
	} );

	it( 'sets the data-mode attribute on the container', () => {
		const { container } = render(
			<ComingSoon mode="mermaid" label="Mermaid" note="Coming soon." />
		);
		expect( container.querySelector( '[data-mode="mermaid"]' ) ).not.toBeNull();
	} );
} );
