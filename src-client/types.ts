export interface MofuUser {
  id: number;
  username: string;
  nickname: string;
  can_admin: boolean;
}

export interface ThemeConfig {
  name?: string;
  sourceColor: string;
  scheme: 'auto' | 'light' | 'dark';
  contrast: 'standard' | 'medium' | 'high';
  variant: string;
  density: number;
  motion: string;
  isCustom?: boolean;
}

export interface PostMedia {
  id: number;
  kind: string;
  url: string;
  thumb: string;
  width: number | null;
  height: number | null;
}

export interface PostTopic {
  name: string;
  slug: string;
  url: string;
}

export interface PostAuthor {
  id: number;
  username: string;
  nickname: string;
  initial: string;
  hue: number;
  url: string;
  avatar_url?: string;
}

export interface PostRepostQuote {
  available: boolean;
  id: number;
  url: string;
  excerpt: string;
  author: { nickname: string; url: string } | null;
  thumb: string;
}

export interface PostItem {
  id: number;
  content_html: string;
  content_text: string;
  excerpt: string;
  visibility: string;
  visibility_label: string;
  status: string;
  is_pinned: boolean;
  is_owner: boolean;
  can_edit: boolean;
  author: PostAuthor;
  time_ago: string;
  created_at: string;
  like_count: number;
  comment_count: number;
  repost_count: number;
  liked: boolean;
  favorited: boolean;
  reposted: boolean;
  is_repost: boolean;
  repost: PostRepostQuote | null;
  media: PostMedia[];
  topics: PostTopic[];
}

export interface CommentItem {
  id: number;
  content_html: string;
  content_text: string;
  author: PostAuthor;
  reply_to: { username: string; nickname: string } | null;
  time_ago: string;
  created_at: string;
  like_count: number;
  liked: boolean;
  is_owner: boolean;
  can_remove: boolean;
  replies?: CommentItem[];
}

export interface NotificationItem {
  id: number;
  type: string;
  message: string;
  excerpt: string;
  actor: PostAuthor | null;
  url: string;
  read: boolean;
  time_ago: string;
  created_at: string;
}

/** 匿名内容作者（化名或署名） */
export interface AliasAuthor {
  kind: 'anonymous' | 'named';
  display: string;
  initial: string;
  hue: number;
  url?: string;
}

/** 表白墙 / 树洞投稿 */
export interface ConfessionItem {
  id: number;
  board: 'wall' | 'hole';
  board_label: string;
  category: string;
  category_label: string;
  content_html: string;
  content_text: string;
  excerpt: string;
  mode: 'anonymous' | 'named';
  author: AliasAuthor;
  is_owner: boolean;
  can_delete: boolean;
  status: string;
  status_label: string;
  is_featured: boolean;
  like_count: number;
  comment_count: number;
  favorite_count: number;
  liked: boolean;
  favorited: boolean;
  media: PostMedia[];
  time_ago: string;
  created_at: string;
  url: string;
}

/** 表白墙 / 树洞匿名评论 */
export interface ConfessionCommentItem {
  id: number;
  content_html: string;
  content_text: string;
  author: AliasAuthor;
  reply_to: null;
  time_ago: string;
  created_at: string;
  like_count: number;
  liked: boolean;
  is_owner: boolean;
  can_remove: boolean;
  replies?: ConfessionCommentItem[];
}

export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  pages: number;
}

declare global {
  interface Window {
    __MOFU__?: {
      csrf: string;
      user: MofuUser | null;
      theme: ThemeConfig;
      upload?: { maxMb: number; videoMaxMb: number };
      twikoo?: { envId: string; scriptUrl?: string; lang?: string };
    };
  }
}
