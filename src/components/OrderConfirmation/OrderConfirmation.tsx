import React, { useState, useEffect } from 'react';
import { getSubscriptionDetails } from '../../services/api';
import './OrderConfirmation.css';

interface OrderConfirmationProps {
  userId?: number;
}

interface SubscriptionDetails {
  id: number;
  product_name: string;
  status: string;
  total_with_fee: number;
  unit_price: number;
  product_subtotal: number;
  shipping_total: number;
  annual_fee: number;
  fee_amount: number;
  variant_details: {
    size: string;
    qty: number;
    frequency: number;
    advance_percent: number;
  };
  shipping_address: any;
  created_at: string;
  next_renewal_date?: string;
}

const OrderConfirmation: React.FC<OrderConfirmationProps> = () => {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [subscription, setSubscription] = useState<SubscriptionDetails | null>(null);

  useEffect(() => {
    // Obtener subscription_id de la URL
    const urlParams = new URLSearchParams(window.location.search);
    const subscriptionId = urlParams.get('subscription_id');
    const statusParam = urlParams.get('status');
    const errorParam = urlParams.get('error');

    // Si hay un error en la URL, mostrar mensaje apropiado
    if (errorParam === 'missing_subscription_id') {
      setError('No se encontró el ID de suscripción en la URL. Por favor, contacta con soporte.');
      setLoading(false);
      return;
    }

    if (errorParam === 'subscription_not_found') {
      setError('No se encontró la suscripción. Por favor, contacta con soporte.');
      setLoading(false);
      return;
    }

    if (!subscriptionId) {
      setError('No se encontró el ID de suscripción en la URL.');
      setLoading(false);
      return;
    }

    if (statusParam === 'cancelled') {
      setError('El pago fue cancelado. Por favor, intenta nuevamente.');
      setLoading(false);
      return;
    }

    const id = parseInt(subscriptionId, 10);

    if (statusParam === 'processing') {
      loadSubscriptionDetails(id);

      const pollInterval = window.setInterval(async () => {
        try {
          const details = await getSubscriptionDetails(id);
          setSubscription(details);
          setError(null);
          if (details.status === 'active') {
            window.clearInterval(pollInterval);
            const url = new URL(window.location.href);
            url.searchParams.delete('status');
            window.history.replaceState({}, '', url.toString());
          }
        } catch {
          // Mantener pantalla de procesamiento mientras el webhook confirma el pago
        }
      }, 3000);

      return () => window.clearInterval(pollInterval);
    }

    loadSubscriptionDetails(id);
  }, []);

  const buildProcessingPlaceholder = (subscriptionId: number): SubscriptionDetails => ({
    id: subscriptionId,
    product_name: 'Suscripción',
    status: 'pending',
    total_with_fee: 0,
    unit_price: 0,
    product_subtotal: 0,
    shipping_total: 0,
    annual_fee: 0,
    fee_amount: 0,
    variant_details: {
      size: '',
      qty: 0,
      frequency: 0,
      advance_percent: 0,
    },
    shipping_address: null,
    created_at: new Date().toISOString(),
  });

  const loadSubscriptionDetails = async (
    subscriptionId: number,
    options: { silent?: boolean } = {}
  ) => {
    try {
      if (!options.silent) {
        setLoading(true);
      }
      setError(null);
      const details = await getSubscriptionDetails(subscriptionId);
      setSubscription(details);

      if (details.status === 'active') {
        const url = new URL(window.location.href);
        url.searchParams.delete('status');
        window.history.replaceState({}, '', url.toString());
      }
    } catch (err: any) {
      console.error('Error loading subscription details:', err);
      const urlParams = new URLSearchParams(window.location.search);
      const statusParam = urlParams.get('status');
      const isProcessingReturn = statusParam === 'processing';

      if (isProcessingReturn) {
        setError(null);
        setSubscription((current) => current ?? buildProcessingPlaceholder(subscriptionId));
      } else {
        setError(err.message || 'Error al cargar los detalles de la suscripción.');
      }
    } finally {
      if (!options.silent) {
        setLoading(false);
      }
    }
  };

  if (loading) {
    return (
      <div className="cna-order-confirmation">
        <div className="cna-loading-state">
          <div className="cna-spinner"></div>
          <p>Cargando confirmación de tu orden...</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="cna-order-confirmation">
        <div className="cna-error-state">
          <div className="cna-error-icon">✕</div>
          <h2>Error</h2>
          <p>{error}</p>
        </div>
      </div>
    );
  }

  if (!subscription) {
    return (
      <div className="cna-order-confirmation">
        <div className="cna-error-state">
          <p>No se encontraron detalles de la suscripción.</p>
        </div>
      </div>
    );
  }

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('es-ES', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  const isSuccess = subscription.status === 'active';
  const urlParams = new URLSearchParams(window.location.search);
  const statusParam = urlParams.get('status');
  const isProcessing = statusParam === 'processing' && !isSuccess;

  return (
    <div className="cna-order-confirmation">
      <div className={`cna-confirmation-header ${isSuccess ? 'success' : isProcessing ? 'processing' : 'pending'}`}>
        <div className="cna-confirmation-icon">
          {isSuccess ? '✓' : isProcessing ? '⏳' : '✓'}
        </div>
        <h1>
          {isSuccess
            ? '¡Gracias por tu compra!'
            : isProcessing
            ? 'Tu pago está siendo procesado'
            : `¡Gracias por tu suscribirte a ${subscription.product_name}`}
        </h1>
        <p className="cna-confirmation-message">
          {isSuccess
            ? 'Tu suscripción ha sido confirmada y está activa. Las entregas comenzarán pronto.'
            : isProcessing
            ? 'Estamos procesando tu pago. Te notificaremos cuando tu suscripción esté activa. Por favor, espera unos momentos...'
            : 'Tu suscripción está siendo procesada. Te notificaremos cuando esté activa.'}
        </p>
      </div>

      <div className="cna-confirmation-details">
        <div className="cna-detail-card">
          <h2>Detalles de la Suscripción</h2>
          <div className="cna-detail-row">
            <span className="cna-detail-label">Número de Orden:</span>
            <span className="cna-detail-value">#{subscription.id}</span>
          </div>
          <div className="cna-detail-row">
            <span className="cna-detail-label">Producto:</span>
            <span className="cna-detail-value">{subscription.product_name}</span>
          </div>
          <div className="cna-detail-row">
            <span className="cna-detail-label">Cantidad:</span>
            <span className="cna-detail-value">
              {subscription.variant_details.qty} unidades
            </span>
          </div>
          <div className="cna-detail-row">
            <span className="cna-detail-label">Frecuencia:</span>
            <span className="cna-detail-value">
              Cada {subscription.variant_details.frequency} semana(s)
            </span>
          </div>
          <div className="cna-detail-row">
            <span className="cna-detail-label">Estado:</span>
            <span className={`cna-status-badge cna-status-${subscription.status}`}>
              {subscription.status === 'active' ? 'Activa' : subscription.status}
            </span>
          </div>
          <div className="cna-detail-row">
            <span className="cna-detail-label">Fecha de Creación:</span>
            <span className="cna-detail-value">{formatDate(subscription.created_at)}</span>
          </div>
          {subscription.next_renewal_date && (
            <div className="cna-detail-row">
              <span className="cna-detail-label">Próxima Renovación:</span>
              <span className="cna-detail-value">
                {formatDate(subscription.next_renewal_date)}
              </span>
            </div>
          )}
        </div>

        <div className="cna-detail-card">
          <h2>Resumen de Pago</h2>
          <div className="cna-summary-row">
            <span>Precio Unitario:</span>
            <span>${subscription.unit_price.toFixed(2)}</span>
          </div>
          <div className="cna-summary-row">
            <span>Subtotal Producto:</span>
            <span>${subscription.product_subtotal.toFixed(2)}</span>
          </div>
          {subscription.shipping_total > 0 && (
            <div className="cna-summary-row">
              <span>Envío ({subscription.variant_details.qty} unidades):</span>
              <span>${subscription.shipping_total.toFixed(2)}</span>
            </div>
          )}
          {subscription.annual_fee > 0 && (
            <div className="cna-summary-row">
              <span>Fee de suscripción anual:</span>
              <span>${subscription.annual_fee.toFixed(2)}</span>
            </div>
          )}
          {subscription.fee_amount > 0 && (
            <div className="cna-summary-row">
              <span>Fee pago con tarjeta:</span>
              <span>${subscription.fee_amount.toFixed(2)}</span>
            </div>
          )}
          <div className="cna-summary-total">
            <span>Total Pagado:</span>
            <span>${subscription.total_with_fee.toFixed(2)}</span>
          </div>
        </div>

        <div className="cna-confirmation-actions">
          <a href="/mi-cuenta" className="cna-button cna-button-primary">
            Ver Mis Suscripciones
          </a>
          <a href="/" className="cna-button cna-button-secondary">
            Volver al Inicio
          </a>
        </div>
      </div>
    </div>
  );
};

export default OrderConfirmation;
