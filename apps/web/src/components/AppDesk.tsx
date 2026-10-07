"use client";

import { useRouter } from "next/navigation";
import { Desk } from "@/components/sofabuilt/Desk";
import type { SbDict } from "@/dictionaries/sofabuilt";

/** The prototype form's draft key (PrototypeForm reads it once and fills the idea in). */
const DRAFT = "aifactory-proto-draft";
const MAX_PROMPT = 4000;

/**
 * Appmitki's start (2026-10-05, owner: "Appmitki the Sofabuilt way, for apps"): the desk scopes
 * and prices the app part by part. The free preview stays: it opens the prototype form with the
 * agreed scope written in, so the preview shows what the customer would order.
 */
export function AppDesk({ t, doors, locale, idea }: { t: SbDict["desk"]; doors: SbDict["hero"]["doors"]; locale: string; idea?: string }) {
  const router = useRouter();

  return (
    <Desk
      t={t}
      doors={doors}
      locale={locale}
      start="idea"
      startPlatform="app"
      only="app"
      initialText={idea}
      onPreview={(scope) => {
        const prompt = [`${scope.name}: ${scope.purpose}`, ...scope.features.map((f) => `- ${f}`)].join("\n").slice(0, MAX_PROMPT);
        try {
          localStorage.setItem(DRAFT, JSON.stringify({ prompt, kind: "app" }));
        } catch {}
        router.push(`/${locale}/prototype?form=1&kind=app`);
      }}
    />
  );
}
