/**
 * BuilderPage — full-screen visual schedule builder.
 */

import { FlowCanvas } from '@/components/builder/FlowCanvas';

export function BuilderPage() {
  return (
    <div className="h-[calc(100vh-3rem)] -m-6">
      <FlowCanvas />
    </div>
  );
}
