// One-off helper: kill stray node processes left by earlier manual server
// boots + playwright runs (the Windows sh shell does not reap background
// jobs). Only kills processes whose command line references the velocity
// fixture server, serve.mjs, or playwright test workers.
import { execSync } from 'node:child_process';

const out = execSync(
	'powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \\"Name=\'node.exe\'\\" | Select-Object ProcessId, CommandLine | ConvertTo-Json -Compress"',
	{ encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }
);
let rows = [];
try {
	const parsed = JSON.parse(out);
	rows = Array.isArray(parsed) ? parsed : [parsed];
} catch {
	process.exit(0);
}
let killed = 0;
for (const row of rows) {
	const cmd = String(row.CommandLine ?? '');
	if (/fixture-server\.mjs|scripts\\serve\.mjs|scripts\/serve\.mjs|playwright/.test(cmd)) {
		// Never kill the process running this script itself.
		if (Number(row.ProcessId) === process.pid) continue;
		try {
			execSync(`taskkill /F /PID ${row.ProcessId}`, { stdio: 'ignore' });
			killed += 1;
		} catch {
			// already gone
		}
	}
}
console.log(`[cleanup] killed ${killed} stray node process(es)`);
