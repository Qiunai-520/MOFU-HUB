import type { PageModule } from './types';

interface Member {
  name: string;
  role: string;
  bio: string;
  avatar: string;
  email: string;
  since: string;
}

const FOCUSABLE = 'button, a[href]';

/** 一行「标签 + 值」的骨架；值由调用方按「实际渲染出的行」顺序回填 */
function row(label: string): string {
  return `<div class="member-dialog__row">
      <span class="member-dialog__label">${label}</span>
      <span class="member-dialog__value" data-member-value></span>
  </div>`;
}

/**
 * 团队成员资料弹层（关于页）。
 * 成员数据来自卡片上的 data-member-* ，用 textContent 回填，避免把后台内容当 HTML 解析。
 */
function openMemberDialog(member: Member): void {
  document.querySelector('.member-dialog')?.remove();

  const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  const dialog = document.createElement('div');
  dialog.className = 'member-dialog';

  // 值为空的行不渲染；回填时只按「实际渲染出的行」对齐，避免邮箱为空时日期被跳过
  const infos: Array<[string, string]> = [
    ['邮箱', member.email],
    ['加入时间', member.since],
  ];
  const visibleInfos = infos.filter(([, value]) => value.trim() !== '');

  dialog.innerHTML = `
<div class="member-dialog__panel mofu-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="member-dialog-name" tabindex="-1">
    <div class="member-dialog__head">
        <span class="member-dialog__avatar" data-member-avatar></span>
        <span>
            <span class="member-dialog__name" id="member-dialog-name" data-member-name></span>
            ${member.role.trim() === '' ? '' : '<span class="member-dialog__role meta-chip meta-chip--accent" data-member-role></span>'}
        </span>
    </div>
    <div class="member-dialog__rows">
        ${visibleInfos.map(([label]) => row(label)).join('')}
    </div>
    ${member.bio.trim() === '' ? '' : '<p class="member-dialog__bio" data-member-bio></p>'}
    <div class="member-dialog__foot">
        <button class="filled-btn filled-btn--sm" type="button" data-member-close>知道了</button>
    </div>
</div>`;

  document.body.append(dialog);

  const panel = dialog.querySelector<HTMLElement>('.member-dialog__panel');
  const avatarBox = dialog.querySelector<HTMLElement>('[data-member-avatar]');

  const nameEl = dialog.querySelector<HTMLElement>('[data-member-name]');

  if (nameEl !== null) {
    nameEl.textContent = member.name;
  }

  dialog.querySelector<HTMLElement>('[data-member-role]')?.append(member.role);
  dialog.querySelector<HTMLElement>('[data-member-bio]')?.append(member.bio);

  const values = dialog.querySelectorAll<HTMLElement>('[data-member-value]');

  visibleInfos.forEach(([, value], index) => {
    const node = values[index];

    if (node !== undefined) {
      node.textContent = value;
    }
  });

  if (avatarBox !== null) {
    if (member.avatar !== '') {
      const img = document.createElement('img');
      img.className = 'member-dialog__avatar';
      img.src = member.avatar;
      img.alt = '';
      img.loading = 'lazy';
      avatarBox.replaceWith(img);
    } else {
      avatarBox.textContent = member.name.slice(0, 1);
    }
  }

  const close = (): void => {
    document.removeEventListener('keydown', onKeydown);
    dialog.remove();
    previouslyFocused?.focus();
  };

  dialog.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;

    if (target === dialog || target.closest('[data-member-close]') !== null) {
      close();
    }
  });

  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
      close();

      return;
    }

    if (event.key !== 'Tab' || panel === null) {
      return;
    }

    const focusables = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE));

    if (focusables.length === 0) {
      event.preventDefault();

      return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1] ?? first;

    if (first === undefined) {
      return;
    }

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  document.addEventListener('keydown', onKeydown);
  panel?.focus();
}

/** 关于页：点团队成员卡片弹出资料卡 */
export const init: PageModule['init'] = () => {
  const onClick = (event: Event): void => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const card = target.closest<HTMLElement>('[data-team-member]');

    if (card === null) {
      return;
    }

    openMemberDialog({
      name: card.dataset.memberName ?? '',
      role: card.dataset.memberRole ?? '',
      bio: card.dataset.memberBio ?? '',
      avatar: card.dataset.memberAvatar ?? '',
      email: card.dataset.memberEmail ?? '',
      since: card.dataset.memberSince ?? '',
    });
  };

  document.addEventListener('click', onClick);

  return () => document.removeEventListener('click', onClick);
};
