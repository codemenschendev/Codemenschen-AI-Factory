/**
 * A stand-in for the Shopify Admin GraphQL API, so test cases run in plain Node without a store.
 *
 *   const admin = fakeAdmin({ AppSettings: () => ({ currentAppInstallation: { id: "gid://x/1", metafield: null } }) });
 *   await someLibFunction(admin);
 *   admin.calls  // [{ operation, query, variables }]
 *
 * A handler is chosen by the operation name (`query AppSettings`, `mutation SaveAppSettings`) and
 * returns the `data` object; it gets the variables. An operation without a handler throws, so a test
 * notices an unexpected call. Use named operations in the app's code.
 */
import path from "node:path";
import { pathToFileURL } from "node:url";

export function fakeAdmin(handlers = {}) {
  const calls = [];
  return {
    calls,
    async graphql(query, options = {}) {
      const operation = /\b(?:query|mutation)\s+(\w+)/.exec(query)?.[1];
      const variables = options.variables ?? {};
      calls.push({ operation, query, variables });
      const handler = handlers[operation];
      if (!handler) throw new Error(`no handler for GraphQL operation ${operation ?? "(unnamed)"}`);
      const data = await handler(variables, query);
      return { json: async () => ({ data }) };
    },
  };
}

/** Imports a module of the app (for example "app/lib/settings.ts"). */
export function loadLib(rel) {
  return import(pathToFileURL(path.resolve(rel)).href);
}
