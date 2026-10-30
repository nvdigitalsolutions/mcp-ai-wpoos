/**
 * Shared vitest setup for the bridge admin bundle.
 *
 * The /vitest entry extends vitest's own `expect` — no global leakage, so
 * the suite keeps explicit imports.
 */
import '@testing-library/jest-dom/vitest';
