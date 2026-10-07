/**
 * Builder node components — render smoke tests for the @xyflow/react nodes.
 *
 * `NodeProps` in @xyflow/react v12 requires the full node descriptor
 * (dragging/zIndex/positionAbsolute* etc.) that React Flow itself supplies.
 * The factory below builds a complete props object; the cast is needed
 * because the fields are typed through @xyflow/system internals.
 */

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReactFlowProvider } from '@xyflow/react';
import type { ComponentProps } from 'react';
import { ToolNode } from '../ToolNode';
import { TriggerNode } from '../TriggerNode';

function renderNode( node: React.ReactElement ) {
  // Custom nodes use <Handle/>, which requires the provider context.
  return render( <ReactFlowProvider>{ node }</ReactFlowProvider> );
}

function toolNodeProps(
  overrides: Partial<ComponentProps<typeof ToolNode>> = {}
): ComponentProps<typeof ToolNode> {
  return {
    id: 'tool-1',
    type: 'toolNode',
    data: {
      toolSlug: 'create_lead',
      toolName: 'Create Lead',
      toolkit: 'CRM',
      arguments: {},
    },
    selected: false,
    dragging: false,
    zIndex: 0,
    isConnectable: true,
    positionAbsoluteX: 0,
    positionAbsoluteY: 0,
    draggable: true,
    selectable: true,
    deletable: true,
    ...overrides,
  } as ComponentProps<typeof ToolNode>;
}

function triggerNodeProps(
  overrides: Partial<ComponentProps<typeof TriggerNode>> = {}
): ComponentProps<typeof TriggerNode> {
  return {
    id: 'trigger',
    type: 'triggerNode',
    data: { triggerType: 'cron', schedule: 'wp_mcp_ai_every_6_hours' },
    selected: false,
    dragging: false,
    zIndex: 0,
    isConnectable: true,
    positionAbsoluteX: 0,
    positionAbsoluteY: 0,
    draggable: true,
    selectable: true,
    deletable: true,
    ...overrides,
  } as ComponentProps<typeof TriggerNode>;
}

describe( 'ToolNode', () => {
  it( 'renders toolkit badge, label, and slug', () => {
    renderNode( <ToolNode { ...toolNodeProps() } /> );
    expect( screen.getByText( 'CRM' ) ).toBeInTheDocument();
    expect( screen.getByText( 'Create Lead' ) ).toBeInTheDocument();
    expect( screen.getByText( 'create_lead' ) ).toBeInTheDocument();
  } );

  it( 'falls back to the slug as label', () => {
    renderNode(
      <ToolNode
        { ...toolNodeProps( {
          data: { toolSlug: 'check_availability', toolName: '', toolkit: '', arguments: {} },
        } ) }
      />
    );
    // Label falls back to the slug, and the slug line also renders it.
    expect( screen.getAllByText( 'check_availability' ) ).toHaveLength( 2 );
  } );
} );

describe( 'TriggerNode', () => {
  it( 'renders the trigger label and humanized schedule', () => {
    renderNode( <TriggerNode { ...triggerNodeProps() } /> );
    expect( screen.getByText( 'Scheduled' ) ).toBeInTheDocument();
    expect( screen.getByText( 'Every every 6 hours' ) ).toBeInTheDocument();
  } );
} );
