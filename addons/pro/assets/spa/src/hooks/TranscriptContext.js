/**
 * TranscriptContext — React context for sharing transcript session
 * state between the Layout (sidebar) and AgentPanel (chat surface).
 */

import { createContext } from '@wordpress/element';

export const TranscriptContext = createContext(null);
