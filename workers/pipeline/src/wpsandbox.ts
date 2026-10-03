/**
 * The WordPress sandbox plugin tests run in (Sofabuilt, docs/specs/sofabuilt.md): WordPress on
 * SQLite with Plugin Check and WooCommerce, built once next to the repositories (REPOS/.wp-sandbox)
 * so the worker's test stage and the code agent on the host find it at the same relative place.
 * Needs PHP 8.1+ and network on the first run; afterwards it is reused.
 */
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { copyFile, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import path from "node:path";
import { REPOS_PATH } from "./repo.ts";

const exec = promisify(execFile);
export const SANDBOX = path.join(REPOS_PATH, ".wp-sandbox");
const PLUGINS = ["sqlite-database-integration", "plugin-check", "woocommerce"];

let building: Promise<void> | null = null;

/** The sandbox's WordPress as major.minor (for readme.txt's "Tested up to"), or null without one. */
export async function currentWordPress(): Promise<string | null> {
  try {
    const src = await readFile(path.join(SANDBOX, "wp", "wp-includes", "version.php"), "utf8");
    return src.match(/\$wp_version\s*=\s*'(\d+\.\d+)/)?.[1] ?? null;
  } catch {
    return null;
  }
}

export function ensureSandbox(): Promise<void> {
  if (existsSync(path.join(SANDBOX, ".ready"))) return Promise.resolve();
  building ??= build().finally(() => {
    building = null;
  });
  return building;
}

async function sh(cmd: string, args: string[], cwd = SANDBOX): Promise<string> {
  const { stdout } = await exec(cmd, args, { cwd, maxBuffer: 32 * 1024 * 1024, timeout: 600_000 });
  return stdout;
}

const wp = (args: string[]) => sh("php", ["-d", "memory_limit=512M", path.join(SANDBOX, "wp-cli.phar"), ...args, "--allow-root", `--path=${path.join(SANDBOX, "wp")}`]);

async function build(): Promise<void> {
  console.log("wp-sandbox: building", SANDBOX);
  await rm(SANDBOX, { recursive: true, force: true });
  await mkdir(SANDBOX, { recursive: true });
  await sh("curl", ["-sSfL", "-o", "wp-cli.phar", "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"]);
  await wp(["core", "download", "--skip-content"]);
  const content = path.join(SANDBOX, "wp", "wp-content");
  await mkdir(path.join(content, "plugins"), { recursive: true });
  await mkdir(path.join(content, "database"), { recursive: true });
  for (const slug of PLUGINS) {
    const zip = path.join(SANDBOX, `${slug}.zip`);
    await sh("curl", ["-sSfL", "-o", zip, `https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip`]);
    await sh("unzip", ["-q", "-o", zip, "-d", path.join(content, "plugins")]);
    await rm(zip);
  }
  // The SQLite drop-in finds its plugin folder by itself (db.copy falls back to a relative path).
  await copyFile(path.join(content, "plugins", "sqlite-database-integration", "db.copy"), path.join(content, "db.php"));
  await wp(["config", "create", "--dbname=wp", "--dbuser=wp", "--dbpass=wp", "--skip-check"]);
  await wp(["core", "install", "--url=http://localhost", "--title=Sandbox", "--admin_user=admin", "--admin_password=sandbox",
    "--admin_email=sandbox@example.com", "--skip-email"]);
  await wp(["plugin", "activate", "plugin-check"]);
  await writeFile(path.join(SANDBOX, ".ready"), new Date().toISOString());
  console.log("wp-sandbox: ready");
}
