/**
 * Form — react-hook-form + zod glue (shadcn form pattern, dependency-light).
 *
 * Usage:
 *   const form = useForm<Values>({ resolver: zodResolver(schema) });
 *   <Form {...form}> <FormField control name="x" render={({ field }) => (
 *     <FormItem><FormLabel>…</FormLabel><FormControl><Input {...field}/></FormControl>
 *     <FormMessage/></FormItem> )} /> </Form>
 */

import { createContext, useContext, type ComponentPropsWithoutRef, type HTMLAttributes } from 'react';
import { Controller, type ControllerProps, type FieldPath, type FieldValues, type UseFormReturn } from 'react-hook-form';
import { cn } from '@/lib/utils';
import { Label } from './label';

const FormContext = createContext<UseFormReturn | null>(null);

export function Form<T extends FieldValues>({
  children,
  ...form
}: UseFormReturn<T> & { children: React.ReactNode }) {
  return (
    <FormContext.Provider value={form as UseFormReturn}>
      <form
        onSubmit={form.handleSubmit(() => undefined)}
        className="space-y-4"
        noValidate
      >
        {children}
      </form>
    </FormContext.Provider>
  );
}

export function FormField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>(props: ControllerProps<TFieldValues, TName>) {
  return (
    <FormContext.Provider value={useContext(FormContext)}>
      <Controller {...props} />
    </FormContext.Provider>
  );
}

export function FormItem({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('space-y-1.5', className)} {...props} />;
}

export function FormLabel({
  className,
  ...props
}: ComponentPropsWithoutRef<typeof Label>) {
  return <Label className={cn(className)} {...props} />;
}

export function FormMessage({ className, children, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return (
    <p className={cn('text-sm text-red-600', className)} role="alert" {...props}>
      {children}
    </p>
  );
}
