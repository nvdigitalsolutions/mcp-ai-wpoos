/**
 * Command Store — Zustand store for the command palette registry.
 */

import { create } from 'zustand';

export interface Command {
	id: string;
	label: string;
	description?: string;
	category: 'navigation' | 'action' | 'tool' | 'thread';
	icon?: string;
	handler: () => void;
}

export interface CommandState {
	commands: Command[];

	setCommands: ( commands: Command[] ) => void;
	registerCommand: ( command: Command ) => void;
	unregisterCommand: ( id: string ) => void;
}

export const useCommandStore = create< CommandState >( ( set ) => ( {
	commands: [],

	setCommands: ( commands ) => set( { commands } ),
	registerCommand: ( command ) =>
		set( ( s ) => ( { commands: [ ...s.commands.filter( ( c ) => c.id !== command.id ), command ] } ) ),
	unregisterCommand: ( id ) =>
		set( ( s ) => ( { commands: s.commands.filter( ( c ) => c.id !== id ) } ) ),
} ) );
