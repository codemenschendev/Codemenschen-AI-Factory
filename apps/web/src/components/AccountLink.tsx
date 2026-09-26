"use client";

import Link from "next/link";
import type { Locale } from "@/lib/i18n";
import { useToken } from "@/lib/token";
import { Icon } from "./LineIcon";

/**
 * Nav entry to the customer portal: "My projects" once a portal token exists on this device, "Sign in" before.
 * It reads the shared token store: a sign-in in this same tab (the confirm link of a prototype) fires no
 * storage event, and the header, which the layout never re-renders, kept saying "Sign in".
 */
export function AccountLink({ locale, labels }: { locale: Locale; labels: { account: string; login: string } }) {
  const signedIn = !!useToken();
  const label = signedIn ? labels.account : labels.login;
  // On a small phone the words do not fit next to the logo: the person icon stands in for them.
  return (
    <Link href={`/${locale}/account`} className="lang-toggle nav-account" aria-label={label}>
      <Icon name="user" className="ico nav-account-ico" />
      <span className="nav-account-label">{label}</span>
    </Link>
  );
}
