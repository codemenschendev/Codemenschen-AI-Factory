"use client";

import { useSyncExternalStore } from "react";
import Link from "next/link";
import type { Locale } from "@/lib/i18n";

const subscribe = (cb: () => void) => {
  window.addEventListener("storage", cb);
  return () => window.removeEventListener("storage", cb);
};
const hasToken = () => {
  try {
    return !!localStorage.getItem("aifactory-token");
  } catch {
    return false; // storage blocked: show "Sign in"
  }
};

/** Nav entry to the customer portal: "My projects" once a portal token exists on this device, "Sign in" before. */
export function AccountLink({ locale, labels }: { locale: Locale; labels: { account: string; login: string } }) {
  const signedIn = useSyncExternalStore(subscribe, hasToken, () => false);
  return (
    <Link href={`/${locale}/account`} className="lang-toggle nav-account">
      {signedIn ? labels.account : labels.login}
    </Link>
  );
}
