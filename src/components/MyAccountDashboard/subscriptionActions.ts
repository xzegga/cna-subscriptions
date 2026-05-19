import type { Subscription } from '../../services/api';

export type SubscriptionActionType =
  | 'pause'
  | 'activate'
  | 'cancel'
  | 'enable_auto_renew'
  | 'disable_auto_renew';

export interface SubscriptionActionOption {
  value: SubscriptionActionType;
  label: string;
}

export interface SubscriptionActionCopy {
  title: string;
  message: string;
  confirmLabel: string;
  variant: 'default' | 'danger';
}

const isAutoRenewOn = (sub: Subscription): boolean =>
  sub.is_auto_renew === 1 || sub.is_auto_renew === '1';

const hasPaymentToken = (sub: Subscription): boolean =>
  sub.has_payment_token === true ||
  sub.has_payment_token === 1 ||
  sub.has_payment_token === '1';

export const SUBSCRIPTION_ACTION_COPY: Record<SubscriptionActionType, SubscriptionActionCopy> = {
  pause: {
    title: 'Pausar suscripción',
    message:
      'Al pausar tu suscripción no se realizarán cobros automáticos ni nuevas entregas hasta que la reactives. Las entregas ya programadas de este ciclo se mantienen. ¿Deseas continuar?',
    confirmLabel: 'Sí, pausar',
    variant: 'default',
  },
  activate: {
    title: 'Reactivar suscripción',
    message:
      'Tu suscripción volverá a estar activa y continuará según la programación acordada. ¿Deseas reactivarla?',
    confirmLabel: 'Sí, reactivar',
    variant: 'default',
  },
  cancel: {
    title: 'Cancelar suscripción',
    message:
      'Esta acción cancelará tu suscripción. No se realizarán cobros ni entregas futuras. ¿Estás seguro de que deseas cancelar?',
    confirmLabel: 'Sí, cancelar',
    variant: 'danger',
  },
  enable_auto_renew: {
    title: 'Activar renovación automática',
    message:
      'Se cobrará automáticamente en la próxima fecha de renovación con tu método de pago guardado. ¿Deseas activar la renovación automática?',
    confirmLabel: 'Sí, activar',
    variant: 'default',
  },
  disable_auto_renew: {
    title: 'Desactivar renovación automática',
    message:
      'Se desactivará el cobro automático del próximo ciclo. Las entregas ya programadas de este período se mantienen. ¿Deseas continuar?',
    confirmLabel: 'Sí, desactivar',
    variant: 'default',
  },
};

/** Opciones disponibles según estado actual (similar al panel de administración). */
export function getAvailableSubscriptionActions(
  subscription: Subscription
): SubscriptionActionOption[] {
  const { status } = subscription;
  const autoOn = isAutoRenewOn(subscription);
  const options: SubscriptionActionOption[] = [];

  if (status === 'active') {
    options.push({ value: 'pause', label: 'Pausar suscripción' });
    if (autoOn) {
      options.push({
        value: 'disable_auto_renew',
        label: 'Desactivar auto-renovación (mantener entregas actuales)',
      });
    } else {
      options.push({ value: 'enable_auto_renew', label: 'Activar auto-renovación' });
    }
    options.push({ value: 'cancel', label: 'Cancelar suscripción' });
  } else if (status === 'paused') {
    options.push({ value: 'activate', label: 'Reactivar suscripción' });
    if (autoOn) {
      options.push({
        value: 'disable_auto_renew',
        label: 'Desactivar auto-renovación (mantener entregas actuales)',
      });
    }
    options.push({ value: 'cancel', label: 'Cancelar suscripción' });
  } else if (status === 'pending' || status === 'payment_failed') {
    options.push({ value: 'cancel', label: 'Cancelar suscripción' });
  }

  return options.filter((opt) => {
    if (opt.value === 'enable_auto_renew' && !hasPaymentToken(subscription)) {
      return false;
    }
    return true;
  });
}
