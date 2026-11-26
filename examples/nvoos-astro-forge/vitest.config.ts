// SPDX-License-Identifier: MIT
import { defineConfig } from 'vitest/config';

// A large slice of this suite does real filesystem work: it scaffolds a whole
// Astro project into a temp dir, then copies, vendors, and rewrites it. That is
// comfortably inside vitest's 5s default on an idle machine, but on a contended
// runner (many workers, an on-access virus scanner, a busy temp volume) it
// overruns and surfaces as a spurious "Test timed out in 5000ms" — which reads
// as a real failure and sends people hunting for a bug that isn't there.
//
// Raising the budget changes no assertion and masks nothing: the e2e gate sets
// its own much larger per-test timeouts inline (120s–420s), and inline values
// still win over this default.
export default defineConfig( {
	test: {
		testTimeout: 30_000,
		// Cleanup hooks delete the temp trees these tests create. Deleting a
		// deep tree can take a moment to drain on Windows, so give the hooks
		// the same headroom as the tests themselves.
		hookTimeout: 30_000,
	},
} );
