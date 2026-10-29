import { api } from '@/core/api';
import { $, $$, escapeHtml } from '@/core/dom';
import { toast, toastError } from '@/core/toast';
import type { PageModule } from './types';

interface CheckinStatus {
  checked_today: boolean;
  streak: number;
  best: number;
  total: number;
  next_reward: { mofu: number; xp: number; label: string };
}

interface LevelStats {
  level: number;
  xp: number;
  remaining_xp: number;
  progress: number;
  level_name: string;
}

interface CalendarDay {
  date: string;
  day: number;
  checked: boolean;
  today: boolean;
  weekday: number;
}

interface QuizQuestion {
  id: number;
  question: string;
  options: string[];
}

interface QuizResult {
  score: number;
  correct: number;
  total: number;
  passed: boolean;
  pass_score: number;
}

function updateBalance(balance: number): void {
  const el = $<HTMLElement>('[data-wallet-balance]');
  if (el !== null) el.textContent = String(balance);
}

function renderCalendar(host: HTMLElement, days: CalendarDay[], specialDays: number[]): void {
  host.innerHTML = days
    .map((day) => {
      const classes = ['cal-day'];
      if (day.checked) classes.push('is-checked');
      if (day.today) classes.push('is-today');
      if (specialDays.includes(day.day)) classes.push('is-special');
      return `<span class="${classes.join(' ')}" title="${escapeHtml(day.date)}">${day.day}</span>`;
    })
    .join('');
}

async function loadCalendar(host: HTMLElement | null): Promise<void> {
  if (host === null) return;
  try {
    const { data } = await api.get<{ days: CalendarDay[]; special_days: number[] }>('/api/v1/growth/calendar');
    renderCalendar(host, data.days, data.special_days);
  } catch {
    host.innerHTML = '';
  }
}

function setupTabs(): void {
  const tabs = $$<HTMLButtonElement>('[data-growth-tabs] [data-tab]');
  const panels = $$<HTMLElement>('[data-panel]');

  const activate = (name: string): void => {
    for (const tab of tabs) tab.classList.toggle('is-active', tab.dataset.tab === name);
    for (const panel of panels) panel.classList.toggle('is-active', panel.dataset.panel === name);
  };

  for (const tab of tabs) {
    tab.addEventListener('click', () => activate(tab.dataset.tab ?? ''));
  }
}

function setupCheckin(root: HTMLElement): void {
  const button = $<HTMLButtonElement>('[data-action="checkin"]', root);
  const calendarHost = $<HTMLElement>('[data-checkin-calendar]', root);

  void loadCalendar(calendarHost);

  button?.addEventListener('click', async () => {
    if (button.disabled) return;
    button.disabled = true;

    try {
      const { data } = await api.post<{ already: boolean; streak: number; best: number; reward_mofu?: number; status: CheckinStatus }>(
        '/api/v1/growth/checkin',
      );

      if (data.already) {
        toast('今天已经签过啦', 'info');
      } else {
        toast(`签到成功，获得 ${data.reward_mofu ?? 0} M币`, 'success');
      }

      const status = data.status;
      const streak = $<HTMLElement>('[data-checkin-streak]', root);
      const best = $<HTMLElement>('[data-checkin-best]', root);
      const total = $<HTMLElement>('[data-checkin-total]', root);
      const next = $<HTMLElement>('[data-checkin-next]', root);

      if (streak !== null) streak.textContent = String(status.streak);
      if (best !== null) best.textContent = String(status.best);
      if (total !== null) total.textContent = String(status.total);
      if (next !== null) next.textContent = `${status.next_reward.mofu} M币 · ${status.next_reward.xp} XP`;

      button.textContent = status.checked_today ? '今日已签到' : '立即签到';
      button.disabled = status.checked_today;

      if (!data.already) {
        const { data: profile } = await api.get<{ wallet: { balance: number } }>('/api/v1/growth');
        updateBalance(profile.wallet.balance);
      }

      void loadCalendar(calendarHost);
    } catch (error) {
      button.disabled = false;
      toastError(error);
    }
  });
}

function setupTitles(root: HTMLElement): void {
  root.addEventListener('click', async (event) => {
    const trigger = (event.target as HTMLElement).closest<HTMLButtonElement>('[data-action="equip-title"]');

    if (trigger === null || trigger.disabled) return;

    const chip = trigger.closest('[data-title-id]');
    const titleId = Number(trigger.dataset.titleId ?? 0);
    const equipped = trigger.dataset.equipped === '1';

    trigger.disabled = true;

    try {
      // 佩戴传 title_id，取消佩戴传 0（服务端只允许同时戴一个）
      await api.post('/api/v1/growth/title', { title_id: equipped ? 0 : titleId });

      for (const item of $$<HTMLElement>('[data-title-id]', root)) {
        const on = item === chip && !equipped;
        item.classList.toggle('is-equipped', on);

        const btn = $<HTMLButtonElement>('[data-action="equip-title"]', item);

        if (btn !== null) {
          btn.dataset.equipped = on ? '1' : '0';
          btn.textContent = on ? '取消佩戴' : '佩戴';
        }
      }

      toast(equipped ? '已取消佩戴' : '已佩戴称号', 'success');
    } catch (error) {
      toastError(error);
    } finally {
      trigger.disabled = false;
    }
  });
}

