/**
 * Sofabuilt plugins (docs/specs/sofabuilt.md): what the agents are told, and the release artifact,
 * the plugin as a ZIP whose top folder is its slug, which is what WordPress and Playground install.
 */
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { cp, mkdir, mkdtemp, readFile, readdir, rm } from "node:fs/promises";
import { existsSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { ARTIFACTS_PATH } from "./repo.ts";

const exec = promisify(execFile);

/** Added to the coding, fix and revise prompts for a plugin repository. */
export const WP_PLUGIN_RULES =
  "This repository is a WordPress plugin (see README.md). The plugin lives in plugin/ with the main file plugin/<slug>.php, where <slug> is the Text Domain. " +
  "Follow the WordPress coding standards and the WordPress.org plugin guidelines: a full plugin header (Plugin Name, Description, Version, Requires at least, Requires PHP, Author: Sofabuilt, License: GPLv2 or later, Text Domain, and 'Requires Plugins: woocommerce' when needed); " +
  "a readme.txt in the WordPress.org format (Contributors, Tags, Requires at least, Tested up to = the current WordPress version, Stable tag = the plugin Version, License, short description, Description, Installation, FAQ, Changelog); " +
  "an ABSPATH guard at the top of every PHP file; a unique prefix or namespace for every function, class, option, hook, post type and script handle; " +
  "nonces and capability checks on every form and AJAX/REST write; sanitize every input and escape every output (esc_html, esc_attr, esc_url, wp_kses_post); $wpdb->prepare for SQL; " +
  "all user-facing strings translatable with the plugin's text domain; enqueue scripts and styles properly and only where needed; " +
  "no external requests, tracking or remote assets without an explicit setting the site owner turns on; uninstall.php removes the plugin's options and tables. " +
  "Never use the name, logo or texts of another plugin or company. No hidden files and no Markdown files inside plugin/. " +
  "Tests: npm test runs PHP lint, activation in a WordPress sandbox and Plugin Check; every automated criterion needs test/cases/<key>.mjs using the helpers in test/wp.mjs (needWordPress(), wp([...]), phpEval('...')).";

/**
 * Added for the sell-ready package: Freemius does licence keys, updates for buyers, the checkout and
 * EU VAT. The seller creates the product in their own Freemius account later, so the plugin must
 * run as a free version until its id and public key are filled in.
 */
export const WP_PLUGIN_SELL_RULES =
  "The customer bought the sell-ready package: build the Freemius WordPress SDK in so the plugin can be sold with licence keys and automatic updates. " +
  "Clone https://github.com/Freemius/wordpress-sdk (latest release tag) into plugin/vendor/freemius (no .git folder) and add plugin/composer.json that names freemius/wordpress-sdk. " +
  "Create plugin/includes/sell-config.php that defines <PREFIX>_FS_ID, <PREFIX>_FS_PUBLIC_KEY (both empty strings) and <PREFIX>_FS_HAS_PAID_PLANS (true), with a comment that the seller fills them in from their Freemius dashboard. " +
  "In the main file, right after the ABSPATH guard, add the standard Freemius init function <prefix>_fs() that requires vendor/freemius/start.php and calls fs_dynamic_init with id, slug, type plugin, public_key, is_premium true, has_premium_version true, has_paid_plans, menu slug of the plugin's settings page; " +
  "but only when the id and the public key are not empty, otherwise <prefix>_fs() returns null and the plugin runs as its free version. " +
  "Put every paid feature behind a helper that is true only when <prefix>_fs() exists and can_use_premium_code__premium_only() is true, so Freemius can strip it from the free build. Decide with the scope which features are free and which are paid: the free version must be useful on its own. " +
  "Add a short SELLING.md next to the repository's README (not inside plugin/) that lists the paid features and the three seller steps: create the product in Freemius, paste the id and public key into includes/sell-config.php, upload the ZIP to Freemius for deployment. " +
  "Add automated criteria for: the plugin runs with empty Freemius keys, and paid features stay off without a licence.";

/** Added to the product prompt for a plugin: what a good spec and testable criteria look like. */
export const WP_PLUGIN_PRODUCT =
  "This is a WordPress plugin. The context's `scope` is what the customer agreed to and paid for: build exactly its features, nothing from not_included. Its `modules` are the parts the customer paid for and are binding: a feature that would need a part not in `modules` is out of scope. " +
  "SPEC.md: slug (lowercase, hyphens, a new name, never another plugin's), admin screens, front-end output (blocks/shortcodes), data stored (options, post types, tables), hooks, requirements. " +
  "Automated criteria must be checkable with WP-CLI inside WordPress (for example: an option is registered with a default, a shortcode renders the expected markup, a REST route returns 200 for an admin, a post type exists).";

export interface PluginInfo {
  main: string;
  slug: string;
  version: string;
}

export async function pluginInfo(dir: string): Promise<PluginInfo | null> {
  const pdir = path.join(dir, "plugin");
  if (!existsSync(pdir)) return null;
  for (const f of (await readdir(pdir)).filter((f) => f.endsWith(".php"))) {
    const src = (await readFile(path.join(pdir, f), "utf8")).slice(0, 8192);
    if (!/Plugin Name:/i.test(src)) continue;
    const header = (name: string) => src.match(new RegExp(`^[\\s*#@]*${name}:\\s*(.+)$`, "im"))?.[1].trim() ?? null;
    return {
      main: f,
      slug: (header("Text Domain") ?? path.basename(f, ".php")).toLowerCase().replace(/[^a-z0-9-]/g, "-"),
      version: header("Version") ?? "0.1.0",
    };
  }
  return null;
}

/** plugin/ zipped as <slug>/..., stored with the project's artifacts. Returns the relative path. */
export async function zipPlugin(dir: string, projectId: string): Promise<{ artifact_path: string; version: string; slug: string }> {
  const info = await pluginInfo(dir);
  if (!info) throw new Error("no plugin main file with a 'Plugin Name:' header in plugin/");
  const tmp = await mkdtemp(path.join(os.tmpdir(), "plugin-"));
  try {
    await cp(path.join(dir, "plugin"), path.join(tmp, info.slug), { recursive: true });
    const rel = path.join(projectId, `${info.slug}-${info.version}.zip`);
    const out = path.join(ARTIFACTS_PATH, rel);
    await mkdir(path.dirname(out), { recursive: true });
    await rm(out, { force: true });
    await exec("zip", ["-qr", out, info.slug, "-x", "*/.*"], { cwd: tmp });
    return { artifact_path: rel, version: info.version, slug: info.slug };
  } finally {
    await rm(tmp, { recursive: true, force: true });
  }
}
