/**
 * The sign-in token, as a store rather than a value read in an effect.
 *
 * Three panels used to read localStorage inside useEffect and setState with what they found,
 * which React 19's lint calls a cascading render and which is also two paints where one would
 * do. Read through useSyncExternalStore, the server renders the "not known yet" state, the
 * browser renders the real one, and every panel sees a sign-out the moment it happens.
 *
 * `undefined` means not read yet (the server, or hydration); `null` means read and absent.
 */
import { useSyncExternalStore } from "react";

const KEY = "aifactory-token";
const listeners = new Set<() => void>();

function publish(): void {
  for (const l of listeners) l();
}

export function getToken(): string | null {
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

export function setToken(value: string | null): void {
  try {
    if (value === null) localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, value);
  } catch {
    // A browser that will not store it signs the visitor out on the next load. Nothing else breaks.
  }
  publish();
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  const onStorage = (e: StorageEvent) => {
    if (e.key === KEY || e.key === null) publish();
  };
  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
  };
}

const notYet = (): undefined => undefined;

export function useToken(): string | null | undefined {
  return useSyncExternalStore(subscribe, getToken, notYet);
}