/** 答题（转正 / 满级进阶）：题目从服务端随机抽取，答案只在服务端，提交后由服务端评分 */
function setupQuiz(root: HTMLElement): void {
  const startButtons = $$<HTMLButtonElement>('[data-action="start-quiz"]', root);
  const closeButton = $<HTMLButtonElement>('[data-action="close-quiz"]', root);
  const stage = $<HTMLElement>('[data-quiz-stage]', root);
  const list = $<HTMLElement>('[data-quiz-questions]', root);
  const progress = $<HTMLElement>('[data-quiz-progress]', root);
  const form = $<HTMLFormElement>('[data-quiz-form]', root);

  if (startButtons.length === 0 || stage === null || list === null || form === null) {
    return;
  }

  let questions: QuizQuestion[] = [];
  let tier = 1;

  for (const startButton of startButtons) {
    startButton.addEventListener('click', async () => {
      startButton.disabled = true;
      tier = Number(startButton.dataset.tier ?? 1) || 1;

      try {
        const { data } = await api.get<{ questions: QuizQuestion[]; pass_score: number }>(
          `/api/v1/membership/quiz?tier=${tier}`,
        );
        questions = data.questions;
        stage.removeAttribute('hidden');

        list.innerHTML = questions
          .map(
            (q, index) => `
          <fieldset class="quiz-item" data-question-id="${q.id}">
              <legend class="quiz-item__title">${index + 1}. ${escapeHtml(q.question)}</legend>
              ${q.options
                .map(
                  (option, i) => `<label class="quiz-option">
                      <input type="radio" name="q-${q.id}" value="${i}">
                      <span>${escapeHtml(option)}</span>
                  </label>`,
                )
                .join('')}
          </fieldset>`,
          )
          .join('');

        if (progress !== null) progress.textContent = `共 ${questions.length} 题 · ${data.pass_score} 分及格`;
        stage.scrollIntoView({ block: 'nearest' });
      } catch (error) {
        startButton.disabled = false;
        toastError(error);
      }
    });
  }

  closeButton?.addEventListener('click', () => {
    stage.setAttribute('hidden', '');
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const answers: Record<number, number> = {};

    for (const q of questions) {
      const checked = form.querySelector<HTMLInputElement>(`input[name="q-${q.id}"]:checked`);
      if (checked !== null) {
        answers[q.id] = Number(checked.value);
      }
    }

    if (Object.keys(answers).length === 0) {
      toast('请先作答', 'info');
      return;
    }

    const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (submit !== null) submit.disabled = true;

    try {
      const { data } = await api.post<QuizResult>('/api/v1/membership/quiz/submit', { answers, tier });

      if (data.passed) {
        const message =
          tier === 2
            ? `通过！得分 ${data.score}（${data.correct}/${data.total}），你已成为闪电 Lv.6`
            : `通过！得分 ${data.score}（${data.correct}/${data.total}），你已成为正式会员`;
        toast(message, 'success');
        window.setTimeout(() => window.location.reload(), 1200);
      } else {
        toast(`得分 ${data.score}，还需 ${data.pass_score} 分及格，可稍后再试`, 'error');
        if (submit !== null) submit.disabled = false;
      }
    } catch (error) {
      if (submit !== null) submit.disabled = false;
      toastError(error);
    }
  });
}

/** 大会员每日加速包：需手动领取 */
function setupPremiumBonus(root: HTMLElement): void {
  const button = $<HTMLButtonElement>('[data-action="claim-premium-bonus"]', root);

  if (button === null) {
    return;
  }

  button.addEventListener('click', async () => {
    button.disabled = true;

    try {
      const { data } = await api.post<{ xp: number; already: boolean }>('/api/v1/membership/premium-bonus');

      toast(data.already ? '今天已经领过啦' : `已领取 +${data.xp} XP`, 'success');
      window.setTimeout(() => window.location.reload(), 600);
    } catch (error) {
      toastError(error);
      button.disabled = false;
    }
  });
}

export const init: PageModule['init'] = (): (() => void) | void => {
  const root = $<HTMLElement>('[data-growth]');
  if (root === null) return;

  setupTabs();
  setupCheckin(root);
  setupTitles(root);
  setupQuiz(root);
  setupPremiumBonus(root);
};
