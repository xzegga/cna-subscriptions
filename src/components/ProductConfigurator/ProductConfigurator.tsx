import React, { useState, useEffect } from 'react';
import './ProductConfigurator.css';

export interface ProductConfig {
  productId: number;
  variations: Array<{
    name: string;
    description: string;
    price: number;
  }>;
  annualFee: number;
}

export interface Frequency {
  amount: number;
  unit: string;
  label: string;
  weeks: number;
}

export interface ConfiguratorState {
  size: string;
  qty: number;
  frequency: number;
  frequency_unit: string;
  advance_percent: number;
}

interface ProductConfiguratorProps {
  productId: number;
  productName?: string;
  productImage?: string;
  variations: Array<{
    name: string;
    description: string;
    price: number;
  }>;
  annualFee?: number;
  frequencies?: Frequency[];
  minQty?: number;
  onConfigure?: (config: ConfiguratorState) => void;
}

const DEFAULT_MIN_QTY = 4;
const MAX_QTY = 100;
const SESSION_KEY = 'cna_subscription_config';

function normalizeAdvancePercent(value: unknown): number {
  return Number(value) === 50 ? 50 : 100;
}

function loadSavedConfiguratorState(
  productId: number
): Partial<ConfiguratorState> | null {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY);
    if (!raw) return null;

    const saved = JSON.parse(raw);
    if (saved?.productId !== productId || !saved?.config) return null;

    return saved.config as Partial<ConfiguratorState>;
  } catch {
    return null;
  }
}

