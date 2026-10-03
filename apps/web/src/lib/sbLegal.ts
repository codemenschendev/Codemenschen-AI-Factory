import { getDict, type Dict, type Locale } from "@/lib/i18n";
import { rebrand } from "@/lib/brand";
import { sbLegal } from "@/dictionaries/sofabuiltLegal";

/** Sofabuilt's legal pages: the shared imprint and privacy texts with its name, its own terms and withdrawal text. */
export function sbLegalDict(locale: Locale): Dict {
  const d = rebrand(getDict(locale), "sofabuilt");
  const own = sbLegal(locale);

  return { ...d, legal: { ...d.legal, terms: own.terms, withdrawal: own.withdrawal } } as Dict;
}

export const SB_LEGAL_DOCS = ["terms", "withdrawal", "privacy", "imprint"] as const;
