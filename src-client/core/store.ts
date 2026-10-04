import { atom } from 'nanostores';
import type { MofuUser, ThemeConfig } from '@/types';

export const $user = atom<MofuUser | null>(window.__MOFU__?.user ?? null);

export const $unreadCount = atom<number>(0);

export const $theme = atom<ThemeConfig>(
  window.__MOFU__?.theme ?? {
    sourceColor: '#775A0D',
    scheme: 'auto',
    contrast: 'standard',
    variant: 'tonalSpot',
    density: 0,
    motion: 'standard',
  },
);
