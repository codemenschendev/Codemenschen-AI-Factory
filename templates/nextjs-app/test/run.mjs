/**
 * Dependency-free acceptance-test runner. The Test Agent extends
 * test/cases/ with one module per acceptance criterion; each exports
 * { key, run() } and throws on failure. Keeping the runner plain Node
 * means `npm test` needs no install step to execute in the sandbox.
 */
import { readdirSync, existsSync, readFileSync } from "node:fs";

const criteria = existsSync("acceptance-criteria.json")
  ? JSON.parse(readFileSync("acceptance-criteria.json", "utf8"))
  : [];

const results = {};
let failed = 0;

if (existsSync("test/cases")) {
  for (const f of readdirSync("test/cases").filter((f) => f.endsWith(".mjs"))) {
    const mod = await import(`./cases/${f}`);
    try {
      await mod.run();
      results[mod.key] = "passed";
    } catch (e) {
      results[mod.key] = "failed";
      failed++;
      console.error(`FAIL ${mod.key}: ${e.message}`);
    }
  }
}

for (const c of criteria.filter((c) => c.kind === "automated")) {
  if (!(c.key in results)) {
    results[c.key] = "failed";
    failed++;
    console.error(`FAIL ${c.key}: no test case implements this criterion`);
  }
}

const passed = Object.values(results).filter((s) => s === "passed").length;
console.log(JSON.stringify({ passed, failed, criteria_results: results }));
process.exit(failed > 0 ? 1 : 0);
