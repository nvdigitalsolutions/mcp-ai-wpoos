/**
 * Tabs — Radix Tabs primitives, unstyled.
 *
 * @since 0.3.0
 */

import * as TabsPrimitive from '@radix-ui/react-tabs';
import type { ComponentPropsWithoutRef } from 'react';

export const Tabs = TabsPrimitive.Root;

export function TabsList(
	props: ComponentPropsWithoutRef< typeof TabsPrimitive.List >
) {
	return (
		<TabsPrimitive.List
			className="nvoos-toolkit-shell-tabs"
			{ ...props }
		/>
	);
}

export function TabsTrigger(
	props: ComponentPropsWithoutRef< typeof TabsPrimitive.Trigger >
) {
	return (
		<TabsPrimitive.Trigger
			className="nvoos-toolkit-shell-tab"
			{ ...props }
		/>
	);
}

export function TabsContent(
	props: ComponentPropsWithoutRef< typeof TabsPrimitive.Content >
) {
	return <TabsPrimitive.Content { ...props } />;
}
