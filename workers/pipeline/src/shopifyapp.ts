/**
 * Sofabuilt Shopify apps (docs/specs/sofabuilt.md): what the agents are told, and the release
 * artifact, the whole app as a ZIP (code, config, Dockerfile), which the merchant deploys to their
 * own server and registers with `shopify app deploy`.
 */
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { mkdir, readFile, rm } from "node:fs/promises";
import path from "node:path";
import { ARTIFACTS_PATH } from "./repo.ts";

const exec = promisify(execFile);

/** Added to the coding, fix and revise prompts for a Shopify app repository. */
export const SHOPIFY_RULES =
  "This repository is a Shopify app on Shopify's React Router app template (see README.md): embedded in the Shopify admin, Polaris web components (<s-page>, <s-section>, <s-button> ...), App Bridge, sessions in Prisma. Keep the template's structure and dependencies; add packages only when a feature needs them. " +
  "Follow the Shopify App Store requirements: the GraphQL Admin API only, never REST; name every GraphQL operation; request only the access scopes the features need (shopify.app.toml [access_scopes]); keep the three privacy webhooks (app/routes/webhooks.compliance.tsx) and make them delete or report what the app stores; authenticate every admin route with authenticate.admin and every webhook with authenticate.webhook; no access tokens or secrets in the code (environment variables only); settings in app metafields (app/lib/settings.ts) or Prisma models with a migration. " +
  "Storefront features are a theme app extension in extensions/<name>/ (shopify.extension.toml with type = \"theme\", blocks/*.liquid with a {% schema %} that has name and target, assets/), never edits to theme files and no scripts from other hosts. Discount rules are a Shopify Function (discount extension). Paid plans use the Billing API (billing in shopify.server.ts). " +
  "Never use the name, logo or texts of another app or company. Admin texts in English and German when German is in scope. " +
  "Keep logic in plain TypeScript modules in app/lib/ without framework imports (erasable syntax only, imports with .ts extensions), so tests can import them. " +
  "Tests: npm test runs config, API-policy and extension checks, typecheck and build; every automated criterion needs test/cases/<key>.mjs using test/shopify.mjs (fakeAdmin({ OperationName: (variables) => data }), loadLib('app/lib/x.ts')).";

/** Added to the product prompt for a Shopify app. */
export const SHOPIFY_PRODUCT =
  "This is a Shopify app: embedded in the Shopify admin, with theme app extension blocks on the storefront when the scope has them. The context's `scope` is what the customer agreed to and paid for: build exactly its features, nothing from not_included; its `modules` are binding. " +
  "SPEC.md: the app's name (a new name), its purpose, the admin pages, the storefront blocks, the Shopify data it reads or writes and the access scopes that needs, the webhooks, what it stores and how the privacy webhooks handle it. " +
  "Automated criteria must be checkable in plain Node against the logic modules in app/lib/ with the Admin API stand-in (for example: a setting is saved to the app metafield, a product query returns the expected list, a discount rule gives the expected amount, the privacy webhook deletes a customer's rows).";

/** The app's source as a ZIP, without dependencies, builds or local secrets. */
export async function zipShopifyApp(dir: string, projectId: string): Promise<{ artifact_path: string; version: string; slug: string }> {
  const pkg = JSON.parse(await readFile(path.join(dir, "package.json"), "utf8")) as { version?: string };
  const version = pkg.version ?? "0.1.0";
  const slug = projectId.slice(0, 8);
  const rel = path.join(projectId, `shopify-app-${version}.zip`);
  const out = path.join(ARTIFACTS_PATH, rel);
  await mkdir(path.dirname(out), { recursive: true });
  await rm(out, { force: true });
  await exec(
    "zip",
    ["-qr", out, ".", "-x", ".git/*", "node_modules/*", "*/node_modules/*", "build/*", ".react-router/*", ".shopify/*", ".env", ".env.*", "prisma/dev.sqlite*", "test-report.json", "*/.DS_Store"],
    { cwd: dir },
  );
  return { artifact_path: rel, version, slug };
}
