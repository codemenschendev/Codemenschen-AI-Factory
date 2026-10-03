/**
 * Helpers for the plugin's tests: where the plugin is, and a WordPress sandbox to run it in.
 * The sandbox (WordPress on SQLite with Plugin Check and WooCommerce) is shared by every plugin
 * repository and lives next to them (../.wp-sandbox) or at $WP_SANDBOX.
 */
import { spawnSync } from "node:child_process";
import { existsSync, readFileSync, readdirSync } from "node:fs";
import path from "node:path";

export const ROOT = process.cwd();
export const PLUGIN_DIR = path.join(ROOT, "plugin");
export const SANDBOX = process.env.WP_SANDBOX || path.resolve(ROOT, "..", ".wp-sandbox");

export class Skip extends Error {}

/** The main plugin file, its slug (Text Domain), version and whether it needs WooCommerce. */
export function pluginInfo() {
  if (!existsSync(PLUGIN_DIR)) return null;
  for (const f of readdirSync(PLUGIN_DIR).filter((f) => f.endsWith(".php"))) {
    const src = readFileSync(path.join(PLUGIN_DIR, f), "utf8").slice(0, 8192);
    if (!/Plugin Name:/i.test(src)) continue;
    const header = (name) => src.match(new RegExp(`^[\\s*#@]*${name}:\\s*(.+)$`, "im"))?.[1].trim() ?? null;
    return {
      main: f,
      slug: (header("Text Domain") ?? path.basename(f, ".php")).toLowerCase(),
      version: header("Version") ?? "0.1.0",
      requiresWoo: /woocommerce/i.test(header("Requires Plugins") ?? ""),
    };
  }
  return null;
}

/** PHP 8.1 or newer on PATH; the sandbox's SQLite driver needs it. */
export function phpVersion() {
  const r = spawnSync("php", ["-r", "echo PHP_VERSION_ID;"], { encoding: "utf8" });
  return r.status === 0 ? Number(r.stdout.trim()) : 0;
}

export function sandboxReady() {
  return phpVersion() >= 80100 && existsSync(path.join(SANDBOX, ".ready"));
}

export function needWordPress() {
  if (!sandboxReady()) throw new Skip("WordPress sandbox not available here; this runs in the factory test stage");
}

/** WP-CLI in the sandbox. Throws on a non-zero exit unless allowFail. */
export function wp(args, { allowFail = false } = {}) {
  const r = spawnSync("php", ["-d", "memory_limit=512M", path.join(SANDBOX, "wp-cli.phar"), ...args, "--allow-root", `--path=${path.join(SANDBOX, "wp")}`], {
    encoding: "utf8",
    timeout: 180_000,
    maxBuffer: 16 * 1024 * 1024,
  });
  const out = { status: r.status ?? 1, stdout: r.stdout ?? "", stderr: r.stderr ?? "" };
  if (!allowFail && out.status !== 0) throw new Error(`wp ${args.slice(0, 3).join(" ")} failed: ${(out.stderr || out.stdout).slice(-800)}`);
  return out;
}

/** Run PHP inside WordPress (plugin active) and return what it printed. */
export function phpEval(code) {
  return wp(["eval", code]).stdout;
}
