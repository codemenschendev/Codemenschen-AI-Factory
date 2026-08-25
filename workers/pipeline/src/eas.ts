/**
 * EAS Build integration for the release stage (Expo stack only).
 *
 * Active when EXPO_TOKEN is set. Every appwerk app gets a unique Expo slug
 * and Android package derived from the project id, is linked to an EAS
 * project (`eas init`), and built as an installable Android .apk with the
 * template's `preview` profile. The finished .apk is copied into the shared
 * artifacts volume so the portal serves it exactly like the source bundle.
 * iOS device builds need an Apple Developer account and are deferred.
 */
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { createWriteStream, existsSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { pipeline } from "node:stream/promises";
import { Readable } from "node:stream";
import path from "node:path";
import { ARTIFACTS_PATH, commitAll } from "./repo.ts";

const exec = promisify(execFile);

export const EXPO_TOKEN = process.env.EXPO_TOKEN ?? "";
export const EXPO_OWNER = process.env.EXPO_OWNER ?? "";
export const EAS_MODE = EXPO_TOKEN.length > 0;
const EAS_PROFILE = process.env.EAS_PROFILE ?? "preview";

export interface EasBuild {
  platform: "android";
  version: string;
  artifact_path: string;
  build_id: string;
  build_url: string | null;
}

function easEnv(): NodeJS.ProcessEnv {
  return {
    ...process.env,
    EXPO_TOKEN,
    EAS_NO_VCS: "1", // pack the working tree via .easignore, never depend on git state
    CI: "1",
  };
}

/** Stable, store-safe identifiers from the project uuid. */
export function expoIdentity(projectId: string): { slug: string; androidPackage: string; iosBundle: string } {
  const hex = projectId.replace(/[^a-f0-9]/gi, "").toLowerCase() || "app";
  return {
    slug: `appwerk-${hex}`,
    androidPackage: `at.codemenschen.appwerk.p${hex}`,
    iosBundle: `at.codemenschen.appwerk.p${hex}`,
  };
}

/**
 * Idempotently patch app.json: unique slug/package, display name, optional
 * owner. Leaves everything the agents already customised (icons, colours,
 * version) untouched.
 */
export async function prepareExpoProject(dir: string, projectId: string, name: string): Promise<boolean> {
  const file = path.join(dir, "app.json");
  if (!existsSync(file)) throw new Error("app.json missing — not an Expo project");
  const json = JSON.parse(await readFile(file, "utf8"));
  const expo = (json.expo ??= {});
  const id = expoIdentity(projectId);
  let changed = false;
  const set = (obj: Record<string, unknown>, key: string, value: unknown) => {
    if (obj[key] !== value) { obj[key] = value; changed = true; }
  };
  if (!expo.slug || expo.slug === "appwerk-app" || expo.slug === "factory-app") set(expo, "slug", id.slug);
  if (!expo.name || expo.name === "Appwerk App" || expo.name === "Factory App") set(expo, "name", name.slice(0, 30));
  expo.android ??= {};
  expo.ios ??= {};
  if (!expo.android.package || String(expo.android.package).includes("PLACEHOLDER")) set(expo.android, "package", id.androidPackage);
  if (!expo.ios.bundleIdentifier || String(expo.ios.bundleIdentifier).includes("PLACEHOLDER")) set(expo.ios, "bundleIdentifier", id.iosBundle);
  if (EXPO_OWNER && expo.owner !== EXPO_OWNER) set(expo, "owner", EXPO_OWNER);
  if (changed) await writeFile(file, JSON.stringify(json, null, 2) + "\n");
  return changed;
}

async function run(cmd: string, args: string[], dir: string): Promise<string> {
  try {
    const { stdout } = await exec(cmd, args, { cwd: dir, env: easEnv(), maxBuffer: 32 * 1024 * 1024 });
    return stdout;
  } catch (e) {
    const err = e as { stdout?: string; stderr?: string; message?: string };
    const tail = `${err.stderr ?? ""}\n${err.stdout ?? ""}`.trim().slice(-1500);
    throw new Error(`${cmd} ${args.slice(0, 2).join(" ")} failed: ${tail || err.message}`);
  }
}

/** Parse the JSON array `eas build --json` prints (logs may precede it on stdout). */
function parseBuilds(stdout: string): Array<Record<string, any>> {
  const start = stdout.indexOf("[");
  const end = stdout.lastIndexOf("]");
  if (start < 0 || end <= start) throw new Error(`eas build returned no JSON: ${stdout.slice(-500)}`);
  const parsed = JSON.parse(stdout.slice(start, end + 1));
  return Array.isArray(parsed) ? parsed : [parsed];
}

async function download(url: string, dest: string): Promise<void> {
  const res = await fetch(url);
  if (!res.ok || !res.body) throw new Error(`artifact download HTTP ${res.status}`);
  await mkdir(path.dirname(dest), { recursive: true });
  await pipeline(Readable.fromWeb(res.body as never), createWriteStream(dest));
}

/**
 * Full Android build: deps → app.json identity → EAS project link → cloud
 * build (blocks until finished; the worker keeps heartbeating meanwhile)
 * → .apk into the artifacts volume.
 */
export async function easBuildAndroid(dir: string, projectId: string, name: string, version: string): Promise<EasBuild> {
  if (!existsSync(path.join(dir, "node_modules", "expo"))) {
    await run("npm", ["install", "--no-audit", "--no-fund", "--loglevel=error"], dir);
  }
  await prepareExpoProject(dir, projectId, name);
  // Repos seeded before EAS support have no ignore files: never commit node_modules.
  if (!existsSync(path.join(dir, ".gitignore"))) await writeFile(path.join(dir, ".gitignore"), "node_modules/\n.expo/\ndist/\n*.apk\n*.aab\n*.ipa\n");
  if (!existsSync(path.join(dir, ".easignore"))) await writeFile(path.join(dir, ".easignore"), "node_modules/\n.expo/\n.git/\ndist/\ntest-report.json\n");
  if (!existsSync(path.join(dir, "eas.json"))) throw new Error("eas.json missing — template predates EAS support");
  await commitAll(dir, "release: expo/eas project identity");

  // Links (or creates) the EAS project for this slug; writes extra.eas.projectId
  const initArgs = ["init", "--force", "--non-interactive"];
  if (EXPO_OWNER) initArgs.push("--account", EXPO_OWNER);
  await run("eas", initArgs, dir);
  await commitAll(dir, "release: link eas project");

  const stdout = await run(
    "eas",
    ["build", "--platform", "android", "--profile", EAS_PROFILE, "--non-interactive", "--json"],
    dir,
  );
  const build = parseBuilds(stdout).find((b) => String(b.platform).toUpperCase() === "ANDROID");
  if (!build) throw new Error("eas build returned no android build");
  if (build.status !== "FINISHED") {
    throw new Error(`eas build ${build.id} ended with ${build.status}: ${build.error?.message ?? "see expo.dev"}`);
  }
  const url: string | undefined = build.artifacts?.applicationArchiveUrl ?? build.artifacts?.buildUrl;
  if (!url) throw new Error(`eas build ${build.id} finished without an artifact URL`);

  const rel = path.join(projectId, `app-${version}.apk`);
  await download(url, path.join(ARTIFACTS_PATH, rel));
  return { platform: "android", version, artifact_path: rel, build_id: String(build.id), build_url: url };
}
