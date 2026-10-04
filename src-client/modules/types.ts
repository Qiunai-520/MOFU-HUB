import type { CommentItem, PostItem } from '@/types';
import type { ThemeConfig } from '@/types';

export interface PageModule {
  init: () => void | (() => void) | Promise<void | (() => void)>;
}

export type { CommentItem, PostItem, ThemeConfig };
