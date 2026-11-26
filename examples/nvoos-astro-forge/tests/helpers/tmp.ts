// SPDX-License-Identifier: MIT
import { rm } from 'node:fs/promises';

// Temp-tree teardown for the suites that scaffold a whole Astro project into a
// temp dir.
//
// Deleting that tree can lose a race with everything else touching it: on
// Windows a file the on-access virus scanner or the search indexer still holds
// open cannot be unlinked, and `rm` surfaces that as ENOTEMPTY or EPERM out of
// `afterEach` — a cleanup failure that fails a test which had already passed,
// and that reads as a product bug when it is not one.
//
// `fs.rm` retries EBUSY, EMFILE, ENFILE, ENOTEMPTY and EPERM with a linear
// backoff, so a few attempts drain the ordinary case. Whatever still survives
// is litter in the OS temp dir, which the platform sweeps — never a reason to
// fail the suite.
export async function rmTemp( dir: string ): Promise< void > {
	try {
		await rm( dir, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 } );
	} catch {
		// Best effort, by design — see the comment above.
	}
}
