import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { existsSync } from "node:fs";
import { cp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { ARTIFACTS_PATH, commitAll } from "./repo.ts";

const exec = promisify(execFile);

export interface WebPreview {
  platform: "web";
  version: string;
  artifact_path: string;
  status: "preview";
}

/** Route prefix under which the API serves `<artifacts>/<project>/web/`. */
export function previewBasePath(projectId: string): string {
  return `/api/preview/${projectId}`;
}

/**
 * Static web export of the app so reviewers can open it in a browser
 * (portal "open preview" link) — no .apk install, no simulator. Best-effort:
 * the caller records a failure as `preview_error` instead of failing release.
 */
export async function exportWebPreview(dir: string, projectId: string, stack: string, version: string): Promise<WebPreview> {
  const base = previewBasePath(projectId);
  const out = stack === "expo" ? await exportExpo(dir, base) : await exportNext(dir, base);
  const rel = path.join(projectId, "web");
  const dest = path.join(ARTIFACTS_PATH, rel);
  await rm(dest, { recursive: true, force: true });
  await mkdir(dest, { recursive: true });
  await cp(out, dest, { recursive: true });
  if (!existsSync(path.join(dest, "index.html"))) throw new Error(`${stack} web export produced no index.html`);
  return { platform: "web", version, artifact_path: rel, status: "preview" };
}

async function run(cmd: string, args: string[], dir: string, env: Record<string, string> = {}): Promise<string> {
  try {
    const { stdout } = await exec(cmd, args, {
      cwd: dir,
      env: { ...process.env, CI: "1", EXPO_NO_TELEMETRY: "1", NEXT_TELEMETRY_DISABLED: "1", ...env },
      maxBuffer: 32 * 1024 * 1024,
    });
    return stdout;
  } catch (e) {
    const err = e as { stdout?: string; stderr?: string; message?: string };
    const tail = `${err.stderr ?? ""}\n${err.stdout ?? ""}`.trim().slice(-1200);
    throw new Error(`${cmd} ${args.slice(0, 2).join(" ")} failed: ${tail || err.message}`);
  }
}

async function ensureDeps(dir: string): Promise<void> {
  if (!existsSync(path.join(dir, "node_modules"))) {
    await run("npm", ["install", "--no-audit", "--no-fund", "--loglevel=error"], dir);
  }
}

async function ensureIgnore(dir: string, lines: string[]): Promise<void> {
  const file = path.join(dir, ".gitignore");
  const have = existsSync(file) ? await readFile(file, "utf8") : "";
  const missing = lines.filter((l) => !have.split("\n").includes(l));
  if (missing.length) await writeFile(file, `${have.trimEnd()}\n${missing.join("\n")}\n`.trimStart());
}

async function exportExpo(dir: string, base: string): Promise<string> {
  await ensureDeps(dir);
  await ensureIgnore(dir, ["node_modules/", ".expo/", "dist/"]);
  const pkg = JSON.parse(await readFile(path.join(dir, "package.json"), "utf8"));
  const need = ["react-dom", "react-native-web", "@expo/metro-runtime"].filter((d) => !pkg.dependencies?.[d]);
  if (need.length) {
    await run("npx", ["expo", "install", ...need], dir);
    await commitAll(dir, "release: web export dependencies");
  }
  // Absolute asset URLs must resolve under the API's preview route.
  const file = path.join(dir, "app.json");
  const json = JSON.parse(await readFile(file, "utf8"));
  json.expo ??= {};
  json.expo.experiments ??= {};
  if (json.expo.experiments.baseUrl !== base) {
    json.expo.experiments.baseUrl = base;
    await writeFile(file, JSON.stringify(json, null, 2) + "\n");
    await commitAll(dir, "release: web preview base url");
  }
  await rm(path.join(dir, "dist"), { recursive: true, force: true });
  await run("npx", ["expo", "export", "--platform", "web", "--output-dir", "dist"], dir);
  return path.join(dir, "dist");
}

const NEXT_CONFIG = `/** @type {import('next').NextConfig} */
// NEXT_OUTPUT=export + NEXT_BASE_PATH are set by the factory's release stage
// to produce the static web preview; production builds stay standalone.
const isExport = process.env.NEXT_OUTPUT === "export";
module.exports = {
  output: isExport ? "export" : "standalone",
  basePath: process.env.NEXT_BASE_PATH || "",
  ...(isExport ? { images: { unoptimized: true }, trailingSlash: true } : {}),
};
`;

async function exportNext(dir: string, base: string): Promise<string> {
  await ensureDeps(dir);
  await ensureIgnore(dir, ["node_modules/", ".next/", "out/"]);
  // Repos seeded from the pre-preview template have a fixed standalone config.
  const cfg = path.join(dir, "next.config.js");
  if (!existsSync(cfg) || (await readFile(cfg, "utf8")).includes('module.exports = { output: "standalone" };')) {
    await writeFile(cfg, NEXT_CONFIG);
    await commitAll(dir, "release: env-driven next.config for the web preview");
  }
  await rm(path.join(dir, "out"), { recursive: true, force: true });
  await run("npx", ["next", "build"], dir, { NEXT_OUTPUT: "export", NEXT_BASE_PATH: base });
  return path.join(dir, "out");
}
