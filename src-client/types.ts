export interface MofuUser {
  id: number;
  username: string;
  nickname: string;
  email?: string;
  avatar_url?: string;
  avatar_frame_class?: string;
  name_badge_emoji?: string;
  name_badge_label?: string;
  name_badge_image?: string;
  profile_url?: string;
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

export interface PostTag {
  id: number;
  slug: string;
  name: string;
  color: string;
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
  avatar_frame_class?: string;
  name_badge_emoji?: string;
  name_badge_label?: string;
  name_badge_image?: string;
  /** 列表/详情通用：转正后的等级与佩戴称号（未转正时 level 为 0、不展示） */
  level?: number | null;
  is_formal?: boolean;
  /** 满级（Lv6）后通过进阶答题的「闪电」标记，展示为等级徽章前的小闪电 */
  is_flash?: boolean;
  /** 大会员（membership = premium） */
  is_premium?: boolean;
  title?: { name: string; icon: string } | null;
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
  /** 转发帖不能编辑但可删除（作者/管理员） */
  can_delete?: boolean;
  /** 付费动态：未购买时只有摘要可见 */
  is_paid?: boolean;
  price?: number;
  unlocked?: boolean;
  author: PostAuthor;
  time_ago: string;
  created_at: string;
  like_count: number;
  comment_count: number;
  favorite_count: number;
  repost_count: number;
  liked: boolean;
  favorited: boolean;
  reposted: boolean;
  is_repost: boolean;
  repost: PostRepostQuote | null;
  media: PostMedia[];
  topics: PostTopic[];
  tags?: PostTag[];
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
      twikoo?: { envId: string; scriptUrl?: string; lang?: string; emotionCdn?: string };
      navPrimaryPaths?: string[];
    };
  }
}
