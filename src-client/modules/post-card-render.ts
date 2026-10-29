import { escapeHtml } from '@/core/dom';
import { $user } from '@/core/store';
import type { PostItem } from '@/types';

function renderRepost(post: PostItem): string {
  if (post.repost === null) {
    return '';
  }

  if (!post.repost.available || post.repost.author === null) {
    return '<div class="repost-quote is-unavailable"><p class="repost-quote__missing">原动态已删除或不可见</p></div>';
  }

  const thumb =
    post.repost.thumb !== ''
      ? `<img class="repost-quote__thumb" src="${escapeHtml(post.repost.thumb)}" alt="" loading="lazy">`
      : '';

  return `<div class="repost-quote">
      <div class="repost-quote__head">
          <span class="material-symbols-outlined" aria-hidden="true">repeat</span>
          <a href="${escapeHtml(post.repost.author.url)}">${escapeHtml(post.repost.author.nickname)}</a>
      </div>
      <a class="repost-quote__body" href="${escapeHtml(post.repost.url)}">
          <span class="repost-quote__text">${escapeHtml(post.repost.excerpt)}</span>
          ${thumb}
      </a>
  </div>`;
}

function renderMedia(post: PostItem): string {
  if (post.media.length === 0) {
    return '';
  }

  // 列表只保留最多 3 个矩形预览，正文不再直接铺开完整媒体。
  const shown = post.media.slice(0, 3);
  const items = shown
    .map((media, index) => {
      const isMore = index === shown.length - 1 && post.media.length > 3;

      if (media.kind === 'video') {
        return (
          `<a class="post-preview-media__item post-preview-media__item--video" href="/posts/${post.id}" aria-label="进入帖子查看视频">` +
          `<video preload="metadata" muted playsinline src="${escapeHtml(media.url)}"></video>` +
          '<span class="post-preview-media__play material-symbols-outlined" aria-hidden="true">play_arrow</span>' +
          '<span class="post-preview-media__tag">视频</span></a>'
        );
      }

      if (isMore) {
        return (
          `<a class="post-preview-media__item post-preview-media__item--more" href="/posts/${post.id}" aria-label="查看全部 ${post.media.length} 张图片">` +
          `<img src="${escapeHtml(media.thumb)}" alt="" loading="lazy">` +
          `<span class="post-preview-media__more">+${post.media.length - 2}</span></a>`
        );
      }

      return (
        `<button class="post-preview-media__item" type="button" data-action="lightbox" data-kind="image" data-src="${escapeHtml(media.url)}" aria-label="预览图片">` +
        `<img src="${escapeHtml(media.thumb)}" alt="动态图片" loading="lazy"></button>`
      );
    })
    .join('');

  return `<div class="post-preview-media" data-count="${shown.length}" aria-label="动态媒体预览">${items}</div>`;
}

function renderNameBadge(post: PostItem): string {
  if (post.author.name_badge_image) {
    return `<img class="name-badge name-badge--image" src="${escapeHtml(post.author.name_badge_image)}" alt="">`;
  }

  return post.author.name_badge_emoji
    ? `<span class="name-badge" title="${escapeHtml(post.author.name_badge_label ?? '个人挂件')}">${escapeHtml(post.author.name_badge_emoji)}</span>`
    : '';
}

function renderAvatar(post: PostItem): string {
  const author = post.author;
  const inner = author.avatar_url
    ? `<img class="avatar" src="${escapeHtml(author.avatar_url)}" alt="" loading="lazy" decoding="async">`
    : `<span class="avatar" style="--avatar-hue: ${author.hue}" aria-hidden="true">${escapeHtml(author.initial)}</span>`;

  if (author.avatar_frame_class) {
    return `<span class="avatar-frame avatar-frame--md ${escapeHtml(author.avatar_frame_class)}">${inner}</span>`;
  }

  return author.avatar_url
    ? `<img class="avatar avatar--md" src="${escapeHtml(author.avatar_url)}" alt="" loading="lazy" decoding="async">`
    : `<span class="avatar avatar--md" style="--avatar-hue: ${author.hue}" aria-hidden="true">${escapeHtml(author.initial)}</span>`;
}

function renderTopics(post: PostItem): string {
  if (post.topics.length === 0) {
    return '';
  }

  const chips = post.topics
    .map((topic) => `<a class="topic-chip" href="${escapeHtml(topic.url)}">#${escapeHtml(topic.name)}</a>`)
    .join('');

  return `<div class="post-topics">${chips}</div>`;
}

function renderTags(post: PostItem): string {
  const tags = post.tags ?? [];

  if (tags.length === 0) {
    return '';
  }

  const chips = tags
    .map((tag) => {
      const style = tag.color !== '' ? ` style="--tag-color: ${escapeHtml(tag.color)}"` : '';

      return `<a class="tag-chip" href="${escapeHtml(tag.url)}"${style}>${escapeHtml(tag.name)}</a>`;
    })
    .join('');

  return `<div class="post-tags">${chips}</div>`;
}

