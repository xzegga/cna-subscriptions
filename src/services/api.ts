/**
 * Servicio API para comunicación con endpoints REST de WordPress
 * 
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

const API_BASE = '/wp-json/cna/v1';

export interface ShippingOption {
  type: 'home' | 'pickup';
  label: string;
  cost: number;
  zone_id?: number;
}

export interface PickupStore {
  id: number;
  name: string;
  address: string;
  department?: string;
  municipality?: string;
  district?: string;
  phone?: string;
  hours?: string;
}

export interface ProductVariant {
  size: string;
  qty: number;
  frequency: number;
  advance_percent: number;
}

export interface ShippingAddress {
  country?: string;
  department: string;
  municipality: string;
  district: string;
  address?: string;
  type: 'home' | 'pickup';
  store_id?: number;
}

export interface BillingAddress {
  address_1: string;
  city: string;
  state: string;
  country: string;
  reference?: string;
}

export interface UserMetadata {
  first_name?: string;
  last_name?: string;
  user_email?: string;
  nationality?: string;
  phone?: string;
}

export interface OrderData {
  product_id: number;
  user_id: number;
  variant: ProductVariant;
  shipping: ShippingAddress;
  billing?: BillingAddress;
  user_metadata?: UserMetadata;
  auto_renew?: number;
}

export interface OrderResponse {
  subscription_id: number;
  payment_url: string;
  totals: {
    unit_price: number;
    qty: number;
    product_subtotal: number;
    advance_percent: number;
    advance_amount: number;
    annual_fee: number;
    shipping_unit: number;
    shipping_total: number;
    net_amount: number;
    pasarela_fee: number;
    fee_amount: number;
    total_with_fee: number;
  };
}

export interface Subscription {
  id: number;
  user_id: number;
  product_id: number;
  product_name?: string;
  status: string;
  is_auto_renew: number | string;
  has_payment_token?: boolean | number | string;
  next_renewal_date: string;
  shipping_address_json: string;
  variant_details: string;
  shipping_cost_unit: number;
  created_at: string;
  updated_at: string;
}

export type SubscriptionActionType =
  | 'pause'
  | 'activate'
  | 'cancel'
  | 'enable_auto_renew'
  | 'disable_auto_renew';

export interface SubscriptionActionResponse {
  success: boolean;
  message: string;
  status?: string;
  is_auto_renew?: number;
  subscription?: Subscription;
}

export interface Delivery {
  id: number;
  subscription_id: number;
  scheduled_date: string;
  payment_status: string;
  /** API puede devolver string (decimal MySQL serializado en JSON). */
  amount_to_collect: number | string;
  delivery_status: string;
  delivered_at?: string;
  notes?: string;
}

/**
 * Obtiene las opciones de envío disponibles para un producto y ubicación
 */
export async function getShippingOptions(
  productId: number,
  location?: {
    country?: string;
    department?: string;
    municipality?: string;
    district?: string;
  }
): Promise<{ options: ShippingOption[] }> {
  const params = new URLSearchParams({
    product_id: productId.toString(),
  });

  if (location) {
    if (location.country) params.append('country', location.country);
    if (location.department) params.append('department', location.department);
    if (location.municipality) params.append('municipality', location.municipality);
    if (location.district) params.append('district', location.district);
  }

  const response = await fetch(`${API_BASE}/shipping-options?${params.toString()}`);
  
  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Error al obtener opciones de envío');
  }

  return response.json();
}

/**
 * Obtiene la lista de tiendas de recogida activas
 */
export async function getPickupStores(): Promise<{ stores: PickupStore[] }> {
  const response = await fetch(`${API_BASE}/pickup-stores`);
  
  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Error al obtener tiendas de recogida');
  }

  return response.json();
}

/**
 * Crea una orden y obtiene la URL de pago
 */
export async function createOrder(orderData: OrderData): Promise<OrderResponse> {
  const response = await fetch(`${API_BASE}/create-order`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
    body: JSON.stringify(orderData),
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Error al crear la orden');
  }

  return response.json();
}

/**
 * Obtiene las suscripciones del usuario actual
 */
