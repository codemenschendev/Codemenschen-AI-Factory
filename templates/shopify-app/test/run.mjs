/**
 * The app's test run (Sofabuilt, docs/specs/sofabuilt.md). Plain Node; installs the dependencies
 * once when node_modules is missing.
 *
 *   app-config       shopify.app.toml: scopes, the three privacy webhooks, a route for every webhook
 *   api-policy       GraphQL Admin API only (no REST), no access tokens or secrets in the code
 *   extensions       theme app extensions: every block has a valid schema, no scripts from other hosts
 *   typecheck        react-router typegen + tsc
 *   build            react-router build
 *   <criterion key>  one test/cases/<key>.mjs per automated acceptance criterion
 *
 * The last line is the JSON summary the factory reads: {passed, failed, criteria_results, details}.
 */
import { spawnSync } from "node:child_process";
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";

// Test cases import the app's TypeScript modules; older Node needs the flag for that.
if (!process.features?.typescript && !process.execArgv.includes("--experimental-strip-types")) {
  const r = spawnSync(process.execPath, ["--experimental-strip-types", "--no-warnings", ...process.argv.slice(1)], { stdio: "inherit" });
  process.exit(r.status ?? 1);
}

const results = {};
const details = [];
const skipped = [];
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
        if (statSync(p).isDirectory()) return f === "node_modules" || f === "build" || f.startsWith(".") ? [] : files(p);
        return [p];
      })
    : [];
const run = (cmd, args, timeout = 300_000) => spawnSync(cmd, args, { encoding: "utf8", timeout, env: { ...process.env, CI: "1" } });
const tail = (r) => `${r.stdout ?? ""}\n${r.stderr ?? ""}`.trim().split("\n").slice(-12).join("\n").slice(-900);

// 1. shopify.app.toml
const toml = existsSync("shopify.app.toml") ? readFileSync("shopify.app.toml", "utf8").replace(/^\s*#.*$/gm, "") : "";
if (!toml) fail("app-config", "shopify.app.toml is missing");
const scopes = (/^\s*scopes\s*=\s*"([^"]*)"/m.exec(toml)?.[1] ?? "").split(",").map((s) => s.trim()).filter(Boolean);
for (const s of scopes) {
  if (!/^(unauthenticated_)?(read|write)_[a-z_]+$/.test(s)) fail("app-config", `scope "${s}" is not a Shopify access scope`);
}
if (!/^\s*api_version\s*=\s*"\d{4}-\d{2}"/m.test(toml)) fail("app-config", "[webhooks] api_version is missing");
const subs = toml.split("[[webhooks.subscriptions]]").slice(1);
const compliance = subs.flatMap((b) => (/compliance_topics\s*=\s*\[([^\]]*)\]/.exec(b)?.[1] ?? "").match(/"[^"]+"/g) ?? []).map((t) => t.slice(1, -1));
for (const t of ["customers/data_request", "customers/redact", "shop/redact"]) {
  if (!compliance.includes(t)) fail("app-config", `the privacy webhook ${t} is not subscribed`);
}
for (const b of subs) {
  const uri = /uri\s*=\s*"([^"]+)"/.exec(b)?.[1];
  if (!uri?.startsWith("/")) continue;
  const name = uri.slice(1).replace(/\//g, ".");
  const found = ["tsx", "ts", "jsx", "js"].some((x) => existsSync(`app/routes/${name}.${x}`) || existsSync(`app/routes/${name}/route.${x}`));
  if (!found) fail("app-config", `no route handles the webhook ${uri} (expected app/routes/${name}.tsx)`);
}
pass("app-config");

