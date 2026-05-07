/**
 * NV oOS Canvas Toolkit — BPMN diagram viewer/editor (bpmn-js).
 *
 * Renders a BPMN 2.0 diagram using bpmn-js `NavigatedViewer` (MIT). Users
 * can pan and zoom the rendered diagram. An XML textarea lets them paste or
 * edit raw BPMN XML; clicking "Apply" re-imports the diagram.
 *
 * Full modeller support (drag-to-create shapes) is intentionally deferred to
 * a follow-up PR to keep this initial implementation minimal.
 *
 * @link    https://github.com/bpmn-io/bpmn-js
 * @credit  bpmn-js by bpmn.io / Camunda Services GmbH (MIT)
 * @since   0.2.0
 */

import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import NavigatedViewer from 'bpmn-js/lib/NavigatedViewer';
import 'bpmn-js/dist/assets/diagram-js.css';
import 'bpmn-js/dist/assets/bpmn-js.css';

/** Minimal valid BPMN 2.0 XML shown as a starter diagram. */
const DEFAULT_BPMN = `<?xml version="1.0" encoding="UTF-8"?>
<definitions xmlns="http://www.omg.org/spec/BPMN/20100524/MODEL"
             xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI"
             xmlns:dc="http://www.omg.org/spec/DD/20100524/DC"
             xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
             targetNamespace="http://bpmn.io/schema/bpmn">
  <process id="Process_1" isExecutable="true">
    <startEvent id="StartEvent_1" name="Start" />
    <sequenceFlow id="Flow_1" sourceRef="StartEvent_1" targetRef="Task_1" />
    <task id="Task_1" name="Do something" />
    <sequenceFlow id="Flow_2" sourceRef="Task_1" targetRef="EndEvent_1" />
    <endEvent id="EndEvent_1" name="End" />
  </process>
  <bpmndi:BPMNDiagram id="BPMNDiagram_1">
    <bpmndi:BPMNPlane id="BPMNPlane_1" bpmnElement="Process_1">
      <bpmndi:BPMNShape id="_BPMNShape_StartEvent_1" bpmnElement="StartEvent_1">
        <dc:Bounds x="152" y="82" width="36" height="36" />
      </bpmndi:BPMNShape>
      <bpmndi:BPMNShape id="Task_1_di" bpmnElement="Task_1">
        <dc:Bounds x="250" y="60" width="100" height="80" />
      </bpmndi:BPMNShape>
      <bpmndi:BPMNShape id="EndEvent_1_di" bpmnElement="EndEvent_1">
        <dc:Bounds x="412" y="82" width="36" height="36" />
      </bpmndi:BPMNShape>
      <bpmndi:BPMNEdge id="Flow_1_di" bpmnElement="Flow_1">
        <dc:Waypoint x="188" y="100" /><dc:Waypoint x="250" y="100" />
      </bpmndi:BPMNEdge>
      <bpmndi:BPMNEdge id="Flow_2_di" bpmnElement="Flow_2">
        <dc:Waypoint x="350" y="100" /><dc:Waypoint x="412" y="100" />
      </bpmndi:BPMNEdge>
    </bpmndi:BPMNPlane>
  </bpmndi:BPMNDiagram>
</definitions>`;

interface BpmnCanvasProps {
	toolkit?: string;
}

export function BpmnCanvas( { toolkit }: BpmnCanvasProps ) {
	const containerRef = useRef<HTMLDivElement>( null );
	const viewerRef    = useRef<InstanceType<typeof NavigatedViewer> | null>( null );
	const [ xmlValue, setXmlValue ] = useState( DEFAULT_BPMN );
	const [ error, setError ]       = useState<string | null>( null );

	/** Import BPMN XML into the viewer. */
	const importXml = ( xml: string ) => {
		if ( ! viewerRef.current ) {
			return;
		}
		viewerRef.current.importXML( xml )
			.then( () => {
				setError( null );
				viewerRef.current?.get<{ zoom: ( level: string | number ) => void }>( 'canvas' ).zoom( 'fit-viewport' );
			} )
			.catch( ( err: { message?: string } ) => {
				setError( err?.message ?? __( 'Failed to import BPMN XML.', 'nvoos-canvas-toolkit' ) );
			} );
	};

	useEffect( () => {
		if ( ! containerRef.current ) {
			return;
		}

		const viewer = new NavigatedViewer( { container: containerRef.current } );
		viewerRef.current = viewer;
		importXml( DEFAULT_BPMN );

		return () => {
			viewer.destroy();
			viewerRef.current = null;
		};
	}, [] );

	const handleApply = () => {
		importXml( xmlValue );
	};

	return (
		<div className="nvoos-canvas-toolkit-bpmn" role="application" aria-label={ __( 'BPMN diagram editor', 'nvoos-canvas-toolkit' ) }>
			<header className="nvoos-canvas-toolkit-bpmn-header">
				<strong>{ __( 'BPMN Diagram', 'nvoos-canvas-toolkit' ) }</strong>
				{ toolkit ? (
					<span className="nvoos-canvas-toolkit-bpmn-toolkit">{ toolkit }</span>
				) : null }
			</header>

			<div className="nvoos-canvas-toolkit-bpmn-surface" ref={ containerRef } />

			{ error ? (
				<p className="nvoos-canvas-toolkit-bpmn-error" role="alert">{ error }</p>
			) : null }

			<div className="nvoos-canvas-toolkit-bpmn-xml">
				<label htmlFor="nvoos-bpmn-xml-input">
					{ __( 'BPMN XML', 'nvoos-canvas-toolkit' ) }
				</label>
				<textarea
					id="nvoos-bpmn-xml-input"
					value={ xmlValue }
					onChange={ ( e ) => setXmlValue( e.target.value ) }
					rows={ 6 }
					spellCheck={ false }
					aria-label={ __( 'BPMN XML source', 'nvoos-canvas-toolkit' ) }
				/>
				<button type="button" onClick={ handleApply }>
					{ __( 'Apply', 'nvoos-canvas-toolkit' ) }
				</button>
			</div>
		</div>
	);
}
