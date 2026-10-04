import { atom } from 'nanostores';

// Define the shape of translation map (optional)
export interface TranslationMap {
  [key: string]: string | TranslationMap;
}

// Stores
export const $lang = atom<string>(getDefaultLang());
export const $translations = atom<TranslationMap>({});

// Load translations from JSON file
export async function loadTranslations(lang: string): Promise<void> {
  try {
    const res = await fetch(`/i18n/${lang}.json`, { cache: 'no-store' });
    if (!res.ok) {
      console.warn(`[i18n] Failed to load language ${lang}: ${res.status}`);
      // fallback to empty
      $translations.set({});
      return;
    }
    const json: TranslationMap = await res.json();
    $translations.set(json);
    $lang.set(lang);
    localStorage.setItem('mofu-lang', lang);
  } catch (err) {
    console.error('[i18n] Error loading translations', err);
    $translations.set({});
  }
}

// Get default language from storage or navigator
function getDefaultLang(): string {
  const stored = localStorage.getItem('mofu-lang');
  if (stored) return stored;
  const navLang = navigator.language;

  if (navLang.startsWith('zh')) return 'zh-CN';

  return 'en';
}

// Simple t function with placeholder replacement
export function t(key: string, values: Record<string, string> = {}): string {
  // Try to get nested key using dot notation
  const getNested = (obj: TranslationMap, path: string): string | undefined => {
    const parts = path.split('.');
    let cur: any = obj;
    for (const p of parts) {
      if (cur == null || !(p in cur)) return undefined;
      cur = cur[p];
    }
    return typeof cur === 'string' ? cur : undefined;
  };

  const current = $translations.get();
  let text = getNested(current, key);
  if (text === undefined) {
    // fallback to key itself
    text = key;
  }
  // Replace placeholders like {name}
  return text.replace(/\{(\w+)}/g, (_, p) => values[p] ?? `{${p}}`);
}

/** 立即切换语言（供设置页等控件调用）：$lang 监听会自动加载并触发 DOM 重译 */
export function setLang(lang: string): void {
  if (typeof document === 'undefined') return;
  $lang.set(lang);
}

// Initialize i18n: load default language
export async function initI18n(): Promise<void> {
  const lang = $lang.get();
  document.documentElement.lang = lang;
  await loadTranslations(lang);
  applyTranslations();
  // re-apply on translation change
  $translations.listen(() => applyTranslations());
  $lang.listen(async (newLang) => {
    document.documentElement.lang = newLang;
    await loadTranslations(newLang);
  });
}

/** Apply translations to DOM elements with data-i18n attribute */
export function applyTranslations(): void {
  if (typeof document === 'undefined') return;
  const texts = $translations.get();
  const nodes = document.querySelectorAll('[data-i18n]') as NodeListOf<HTMLElement>;
  nodes.forEach(el => {
    const key = el.dataset.i18n;
    if (!key) return;
    const text = getNestedText(texts, key);
    if (text) {
      el.textContent = text;
    }
  });
}

function getNestedText(obj: TranslationMap, path: string): string | undefined {
  const parts = path.split('.');
  let cur: any = obj;
  for (const p of parts) {
    if (cur == null || typeof cur !== 'object' || !(p in cur)) return undefined;
    cur = cur[p];
  }
  return typeof cur === 'string' ? cur : undefined;
}