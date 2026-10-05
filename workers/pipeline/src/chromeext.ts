/**
 * Sofabuilt Chrome extensions (docs/specs/sofabuilt.md): what the agents are told, and the release
 * artifact, a ZIP with manifest.json at its root, which is what the Chrome Web Store takes.
 */
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { mkdir, readFile, rm } from "node:fs/promises";
import path from "node:path";
import { ARTIFACTS_PATH } from "./repo.ts";

const exec = promisify(execFile);

/** Added to the coding, fix and revise prompts for an extension repository. */
export const CHROME_RULES =
  "This repository is a Chrome extension, Manifest V3 (see README.md). The extension lives in extension/ and is zipped as it is. " +
  "Follow the Chrome Web Store program policies: a single clear purpose; only the permissions and host permissions the features need (prefer activeTab and optional host permissions over <all_urls>); " +
  "no remote code (no eval, no new Function, no scripts or modules from other servers, no remotely hosted Wasm); no minified or obfuscated code; " +
  "a background service worker of type module; user data stays on the device or in chrome.storage unless the scope says otherwise, and anything sent elsewhere is shown to the user first; " +
  "all user-facing strings in _locales (en, and de when German is in scope) with __MSG_ keys in the manifest; a description of at most 132 characters; icons 16, 48 and 128 px (draw simple own ones, replacing the placeholders). " +
  "Never use the name, logo or texts of another extension or company. No hidden files and no Markdown inside extension/. " +
  "Keep logic in plain ES modules in extension/lib/ that do not touch the page directly, so tests can import them. " +
  "Tests: npm test runs manifest, store-policy and syntax checks; every automated criterion needs test/cases/<key>.mjs using test/chrome.mjs (chrome stand-in, loadLib('lib/x.js')).";

/** Added to the product prompt for an extension. */
export const CHROME_PRODUCT =
  "This is a Chrome extension (Manifest V3; it also runs in Edge and Brave). The context's `scope` is what the customer agreed to and paid for: build exactly its features, nothing from not_included; its `modules` are binding. " +
  "SPEC.md: the extension's name (a new name), its single purpose, the toolbar window, the settings page, what it does on web pages, the permissions it needs and why, what it stores. " +
  "Automated criteria must be checkable in plain Node against the logic modules in extension/lib/ with the chrome stand-in (for example: a setting is saved to chrome.storage.sync, a message returns the expected answer, a context menu entry is created, a badge shows the count).";

/** extension/ zipped with manifest.json at the root. Returns the relative artifact path. */
export async function zipExtension(dir: string, projectId: string): Promise<{ artifact_path: string; version: string; slug: string }> {
  const manifest = JSON.parse(await readFile(path.join(dir, "extension", "manifest.json"), "utf8")) as { version?: string; name?: string };
  const version = manifest.version ?? "0.1.0";
  const slug = projectId.slice(0, 8);
  const rel = path.join(projectId, `extension-${version}.zip`);
  const out = path.join(ARTIFACTS_PATH, rel);
  await mkdir(path.dirname(out), { recursive: true });
  await rm(out, { force: true });
  await exec("zip", ["-qr", out, ".", "-x", ".*", "*/.*"], { cwd: path.join(dir, "extension") });
  return { artifact_path: rel, version, slug };
}
