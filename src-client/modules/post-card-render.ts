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

  const first = post.media[0];

  if (first !== undefined && first.kind === 'video') {
    return (
      '<div class="post-media" data-count="1">' +
      `<video class="post-media__video" controls preload="metadata" playsinline src="${escapeHtml(first.url)}"></video>` +
      '</div>'
    );
  }

  const shown = post.media.slice(0, 4);
  const items = shown
    .map(
      (media) =>
        `<button class="post-media__item" type="button" data-action="lightbox" data-src="${escapeHtml(media.url)}">` +
        `<img src="${escapeHtml(media.thumb)}" alt="动态图片" loading="lazy"></button>`,
    )
    .join('');

  return `<div class="post-media" data-count="${shown.length}">${items}</div>`;
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

/**
 * 客户端渲染动态卡片。
 * 契约：结构（含头部举报/查看、底部点赞/评论/收藏/分享四个操作）
 * 必须与 components/post-card.twig 保持一致，改动时两边同步。
 */
export function renderPostCard(post: PostItem): HTMLElement {
  const template = document.createElement('template');
  const headAction = post.can_edit
    ? `<a class="icon-btn icon-btn--sm" href="/posts/${post.id}" aria-label="查看动态" title="查看动态">
            <span class="material-symbols-outlined" aria-hidden="true">more_vert</span>
        </a>`
    : $user.get() !== null
      ? `<button class="icon-btn icon-btn--sm" type="button" data-action="report"
                data-target-type="post" data-target-id="${post.id}" aria-label="举报">
            <span class="material-symbols-outlined" aria-hidden="true">flag</span>
        </button>`
      : '';

  template.innerHTML = `
<article class="card post-card" data-post-id="${post.id}" data-post-link="/posts/${post.id}">
    <header class="post-card__head">
        <span class="avatar avatar--md" style="--avatar-hue: ${post.author.hue}" aria-hidden="true">${escapeHtml(post.author.initial)}</span>
        <div class="post-card__meta">
            <a class="post-card__author" href="${escapeHtml(post.author.url)}">${escapeHtml(post.author.nickname)}</a>
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
        ${post.content_text !== '' ? `<div class="post-content">${post.content_html}</div>` : ''}
        ${renderRepost(post)}
        ${renderMedia(post)}
        ${renderTopics(post)}
    </div>
    <footer class="post-card__foot">
        <button class="post-action${post.liked ? ' is-active' : ''}" type="button"
                data-action="toggle-like" data-target-type="post" data-target-id="${post.id}"
                aria-pressed="${post.liked ? 'true' : 'false'}" aria-label="点赞">
            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
            <span class="post-action__count" data-like-count>${post.like_count}</span>
        </button>
        <a class="post-action" href="/posts/${post.id}#comments" aria-label="评论">
            <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
            <span class="post-action__count">${post.comment_count}</span>
        </a>
        <button class="post-action${post.favorited ? ' is-active' : ''}" type="button"
                data-action="toggle-favorite" data-target-type="post" data-target-id="${post.id}"
                aria-pressed="${post.favorited ? 'true' : 'false'}" aria-label="收藏">
            <span class="material-symbols-outlined" aria-hidden="true">bookmark</span>
        </button>
        <button class="post-action post-action--tail" type="button" data-action="share"
                data-share-url="/posts/${post.id}" aria-label="复制链接">
            <span class="material-symbols-outlined" aria-hidden="true">share</span>
        </button>
    </footer>
</article>`;

  return template.content.firstElementChild as HTMLElement;
}
