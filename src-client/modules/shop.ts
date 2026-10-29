import { api } from '@/core/api';
import { $ } from '@/core/dom';
import { toast, toastError } from '@/core/toast';
import type { PageModule } from './types';

/** MOFU小店：M币兑换与在线支付下单 */
export const init: PageModule['init'] = () => {
  const root = $<HTMLElement>('.shop-page');

  if (root === null) {
    return;
  }

  const onClick = async (event: Event): Promise<void> => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const buy = target.closest<HTMLButtonElement>('[data-action="buy-product"]');

    if (buy !== null && !buy.disabled) {
      buy.disabled = true;

      try {
        await api.post(`/api/v1/shop/${buy.dataset.id ?? ''}/purchase`);
        toast('兑换成功', 'success');
        window.setTimeout(() => window.location.reload(), 500);
      } catch (error) {
        buy.disabled = false;
        toastError(error);
      }

      return;
    }

    const pay = target.closest<HTMLButtonElement>('[data-action="pay-product"]');

    if (pay === null || pay.disabled) {
      return;
    }

    pay.disabled = true;

    try {
      const { data } = await api.post<{ pay_url: string }>(`/api/v1/shop/${pay.dataset.id ?? ''}/pay`);

      if (data.pay_url !== '') {
        window.location.href = data.pay_url;

        return;
      }

      pay.disabled = false;
    } catch (error) {
      pay.disabled = false;
      toastError(error);
    }
  };

  root.addEventListener('click', (event) => void onClick(event));
};
