/**
 * The app's settings, kept in an app-owned metafield on the installation: no database table, and
 * Shopify removes them when the app is uninstalled. Plain TypeScript without framework imports, so
 * test/cases can import it with the admin stand-in (test/shopify.mjs).
 */
export type Admin = {
  graphql: (query: string, options?: { variables?: Record<string, unknown> }) => Promise<{ json: () => Promise<any> }>;
};

export type Settings = Record<string, unknown>;

export const DEFAULTS: Settings = {};

export async function loadSettings(admin: Admin): Promise<Settings> {
  const res = await admin.graphql(`#graphql
    query AppSettings {
      currentAppInstallation {
        id
        metafield(namespace: "$app", key: "settings") { value }
      }
    }`);
  const { data } = await res.json();
  const raw = data?.currentAppInstallation?.metafield?.value;
  return { ...DEFAULTS, ...(raw ? JSON.parse(raw) : {}) };
}

export async function saveSettings(admin: Admin, settings: Settings): Promise<void> {
  const res = await admin.graphql(`#graphql
    query AppInstallationId { currentAppInstallation { id } }`);
  const ownerId = (await res.json()).data.currentAppInstallation.id;
  const saved = await admin.graphql(
    `#graphql
    mutation SaveAppSettings($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) { userErrors { field message } }
    }`,
    { variables: { metafields: [{ ownerId, namespace: "$app", key: "settings", type: "json", value: JSON.stringify(settings) }] } },
  );
  const errors = (await saved.json()).data?.metafieldsSet?.userErrors ?? [];
  if (errors.length) throw new Error(errors.map((e: { message: string }) => e.message).join("; "));
}
