import { sbDict, type SbDict } from "./sofabuilt";

/**
 * Appmitki's app desk (2026-10-05): Sofabuilt's desk texts with the words that name the product
 * changed to an app. Everything else (questions, checkout, errors) is shared.
 */
export function appDesk(locale: string): { desk: SbDict["desk"]; doors: SbDict["hero"]["doors"] } {
  const sb = sbDict(locale);
  const de = locale === "de";
  return {
    doors: sb.hero.doors,
    desk: {
      ...sb.desk,
      eyebrow: de ? "App-Desk" : "App desk",
      title: de ? "Erzähl uns deine App-Idee" : "Tell us your app idea",
      lede: de
        ? "Beschreib deine App. Rechts siehst du sofort, was sie kann und was jeder Teil kostet."
        : "Describe your app. On the right you see at once what it does and what each part costs.",
      priceEmpty: de
        ? "Dein Preis erscheint hier nach deiner ersten Nachricht, Teil für Teil."
        : "Your price appears here after your first message, part by part.",
      build: de ? "Deine App" : "Your app",
      parts: de ? "Teile deiner App" : "Parts of your app",
      care: de
        ? "Wartung, optional: {price} pro Monat für Änderungen, die ersten 3 Monate gratis"
        : "Care, optional: {price} a month for changes, first 3 months free",
      sendIdea: de ? "Idee senden" : "Send my idea",
    },
  };
}
