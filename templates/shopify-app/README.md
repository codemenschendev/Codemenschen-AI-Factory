# Shopify app (Sofabuilt template)

- Built on Shopify's React Router app template (TEMPLATE-LICENSE.md): an embedded app in the Shopify
  admin, Polaris web components (`<s-page>`, `<s-section>`, ...), App Bridge, sessions in Prisma
  (SQLite in development). `shopify app dev` runs it against a development store.
- `shopify.app.toml`: the access scopes (only the ones the features need), the webhooks. The three
  privacy webhooks every App Store app must answer are wired to `app/routes/webhooks.compliance.tsx`.
- Admin API: GraphQL only, never REST. Name every operation (`query ProductList`), the test stand-in
  picks its answer by that name. Settings live in an app metafield (`app/lib/settings.ts`).
- Storefront parts are a theme app extension in `extensions/<name>/` (`shopify.extension.toml` with
  `type = "theme"`, `blocks/*.liquid` with a `{% schema %}` that has `name` and `target`, `assets/`).
  Merchants add the block in the theme editor; no theme files are edited.
- Keep logic in plain TypeScript modules in `app/lib/` without framework imports (erasable syntax
  only: no enums, no namespaces; import each other with the `.ts` extension), so tests can import them.
- `npm test` runs `test/run.mjs`: config checks, API policy, extension checks, typecheck and build,
  then one case per automated acceptance criterion in `test/cases/<key>.mjs`.
- A case exports `key` and `run()`. `import { fakeAdmin, loadLib } from "../shopify.mjs"`:
  `fakeAdmin({ OperationName: (variables) => data })` stands in for the Admin GraphQL API and records
  `calls`; `loadLib("app/lib/x.ts")` imports a module. Throw an Error when the result is wrong.
