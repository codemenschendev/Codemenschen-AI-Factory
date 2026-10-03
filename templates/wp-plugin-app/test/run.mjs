/**
 * The plugin's test run (Sofabuilt, docs/specs/sofabuilt.md). Plain Node, no install step.
 *
 *   php-lint          every PHP file parses
 *   plugin-activates  the plugin activates in a real WordPress without errors
 *   plugin-check      no errors from Plugin Check, the tool the WordPress.org review team uses
 *   <criterion key>   one test/cases/<key>.mjs per automated acceptance criterion
 *
 * The last line is the JSON summary the factory reads: {passed, failed, criteria_results, details}.
 */
import { spawnSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync } from "node:fs";
import path from "node:path";
import { PLUGIN_DIR, SANDBOX, Skip, phpVersion, pluginInfo, sandboxReady, wp } from "./wp.mjs";

// Each check runs on its own: run together, Plugin Check 2.x dropped some files' results.
const CHECKS = [
  "i18n_usage", "code_obfuscation", "plugin_content", "file_type", "plugin_header_fields", "late_escaping",
  "safe_redirect", "plugin_updater", "plugin_uninstall", "plugin_review_phpcs", "direct_db_queries", "plugin_readme",
  "localhost", "no_unfiltered_uploads", "trademarks", "offloading_files", "write_file", "setting_sanitization",
  "prefixing", "direct_db", "minified_files", "direct_file_access", "external_admin_menu_links",
  "wp_functions_compatibility", "enqueued_resources", "performant_wp_query_params", "php_error_reporting",
];

const results = {};
const details = [];
const skipped = [];
let failed = 0;
const fail = (key, why) => {
  results[key] = "failed";
  failed++;
  details.push(`${key}: ${why}`);
  console.error(`FAIL ${key}: ${why}`);
};
const pass = (key) => {
  if (results[key] !== "failed") results[key] = "passed";
};

const criteria = existsSync("acceptance-criteria.json") ? JSON.parse(readFileSync("acceptance-criteria.json", "utf8")) : [];
const info = pluginInfo();

function phpFiles(dir) {
  return readdirSync(dir).flatMap((f) => {
    const p = path.join(dir, f);
    if (statSync(p).isDirectory()) return f === "vendor" || f === "node_modules" ? [] : phpFiles(p);
    return f.endsWith(".php") ? [p] : [];
  });
}

if (!info) {
  fail("plugin-structure", "no PHP file with a 'Plugin Name:' header in plugin/");
} else if (phpVersion() >= 80100) {
  for (const f of phpFiles(PLUGIN_DIR)) {
    const r = spawnSync("php", ["-l", f], { encoding: "utf8" });
    if (r.status !== 0) fail("php-lint", `${path.relative(".", f)}: ${(r.stdout || r.stderr).trim().split("\n")[0]}`);
  }
  pass("php-lint");
} else {
  skipped.push("php-lint (PHP 8.1+ not on this machine)");
}

const lock = path.join(SANDBOX, ".lock");
let locked = false;
let installed = false;

async function withSandbox() {
  // One test run at a time uses the shared sandbox; a lock older than 15 minutes is stale.
  for (let i = 0; i < 120; i++) {
    try {
      mkdirSync(lock);
      locked = true;
      break;
    } catch {
      if (existsSync(lock) && Date.now() - statSync(lock).mtimeMs > 15 * 60_000) rmSync(lock, { recursive: true, force: true });
      await new Promise((r) => setTimeout(r, 5000));
    }
  }
  if (!locked) throw new Error("the WordPress sandbox stayed busy for 10 minutes");

  const target = path.join(SANDBOX, "wp", "wp-content", "plugins", info.slug);
  rmSync(target, { recursive: true, force: true });
  cpSync(PLUGIN_DIR, target, { recursive: true });
  installed = true;

  if (info.requiresWoo) wp(["plugin", "activate", "woocommerce"], { allowFail: true });
  const act = wp(["plugin", "activate", info.slug], { allowFail: true });
  const actOut = `${act.stdout}\n${act.stderr}`;
  if (act.status !== 0 || /Fatal error|Parse error|Warning:|Deprecated:|Notice:/.test(actOut)) {
    fail("plugin-activates", actOut.trim().slice(-600));
  } else {
    pass("plugin-activates");
  }

  let errors = 0;
  for (const check of CHECKS) {
    const r = wp(["plugin", "check", info.slug, `--checks=${check}`, "--format=json"], { allowFail: true });
    let file = "";
    for (const line of r.stdout.split("\n")) {
      if (line.startsWith("FILE: ")) file = line.slice(6).trim();
      if (!line.startsWith("[")) continue;
      let rows = [];
      try {
        rows = JSON.parse(line);
      } catch {
        continue;
      }
      for (const row of rows) {
        const msg = `${file}:${row.line} ${row.code}: ${String(row.message).replace(/<[^>]+>/g, "").slice(0, 220)}`;
        if (row.type === "ERROR") {
          errors++;
          details.push(`plugin-check: ${msg}`);
        } else {
          console.log(`warning ${msg}`);
        }
      }
    }
  }
  if (errors > 0) {
    results["plugin-check"] = "failed";
    failed++;
    console.error(`FAIL plugin-check: ${errors} error(s)`);
  } else {
    pass("plugin-check");
  }
}

async function runCases() {
  if (!existsSync("test/cases")) return;
  for (const f of readdirSync("test/cases").filter((f) => f.endsWith(".mjs"))) {
    const mod = await import(`./cases/${f}`);
    try {
      await mod.run();
      results[mod.key] = "passed";
    } catch (e) {
      if (e instanceof Skip) {
        results[mod.key] = "passed";
        skipped.push(`${mod.key} (${e.message})`);
      } else {
        fail(mod.key, e.message);
      }
    }
  }
}

try {
  if (info && sandboxReady()) {
    await withSandbox();
  } else if (info) {
    skipped.push("plugin-activates, plugin-check (no WordPress sandbox here)");
  }
  await runCases();
} catch (e) {
  fail("test-run", e.message);
} finally {
  if (installed) {
    wp(["plugin", "deactivate", info.slug], { allowFail: true });
    if (info.requiresWoo) wp(["plugin", "deactivate", "woocommerce"], { allowFail: true });
    rmSync(path.join(SANDBOX, "wp", "wp-content", "plugins", info.slug), { recursive: true, force: true });
  }
  if (locked) rmSync(lock, { recursive: true, force: true });
}

for (const c of criteria.filter((c) => c.kind === "automated")) {
  if (!(c.key in results)) fail(c.key, "no test case implements this criterion");
}
if (skipped.length) console.log(`skipped here: ${skipped.join("; ")}`);

const passed = Object.values(results).filter((s) => s === "passed").length;
console.log(JSON.stringify({ passed, failed, criteria_results: results, details: details.slice(0, 40) }));
process.exit(failed > 0 ? 1 : 0);
