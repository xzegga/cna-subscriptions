import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  getUserSubscriptions,
  getSubscriptionDeliveries,
  performSubscriptionAction,
} from '../../services/api';
import type {
  Subscription,
  Delivery,
  SubscriptionActionType,
} from '../../services/api';
import SubscriptionConfirmModal from './SubscriptionConfirmModal';
import {
  SUBSCRIPTION_ACTION_COPY,
  getAvailableSubscriptionActions,
} from './subscriptionActions';
import './MyAccountDashboard.css';

/** REST puede devolver decimales MySQL como string; evita .toFixed() en no-números. */
function toAmount(value: unknown): number {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  const n = parseFloat(String(value ?? ''));
  return Number.isFinite(n) ? n : 0;
}

/** Parsea fechas MySQL (`YYYY-MM-DD` o `YYYY-MM-DD HH:mm:ss`) para evitar "Invalid Date". */
function formatDate(dateString: string) {
  if (!dateString || String(dateString).startsWith('0000-00')) {
    return '—';
  }
  const s = String(dateString).trim();
  let iso = s;
  if (/\d{4}-\d{2}-\d{2} \d/.test(s)) {
    iso = s.replace(' ', 'T');
  } else if (!s.includes('T') && /^\d{4}-\d{2}-\d{2}$/.test(s)) {
    iso = `${s}T12:00:00`;
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }
  return date.toLocaleDateString('es-SV', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
}

interface MyAccountDashboardProps {
  userId: number;
  initialSubscriptionId?: number;
}

const MyAccountDashboard: React.FC<MyAccountDashboardProps> = ({
  userId,
  initialSubscriptionId = 0,
}) => {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [expandedSubscriptions, setExpandedSubscriptions] = useState<Set<number>>(new Set());
  const [deliveriesCache, setDeliveriesCache] = useState<Record<number, Delivery[]>>({});
  const [deliveriesLoading, setDeliveriesLoading] = useState<Record<number, boolean>>({});
  const [deliveriesError, setDeliveriesError] = useState<Record<number, string>>({});
  const [actionSelectValue, setActionSelectValue] = useState<Record<number, string>>({});
  const [pendingAction, setPendingAction] = useState<{
    subscriptionId: number;
    action: SubscriptionActionType;
  } | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const pendingExpandRef = useRef<number | null>(
    initialSubscriptionId > 0 ? initialSubscriptionId : null
  );

  const loadDeliveries = useCallback(async (subscriptionId: number) => {
    setDeliveriesLoading((prev) => ({ ...prev, [subscriptionId]: true }));
    setDeliveriesError((prev) => {
      const next = { ...prev };
      delete next[subscriptionId];
      return next;
    });

    try {
      const deliveries = await getSubscriptionDeliveries(subscriptionId);
      setDeliveriesCache((prev) => ({
        ...prev,
        [subscriptionId]: deliveries,
      }));
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Error al cargar entregas';
      setDeliveriesError((prev) => ({ ...prev, [subscriptionId]: message }));
    } finally {
      setDeliveriesLoading((prev) => ({ ...prev, [subscriptionId]: false }));
    }
  }, []);

  const expandSubscription = useCallback(
    (subscriptionId: number) => {
      setExpandedSubscriptions((prev) => {
        const next = new Set(prev);
        next.add(subscriptionId);
        return next;
      });
      loadDeliveries(subscriptionId);
    },
    [loadDeliveries]
  );

  const loadSubscriptions = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const data = await getUserSubscriptions();
      setSubscriptions(data);

      const pendingId = pendingExpandRef.current;
      if (pendingId && data.some((sub) => sub.id === pendingId)) {
        pendingExpandRef.current = null;
        expandSubscription(pendingId);
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Error al cargar suscripciones');
    } finally {
      setLoading(false);
    }
  }, [expandSubscription]);

  useEffect(() => {
    loadSubscriptions();
  }, [userId, loadSubscriptions]);

  const handleToggleExpand = (
    event: React.MouseEvent<HTMLButtonElement>,
    subscriptionId: number
  ) => {
    event.preventDefault();
    event.stopPropagation();
    event.nativeEvent.stopImmediatePropagation();

    if (expandedSubscriptions.has(subscriptionId)) {
      setExpandedSubscriptions((prev) => {
        const next = new Set(prev);
        next.delete(subscriptionId);
        return next;
      });
    } else {
      expandSubscription(subscriptionId);
    }
  };

  const handleActionSelect = (subscriptionId: number, value: string) => {
    if (!value) {
      return;
    }
    setActionSelectValue((prev) => ({ ...prev, [subscriptionId]: value }));
    setPendingAction({
      subscriptionId,
      action: value as SubscriptionActionType,
    });
  };

  const closeActionModal = () => {
    if (actionLoading) {
      return;
    }
    if (pendingAction) {
      setActionSelectValue((prev) => ({ ...prev, [pendingAction.subscriptionId]: '' }));
    }
    setPendingAction(null);
  };

  const confirmPendingAction = async () => {
    if (!pendingAction) {
      return;
    }

    const { subscriptionId, action } = pendingAction;
    setActionLoading(true);
    setError(null);

    try {
      const result = await performSubscriptionAction(subscriptionId, action);
      if (result.subscription) {
        setSubscriptions((prev) =>
          prev.map((sub) => (sub.id === subscriptionId ? result.subscription! : sub))
        );
      } else {
        await loadSubscriptions();
      }
      setSuccessMessage(result.message || 'Acción realizada correctamente');
      setActionSelectValue((prev) => ({ ...prev, [subscriptionId]: '' }));
      setPendingAction(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Error al realizar la acción');
      setActionSelectValue((prev) => ({ ...prev, [subscriptionId]: '' }));
      setPendingAction(null);
    } finally {
      setActionLoading(false);
    }
  };

  const parseVariantDetails = (json: string) => {
    try {
      return JSON.parse(json);
    } catch {
      return {};
    }
  };

  const parseShippingAddress = (json: string) => {
    try {
      return JSON.parse(json);
    } catch {
      return {};
    }
  };

  const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
      active: 'Activa',
      pending: 'Pendiente',
      cancelled: 'Cancelada',
      paused: 'Pausada',
      payment_failed: 'Pago Fallido',
      completed: 'Completada',
    };
    return labels[status] || status;
  };

  const getStatusClass = (status: string) => {
    const classes: Record<string, string> = {
      active: 'status-active',
      pending: 'status-pending',
      cancelled: 'status-cancelled',
      paused: 'status-pending',
      payment_failed: 'status-failed',
      completed: 'status-completed',
    };
    return classes[status] || '';
  };

  if (loading) {
    return (
      <div className="cna-my-account">
        <div className="cna-loading">Cargando suscripciones...</div>
      </div>
    );
  }

  if (error && subscriptions.length === 0) {
    return (
      <div className="cna-my-account">
        <div className="cna-error-message">{error}</div>
      </div>
    );
  }

  if (subscriptions.length === 0) {
    return (
      <div className="cna-my-account">
        <h2 className="cna-my-account__title">Mis Suscripciones</h2>
        <p>No tienes suscripciones activas.</p>
      </div>
    );
  }

  return (
    <div className="cna-my-account">
      <h2 className="cna-my-account__title">Mis Suscripciones</h2>

      {error && <div className="cna-error-message">{error}</div>}
      {successMessage && (
        <div className="cna-success-message" role="status">
          {successMessage}
          <button
            type="button"
            className="cna-success-message__dismiss"
            onClick={() => setSuccessMessage(null)}
            aria-label="Cerrar mensaje"
          >
            ×
          </button>
        </div>
      )}

      <div className="cna-subscriptions-list">
        {subscriptions.map((subscription) => {
          const variant = parseVariantDetails(subscription.variant_details);
          const shipping = parseShippingAddress(subscription.shipping_address_json);
          const deliveries = deliveriesCache[subscription.id] || [];
          const isExpanded = expandedSubscriptions.has(subscription.id);
          const isLoadingDeliveries = deliveriesLoading[subscription.id];
          const deliveryLoadError = deliveriesError[subscription.id];
          const availableActions = getAvailableSubscriptionActions(subscription);

          return (
            <div
              key={subscription.id}
              data-subscription-card={subscription.id}
              className={`cna-subscription-card ${isExpanded ? 'is-expanded' : ''}`}
            >
              <div className="cna-subscription-header">
                <div className="cna-subscription-info">
                  <h3>
                    {subscription.product_name || `Suscripción #${subscription.id}`}
                  </h3>
                  <span className={`cna-status-badge ${getStatusClass(subscription.status)}`}>
                    {getStatusLabel(subscription.status)}
                  </span>
                </div>
                <button
                  type="button"
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={(event) => handleToggleExpand(event, subscription.id)}
                  className="cna-toggle-button"
                  aria-expanded={isExpanded}
                  aria-controls={`cna-subscription-details-${subscription.id}`}
                >
                  {isExpanded ? 'Ocultar detalles' : 'Ver detalles'}
                </button>
              </div>

              <div className="cna-subscription-summary">
                <div className="cna-summary-item">
                  <strong>Tamaño:</strong> {variant.size || 'N/A'}
                </div>
                <div className="cna-summary-item">
                  <strong>Cantidad:</strong> {variant.qty || 'N/A'} unidades
                </div>
                <div className="cna-summary-item">
                  <strong>Frecuencia:</strong> Cada {variant.frequency || 'N/A'} semana(s)
                </div>
                {subscription.next_renewal_date && (
                  <div className="cna-summary-item">
                    <strong>Próxima renovación:</strong>{' '}
                    {formatDate(subscription.next_renewal_date)}
                  </div>
                )}
                <div className="cna-summary-item">
                  <strong>Auto-renovación:</strong>{' '}
                  {subscription.is_auto_renew === 1 || subscription.is_auto_renew === '1'
                    ? 'Activa'
                    : 'Desactivada'}
                </div>
              </div>

              {isExpanded && (
                <div
                  id={`cna-subscription-details-${subscription.id}`}
                  className="cna-subscription-details"
                >
                  {availableActions.length > 0 && (
                    <div className="cna-detail-section cna-actions-section">
                      <h4>Gestionar suscripción</h4>
                      <p className="cna-detail-hint">
                        Renovación automática:{' '}
                        <strong>
                          {subscription.is_auto_renew === 1 || subscription.is_auto_renew === '1'
                            ? 'Activa'
                            : 'Desactivada'}
                        </strong>
                      </p>
                      <label
                        className="cna-action-select-label"
                        htmlFor={`cna-action-${subscription.id}`}
                      >
                        Acción:
                      </label>
                      <select
                        id={`cna-action-${subscription.id}`}
                        className="cna-action-select"
                        value={actionSelectValue[subscription.id] || ''}
                        onChange={(event) =>
                          handleActionSelect(subscription.id, event.target.value)
                        }
                        disabled={actionLoading}
                      >
                        <option value="">Seleccionar acción...</option>
                        {availableActions.map((opt) => (
                          <option key={opt.value} value={opt.value}>
                            {opt.label}
                          </option>
                        ))}
                      </select>
                      {!(
                        subscription.has_payment_token === true ||
                        subscription.has_payment_token === 1 ||
                        subscription.has_payment_token === '1'
                      ) &&
                        subscription.status === 'active' &&
                        !(subscription.is_auto_renew === 1 || subscription.is_auto_renew === '1') && (
                          <p className="cna-detail-hint cna-detail-hint--warning">
                            Para activar la auto-renovación necesitas un método de pago guardado
                            (completa un pago con tarjeta en Pagadito).
                          </p>
                        )}
                    </div>
                  )}

                  <div className="cna-detail-section">
                    <h4>Dirección de envío</h4>
                    {shipping.type === 'pickup' ? (
                      <p>Recoger en tienda</p>
                    ) : (
                      <p>
                        {shipping.address && `${shipping.address}, `}
                        {shipping.district && `${shipping.district}, `}
                        {shipping.municipality && `${shipping.municipality}, `}
                        {shipping.department && `${shipping.department}`}
                      </p>
                    )}
                  </div>

                  <div className="cna-detail-section">
                    <h4>Fechas de entrega</h4>
                    {isLoadingDeliveries && (
                      <p className="cna-detail-hint">Cargando entregas...</p>
                    )}
                    {deliveryLoadError && (
                      <p className="cna-error-message cna-error-message--inline">
                        {deliveryLoadError}
                      </p>
                    )}
                    {!isLoadingDeliveries && !deliveryLoadError && deliveries.length === 0 && (
                      <p className="cna-detail-hint">No hay entregas programadas.</p>
                    )}
                    {deliveries.length > 0 && (
                      <ul className="cna-deliveries-list">
                        {deliveries.map((delivery) => {
                          const sd = String(delivery.scheduled_date ?? '');
                          const deliveryDate = new Date(
                            /\d{4}-\d{2}-\d{2} \d/.test(sd)
                              ? sd.replace(' ', 'T')
                              : sd.includes('T')
                                ? sd
                                : `${sd}T12:00:00`
                          );
                          const isPast =
                            !Number.isNaN(deliveryDate.getTime()) && deliveryDate < new Date();
                          const statusLabels: Record<string, string> = {
                            scheduled: 'Programada',
                            delivered: 'Entregada',
                            cancelled: 'Cancelada',
                            pending: 'Pendiente',
                            delivered_home: 'Entregada en domicilio',
                            delivered_to_customer: 'Entregada al cliente',
                            dispatched_to_store: 'Enviada a tienda',
                          };
                          const statusLabel =
                            statusLabels[delivery.delivery_status] || delivery.delivery_status;

                          return (
                            <li
                              key={delivery.id}
                              className={`cna-delivery-item ${isPast ? 'past' : ''}`}
                            >
                              <div className="cna-delivery-info">
                                <div className="cna-delivery-date-row">
                                  <span className="cna-delivery-date">
                                    {formatDate(delivery.scheduled_date)}
                                  </span>
                                  <span
                                    className={`cna-delivery-status cna-status-${delivery.delivery_status}`}
                                  >
                                    {statusLabel}
                                  </span>
                                </div>
                              </div>
                              {toAmount(delivery.amount_to_collect) > 0 && (
                                <span className="cna-delivery-amount">
                                  Cobrar: ${toAmount(delivery.amount_to_collect).toFixed(2)}
                                </span>
                              )}
                            </li>
                          );
                        })}
                      </ul>
                    )}
                  </div>

                  <div className="cna-detail-section">
                    <h4>Información</h4>
                    <p>
                      <strong>ID suscripción:</strong> #{subscription.id}
                    </p>
                    <p>
                      <strong>Creada:</strong> {formatDate(subscription.created_at)}
                    </p>
                    {subscription.updated_at !== subscription.created_at && (
                      <p>
                        <strong>Actualizada:</strong> {formatDate(subscription.updated_at)}
                      </p>
                    )}
                  </div>
                </div>
              )}
            </div>
          );
        })}
      </div>

      <SubscriptionConfirmModal
        open={pendingAction !== null}
        copy={
          pendingAction ? SUBSCRIPTION_ACTION_COPY[pendingAction.action] : null
        }
        loading={actionLoading}
        onConfirm={confirmPendingAction}
        onCancel={closeActionModal}
      />
    </div>
  );
};

export default MyAccountDashboard;
