import { de } from "@/dictionaries/de";
import { en } from "@/dictionaries/en";

export const LOCALES = ["de", "en"] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = "de";

export type Dict = typeof de;

const dicts: Record<Locale, Dict> = { de, en };

export function isLocale(v: string): v is Locale {
  return (LOCALES as readonly string[]).includes(v);
}

export function getDict(locale: Locale): Dict {
  return dicts[locale];
}

/** Localized text pair, the shape used across catalog data. */
export interface I18nText {
  en: string;
  de: string;
}

export function t(pair: I18nText, locale: Locale): string {
  return pair[locale];
}

export function eur(n: number, locale: Locale): string {
  return locale === "de"
    ? `${n.toLocaleString("de-AT")} €`
    : `€${n.toLocaleString("en-IE")}`;
}
