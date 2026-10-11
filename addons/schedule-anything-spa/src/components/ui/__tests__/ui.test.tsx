/**
 * UI kit — component tests for the shadcn-style primitives.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { Button } from '../button';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../card';
import { Input } from '../input';
import { Badge } from '../badge';
import { Skeleton } from '../skeleton';
import { Dialog, DialogTrigger, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '../dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../select';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../tabs';

describe( 'Button', () => {
  it( 'renders variants and fires clicks', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(
      <>
        <Button variant="destructive" onClick={ onClick }>
          Remove
        </Button>
        <Button variant="outline">Cancel</Button>
        <Button disabled>Nope</Button>
      </>
    );
    await user.click( screen.getByRole( 'button', { name: 'Remove' } ) );
    expect( onClick ).toHaveBeenCalledTimes( 1 );
    expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeInTheDocument();
    expect( screen.getByRole( 'button', { name: 'Nope' } ) ).toBeDisabled();
  } );
} );

describe( 'Card', () => {
  it( 'renders header, title, description, and content', () => {
    render(
      <Card>
        <CardHeader>
          <CardTitle>Usage</CardTitle>
          <CardDescription>Last 30 days</CardDescription>
        </CardHeader>
        <CardContent>Chart goes here</CardContent>
      </Card>
    );
    expect( screen.getByRole( 'heading', { name: 'Usage' } ) ).toBeInTheDocument();
    expect( screen.getByText( 'Last 30 days' ) ).toBeInTheDocument();
    expect( screen.getByText( 'Chart goes here' ) ).toBeInTheDocument();
  } );
} );

describe( 'Input / Badge / Skeleton', () => {
  it( 'renders an accessible labelled input', () => {
    render(
      <div>
        <label htmlFor="search">Search</label>
        <Input id="search" placeholder="Type…" />
      </div>
    );
    expect( screen.getByLabelText( 'Search' ) ).toHaveAttribute( 'placeholder', 'Type…' );
  } );

  it( 'renders badge variants', () => {
    render(
      <>
        <Badge variant="success">Active</Badge>
        <Badge variant="destructive">Failed</Badge>
      </>
    );
    expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
    expect( screen.getByText( 'Failed' ) ).toBeInTheDocument();
  } );

  it( 'renders skeletons', () => {
    const { container } = render( <Skeleton className="h-4 w-24" /> );
    expect( container.querySelector( '.animate-pulse' ) ).toBeInTheDocument();
  } );
} );

describe( 'Dialog', () => {
  it( 'opens via trigger, exposes the dialog role, and closes via the built-in close', async () => {
    const user = userEvent.setup();
    render(
      <Dialog>
        <DialogTrigger asChild>
          <Button variant="outline">Open</Button>
        </DialogTrigger>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Confirm</DialogTitle>
            <DialogDescription>Are you sure?</DialogDescription>
          </DialogHeader>
        </DialogContent>
      </Dialog>
    );
    expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();

    await user.click( screen.getByRole( 'button', { name: 'Open' } ) );
    const dialog = await screen.findByRole( 'dialog' );
    expect( within( dialog ).getByText( 'Are you sure?' ) ).toBeInTheDocument();

    		await user.click( within( dialog ).getByRole( 'button', { name: /Close/ } ) );
    await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument() );
  } );
} );

describe( 'Select', () => {
  function Harness( { onValue }: { onValue: ( v: string ) => void } ) {
    const [ value, setValue ] = useState( '' );
    return (
      <Select
        value={ value }
        onValueChange={ ( v ) => {
          setValue( v );
          onValue( v );
        } }
      >
        <SelectTrigger aria-label="Toolkit">
          <SelectValue placeholder="Pick…" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="crm">CRM</SelectItem>
          <SelectItem value="eca">ECA</SelectItem>
        </SelectContent>
      </Select>
    );
  }

  it( 'reports the selected item (form-free — see toolkit-shell FormView notes for the form caveat)', async () => {
    const user = userEvent.setup();
    const onValue = vi.fn();
    render( <Harness onValue={ onValue } /> );

    await user.click( screen.getByRole( 'combobox' ) );
    await user.click( await screen.findByRole( 'option', { name: 'CRM' } ) );

    await waitFor( () => expect( onValue ).toHaveBeenCalledWith( 'crm' ) );
  } );
} );

describe( 'Tabs', () => {
  it( 'switches content by tab', async () => {
    const user = userEvent.setup();
    render(
      <Tabs defaultValue="schedules">
        <TabsList>
          <TabsTrigger value="schedules">Schedules</TabsTrigger>
          <TabsTrigger value="history">History</TabsTrigger>
        </TabsList>
        <TabsContent value="schedules">Schedule list</TabsContent>
        <TabsContent value="history">Run history</TabsContent>
      </Tabs>
    );
    expect( screen.getByText( 'Schedule list' ) ).toBeVisible();
    await user.click( screen.getByRole( 'tab', { name: 'History' } ) );
    expect( screen.getByText( 'Run history' ) ).toBeVisible();
  } );
} );
