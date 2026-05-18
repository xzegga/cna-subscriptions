import React, { useState, useEffect } from 'react';
import {
  getUserSubscriptions,
  getSubscriptionDeliveries,
  toggleRenewal,
} from '../../services/api';
import type {
  Subscription,
  Delivery,
} from '../../services/api';
import './MyAccountDashboard.css';

interface MyAccountDashboardProps {
  userId: number;
}

const MyAccountDashboard: React.FC<MyAccountDashboardProps> = ({ userId }) => {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [expandedSubscriptions, setExpandedSubscriptions] = useState<Set<number>>(new Set());
  const [deliveriesCache, setDeliveriesCache] = useState<Record<number, Delivery[]>>({});

  useEffect(() => {
    loadSubscriptions();
  }, [userId]);

  const loadSubscriptions = async () => {
    try {
      setLoading(true);
      setError(null);
      const data = await getUserSubscriptions();
      setSubscriptions(data);
    } catch (err: any) {
      setError(err.message || 'Error al cargar suscripciones');
    } finally {
      setLoading(false);
    }
  };

  const loadDeliveries = async (subscriptionId: number) => {
    if (deliveriesCache[subscriptionId]) {
      return; // Ya están cargadas
    }

    try {
      const deliveries = await getSubscriptionDeliveries(subscriptionId);
      setDeliveriesCache({
        ...deliveriesCache,
        [subscriptionId]: deliveries,
      });
    } catch (err: any) {
      console.error('Error loading deliveries:', err);
    }
  };

  const handleToggleExpand = (subscriptionId: number) => {
    const newExpanded = new Set(expandedSubscriptions);
    if (newExpanded.has(subscriptionId)) {
      newExpanded.delete(subscriptionId);
    } else {
      newExpanded.add(subscriptionId);
      loadDeliveries(subscriptionId);
    }
    setExpandedSubscriptions(newExpanded);
  };

  const handleToggleRenewal = async (subscriptionId: number, currentValue: number | string) => {
    // Normalizar el valor actual (puede venir como 1, '1', 0, '0')
    const current = currentValue === 1 || currentValue === '1' ? 1 : 0;
    const newValue = current === 1 ? 0 : 1;
    
    try {
      await toggleRenewal(subscriptionId, newValue === 1);
      // Actualizar estado local
      setSubscriptions(
        subscriptions.map((sub) =>
          sub.id === subscriptionId
            ? { ...sub, is_auto_renew: newValue }
            : sub
        )
      );
    } catch (err: any) {
      setError(err.message || 'Error al actualizar auto-renovación');
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

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('es-SV', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
      active: 'Activa',
      pending: 'Pendiente',
      cancelled: 'Cancelada',
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

  if (error) {
    return (
      <div className="cna-my-account">
        <div className="cna-error-message">{error}</div>
      </div>
    );
  }

  if (subscriptions.length === 0) {
    return (
      <div className="cna-my-account">
        <h2>Mis Suscripciones</h2>
        <p>No tienes suscripciones activas.</p>
      </div>
    );
  }

  return (
    <div className="cna-my-account">
      <h2>Mis Suscripciones</h2>

      <div className="cna-subscriptions-list">
        {subscriptions.map((subscription) => {
          const variant = parseVariantDetails(subscription.variant_details);
          const shipping = parseShippingAddress(subscription.shipping_address_json);
          const deliveries = deliveriesCache[subscription.id] || [];
          const isExpanded = expandedSubscriptions.has(subscription.id);

          return (
            <div key={subscription.id} className="cna-subscription-card">
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
                  onClick={() => handleToggleExpand(subscription.id)}
                  className="cna-toggle-button"
                >
                  {isExpanded ? 'Ocultar' : 'Ver Detalles'}
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
              </div>

              {/* Detalles expandidos */}
              {isExpanded && (
                <div className="cna-subscription-details">
                  {/* Toggle de Auto-renovación - Solo en detalles */}
                  <div className="cna-detail-section">
                    <label className="cna-toggle-label">
                      <input
                        type="checkbox"
                        checked={subscription.is_auto_renew === 1 || subscription.is_auto_renew === '1'}
                        onChange={() =>
                          handleToggleRenewal(subscription.id, subscription.is_auto_renew)
                        }
                      />
                      <span>Renovación automática</span>
                    </label>
                  </div>

                  <div className="cna-detail-section">
                    <h4>Dirección de Envío</h4>
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

                  {deliveries.length > 0 && (
                    <div className="cna-detail-section">
                      <h4>Fechas de Entrega</h4>
                      <ul className="cna-deliveries-list">
                        {deliveries.map((delivery) => {
                          const deliveryDate = new Date(delivery.scheduled_date);
                          const isPast = deliveryDate < new Date();
                          const statusLabels: Record<string, string> = {
                            'scheduled': 'Programada',
                            'delivered': 'Entregada',
                            'cancelled': 'Cancelada',
                            'pending': 'Pendiente',
                          };
                          const statusLabel = statusLabels[delivery.delivery_status] || delivery.delivery_status;
                          
                          return (
                            <li key={delivery.id} className={`cna-delivery-item ${isPast ? 'past' : ''}`}>
                              <div className="cna-delivery-info">
                                <span className="cna-delivery-date">
                                  {formatDate(delivery.scheduled_date)}
                                </span>
                                <span className={`cna-delivery-status cna-status-${delivery.delivery_status}`}>
                                  {statusLabel}
                                </span>
                              </div>
                              {delivery.amount_to_collect > 0 && (
                                <span className="cna-delivery-amount">
                                  Cobrar: ${delivery.amount_to_collect.toFixed(2)}
                                </span>
                              )}
                            </li>
                          );
                        })}
                      </ul>
                    </div>
                  )}

                  <div className="cna-detail-section">
                    <h4>Información</h4>
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
    </div>
  );
};

export default MyAccountDashboard;
