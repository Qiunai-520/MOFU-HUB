import { escapeHtml } from '@/core/dom';
import { $user } from '@/core/store';

/** 评论渲染需要的字段（具名评论与匿名评论的公共形状） */
export interface RenderableComment {
  id: number;
  content_html: string;
  time_ago: string;
  created_at?: string;
  like_count: number;
  liked: boolean;
  can_remove: boolean;
  reply_to?: { nickname: string } | null;
  author: {
    nickname?: string;
    display?: string;
    url?: string;
    avatar_url?: string;
    avatar_frame_class?: string;
    name_badge_emoji?: string;
    name_badge_label?: string;
    name_badge_image?: string;
    hue: number;
    initial: string;
  };
}

export interface CommentRenderOptions {
  isReply?: boolean;
  /** 表白墙 / 树洞：匿名展示（首字头像，不链接、不显示回复对象） */
  anonymous?: boolean;
}

/**
 * 渲染单条评论。
 * 契约：结构必须与 app/Views/components/comment.twig 保持一致（改动时两端同步）。
 */
export function renderComment(comment: RenderableComment, options: CommentRenderOptions = {}): HTMLElement {
  const template = document.createElement('template');
  const isReply = options.isReply === true;
  const anonymous = options.anonymous === true;
  const name = (anonymous ? comment.author.display : comment.author.nickname) ?? '';

  const baseAvatar =
    !anonymous && typeof comment.author.avatar_url === 'string' && comment.author.avatar_url !== ''
      ? `<img class="avatar avatar--sm" src="${escapeHtml(comment.author.avatar_url)}" alt="" loading="lazy">`
      : `<span class="avatar avatar--sm" style="--avatar-hue: ${comment.author.hue}" aria-hidden="true">${escapeHtml(comment.author.initial)}</span>`;
  const frameInner = comment.author.avatar_url
    ? `<img class="avatar" src="${escapeHtml(comment.author.avatar_url)}" alt="" loading="lazy">`
    : `<span class="avatar" style="--avatar-hue: ${comment.author.hue}" aria-hidden="true">${escapeHtml(comment.author.initial)}</span>`;
  const avatarInner = !anonymous && comment.author.avatar_frame_class
    ? `<span class="avatar-frame avatar-frame--sm ${escapeHtml(comment.author.avatar_frame_class)}">${frameInner}</span>`
    : baseAvatar;

  // 具名评论的头像可点进个人主页（与 components/comment.twig 保持一致）
  const avatar = anonymous
    ? avatarInner
    : `<a class="avatar-link" href="${escapeHtml(comment.author.url ?? '')}" aria-label="${escapeHtml(name)} 的主页">${avatarInner}</a>`;

  const author = anonymous
    ? `<span class="comment__author comment-card__author--anon">${escapeHtml(name)}</span>`
    : `<a class="comment__author" href="${escapeHtml(comment.author.url ?? '')}">${escapeHtml(name)}${comment.author.name_badge_image ? `<img class="name-badge name-badge--image" src="${escapeHtml(comment.author.name_badge_image)}" alt="">` : comment.author.name_badge_emoji ? `<span class="name-badge" title="${escapeHtml(comment.author.name_badge_label ?? '个人挂件')}">${escapeHtml(comment.author.name_badge_emoji)}</span>` : ''}</a>`;

  const replyTo =
    !anonymous && comment.reply_to
      ? `<span class="comment__reply-to">回复 ${escapeHtml(comment.reply_to.nickname)}</span>`
      : '';

  template.innerHTML = `
<div class="comment${isReply ? ' comment--reply' : ''}" data-comment-id="${comment.id}">
    ${avatar}
    <div class="comment__body">
        <div class="comment__head">
            ${author}
            ${replyTo}
            <time class="comment__time" datetime="${escapeHtml(comment.created_at ?? '')}">${escapeHtml(comment.time_ago)}</time>
        </div>
        <div class="comment__content">${comment.content_html}</div>
        <div class="comment__actions">
            <button class="post-action post-action--sm${comment.liked ? ' is-active' : ''}" type="button"
                    aria-label="点赞"
                    data-action="toggle-like" data-target-type="${anonymous ? 'confession_comment' : 'comment'}" data-target-id="${comment.id}"
                    aria-pressed="${comment.liked ? 'true' : 'false'}">
                <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
                <span class="post-action__count" data-like-count>${comment.like_count}</span>
            </button>
            ${$user.get() !== null ? `<button class="text-btn text-btn--sm" type="button" data-action="reply" data-comment-id="${comment.id}" data-reply-name="${escapeHtml(name)}">回复</button>` : ''}
            ${$user.get() !== null && !comment.can_remove ? `<button class="text-btn text-btn--sm" type="button" data-action="report" data-target-type="${anonymous ? 'confession_comment' : 'comment'}" data-target-id="${comment.id}">举报</button>` : ''}
            ${comment.can_remove ? `<button class="text-btn text-btn--sm text-btn--danger" type="button" data-action="remove-comment" data-comment-id="${comment.id}">删除</button>` : ''}
        </div>
    </div>
</div>`;

  return template.content.firstElementChild as HTMLElement;
}
