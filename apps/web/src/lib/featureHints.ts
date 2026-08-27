import type { FeatureKey } from "@ai-factory/pricing";

/**
 * Feature hints while the customer types — pure keyword matching, no server,
 * no model. Deliberately generous (a hint is one tap to accept or ignore);
 * the "sharpen my idea" round gives the precise suggestion afterwards.
 */
const HINTS: Record<FeatureKey, RegExp> = {
  auth: /\b(login|log-in|anmeld|registr|account|konto|konten|benutzer|nutzerkonto|mitglied|member|user|profil|passwor|sign[- ]?(in|up))/i,
  pay: /\b(zahl|bezahl|payment|pay\b|stripe|paypal|abo|abonnement|subscription|kauf|buy|checkout|preis|price|rechnung|invoice|tip|trinkgeld|gebühr|fee)/i,
  dash: /\b(dashboard|statistik|statistic|auswertung|report|analytics|chart|diagramm|übersicht|overview|kpi|umsatz|revenue|verlauf|history|fortschritt|progress)/i,
  ai: /\b(ki\b|k\.i\.|ai\b|künstlich|artificial|chatgpt|gpt|claude|llm|assistent|assistant|chatbot|empfehl|recommend|erkenn|recogni|generier|generat|zusammenfass|summar|scan|ocr)/i,
  notif: /\b(push|benachrichtig|notification|erinner|remind|alert|alarm|hinweis)/i,
  api: /\b(api|schnittstelle|integration|anbind|sync|synchron|import|export|kalender|calendar|google|outlook|excel|csv|webhook|shopify|woocommerce|crm|erp|slack|whatsapp|e-?mail)/i,
  offline: /\b(offline|ohne internet|without internet|kein netz|no connection|lokal speicher|local storage)/i,
  i18n: /\b(mehrsprach|multi-?lingual|multi-?language|sprachen|languages|english und|englisch|deutsch und|übersetz|translat|international)/i,
};

export function featureHints(text: string): FeatureKey[] {
  const t = text.trim();
  if (t.length < 12) return [];
  return (Object.keys(HINTS) as FeatureKey[]).filter((k) => HINTS[k].test(t));
}
