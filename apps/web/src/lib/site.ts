/** The storefront's public address, for robots.txt, the sitemap and security.txt. */
export const SITE = (process.env.NEXT_PUBLIC_SITE_URL ?? "https://appmitki.com").replace(/\/$/, "");
