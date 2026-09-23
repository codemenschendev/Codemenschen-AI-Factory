import type { Metadata } from "next";
import { LOCALES } from "@/lib/i18n";
import "../../../admin.css";

/**
 * A root layout of its own, not a page inside the storefront.
 *
 * The operator console used to be rendered inside the selling layout: the sticky Appwerk header,
 * the "start now" button, the legal footer. That is the wrong furniture for a screen where
 * somebody pauses a campaign, and on a small window the real content started below the fold.
 * Being a second root layout is what lets that chrome go away entirely.
 */
export const metadata: Metadata = {
  title: "Appwerk ops",
  robots: { index: false, follow: false },
};

export function generateStaticParams() {
  return LOCALES.map((locale) => ({ locale }));
}

export default async function AdminLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  return (
    <html lang={locale}>
      <body>{children}</body>
    </html>
  );
}