/**
 * 客户端渲染动态卡片。
 * 契约：结构（含头部举报/查看、底部点赞/评论/收藏/分享四个操作）必须与
 * components/post-card.twig 保持一致，改动时两边同步。
 */
export function renderPostCard(post: PostItem): HTMLElement {
  const template = document.createElement('template');
  const headAction = post.can_delete
    ? `<button class="icon-btn icon-btn--sm" type="button" data-action="delete-post" data-post-id="${post.id}" aria-label="删除动态" title="删除动态">
            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
        </button>`
    : post.can_edit
    ? `<a class="icon-btn icon-btn--sm" href="/posts/${post.id}" aria-label="查看动态" title="查看动态">
            <span class="material-symbols-outlined" aria-hidden="true">more_vert</span>
        </a>`
    : $user.get() !== null
      ? `<button class="icon-btn icon-btn--sm" type="button" data-action="report"
                data-target-type="post" data-target-id="${post.id}" aria-label="举报">
            <span class="material-symbols-outlined" aria-hidden="true">flag</span>
        </button>`
      : '';

  const levelChip = post.author.is_formal
    ? `<span class="level-chip${post.author.is_flash ? ' level-chip--flash' : ''}">` +
      `${post.author.is_flash ? '<span class="material-symbols-outlined" aria-hidden="true">bolt</span>' : ''}` +
      `Lv.${post.author.level}</span>`
    : '';

  const title = post.author.title;
  const titleTag =
    title !== null && title !== undefined
      ? `<span class="level-chip"><span class="material-symbols-outlined" aria-hidden="true">${escapeHtml(title.icon)}</span>${escapeHtml(title.name)}</span>`
      : '';

  template.innerHTML = `
<article class="card post-card" data-post-id="${post.id}" data-post-link="/posts/${post.id}">
    <header class="post-card__head">
        ${renderAvatar(post)}
        <div class="post-card__meta">
            <div class="post-card__title-row">
            <a class="post-card__author${post.author.is_premium ? ' is-premium' : ''}" href="${escapeHtml(post.author.url)}">${escapeHtml(post.author.nickname)}${renderNameBadge(post)}</a>
            ${levelChip}
            ${titleTag}
            </div>
            <div class="post-card__sub">
                <time datetime="${escapeHtml(post.created_at)}">${escapeHtml(post.time_ago)}</time>
                ${post.is_repost ? '<span class="meta-chip">转发</span>' : ''}
                ${post.visibility === 'private' ? '<span class="meta-chip">仅自己可见</span>' : ''}
                ${post.is_pinned ? '<span class="meta-chip meta-chip--accent">置顶</span>' : ''}
            </div>
        </div>
        ${headAction}
    </header>
    <div class="post-card__body">
        ${
          post.content_text !== ''
            ? `<div class="post-content" data-post-content>
            <div class="post-content__text" data-post-content-text>${post.content_html}</div>
            <a class="post-more" href="/posts/${post.id}" hidden>…查看更多</a>
        </div>`
            : ''
        }
        ${
          post.is_paid === true && post.unlocked === false
            ? `<div class="paid-lock">
            <p class="paid-lock__excerpt">${escapeHtml((post.excerpt ?? '').slice(0, 60))}</p>
            <div class="paid-lock__foot">
                <span class="material-symbols-outlined" aria-hidden="true">lock</span>
                <span class="paid-lock__price">${post.price ?? 0} M币</span>
                <button class="filled-btn filled-btn--sm" type="button" data-action="purchase-post" data-post-id="${post.id}">解锁查看</button>
            </div>
        </div>`
            : ''
        }
        ${renderRepost(post)}
        ${renderTopics(post)}
        ${renderTags(post)}
    </div>
    ${renderMedia(post)}
    <footer class="post-card__foot">
        <button class="post-action${post.liked ? ' is-active' : ''}" type="button"
                data-action="toggle-like" data-target-type="post" data-target-id="${post.id}"
                aria-pressed="${post.liked ? 'true' : 'false'}" aria-label="点赞">
            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
            <span class="post-action__count" data-like-count>${post.like_count}</span>
        </button>
        <a class="post-action" href="/posts/${post.id}#comments" aria-label="评论">
            <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
            <span class="post-action__count" data-twikoo-count data-comment-path="/posts/${post.id}">${post.comment_count}</span>
        </a>
        <button class="post-action${post.favorited ? ' is-active' : ''}" type="button"
                data-action="toggle-favorite" data-target-type="post" data-target-id="${post.id}"
                aria-pressed="${post.favorited ? 'true' : 'false'}" aria-label="收藏">
            <span class="material-symbols-outlined" aria-hidden="true">bookmark</span>
            <span class="post-action__count" data-favorite-count>${post.favorite_count ?? 0}</span>
        </button>
        <button class="post-action post-action--tail" type="button" data-action="share"
                data-share-url="/posts/${post.id}" aria-label="复制链接">
            <span class="material-symbols-outlined" aria-hidden="true">share</span>
        </button>
    </footer>
</article>`;

  return template.content.firstElementChild as HTMLElement;
}
