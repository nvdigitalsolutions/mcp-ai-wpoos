/**
 * Tooltip — Radix Tooltip primitives with shadcn styling.
 */

import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { type ComponentPropsWithoutRef } from 'react';
import { cn } from '@/lib/utils';

export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

export function TooltipContent({
  className,
  sideOffset = 4,
  ...props
}: ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        sideOffset={sideOffset}
        className={cn(
          'z-50 overflow-hidden rounded-md bg-gray-900 px-3 py-1.5 text-xs text-white shadow-md',
          className
        )}
        {...props}
      />
    </TooltipPrimitive.Portal>
  );
}
