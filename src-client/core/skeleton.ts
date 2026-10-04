/**
 * 列表加载骨架屏：抓取期间在列表末尾插入占位行，完成后移除。
 * 纯占位视觉，不参与业务逻辑；样式见 styles/base.css 的 .skeleton。
 */
const SKELETON_ATTR = 'data-skeleton-row';

/** 在列表末尾插入 count 个骨架行，返回插入的节点 */
export function showSkeletonRows(list: HTMLElement, count = 2): HTMLElement[] {
  const rows: HTMLElement[] = [];

  for (let i = 0; i < count; i++) {
    const row = document.createElement('div');
    row.className = 'card skeleton-card';
    row.setAttribute(SKELETON_ATTR, '');
    row.setAttribute('aria-hidden', 'true');
    row.innerHTML =
      '<span class="skeleton skeleton--avatar"></span>' +
      '<span class="skeleton-lines">' +
      '<span class="skeleton skeleton--line" style="width: 42%"></span>' +
      '<span class="skeleton skeleton--line" style="width: 88%"></span>' +
      '<span class="skeleton skeleton--line" style="width: 64%"></span>' +
      '</span>';
    list.append(row);
    rows.push(row);
  }

  return rows;
}

/** 移除列表中的骨架行 */
export function removeSkeletonRows(list: HTMLElement): void {
  list.querySelectorAll(`[${SKELETON_ATTR}]`).forEach((node) => node.remove());
}
