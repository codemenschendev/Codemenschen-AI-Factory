/**
 * The visitor's own list of prototypes, kept in their browser and nowhere else.
 *
 * A prototype needs no account, so the server has nobody to file one under: the only thing that
 * ever pointed at it was the link in the address bar. Close the tab and it was gone, and the next
 * visit offered an empty box and nothing else. This is the smallest thing that fixes that. It
 * stores an id, a sentence and a date per prototype, in localStorage, on the machine that made it.
 * Nothing is sent anywhere and nothing new is kept on the server.
 *
 * It is exposed as a store rather than a getter so a component can read it with
 * useSyncExternalStore: React then renders nothing for it on the server and the real list on the
 * client, without an effect that sets state and without a hydration mismatch.
 *
 * Every read and write is wrapped: private windows, blocked site data and a full quota all throw,
 * and a page that cannot remember must still work.
 */
const KEY = "aifactory-prototypes";

/** Twelve is more than anyone scrolls and small enough to stay a list rather than an archive. */
const MAX = 12;

/** The API keeps a prototype seven days. An entry that outlives it points at a 410. */
const LIVE_DAYS = 7;

export interface Remembered {
  id: string;
  kind: "site" | "app" | "ads";
  /** What the visitor typed. It is the only label there is until the build names itself. */
  prompt: string;
  /** The title the generator gave it, once the build is done. */
  title?: string;
  /** When it was created, ISO. */
  at: string;
}

function read(): Remembered[] {
  try {
    const raw = localStorage.getItem(KEY);
    const list: unknown = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(list)) return [];

    return list.filter(
      (e): e is Remembered =>
        !!e && typeof e === "object" && typeof (e as Remembered).id === "string",
    );
  } catch {
    return [];
  }
}

function write(list: Remembered[]): void {
  try {
    // Expired ones are dropped on the way out, so the store empties itself over time instead of
    // holding ids that answer 410 forever.
    localStorage.setItem(KEY, JSON.stringify(list.filter((e) => daysLeft(e) > 0).slice(0, MAX)));
  } catch {
    // A browser that will not store this is a browser that shows no list. Nothing else breaks.
  }
  publish();
}

/** How long an entry has left, in whole days. Zero means it is gone from the server. */
export function daysLeft(e: Remembered): number {
  const made = Date.parse(e.at);
  if (Number.isNaN(made)) return 0;
  const left = made + LIVE_DAYS * 86400_000 - Date.now();

  return left <= 0 ? 0 : Math.ceil(left / 86400_000);
}

/** Newest first, and without the ones the server has already thrown away. */
function current(): Remembered[] {
  const kept = read().filter((e) => daysLeft(e) > 0);
  kept.sort((a, b) => b.at.localeCompare(a.at));

  return kept;
}

/**
 * The snapshot has to be the same object between renders or React re-renders forever, so it is
 * computed once and then only when something writes.
 */
const EMPTY: Remembered[] = [];
let snapshot: Remembered[] | null = null;
const listeners = new Set<() => void>();

function publish(): void {
  snapshot = current();
  for (const l of listeners) l();
}

export function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  // Another tab of the same site writing the list is the one external change worth following.
  const onStorage = (e: StorageEvent) => {
    if (e.key === KEY || e.key === null) publish();
  };
  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
  };
}

export function getSnapshot(): Remembered[] {
  if (snapshot === null) snapshot = current();

  return snapshot;
}

/** On the server there is no browser and therefore nothing remembered. */
export function getServerSnapshot(): Remembered[] {
  return EMPTY;
}

export function remember(entry: Omit<Remembered, "at"> & { at?: string }): void {
  const e: Remembered = { ...entry, at: entry.at ?? new Date().toISOString() };
  write([e, ...read().filter((x) => x.id !== e.id)]);
}

/** Fill in what only the finished build knows. Silent if the entry is not this browser's. */
export function rename(id: string, title: string | null): void {
  if (!title) return;
  const all = read();
  const found = all.find((e) => e.id === id);
  if (!found || found.title === title) return;
  found.title = title;
  write(all);
}

/** Drop one, because it expired, failed or the visitor asked. */
export function forget(id: string): void {
  const all = read();
  if (!all.some((e) => e.id === id)) return;
  write(all.filter((e) => e.id !== id));
}
