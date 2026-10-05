import type { ActionFunctionArgs } from "react-router";
import { authenticate } from "../shopify.server";
import db from "../db.server";

/**
 * The three privacy webhooks every App Store app must answer (shopify.app.toml). authenticate.webhook
 * checks the HMAC and answers 401 when it is wrong, which is what Shopify's review tests.
 * Delete or export here whatever the app stores about a customer or a shop.
 */
export const action = async ({ request }: ActionFunctionArgs) => {
  const { shop, topic } = await authenticate.webhook(request);

  switch (topic) {
    case "CUSTOMERS_DATA_REQUEST":
      // The app keeps no customer data beyond what Shopify holds. If it does, send it to the shop owner here.
      break;
    case "CUSTOMERS_REDACT":
      // Delete this customer's rows here if the app stores any.
      break;
    case "SHOP_REDACT":
      await db.session.deleteMany({ where: { shop } });
      break;
  }

  return new Response();
};