export async function getUserSubscriptions(): Promise<Subscription[]> {
  const response = await fetch(`${API_BASE}/user/subscriptions`, {
    headers: {
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Error al obtener suscripciones');
  }

  const data = await response.json();
  return data.subscriptions || [];
}

/**
 * Obtiene las entregas de una suscripción
 */
export async function getSubscriptionDeliveries(subscriptionId: number): Promise<Delivery[]> {
  const response = await fetch(`${API_BASE}/subscriptions/${subscriptionId}/deliveries`, {
    headers: {
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Error al obtener entregas');
  }

  const data = await response.json();
  return data.deliveries || [];
}

/**
 * Activa o desactiva la auto-renovación de una suscripción
 */
export async function toggleRenewal(subscriptionId: number, enabled: boolean): Promise<{ success: boolean }> {
  return performSubscriptionAction(
    subscriptionId,
    enabled ? 'enable_auto_renew' : 'disable_auto_renew'
  );
}

/**
 * Ejecuta una acción sobre la suscripción (pausar, reactivar, auto-renovación, etc.)
 */
export async function performSubscriptionAction(
  subscriptionId: number,
  action: SubscriptionActionType
): Promise<SubscriptionActionResponse> {
  const response = await fetch(`${API_BASE}/subscriptions/${subscriptionId}/action`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
    body: JSON.stringify({ action }),
  });

  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.message || 'Error al realizar la acción');
  }

  return data;
}

/**
 * Obtiene los datos geográficos de El Salvador
 */
export async function getLocationData(): Promise<{
  departments: string[];
  municipalities: Record<string, string[]>;
  districts: Record<string, Record<string, string[]>>;
}> {
  const response = await fetch(`${API_BASE}/locations`);
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al obtener datos geográficos');
  }

  return response.json();
}

/**
 * Obtiene los metadatos del usuario actual
 */
export async function getCurrentUserData(): Promise<UserMetadata> {
  const response = await fetch(`${API_BASE}/user/data`, {
    headers: {
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
  });
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al obtener datos del usuario');
  }

  return response.json();
}

/**
 * Obtiene el fee de la pasarela activa
 */
export async function getGatewayFee(): Promise<{ fee: number; fee_percent: number; fee_fixed: number }> {
  const response = await fetch(`${API_BASE}/gateway-fee`);
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al obtener fee de la pasarela');
  }

  return response.json();
}

/**
 * Obtiene los detalles de una suscripción
 */
export async function getSubscriptionDetails(subscriptionId: number): Promise<any> {
  const response = await fetch(`${API_BASE}/subscriptions/${subscriptionId}`, {
    headers: {
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
  });
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al obtener detalles de la suscripción');
  }

  return response.json();
}

export interface UserAddress {
  id: number;
  user_id: number;
  label: string;
  country: string;
  department: string;
  municipality: string;
  district: string;
  address: string;
  is_default: number;
  created_at: string;
  updated_at: string;
}

/**
 * Obtiene las direcciones de entrega del usuario
 */
export async function getUserAddresses(): Promise<{ addresses: UserAddress[] }> {
  const response = await fetch(`${API_BASE}/user/addresses`, {
    headers: {
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
  });
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al obtener direcciones');
  }

  return response.json();
}

/**
 * Guarda una nueva dirección de entrega
 */
export async function saveUserAddress(address: {
  label?: string;
  country?: string;
  department: string;
  municipality: string;
  district: string;
  address: string;
  is_default?: number;
}): Promise<{ success: boolean; address_id: number; message: string }> {
  const response = await fetch(`${API_BASE}/user/addresses`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
    body: JSON.stringify(address),
  });
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al guardar la dirección');
  }

  return response.json();
}

/**
 * Actualiza una dirección de entrega existente
 */
export async function updateUserAddress(addressId: number, address: {
  label?: string;
  country?: string;
  department: string;
  municipality: string;
  district: string;
  address: string;
  is_default?: number;
}): Promise<{ success: boolean; message: string }> {
  const response = await fetch(`${API_BASE}/user/addresses/${addressId}`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
    },
    body: JSON.stringify(address),
  });
  
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || 'Error al actualizar la dirección');
  }

  return response.json();
}
