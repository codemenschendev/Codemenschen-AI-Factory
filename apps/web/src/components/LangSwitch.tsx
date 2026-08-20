"use client";

import { usePathname } from "next/navigation";
import type { Locale } from "@/lib/i18n";

/** Switches locale on the SAME page (appwerk doc 12) and persists the manual
    choice so the middleware never auto-overrides it. */
export function LangSwitch({ current }: { current: Locale }) {
  const pathname = usePathname() ?? `/${current}`;
  const other: Locale = current === "de" ? "en" : "de";
  const target = pathname.replace(new RegExp(`^/${current}`), `/${other}`);
  return (
    <a
      className="lang-toggle"
      href={target}
      onClick={() => {
        document.cookie = `locale=${other};path=/;max-age=31536000;samesite=lax`;
      }}
    >
      {other.toUpperCase()}
    </a>
  );
}
