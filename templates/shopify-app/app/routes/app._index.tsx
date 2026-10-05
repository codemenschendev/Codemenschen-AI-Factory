import type { HeadersFunction, LoaderFunctionArgs } from "react-router";
import { useLoaderData } from "react-router";
import { authenticate } from "../shopify.server";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { loadSettings } from "../lib/settings.ts";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin } = await authenticate.admin(request);
  return { settings: await loadSettings(admin) };
};

// The app's home page in the Shopify admin. Replace it with the app's own first screen.
export default function Index() {
  const { settings } = useLoaderData<typeof loader>();

  return (
    <s-page heading="Home">
      <s-section heading="Settings">
        <s-paragraph>{JSON.stringify(settings)}</s-paragraph>
      </s-section>
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