const ProductConfigurator: React.FC<ProductConfiguratorProps> = ({
  productId,
  productName = '',
  productImage = '',
  variations,
  annualFee = 0,
  frequencies = [],
  minQty = DEFAULT_MIN_QTY,
  onConfigure,
}) => {
  const effectiveMinQty = minQty > 0 ? minQty : DEFAULT_MIN_QTY;
  // Si no hay frecuencias, crear una por defecto
  const defaultFrequencies: Frequency[] = frequencies.length > 0 
    ? frequencies 
    : [{ amount: 1, unit: 'weeks', label: 'Cada semana', weeks: 1 }];

  const [config, setConfig] = useState<ConfiguratorState>(() => {
    const saved = loadSavedConfiguratorState(productId);
    const defaultSize =
      variations.length > 0 ? variations[0].name.toLowerCase() : '';
    const savedSize =
      saved?.size &&
      variations.some((v) => v.name.toLowerCase() === saved.size)
        ? saved.size
        : defaultSize;
    const savedQty =
      saved?.qty && saved.qty >= effectiveMinQty && saved.qty <= MAX_QTY
        ? saved.qty
        : effectiveMinQty;
    const savedFrequency = defaultFrequencies.find(
      (f) => f.weeks === saved?.frequency
    );

    return {
      size: savedSize,
      qty: savedQty,
      frequency: savedFrequency?.weeks ?? defaultFrequencies[0]?.weeks ?? 1,
      frequency_unit:
        savedFrequency?.unit ?? defaultFrequencies[0]?.unit ?? 'weeks',
      advance_percent: normalizeAdvancePercent(saved?.advance_percent),
    };
  });

  const [calculatedPrice, setCalculatedPrice] = useState(0);

  // Re-sync when returning via browser back (bfcache) after visiting checkout
  useEffect(() => {
    const handlePageShow = (event: PageTransitionEvent) => {
      if (!event.persisted) return;

      const saved = loadSavedConfiguratorState(productId);
      if (!saved) return;

      setConfig((prev) => ({
        ...prev,
        size:
          saved.size &&
          variations.some((v) => v.name.toLowerCase() === saved.size)
            ? saved.size
            : prev.size,
        qty:
          saved.qty && saved.qty >= effectiveMinQty && saved.qty <= MAX_QTY
            ? saved.qty
            : prev.qty,
        frequency: saved.frequency ?? prev.frequency,
        frequency_unit: saved.frequency_unit ?? prev.frequency_unit,
        advance_percent: normalizeAdvancePercent(
          saved.advance_percent ?? prev.advance_percent
        ),
      }));
    };

    window.addEventListener('pageshow', handlePageShow);
    return () => window.removeEventListener('pageshow', handlePageShow);
  }, [productId, variations, effectiveMinQty, defaultFrequencies]);

  // Obtener precio de la variación seleccionada
  const selectedVariation = variations.find(
    (v) => v.name.toLowerCase() === config.size
  );

  // Calcular precio en tiempo real
  useEffect(() => {
    if (!selectedVariation) {
      setCalculatedPrice(0);
      return;
    }

    const unitPrice = selectedVariation.price;
    const productSubtotal = unitPrice * config.qty;
    const advancePercent = normalizeAdvancePercent(config.advance_percent);
    const advanceAmount = productSubtotal * (advancePercent / 100);
    const total = advanceAmount + annualFee;

    setCalculatedPrice(total);
  }, [config, selectedVariation, annualFee]);

  // Notificar cambios al componente padre
  useEffect(() => {
    if (onConfigure) {
      onConfigure(config);
    }
  }, [config, onConfigure]);

  const handleSizeChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    setConfig({ ...config, size: e.target.value.toLowerCase() });
  };

  const handleQtyChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const qty = parseInt(e.target.value, 10);
    if (qty >= effectiveMinQty && qty <= MAX_QTY) {
      setConfig({ ...config, qty });
    }
  };

  const handleFrequencyChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const selectedWeeks = parseInt(e.target.value, 10);
    const selectedFrequency = defaultFrequencies.find(f => f.weeks === selectedWeeks);
    
    setConfig({ 
      ...config, 
      frequency: selectedWeeks,
      frequency_unit: selectedFrequency?.unit || 'weeks'
    });
  };


  const handleSubscribe = () => {
    // Verificar si el usuario está autenticado
    const userIdElement = document.getElementById('cna-user-id');
    const userId = userIdElement ? parseInt(userIdElement.textContent || '0', 10) : 0;

    if (userId === 0) {
      // Usuario no autenticado - redirigir a página de login interna con return URL
      const checkoutUrl = '/finalizar-suscripcion';
      
      // Guardar configuración en SessionStorage antes de redirigir
      const configData = {
        productId,
        productName,
        productImage,
        config,
        calculatedPrice,
        variation: selectedVariation,
        annualFee,
        minQty: effectiveMinQty,
      };
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(configData));
      
      // Redirigir a página de login interna (asumiendo que existe una página con slug 'iniciar-sesion')
      // Si no existe, crear una página con el shortcode [cna_login]
      const loginUrl = `/iniciar-sesion?redirect_to=${encodeURIComponent(checkoutUrl)}`;
      window.location.href = loginUrl;
      return;
    }

    // Guardar configuración en SessionStorage
    const configData = {
      productId,
      productName,
      productImage,
      config,
      calculatedPrice,
      variation: selectedVariation,
      annualFee,
      minQty: effectiveMinQty,
    };

    sessionStorage.setItem(SESSION_KEY, JSON.stringify(configData));

    // Redirigir al checkout
    window.location.href = '/finalizar-suscripcion';
  };

  if (variations.length === 0) {
    return (
      <div className="cna-product-configurator">
        <p>Este producto no tiene variaciones configuradas.</p>
      </div>
    );
  }

  const unitPrice = selectedVariation?.price ?? 0;
  const subscriptionTotal = unitPrice * config.qty;
  const advancePercent = normalizeAdvancePercent(config.advance_percent);
  const advanceAmount = subscriptionTotal * (advancePercent / 100);
  const perDeliveryOnReceipt =
    advancePercent < 100 ? unitPrice * ((100 - advancePercent) / 100) : 0;

  return (
    <div className="cna-product-configurator">
      {productName ? (
        <h2 className="cna-product-configurator__title">{productName}</h2>
      ) : (
        <h2 className="cna-product-configurator__title">Configura tu Suscripción</h2>
      )}

      {/* Selector de Tamaño */}
      <div className="cna-config-field">
        <label htmlFor="cna-size">Tamaño</label>
        <select
          id="cna-size"
          value={config.size}
          onChange={handleSizeChange}
          className="cna-select"
        >
          <option value="" disabled>
            Elige una opción
          </option>
          {variations.map((variation) => (
            <option key={variation.name} value={variation.name.toLowerCase()}>
              {variation.name} - ${variation.price.toFixed(2)} {variation.description && `(${variation.description})`}
            </option>
          ))}
        </select>
      </div>

      {/* Selector de Cantidad */}
      <div className="cna-config-field">
        <label htmlFor="cna-qty">
          Cantidad de Entregas
          <span className="cna-help-text">(Mínimo {effectiveMinQty})</span>
        </label>
        <input
          id="cna-qty"
          type="number"
          min={effectiveMinQty}
          max={MAX_QTY}
          value={config.qty}
          onChange={handleQtyChange}
          className="cna-input"
        />
        {config.qty < effectiveMinQty && (
          <span className="cna-error-text">El mínimo es {effectiveMinQty} entregas</span>
        )}
      </div>

      {/* Selector de Frecuencia - Mostrar opciones desde configuración del producto */}
      <div className="cna-config-field">
        <label htmlFor="cna-frequency">Frecuencia</label>
        <select
          id="cna-frequency"
          value={config.frequency}
          onChange={handleFrequencyChange}
          className="cna-select"
        >
          {defaultFrequencies.map((freq, index) => (
            <option key={index} value={freq.weeks}>
              {freq.label}
            </option>
          ))}
        </select>
        {defaultFrequencies.length === 0 && (
          <p className="cna-help-text">
            No hay frecuencias configuradas para este producto.
          </p>
        )}
      </div>

      {/* Selector de Anticipo */}
      <div className="cna-config-field">
        <label htmlFor="cna-advance">Porcentaje de Anticipo</label>
        <div className="cna-advance-options">
          <label>
            <input
              type="radio"
              name="advance"
              value="100"
              checked={normalizeAdvancePercent(config.advance_percent) === 100}
              onChange={() => setConfig({ ...config, advance_percent: 100 })}
            />
            100% (Pago completo por adelantado)
          </label>
          <label>
            <input
              type="radio"
              name="advance"
              value="50"
              checked={normalizeAdvancePercent(config.advance_percent) === 50}
              onChange={() => setConfig({ ...config, advance_percent: 50 })}
            />
            50% (Pagarás el resto en cada entrega)
          </label>
        </div>
        {normalizeAdvancePercent(config.advance_percent) === 50 && (
          <p className="cna-advance-notice">
            Cuando pagas el 50% del valor de tu suscripción, deberás pagar el 50% de cada canasta recibida dependiendo del tipo de canasta seleccionada.
          </p>
        )}
      </div>

      {/* Resumen de Precio */}
      {selectedVariation && (
        <div className="cna-price-summary">
          <section
            className="cna-price-summary__section cna-price-summary__info"
            aria-label="Resumen de tu selección"
          >
            <h3 className="cna-price-summary__heading">Tu selección</h3>
            <dl className="cna-price-details">
              <div className="cna-price-details__row">
                <dt>Precio por entrega</dt>
                <dd>${unitPrice.toFixed(2)}</dd>
              </div>
              <div className="cna-price-details__row">
                <dt>Cantidad de entregas</dt>
                <dd>{config.qty}</dd>
              </div>
              <div className="cna-price-details__row cna-price-details__row--highlight">
                <dt>Valor total de la suscripción</dt>
                <dd>${subscriptionTotal.toFixed(2)}</dd>
              </div>
            </dl>
            <p className="cna-price-summary__hint">
              Referencia del valor completo de tu plan. No es el monto que pagas hoy en su totalidad.
            </p>
          </section>

          <section
            className="cna-price-summary__section cna-price-summary__payment"
            aria-label="Pago inicial"
          >
            <h3 className="cna-price-summary__heading">Pago inicial</h3>
            <dl className="cna-price-details cna-price-details--charges">
              <div className="cna-price-details__row">
                <dt>
                  Anticipo ({advancePercent}%)
                  {advancePercent < 100 && (
                    <span className="cna-price-details__sub">
                      {advancePercent}% del valor de la suscripción
                    </span>
                  )}
                </dt>
                <dd>${advanceAmount.toFixed(2)}</dd>
              </div>
              {annualFee > 0 && (
                <div className="cna-price-details__row">
                  <dt>Fee anual</dt>
                  <dd>${annualFee.toFixed(2)}</dd>
                </div>
              )}
            </dl>

            <div
              className="cna-price-summary__total"
              role="group"
              aria-label="Total a pagar ahora"
            >
              <span className="cna-price-summary__total-label">Total a pagar ahora</span>
              <strong className="cna-price-summary__total-value">
                ${calculatedPrice.toFixed(2)}
              </strong>
            </div>

            {advancePercent < 100 && perDeliveryOnReceipt > 0 && (
              <p className="cna-price-summary__later">
                En cada entrega pagarás{' '}
                <strong>${perDeliveryOnReceipt.toFixed(2)}</strong> ({100 - advancePercent}%
                restante por canasta).
              </p>
            )}
          </section>

          <p className="cna-price-note">
            El costo de envío y el fee de tarjeta se calcularán en el checkout.
          </p>
        </div>
      )}

      {/* Botón de Suscripción */}
      <button
        type="button"
        onClick={handleSubscribe}
        disabled={config.qty < effectiveMinQty || !selectedVariation}
        className="cna-subscribe-button"
      >
        Suscríbete
      </button>
    </div>
  );
};

export default ProductConfigurator;
