/**
 * An in-memory stand-in for the Chrome extension APIs the logic in extension/lib/ uses, so tests
 * run in plain Node. Covers storage (local, sync, session), runtime messages, alarms, context menus,
 * notifications, action badge and tabs.query. Extend it in a test when a feature needs more.
 */
import path from "node:path";
import { pathToFileURL } from "node:url";

function area() {
  let data = {};
  return {
    async get(keys) {
      if (keys == null) return { ...data };
      if (typeof keys === "string") return keys in data ? { [keys]: data[keys] } : {};
      if (Array.isArray(keys)) return Object.fromEntries(keys.filter((k) => k in data).map((k) => [k, data[k]]));
      return Object.fromEntries(Object.entries(keys).map(([k, d]) => [k, k in data ? data[k] : d]));
    },
    async set(values) {
      data = { ...data, ...structuredClone(values) };
    },
    async remove(keys) {
      for (const k of [].concat(keys)) delete data[k];
    },
    async clear() {
      data = {};
    },
    _dump: () => ({ ...data }),
  };
}

function event() {
  const listeners = [];
  return {
    addListener: (fn) => listeners.push(fn),
    removeListener: (fn) => listeners.splice(listeners.indexOf(fn) >>> 0, 1),
    hasListener: (fn) => listeners.includes(fn),
    _fire: (...args) => listeners.map((fn) => fn(...args)),
  };
}

export const chrome = {
  storage: { local: area(), sync: area(), session: area(), onChanged: event() },
  runtime: {
    id: "test-extension",
    onMessage: event(),
    onInstalled: event(),
    sendMessage: async (msg) => {
      let reply;
      for (const r of chrome.runtime.onMessage._fire(msg, { id: "test" }, (v) => (reply = v))) if (r instanceof Promise) await r;
      return reply;
    },
    getURL: (p) => `chrome-extension://test-extension/${p}`,
  },
  alarms: { _all: {}, create(name, info) { this._all[name] = info; }, async clear(name) { return delete this._all[name]; }, onAlarm: event() },
  contextMenus: { _items: [], create(item) { this._items.push(item); }, removeAll(cb) { this._items = []; cb?.(); }, onClicked: event() },
  notifications: { _sent: [], create(id, opts) { this._sent.push({ id, ...opts }); }, onClicked: event() },
  action: { _badge: "", setBadgeText({ text }) { this._badge = text; }, setBadgeBackgroundColor() {} },
  tabs: { _tabs: [{ id: 1, url: "https://example.com/", active: true }], async query() { return this._tabs; }, async sendMessage() {} },
  i18n: { getMessage: (key) => key },
};
globalThis.chrome = chrome;

/** Import a module from extension/ (for example "lib/notes.js") with the stand-in in place. */
export async function loadLib(rel) {
  return import(pathToFileURL(path.resolve("extension", rel)).href);
}
