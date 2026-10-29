import { api } from '@/core/api';
import { setButtonLoading } from '@/core/loading';
import { toast, toastError } from '@/core/toast';
import { $user } from '@/core/store';

const REASONS: Array<[string, string]> = [
  ['spam', '垃圾信息'],
  ['harassment', '骚扰或辱骂'],
  ['porn', '色情或不适当内容'],
  ['violence', '暴力或危险内容'],
  ['fraud', '欺诈/诈骗'],
  ['impersonation', '冒充他人'],
  ['copyright', '侵犯版权'],
  ['illegal', '违法违规'],
  ['other', '其他'],
];

const FOCUSABLE = 'input, textarea, button, select, a[href]';

/**
 * 打开举报对话框（动态 / 评论 / 表白墙 / 匿名评论通用）。
 *
 * 焦点管理：打开时聚焦面板（读屏可播报标题），Tab 在面板内循环，
 * 关闭（取消 / 遮罩 / Esc / 提交成功）后焦点还原到触发元素。
 */
export function openReportDialog(targetType: string, targetId: number): void {
  if ($user.get() === null) {
    toast('登录后才能举报', 'info');
    return;
  }

  if (targetType === '' || targetId <= 0) {
    return;
  }

  document.querySelector('.report-dialog')?.remove();

  const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  const dialog = document.createElement('div');
  dialog.className = 'report-dialog mofu-dialog';

  dialog.innerHTML = `
<div class="report-dialog__panel mofu-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="report-dialog-title" tabindex="-1">
    <h2 class="report-dialog__title" id="report-dialog-title">举报内容</h2>
    <p class="report-dialog__subtitle">我们会尽快核实处理，感谢你共同维护社区氛围</p>
    <div class="report-dialog__reasons">
        ${REASONS.map(([value, label], index) => `
        <label class="report-reason">
            <input type="radio" name="report-reason" value="${value}" ${index === 0 ? 'checked' : ''}>
            <span>${label}</span>
        </label>`).join('')}
    </div>
    <textarea class="field__input report-dialog__detail" maxlength="500" placeholder="补充说明（可选）"></textarea>
    <div class="report-dialog__foot">
        <button class="text-btn" type="button" data-report="cancel">取消</button>
        <button class="filled-btn filled-btn--sm" type="button" data-report="submit">提交举报</button>
    </div>
</div>`;

  document.body.append(dialog);

  const panel = dialog.querySelector<HTMLElement>('.report-dialog__panel');

  const close = (): void => {
    document.removeEventListener('keydown', onKeydown);
    dialog.remove();
    previouslyFocused?.focus();
  };

  const submit = async (): Promise<void> => {
    const button = dialog.querySelector<HTMLButtonElement>('[data-report="submit"]');
    const checked = dialog.querySelector<HTMLInputElement>('input[name="report-reason"]:checked');
    const detail = dialog.querySelector<HTMLTextAreaElement>('.report-dialog__detail');

    if (checked === null || checked === undefined) {
      return;
    }

    if (button !== null) {
      button.disabled = true;
      setButtonLoading(button, true);
    }

    try {
      await api.post('/api/v1/reports', {
        target_type: targetType,
        target_id: targetId,
        reason: checked.value,
        detail: detail?.value.trim() ?? '',
      });

      toast('举报已提交，我们会尽快处理', 'success');
      close();
    } catch (error) {
      toastError(error);

      if (button !== null) {
        button.disabled = false;
        setButtonLoading(button, false);
      }
    }
  };

  dialog.addEventListener('click', (event) => {
    const element = event.target as HTMLElement;

    if (element === dialog || element.closest('[data-report="cancel"]')) {
      close();
      return;
    }

    if (element.closest('[data-report="submit"]')) {
      void submit();
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

    const focusables = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
      (element) => !element.hasAttribute('disabled'),
    );

    if (focusables.length === 0) {
      event.preventDefault();
      return;
    }

    const first = focusables[0];

    if (first === undefined) {
      return;
    }

    const last = focusables[focusables.length - 1] ?? first;
    const active = document.activeElement;

    if (event.shiftKey && (active === first || active === panel)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  };

  document.addEventListener('keydown', onKeydown);

  panel?.focus();
}
