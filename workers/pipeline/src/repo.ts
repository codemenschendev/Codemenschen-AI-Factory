import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { mkdir, writeFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import path from "node:path";

const exec = promisify(execFile);

export const REPOS_PATH = process.env.REPOS_PATH ?? "/repos";
export const ARTIFACTS_PATH = process.env.ARTIFACTS_PATH ?? "/artifacts";

export function repoDir(projectId: string): string {
  return path.join(REPOS_PATH, projectId);
}

export const TEMPLATES_PATH = process.env.TEMPLATES_PATH ?? "/templates";

/** Local-git mode; swaps for GitHub-App provisioning when creds exist.
    New repos are seeded from the stack's golden template when one exists. */
export async function ensureRepo(projectId: string, stack = "expo"): Promise<string> {
  const dir = repoDir(projectId);
  if (!existsSync(path.join(dir, ".git"))) {
    await mkdir(dir, { recursive: true });
    const template = path.join(TEMPLATES_PATH, `${stack}-app`);
    if (existsSync(template)) {
      await exec("cp", ["-R", `${template}/.`, dir]);
    }
    await exec("git", ["init", "-b", "main"], { cwd: dir });
    await exec("git", ["config", "user.email", "factory@codemenschen.at"], { cwd: dir });
    await exec("git", ["config", "user.name", "AI Factory"], { cwd: dir });
    await commitAll(dir, `chore: seed from ${stack} golden template`);
  }
  return dir;
}

export async function commitAll(dir: string, message: string): Promise<void> {
  await exec("git", ["add", "-A"], { cwd: dir });
  try {
    await exec("git", ["commit", "-m", message], { cwd: dir });
  } catch (e) {
    if (!String(e).includes("nothing to commit")) throw e;
  }
}

export async function writeRepoFile(dir: string, rel: string, content: string): Promise<void> {
  const p = path.join(dir, rel);
  await mkdir(path.dirname(p), { recursive: true });
  await writeFile(p, content);
}

export async function archiveRepo(projectId: string, version: string): Promise<string> {
  const rel = path.join(projectId, `build-${version}.tar.gz`);
  const out = path.join(ARTIFACTS_PATH, rel);
  await mkdir(path.dirname(out), { recursive: true });
  await exec("tar", ["czf", out, "--exclude=.git", "--exclude=node_modules", "-C", repoDir(projectId), "."]);
  return rel;
}
