/**
 * Model Store — Zustand store for the currently selected AI model and profile.
 */

import { create } from 'zustand';

export interface ModelPreference {
	provider: string;
	model: string;
}

export interface ModelState {
	model: ModelPreference;
	profile: string;
	availableModels: ModelPreference[];
	availableProfiles: string[];

	setModel: ( model: ModelPreference ) => void;
	setProfile: ( profile: string ) => void;
	setAvailableModels: ( models: ModelPreference[] ) => void;
	setAvailableProfiles: ( profiles: string[] ) => void;
}

const DEFAULT_MODEL: ModelPreference = { provider: 'openai', model: 'gpt-4o' };

export const useModelStore = create< ModelState >( ( set ) => ( {
	model: { ...DEFAULT_MODEL },
	profile: 'write',
	availableModels: [],
	availableProfiles: [],

	setModel: ( model ) => set( { model } ),
	setProfile: ( profile ) => set( { profile } ),
	setAvailableModels: ( availableModels ) => set( { availableModels } ),
	setAvailableProfiles: ( availableProfiles ) => set( { availableProfiles } ),
} ) );
