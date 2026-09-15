"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { track } from "@/lib/analytics";

/** One page_view per route, including client-side navigation. Renders nothing. */
export function PageViews() {
  const pathname = usePathname();
  useEffect(() => {
    // The sign-in handoff carries the token in the hash; the path alone is sent, never the hash.
    if (!pathname.includes("/admin")) track("page_view");
  }, [pathname]);
  return null;
}