// 2. What Shopify's review rejects in the code.
const code = files("app").filter((f) => /\.(t|j)sx?$/.test(f));
for (const f of code) {
  const src = readFileSync(f, "utf8");
  if (/\badmin\.rest\b|\/admin\/api\/[^"'`]*\.json/.test(src)) fail("api-policy", `${f}: uses the REST Admin API; new apps must use the GraphQL Admin API`);
  if (/shp(at|ss|ca|pa)_[0-9a-f]{20,}/.test(src)) fail("api-policy", `${f}: contains a Shopify access token or secret`);
}
pass("api-policy");

// 3. Theme app extensions.
for (const ext of existsSync("extensions") ? readdirSync("extensions").filter((d) => statSync(path.join("extensions", d)).isDirectory()) : []) {
  const dir = path.join("extensions", ext);
  const conf = existsSync(path.join(dir, "shopify.extension.toml")) ? readFileSync(path.join(dir, "shopify.extension.toml"), "utf8") : "";
  if (!conf) {
    fail("extensions", `${dir}: shopify.extension.toml is missing`);
    continue;
  }
  if (!/type\s*=\s*"theme"/.test(conf)) continue;
  const liquid = files(dir).filter((f) => f.endsWith(".liquid"));
  if (liquid.reduce((n, f) => n + statSync(f).size, 0) > 100_000) fail("extensions", `${dir}: more than 100 KB of Liquid`);
  for (const f of liquid) {
    const src = readFileSync(f, "utf8");
    if (/<script[^>]+src=["']https?:\/\/(?!cdn\.shopify\.com)/i.test(src)) fail("extensions", `${f}: loads a script from another host`);
    if (!f.includes(`${path.sep}blocks${path.sep}`)) continue;
    const schema = /{%-?\s*schema\s*-?%}([\s\S]*?){%-?\s*endschema\s*-?%}/.exec(src)?.[1];
    if (!schema) {
      fail("extensions", `${f}: a block needs a {% schema %}`);
      continue;
    }
    try {
      const s = JSON.parse(schema);
      if (!s.name || !s.target) fail("extensions", `${f}: the schema needs "name" and "target"`);
    } catch (e) {
      fail("extensions", `${f}: the schema is not valid JSON (${e.message})`);
    }
  }
}
pass("extensions");

// 4. Typecheck and build, once the dependencies are there.
if (!existsSync("node_modules")) {
  const r = run("npm", ["install", "--no-audit", "--no-fund", "--loglevel=error"], 600_000);
  if (r.status !== 0) skipped.push(`typecheck, build (npm install failed: ${tail(r).split("\n").pop()})`);
}
if (existsSync("node_modules")) {
  run("npx", ["prisma", "generate"]);
  const tg = run("npx", ["react-router", "typegen"]);
  const tc = tg.status === 0 ? run("npx", ["tsc", "--noEmit"]) : tg;
  if (tc.status !== 0) fail("typecheck", tail(tc));
  else pass("typecheck");
  const b = run("npx", ["react-router", "build"]);
  if (b.status !== 0) fail("build", tail(b));
  else pass("build");
}

// 5. One case per automated acceptance criterion.
const criteria = existsSync("acceptance-criteria.json") ? JSON.parse(readFileSync("acceptance-criteria.json", "utf8")) : [];
if (existsSync("test/cases")) {
  for (const f of readdirSync("test/cases").filter((f) => f.endsWith(".mjs"))) {
    try {
      const mod = await import(`./cases/${f}`);
      try {
        await mod.run();
        results[mod.key] = "passed";
      } catch (e) {
        fail(mod.key, e.message);
      }
    } catch (e) {
      fail(f.replace(/\.mjs$/, ""), `does not load: ${e.message}`);
    }
  }
}
for (const c of criteria.filter((c) => c.kind === "automated")) {
  if (!(c.key in results)) fail(c.key, "no test case implements this criterion");
}
if (skipped.length) console.log(`skipped here: ${skipped.join("; ")}`);

const passed = Object.values(results).filter((s) => s === "passed").length;
console.log(JSON.stringify({ passed, failed, criteria_results: results, details: details.slice(0, 40) }));
process.exit(failed > 0 ? 1 : 0);
