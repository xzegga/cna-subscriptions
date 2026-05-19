import React from 'react';
import ReactDOM from 'react-dom/client';
import './styles/index.css';
import ProductConfigurator from './components/ProductConfigurator/ProductConfigurator';
import CheckoutWizard from './components/CheckoutWizard/CheckoutWizard';
import MyAccountDashboard from './components/MyAccountDashboard/MyAccountDashboard';
import OrderConfirmation from './components/OrderConfirmation/OrderConfirmation';
import LoginPage from './components/LoginPage/LoginPage';

/**
 * Función helper para montar componentes de React de forma segura
 * Busca un elemento por ID y monta el componente solo si existe
 * 
 * @param id - ID del elemento DOM donde montar el componente
 * @param Component - Componente React a montar
 * @param props - Props para pasar al componente
 */
const mountComponent = (
  id: string,
  Component: React.ComponentType<any>,
  props?: Record<string, any>
) => {
  const rootElement = document.getElementById(id);
  if (rootElement) {
    ReactDOM.createRoot(rootElement).render(
      <React.StrictMode>
        <Component {...props} />
      </React.StrictMode>
    );
  }
};

// Obtener datos del usuario desde WordPress
const getUserId = (): number => {
  const userIdElement = document.getElementById('cna-user-id');
  if (userIdElement) {
    return parseInt(userIdElement.textContent || '0', 10);
  }
  return 0;
};

// Obtener datos del producto desde atributos data
const getProductData = () => {
  const productElement = document.getElementById('cna-product-app');
  if (!productElement) return null;

  const productId = productElement.dataset.productId
    ? parseInt(productElement.dataset.productId, 10)
    : 0;
  const productName = productElement.dataset.productName || '';
  const productImage = productElement.dataset.productImage || '';
  const variationsJson = productElement.dataset.variations || '[]';
  const annualFee = productElement.dataset.annualFee
    ? parseFloat(productElement.dataset.annualFee)
    : 0;
  const frequenciesJson = productElement.dataset.frequencies || '[]';
  const minQty = productElement.dataset.minQty
    ? parseInt(productElement.dataset.minQty, 10)
    : 4;

  try {
    const variations = JSON.parse(variationsJson);
    const frequencies = JSON.parse(frequenciesJson);
    return { productId, productName, productImage, variations, annualFee, frequencies, minQty };
  } catch {
    return null;
  }
};

// 1. Isla del Configurador de Producto
const productData = getProductData();
if (productData && productData.productId > 0) {
  mountComponent('cna-product-app', ProductConfigurator, {
    productId: productData.productId,
    productName: productData.productName,
    productImage: productData.productImage,
    variations: productData.variations,
    annualFee: productData.annualFee,
    frequencies: productData.frequencies || [],
    minQty: productData.minQty,
  });
}

// 2. Isla de Login (página /iniciar-sesion)
const getLoginRedirectTo = (): string => {
  const loginElement = document.getElementById('cna-login-app');
  const fromData = loginElement?.dataset.redirectTo || '';
  if (fromData) {
    return fromData;
  }
  const settings = (window as { wpApiSettings?: { checkoutUrl?: string } }).wpApiSettings;
  return settings?.checkoutUrl || '/finalizar-suscripcion/';
};

if (document.getElementById('cna-login-app')) {
  mountComponent('cna-login-app', LoginPage, {
    redirectTo: getLoginRedirectTo(),
  });
}

// 3. Isla del Wizard de Checkout
const checkoutUserId = getUserId();
if (checkoutUserId > 0) {
  mountComponent('cna-checkout-app', CheckoutWizard, {
    userId: checkoutUserId,
  });
}

// 4. Isla del Dashboard de Mi Cuenta
const getInitialSubscriptionId = (): number => {
  const accountElement = document.getElementById('cna-my-account');
  const fromData = accountElement?.dataset.initialSubscriptionId
    ? parseInt(accountElement.dataset.initialSubscriptionId, 10)
    : 0;
  if (fromData > 0) {
    return fromData;
  }
  const fromUrl = new URLSearchParams(window.location.search).get('subscription_id');
  return fromUrl ? parseInt(fromUrl, 10) : 0;
};

const accountUserId = getUserId();
if (accountUserId > 0) {
  mountComponent('cna-my-account', MyAccountDashboard, {
    userId: accountUserId,
    initialSubscriptionId: getInitialSubscriptionId(),
  });
}

// 5. Isla de Confirmación de Orden
const confirmationUserId = getUserId();
if (confirmationUserId > 0) {
  mountComponent('cna-order-confirmation', OrderConfirmation, {
    userId: confirmationUserId,
  });
}