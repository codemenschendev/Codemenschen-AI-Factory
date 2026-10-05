// Settings kept in chrome.storage.sync (they follow the user to every computer).
export const DEFAULTS = {};

export async function loadSettings() {
  const stored = await chrome.storage.sync.get(null);
  return { ...DEFAULTS, ...stored };
}

export async function saveSettings(values) {
  await chrome.storage.sync.set(values);
}
