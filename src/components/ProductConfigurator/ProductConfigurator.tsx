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
  onConfigure?: (config: ConfiguratorState) => void;
}

const ProductConfigurator: React.FC<ProductConfiguratorProps> = ({
  productId,
  productName = '',
  productImage = '',
  variations,
  annualFee = 0,
  frequencies = [],
  onConfigure,
}) => {
  // Si no hay frecuencias, crear una por defecto
  const defaultFrequencies: Frequency[] = frequencies.length > 0 
    ? frequencies 
    : [{ amount: 1, unit: 'weeks', label: 'Cada semana', weeks: 1 }];

  const [config, setConfig] = useState<ConfiguratorState>({
    size: variations.length > 0 ? variations[0].name.toLowerCase() : '',
    qty: 4,
    frequency: defaultFrequencies[0]?.weeks || 1,
    frequency_unit: defaultFrequencies[0]?.unit || 'weeks',
    advance_percent: 100, // Por defecto 100%
  });

  const [calculatedPrice, setCalculatedPrice] = useState(0);

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
    const advanceAmount = productSubtotal * (config.advance_percent / 100);
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
    if (qty >= 4 && qty <= 100) {
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
      };
      sessionStorage.setItem('cna_subscription_config', JSON.stringify(configData));
      
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
    };

    sessionStorage.setItem('cna_subscription_config', JSON.stringify(configData));

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

  return (
    <div className="cna-product-configurator">
      <h3>Configura tu Suscripción</h3>

      {/* Selector de Tamaño */}
      <div className="cna-config-field">
        <label htmlFor="cna-size">
          <strong>Tamaño:</strong>
        </label>
        <select
          id="cna-size"
          value={config.size}
          onChange={handleSizeChange}
          className="cna-select"
        >
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
          <strong>Cantidad de Entregas:</strong>
          <span className="cna-help-text">(Mínimo 4)</span>
        </label>
        <input
          id="cna-qty"
          type="number"
          min="4"
          max="100"
          value={config.qty}
          onChange={handleQtyChange}
          className="cna-input"
        />
        {config.qty < 4 && (
          <span className="cna-error-text">El mínimo es 4 entregas</span>
        )}
      </div>

      {/* Selector de Frecuencia - Mostrar opciones desde configuración del producto */}
      <div className="cna-config-field">
        <label htmlFor="cna-frequency">
          <strong>Frecuencia:</strong>
        </label>
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
        <label htmlFor="cna-advance">
          <strong>Porcentaje de Anticipo:</strong>
        </label>
        <div className="cna-advance-options">
          <label>
            <input
              type="radio"
              name="advance"
              value="100"
              checked={config.advance_percent === 100}
              onChange={() => setConfig({ ...config, advance_percent: 100 })}
            />
            100% (Pago completo por adelantado)
          </label>
          <label>
            <input
              type="radio"
              name="advance"
              value="50"
              checked={config.advance_percent === 50}
              onChange={() => setConfig({ ...config, advance_percent: 50 })}
            />
            50% (Pagarás el resto en cada entrega)
          </label>
        </div>
        {config.advance_percent === 50 && (
          <p className="cna-advance-notice">
            Cuando pagas el 50% del valor de tu suscripción, deberás pagar el 50% de cada canasta recibida dependiendo del tipo de canasta seleccionada.
          </p>
        )}
      </div>

      {/* Resumen de Precio */}
      <div className="cna-price-summary">
        <div className="cna-price-row">
          <span>Precio unitario:</span>
          <strong>${selectedVariation?.price.toFixed(2) || '0.00'}</strong>
        </div>
        <div className="cna-price-row">
          <span>Subtotal producto ({config.qty} unidades):</span>
          <strong>
            ${((selectedVariation?.price || 0) * config.qty).toFixed(2)}
          </strong>
        </div>
        <div className="cna-price-row">
          <span>Anticipo ({config.advance_percent}%):</span>
          <strong>
            ${(((selectedVariation?.price || 0) * config.qty) * (config.advance_percent / 100)).toFixed(2)}
          </strong>
        </div>
        {annualFee > 0 && (
          <div className="cna-price-row">
            <span>Fee Anual:</span>
            <strong>${annualFee.toFixed(2)}</strong>
          </div>
        )}
        <div className="cna-price-row cna-price-total">
          <span>Total a pagar ahora:</span>
          <strong>${calculatedPrice.toFixed(2)}</strong>
        </div>
        <p className="cna-price-note">
          * El costo de envío y fee de tarjeta se calcularán en el checkout
        </p>
      </div>

      {/* Botón de Suscripción */}
      <button
        type="button"
        onClick={handleSubscribe}
        disabled={config.qty < 4 || !selectedVariation}
        className="cna-subscribe-button"
      >
        Suscribirse Ahora
      </button>
    </div>
  );
};

export default ProductConfigurator;
