/**
 * The extension's test run (Sofabuilt, docs/specs/sofabuilt.md). Plain Node, no install step.
 *
 *   manifest-valid   Manifest V3 with name, version, description, icons and every file it names
 *   store-policy     no remote code and no permission the store rejects outright
 *   js-syntax        every script parses
 *   <criterion key>  one test/cases/<key>.mjs per automated acceptance criterion
 *
 * The last line is the JSON summary the factory reads: {passed, failed, criteria_results, details}.
 */
import { spawnSync } from "node:child_process";
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

const EXT = "extension";
const results = {};
const details = [];
let failed = 0;
const fail = (key, why) => {
  if (results[key] !== "failed") failed++;
  results[key] = "failed";
  details.push(`${key}: ${why}`);
  console.error(`FAIL ${key}: ${why}`);
};
const pass = (key) => {
  if (results[key] !== "failed") results[key] = "passed";
};
const files = (dir) =>
  existsSync(dir)
    ? readdirSync(dir).flatMap((f) => {
        const p = path.join(dir, f);
        return statSync(p).isDirectory() ? files(p) : [p];
      })
    : [];

// 1. The manifest and everything it points at.
let manifest = null;
try {
  manifest = JSON.parse(readFileSync(path.join(EXT, "manifest.json"), "utf8"));
} catch (e) {
  fail("manifest-valid", `extension/manifest.json is missing or not JSON (${e.message})`);
}
if (manifest) {
  const messages = (() => {
    try {
      return JSON.parse(readFileSync(path.join(EXT, "_locales", manifest.default_locale ?? "en", "messages.json"), "utf8"));
    } catch {
      return {};
    }
  })();
  const text = (v) => (typeof v === "string" && v.startsWith("__MSG_") ? messages[v.slice(6, -2)]?.message ?? "" : v ?? "");
  if (manifest.manifest_version !== 3) fail("manifest-valid", "manifest_version must be 3");
  if (!text(manifest.name).trim()) fail("manifest-valid", "name is empty");
  if (!/^\d+(\.\d+){0,3}$/.test(manifest.version ?? "")) fail("manifest-valid", `version "${manifest.version}" is not 1 to 4 numbers`);
  const desc = text(manifest.description);
  if (!desc.trim() || desc.length > 132) fail("manifest-valid", `description must be 1 to 132 characters (is ${desc.length})`);
  for (const size of ["16", "48", "128"]) {
    const icon = manifest.icons?.[size];
    if (!icon || !existsSync(path.join(EXT, icon))) fail("manifest-valid", `icon ${size} missing`);
  }
  const named = [
    manifest.action?.default_popup,
    manifest.options_page,
    manifest.options_ui?.page,
    manifest.background?.service_worker,
    manifest.side_panel?.default_path,
    ...(manifest.content_scripts ?? []).flatMap((c) => [...(c.js ?? []), ...(c.css ?? [])]),
  ].filter(Boolean);
  for (const f of named) if (!existsSync(path.join(EXT, f))) fail("manifest-valid", `${f} is named in the manifest but missing`);
  pass("manifest-valid");

  // 2. Chrome Web Store policy that a machine can check.
  const forbidden = ["debugger", "proxy", "nativeMessaging", "declarativeNetRequestFeedback"];
  for (const p of manifest.permissions ?? []) if (forbidden.includes(p)) fail("store-policy", `permission "${p}" needs a special review; leave it out`);
  const broad = [...(manifest.host_permissions ?? []), ...(manifest.permissions ?? [])].filter((p) => p === "<all_urls>" || p === "*://*/*" || p === "tabs" || p === "history" || p === "cookies");
  if (broad.length) console.log(`warning broad permissions: ${broad.join(", ")} (the store asks why; keep only if a feature needs it)`);
}
for (const f of files(EXT).filter((f) => /\.(js|html)$/.test(f))) {
  const src = readFileSync(f, "utf8");
  if (/\beval\s*\(|new\s+Function\s*\(/.test(src)) fail("store-policy", `${f}: eval or new Function (remote or generated code is not allowed)`);
  if (/<script[^>]+src=["']https?:/i.test(src) || /import\s*\(?\s*["']https?:/.test(src) || /importScripts\(\s*["']https?:/.test(src)) fail("store-policy", `${f}: loads code from another server`);
  if (f.endsWith(".js") && src.split("\n").some((l) => l.length > 1500)) fail("store-policy", `${f}: minified or obfuscated code is not allowed`);
}
pass("store-policy");

// 3. Every script parses as an ES module, the way Manifest V3 loads them (checked as a .mjs copy,
// which every Node version reads as a module).
const tmp = mkdtempSync(path.join(os.tmpdir(), "ext-check-"));
for (const f of files(EXT).filter((f) => f.endsWith(".js"))) {
  const copy = path.join(tmp, "check.mjs");
  writeFileSync(copy, readFileSync(f));
  const r = spawnSync(process.execPath, ["--check", copy], { encoding: "utf8" });
  if (r.status !== 0) {
    const lines = (r.stderr || "").split("\n");
    const at = lines.findIndex((l) => l.includes(copy));
    const where = at >= 0 ? (lines[at].match(/:(\d+)$/)?.[1] ?? "?") : "?";
    fail("js-syntax", `${f}:${where} ${lines.find((l) => /Error/.test(l))?.trim() ?? "does not parse"}`);
  }
}
rmSync(tmp, { recursive: true, force: true });
pass("js-syntax");

// 4. One case per automated acceptance criterion.
const criteria = existsSync("acceptance-criteria.json") ? JSON.parse(readFileSync("acceptance-criteria.json", "utf8")) : [];
if (existsSync("test/cases")) {
  for (const f of readdirSync("test/cases").filter((f) => f.endsWith(".mjs"))) {
    const mod = await import(`./cases/${f}`);
    try {
      await mod.run();
      results[mod.key] = "passed";
    } catch (e) {
      fail(mod.key, e.message);
    }
  }
}
for (const c of criteria.filter((c) => c.kind === "automated")) {
  if (!(c.key in results)) fail(c.key, "no test case implements this criterion");
}

const passed = Object.values(results).filter((s) => s === "passed").length;
console.log(JSON.stringify({ passed, failed, criteria_results: results, details: details.slice(0, 40) }));
process.exit(failed > 0 ? 1 : 0);
