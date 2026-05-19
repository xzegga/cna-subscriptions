import React, { useState, useEffect, useMemo, useRef } from 'react';
import {
  getShippingOptions,
  getPickupStores,
  createOrder,
  getLocationData,
  getCurrentUserData,
  getGatewayFee,
  getUserAddresses,
  saveUserAddress,
  updateUserAddress,
} from '../../services/api';
import type {
  ShippingOption,
  PickupStore,
  OrderData,
  UserMetadata,
  BillingAddress,
  UserAddress,
} from '../../services/api';
import './CheckoutWizard.css';

const DEFAULT_MIN_QTY = 4;
const CHECKOUT_SUBSCRIPTION_KEY = 'cna_checkout_subscription_id';

function getRetrySubscriptionId(): number | undefined {
  const fromStorage = sessionStorage.getItem(CHECKOUT_SUBSCRIPTION_KEY);
  const fromUrl = new URLSearchParams(window.location.search).get('subscription_id');
  const raw = fromStorage || fromUrl || '';
  const id = parseInt(raw, 10);
  return id > 0 ? id : undefined;
}

function getConfigQty(cfg: Record<string, unknown> | null): number {
  if (!cfg) return DEFAULT_MIN_QTY;
  const nested = cfg.config as { qty?: number } | undefined;
  return nested?.qty ?? (cfg.qty as number | undefined) ?? (cfg.minQty as number | undefined) ?? DEFAULT_MIN_QTY;
}

function getAdvancePercent(cfg: Record<string, unknown> | null): number {
  if (!cfg) return 100;
  const nested = cfg.config as { advance_percent?: number } | undefined;
  const raw = nested?.advance_percent ?? (cfg.advance_percent as number | undefined);
  return Number(raw) === 50 ? 50 : 100;
}

function getAnnualFee(cfg: Record<string, unknown> | null): number {
  if (!cfg) return 0;
  return Number(cfg.annualFee) || 0;
}

function computeCheckoutTotals(
  cfg: Record<string, unknown>,
  shippingCost: number,
  gatewayFeePercent: number,
  gatewayFeeFixedAmount: number
) {
  const qty = getConfigQty(cfg);
  const unitPrice = (cfg.variation as { price?: number } | undefined)?.price || 0;
  const productSubtotal = unitPrice * qty;
  const advancePercent = getAdvancePercent(cfg);
  const advanceAmount = productSubtotal * (advancePercent / 100);
  const annualFee = getAnnualFee(cfg);
  const netAmount = advanceAmount + shippingCost + annualFee;
  const totalWithFee =
    gatewayFeePercent >= 1
      ? netAmount
      : Math.round(((netAmount + gatewayFeeFixedAmount) / (1 - gatewayFeePercent)) * 100) / 100;
  const feeAmount = Math.round((totalWithFee - netAmount) * 100) / 100;

  return {
    unit_price: unitPrice,
    qty,
    product_subtotal: productSubtotal,
    advance_percent: advancePercent,
    advance_amount: advanceAmount,
    shipping_total: shippingCost,
    annual_fee: annualFee,
    net_amount: netAmount,
    fee_amount: feeAmount,
    total_with_fee: totalWithFee,
  };
}

interface LocationData {
  departments: string[];
  municipalities: Record<string, string[]>;
  districts: Record<string, Record<string, string[]>>;
}

interface CheckoutWizardProps {
  userId: number;
}

type AccordionStep = 'personal' | 'delivery_method' | 'shipping' | 'store_selection' | 'billing';

const CheckoutWizard: React.FC<CheckoutWizardProps> = ({ userId }) => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeStep, setActiveStep] = useState<AccordionStep>('personal');

  // Datos de configuración desde SessionStorage
  const [config, setConfig] = useState<any>(null);

  // Datos del usuario (siempre editables, no se guardan hasta pagar)
  const [userData, setUserData] = useState<UserMetadata>({
    first_name: '',
    last_name: '',
    user_email: '',
    nationality: '',
    phone: '',
  });
  const [userDataLoaded, setUserDataLoaded] = useState(false);

  // Datos de ubicación
  const [locationData, setLocationData] = useState<LocationData>({
    departments: [],
    municipalities: {},
    districts: {},
  });

  // Formulario de envío
  const [shipping, setShipping] = useState({
    country: 'El Salvador',
    department: '',
    municipality: '',
    district: '',
    address: '',
    type: undefined as 'home' | 'pickup' | undefined, // No se selecciona automáticamente
    store_id: undefined as number | undefined,
  });

  // Facturación
  const [useSameBillingAddress, setUseSameBillingAddress] = useState(true);
  const [billing, setBilling] = useState<BillingAddress>({
    address_1: '',
    city: '',
    state: '',
    country: '',
    reference: '',
  });

  // Opciones de envío y tiendas
  const [pickupStores, setPickupStores] = useState<PickupStore[]>([]);
  const [selectedShippingOption, setSelectedShippingOption] = useState<ShippingOption | null>(null);

  // Direcciones guardadas del usuario
  const [savedAddresses, setSavedAddresses] = useState<UserAddress[]>([]);
  const [selectedAddressId, setSelectedAddressId] = useState<number | null>(null);
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null);
  const [showNewAddressForm, setShowNewAddressForm] = useState(false);

  // Totales calculados (reactivos)
  const [acceptAutoRenew, setAcceptAutoRenew] = useState(true);
  const [orderTotals, setOrderTotals] = useState<any>(null);
  const [gatewayFee, setGatewayFee] = useState<number>(0.06); // Valor por defecto 6%
  const [gatewayFeeFixed, setGatewayFeeFixed] = useState<number>(0);
  const hasSkippedInitialPersonalStep = useRef(false);

  // Cargar configuración desde SessionStorage
  useEffect(() => {
    const savedConfig = sessionStorage.getItem('cna_subscription_config');
    if (savedConfig) {
      try {
        const parsed = JSON.parse(savedConfig);
        setConfig(parsed);
      } catch (e) {
        setError('Error al cargar la configuración. Por favor, vuelve al producto.');
      }
    } else {
      setError('No se encontró configuración de suscripción. Por favor, vuelve al producto.');
    }
  }, []);

  // Cargar datos del usuario (solo para prellenar, no para hacer readonly)
  useEffect(() => {
    loadUserData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  // Cargar datos geográficos y fee de gateway
  useEffect(() => {
    loadLocationData();
    loadPickupStores();
    loadGatewayFee();
    loadSavedAddresses();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Cargar opciones de envío cuando cambia la ubicación (solo si es domicilio)
  useEffect(() => {
    if (config && shipping.type === 'home' && shipping.district && shipping.department && shipping.municipality) {
      loadShippingOptions();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config, shipping.type, shipping.district, shipping.department, shipping.municipality]);

  // Calcular totales cuando cambian los datos relevantes
  useEffect(() => {
    if (config) {
      calculateTotals();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config, selectedShippingOption, shipping.type, shipping.store_id, gatewayFee, gatewayFeeFixed]);

  const loadUserData = async () => {
    try {
      const data = await getCurrentUserData();
      // Prellenar campos pero mantenerlos editables
      setUserData(data);
      setUserDataLoaded(true);
    } catch (err: any) {
      console.error('Error loading user data:', err);
      setUserDataLoaded(true);
    }
  };

  const loadLocationData = async () => {
    try {
      const data = await getLocationData();
      setLocationData(data);
    } catch (err: any) {
      console.error('Error loading location data:', err);
    }
  };

  const loadPickupStores = async () => {
    try {
      const response = await getPickupStores();
      setPickupStores(response.stores);
    } catch (err: any) {
      console.error('Error loading pickup stores:', err);
    }
  };

  const loadGatewayFee = async () => {
    try {
      const response = await getGatewayFee();
      setGatewayFee(response.fee);
      setGatewayFeeFixed(response.fee_fixed ?? 0);
    } catch (err: any) {
      console.error('Error loading gateway fee:', err);
      // Mantener valor por defecto
    }
  };

  const loadSavedAddresses = async () => {
    try {
      const response = await getUserAddresses();
      setSavedAddresses(response.addresses);
      
      // Si hay una dirección por defecto, cargarla automáticamente
      const defaultAddress = response.addresses.find(addr => addr.is_default === 1);
      if (defaultAddress) {
        handleAddressSelect(defaultAddress.id);
      }
    } catch (err: any) {
      console.error('Error loading saved addresses:', err);
    }
  };

  const handleAddressSelect = (addressId: number | null) => {
    setEditingAddressId(null);
    setShowNewAddressForm(false);
    
    if (addressId === null) {
      // Nueva dirección - limpiar campos
      setShipping({
        ...shipping,
        department: '',
        municipality: '',
        district: '',
        address: '',
      });
      setSelectedAddressId(null);
      setSelectedShippingOption(null);
      return;
    }

    const address = savedAddresses.find(addr => addr.id === addressId);
    if (address) {
      setSelectedAddressId(addressId);
      setShipping({
        ...shipping,
        country: address.country || 'El Salvador',
        department: address.department,
        municipality: address.municipality,
        district: address.district,
        address: address.address,
        type: 'home', // Asegurar que sea domicilio cuando se selecciona una dirección
        store_id: undefined,
      });
      // Los shipping options se cargarán automáticamente cuando cambien department/municipality/district
    }
  };

  const handleEditAddress = (addressId: number) => {
    setEditingAddressId(addressId);
    setShowNewAddressForm(false);
    const address = savedAddresses.find(addr => addr.id === addressId);
    if (address) {
      setShipping({
        country: address.country || 'El Salvador',
        department: address.department,
        municipality: address.municipality,
        district: address.district,
        address: address.address,
        type: 'home',
        store_id: undefined,
      });
      setSelectedAddressId(null);
    }
  };

  const handleSaveAddress = async () => {
    if (!shipping.department || !shipping.municipality || !shipping.district || !shipping.address) {
      setError('Por favor completa todos los campos de la dirección');
      return;
    }

    try {
      setLoading(true);
      const addressData = {
        label: 'Mi dirección',
        country: shipping.country,
        department: shipping.department,
        municipality: shipping.municipality,
        district: shipping.district,
        address: shipping.address,
        is_default: savedAddresses.length === 0 ? 1 : 0,
      };

      if (editingAddressId) {
        await updateUserAddress(editingAddressId, addressData);
      } else {
        await saveUserAddress(addressData);
      }

      // Recargar direcciones
      await loadSavedAddresses();
      setEditingAddressId(null);
      setShowNewAddressForm(false);
      
      // Si era una nueva dirección, seleccionarla
      if (!editingAddressId) {
        const response = await getUserAddresses();
        const newAddresses = response.addresses;
        const latestAddress = newAddresses[0]; // La más reciente
        if (latestAddress) {
          handleAddressSelect(latestAddress.id);
        }
      }
    } catch (err: any) {
      setError(err.message || 'Error al guardar la dirección');
    } finally {
      setLoading(false);
    }
  };

  const handleNewAddress = () => {
    setShowNewAddressForm(true);
    setEditingAddressId(null);
    setSelectedAddressId(null);
    setShipping({
      ...shipping,
      department: '',
      municipality: '',
      district: '',
      address: '',
    });
    setSelectedShippingOption(null);
  };

  const handleDeliveryMethodChange = (method: 'home' | 'pickup') => {
    setShipping({
      ...shipping,
      type: method,
      store_id: method === 'pickup' ? undefined : shipping.store_id,
      // Si cambia a pickup, limpiar datos de domicilio
      department: method === 'pickup' ? '' : shipping.department,
      municipality: method === 'pickup' ? '' : shipping.municipality,
      district: method === 'pickup' ? '' : shipping.district,
      address: method === 'pickup' ? '' : shipping.address,
    });
    setSelectedAddressId(null);
    setEditingAddressId(null);
    setShowNewAddressForm(false);
    setSelectedShippingOption(null);
    
    // Avanzar al siguiente paso automáticamente
    setTimeout(() => {
      if (method === 'home') {
        setActiveStep('shipping');
      } else if (method === 'pickup') {
        setActiveStep('store_selection');
      }
    }, 100);
  };

  const loadShippingOptions = async () => {
    if (!config?.productId || !shipping.department || !shipping.municipality || !shipping.district) return;

    try {
      setLoading(true);
      const response = await getShippingOptions(config.productId, {
        country: shipping.country,
        department: shipping.department,
        municipality: shipping.municipality,
        district: shipping.district,
      });

      // Seleccionar la opción que coincida con el tipo seleccionado
      if (shipping.type === 'home') {
        const homeOption = response.options.find((opt) => opt.type === 'home');
        if (homeOption) {
          setSelectedShippingOption(homeOption);
        }
      } else if (shipping.type === 'pickup') {
        const pickupOption = response.options.find((opt) => opt.type === 'pickup');
        if (pickupOption) {
          setSelectedShippingOption(pickupOption);
        }
      }
    } catch (err: any) {
      setError(err.message || 'Error al cargar opciones de envío');
    } finally {
      setLoading(false);
    }
  };

  const calculateTotals = () => {
    if (!config) return;

    try {
      const qty = getConfigQty(config);
      let shippingCost = 0;
      if (
        shipping.type === 'home' &&
        selectedShippingOption &&
        selectedShippingOption.type === 'home'
      ) {
        shippingCost = (selectedShippingOption.cost || 0) * qty;
      }

      setOrderTotals(
        computeCheckoutTotals(config, shippingCost, gatewayFee, gatewayFeeFixed)
      );
    } catch (err: unknown) {
      console.error('Error calculating totals:', err);
    }
  };

  const handleDepartmentChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const dept = e.target.value;
    setShipping({
      ...shipping,
      department: dept,
      municipality: '',
      district: '',
    });
    setSelectedShippingOption(null);
  };

  const handleMunicipalityChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const muni = e.target.value;
    setShipping({
      ...shipping,
      municipality: muni,
      district: '',
    });
    setSelectedShippingOption(null);
  };

  const handleDistrictChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    setShipping({
      ...shipping,
      district: e.target.value,
    });
  };


  const handleUserFieldChange = (field: keyof UserMetadata, value: string) => {
    setUserData({
      ...userData,
      [field]: value,
    });
  };

  const handleBillingChange = (field: keyof BillingAddress, value: string) => {
    setBilling({
      ...billing,
      [field]: value,
    });
  };

  const handleUseSameBillingChange = (checked: boolean) => {
    setUseSameBillingAddress(checked);
    if (checked) {
      // Duplicar shipping en billing
      setBilling({
        address_1: shipping.address,
        city: shipping.municipality,
        state: shipping.department,
        country: shipping.country || 'El Salvador',
        reference: '',
      });
    } else {
      // Limpiar billing si se desactiva
      setBilling({
        address_1: '',
        city: '',
        state: '',
        country: '',
        reference: '',
      });
    }
  };

  // Sincronizar billing cuando cambia shipping y está marcado "usar misma"
  useEffect(() => {
    if (useSameBillingAddress) {
      setBilling({
        address_1: shipping.address,
        city: shipping.municipality,
        state: shipping.department,
        country: shipping.country || 'El Salvador',
        reference: '',
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [useSameBillingAddress, shipping.address, shipping.municipality, shipping.department, shipping.country]);

  // Validaciones por paso
  const isPersonalStepValid = useMemo(() => {
    return (
      userData.first_name && userData.first_name.trim() !== '' &&
      userData.last_name && userData.last_name.trim() !== '' &&
      userData.user_email && userData.user_email.trim() !== '' &&
      userData.nationality && userData.nationality.trim() !== '' &&
      userData.phone && userData.phone.trim() !== ''
    );
  }, [userData]);

  // Solo al cargar el perfil: si ya venía completo, saltar al método de entrega (no al editar)
  useEffect(() => {
    if (!userDataLoaded || hasSkippedInitialPersonalStep.current) {
      return;
    }
    hasSkippedInitialPersonalStep.current = true;

    const wasCompleteOnLoad =
      Boolean(userData.first_name?.trim()) &&
      Boolean(userData.last_name?.trim()) &&
      Boolean(userData.user_email?.trim()) &&
      Boolean(userData.nationality?.trim()) &&
      Boolean(userData.phone?.trim());

    if (wasCompleteOnLoad) {
      setActiveStep('delivery_method');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- evaluate once when profile loads
  }, [userDataLoaded]);

  // Validación: Paso 2 - Método de entrega
  const isDeliveryMethodValid = useMemo(() => {
    return shipping.type === 'home' || shipping.type === 'pickup';
  }, [shipping.type]);

  const hasCompleteShippingAddress = useMemo(() => {
    return (
      !!shipping.department &&
      !!shipping.municipality &&
      !!shipping.district &&
      shipping.address.trim() !== ''
    );
  }, [shipping.department, shipping.municipality, shipping.district, shipping.address]);

  // Validación: Paso 3 - Direcciones de envío (si es domicilio)
  const isShippingStepValid = useMemo(() => {
    if (shipping.type !== 'home') return true;

    if (selectedAddressId) {
      return true;
    }

    // Dirección ingresada manualmente (sin direcciones guardadas, nueva o en edición)
    if (editingAddressId !== null || showNewAddressForm || savedAddresses.length === 0) {
      return hasCompleteShippingAddress;
    }

    return false;
  }, [
    shipping.type,
    selectedAddressId,
    editingAddressId,
    showNewAddressForm,
    savedAddresses.length,
    hasCompleteShippingAddress,
  ]);

  // Validación: Paso 3 - Selección de tienda (si es pickup)
  const isStoreSelectionValid = useMemo(() => {
    if (shipping.type !== 'pickup') return true; // No aplica si no es pickup
    return !!shipping.store_id;
  }, [shipping.type, shipping.store_id]);

  const isBillingStepValid = useMemo(() => {
    if (useSameBillingAddress) {
      return true; // Si usa misma dirección, no necesita validar billing
    }
    return (
      billing.address_1.trim() !== '' &&
      billing.city.trim() !== '' &&
      billing.state.trim() !== '' &&
      billing.country.trim() !== ''
    );
  }, [billing, useSameBillingAddress]);

  const isFormValid = useMemo(() => {
    return (
      isPersonalStepValid &&
      isDeliveryMethodValid &&
      (shipping.type === 'home' ? isShippingStepValid : isStoreSelectionValid) &&
      isBillingStepValid
    );
  }, [
    isPersonalStepValid,
    isDeliveryMethodValid,
    isShippingStepValid,
    isStoreSelectionValid,
    isBillingStepValid,
    shipping.type,
  ]);

  // Función para ir al siguiente paso
  const goToNextStep = () => {
    if (activeStep === 'personal' && isPersonalStepValid) {
      setActiveStep('delivery_method');
    } else if (activeStep === 'delivery_method' && isDeliveryMethodValid) {
      if (shipping.type === 'home') {
        setActiveStep('shipping');
      } else if (shipping.type === 'pickup') {
        setActiveStep('store_selection');
      }
    } else if (activeStep === 'shipping' && isShippingStepValid && shipping.type === 'home') {
      if (!useSameBillingAddress) {
        setActiveStep('billing');
      }
    } else if (activeStep === 'store_selection' && isStoreSelectionValid && shipping.type === 'pickup') {
      if (!useSameBillingAddress) {
        setActiveStep('billing');
      }
    }
  };

  // Determinar si el botón "Siguiente" debe estar habilitado según el paso actual
  const isNextButtonEnabled = () => {
    if (activeStep === 'personal') {
      return isPersonalStepValid;
    } else if (activeStep === 'delivery_method') {
      return isDeliveryMethodValid;
    } else if (activeStep === 'shipping') {
      return isShippingStepValid && shipping.type === 'home';
    } else if (activeStep === 'store_selection') {
      return isStoreSelectionValid && shipping.type === 'pickup';
    } else if (activeStep === 'billing') {
      return isBillingStepValid;
    }
    return false;
  };

  // Determinar si hay un siguiente paso disponible
  const hasNextStep = () => {
    if (activeStep === 'personal') {
      return true; // Siempre hay delivery_method después
    } else if (activeStep === 'delivery_method') {
      return true; // Siempre hay shipping o store_selection después
    } else if (activeStep === 'shipping' || activeStep === 'store_selection') {
      return !useSameBillingAddress; // Solo si no usa misma dirección
    } else if (activeStep === 'billing') {
      return false; // Es el último paso
    }
    return false;
  };

  const handleCreateOrder = async () => {
    if (!config || !userId || !isFormValid) {
      setError('Por favor completa todos los campos requeridos');
      return;
    }

    try {
      setLoading(true);
      setError(null);

      // Preparar datos de facturación
      let finalBilling: BillingAddress | undefined = undefined;
      if (!useSameBillingAddress) {
        finalBilling = billing;
      } else if (shipping.type === 'home') {
        // Duplicar shipping como billing
        finalBilling = {
          address_1: shipping.address,
          city: shipping.municipality,
          state: shipping.department,
          country: shipping.country || 'El Salvador',
          reference: '',
        };
      }

      // Preparar user_metadata: enviar todos los campos que tengan valor
      // El backend actualizará los valores (incluso si ya existen, para permitir edición)
      const userMetadata: UserMetadata = {};
      if (userData.first_name && userData.first_name.trim() !== '') {
        userMetadata.first_name = userData.first_name.trim();
      }
      if (userData.last_name && userData.last_name.trim() !== '') {
        userMetadata.last_name = userData.last_name.trim();
      }
      if (userData.user_email && userData.user_email.trim() !== '') {
        userMetadata.user_email = userData.user_email.trim();
      }
      if (userData.nationality && userData.nationality.trim() !== '') {
        userMetadata.nationality = userData.nationality.trim();
      }
      if (userData.phone && userData.phone.trim() !== '') {
        userMetadata.phone = userData.phone.trim();
      }

      const orderData: OrderData = {
        product_id: config.productId,
        user_id: userId,
        variant: {
          size: config.variation?.name?.toLowerCase() || '',
          qty: getConfigQty(config),
          frequency: config.config?.frequency || config.frequency || 1,
          advance_percent: config.config?.advance_percent || config.advance_percent || 100,
        },
        shipping: {
          country: shipping.country,
          department: shipping.department,
          municipality: shipping.municipality,
          district: shipping.district,
          address: shipping.address,
          type: shipping.type || 'home',
          store_id: shipping.store_id,
        },
        billing: finalBilling,
        user_metadata: Object.keys(userMetadata).length > 0 ? userMetadata : undefined,
        auto_renew: acceptAutoRenew ? 1 : 0,
        retry_subscription_id: getRetrySubscriptionId(),
      };

      const response = await createOrder(orderData);

      if (response.subscription_id) {
        sessionStorage.setItem(CHECKOUT_SUBSCRIPTION_KEY, String(response.subscription_id));
      }

      // Actualizar totales con los datos reales del backend
      if (response.totals) {
        setOrderTotals(response.totals);
      }

      // Redirigir a Pagadito
      if (response.payment_url) {
        window.location.href = response.payment_url;
      } else {
        const errorMsg = 'No se pudo obtener la URL de pago';
        console.error('CNA Checkout Error:', errorMsg, response);
        setError(errorMsg);
      }
    } catch (err: any) {
      const errorMsg = err.message || 'Error al crear la orden';
      console.error('CNA Checkout Exception:', errorMsg, err);
      
      if (err.response) {
        console.error('CNA Checkout Error Response:', err.response);
        if (err.response.data) {
          console.error('CNA Checkout Error Data:', err.response.data);
        }
      }
      
      setError(errorMsg);
    } finally {
      setLoading(false);
    }
  };

  const toggleStep = (step: AccordionStep) => {
    // No permitir abrir pasos si no se ha completado el paso anterior
    if (step === 'delivery_method' && !isPersonalStepValid) {
      return;
    }
    if (step === 'shipping' && shipping.type !== 'home') {
      return;
    }
    if (step === 'store_selection' && shipping.type !== 'pickup') {
      return;
    }
    if (step === 'billing' && (!isDeliveryMethodValid || (shipping.type === 'home' && !isShippingStepValid) || (shipping.type === 'pickup' && !isStoreSelectionValid))) {
      return;
    }
    setActiveStep(activeStep === step ? 'personal' : step);
  };

  if (error) {
    return (
      <div className="cna-checkout-wizard">
        <div className="cna-error-message" role="alert">
          {error}
        </div>
      </div>
    );
  }

  if (!config || !userDataLoaded) {
    return (
      <div className="cna-checkout-wizard">
        <div className="cna-checkout-loading" role="status" aria-live="polite">
          <div className="cna-checkout-loading__spinner" aria-hidden="true" />
          <p className="cna-checkout-loading__title">Preparando tu suscripción</p>
          <p className="cna-checkout-loading__text">
            Estamos cargando los datos de tu pedido. Esto solo tomará un momento.
          </p>
        </div>
      </div>
    );
  }

  // Obtener nombre de la tienda seleccionada
  const selectedStore = pickupStores.find(s => s.id === shipping.store_id);

  // Función para formatear horarios de la tienda (retorna array de objetos para renderizar)
  const formatStoreHours = (hoursJson: string): Array<{day: string, text: string}> => {
    try {
      const hours = typeof hoursJson === 'string' ? JSON.parse(hoursJson) : hoursJson;
      
      if (!hours || typeof hours !== 'object') {
        return [];
      }

      const daysLabels: Record<string, string> = {
        'monday': 'Lun',
        'tuesday': 'Mar',
        'wednesday': 'Mié',
        'thursday': 'Jue',
        'friday': 'Vie',
        'saturday': 'Sáb',
        'sunday': 'Dom',
      };

      const formatted: Array<{day: string, text: string}> = [];
      
      // Mantener orden de días de la semana
      const dayOrder = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
      
      dayOrder.forEach((day) => {
        if (!hours[day]) return;
        
        const schedule = hours[day];
        if (schedule && typeof schedule === 'object') {
          if (schedule.closed === true || schedule.closed === '1' || schedule.closed === 1) {
            formatted.push({ day: daysLabels[day], text: 'Cerrado' });
          } else if (schedule.open && schedule.close) {
            // Formatear horas de 24h a 12h con AM/PM
            const formatTime = (time: string): string => {
              const [hours, minutes] = time.split(':');
              const hour = parseInt(hours, 10);
              const ampm = hour >= 12 ? 'PM' : 'AM';
              const hour12 = hour % 12 || 12;
              return `${hour12}:${minutes} ${ampm}`;
            };
            
            formatted.push({
              day: daysLabels[day],
              text: `${formatTime(schedule.open)} - ${formatTime(schedule.close)}`
            });
          }
        }
      });

      return formatted;
    } catch (e) {
      console.error('Error formatting store hours:', e);
      return [];
    }
  };

  return (
    <div className="cna-checkout-wizard cna-single-page-checkout">
      {error && (
        <div className="cna-error-message">{error}</div>
      )}

      <div className="cna-checkout-layout">
        {/* Área A: Formulario con Accordion */}
        <div className="cna-checkout-form">
          {/* Resumen del Producto */}
          <div className="cna-product-summary-header">
            <div className="cna-product-summary-image">
              {config.productImage ? (
                <img src={config.productImage} alt={config.productName || 'Producto'} />
              ) : (
                <div className="cna-product-placeholder">Imagen no disponible</div>
              )}
            </div>
            <div className="cna-product-summary-info">
              <h3 className="cna-product-title">
                {config.productName || 'Producto'} - {config.variation?.name || ''} {config.variation?.weight || ''}
              </h3>
              <div className="cna-product-price">
                ${(
                  orderTotals?.advance_amount ??
                  (config.variation?.price || 0) *
                    getConfigQty(config) *
                    (getAdvancePercent(config) / 100)
                ).toFixed(2)}
              </div>
              <div className="cna-product-details">
                <div className="cna-product-detail-row">
                  <span className="cna-detail-label">Cantidad:</span>
                  <span className="cna-detail-value">{getConfigQty(config)}</span>
                </div>
                <div className="cna-product-detail-row">
                  <span className="cna-detail-label">Recibir cada:</span>
                  <span className="cna-detail-value">{config.frequencyLabel || 'Cada semana'}</span>
                </div>
                <div className="cna-product-detail-row">
                  <span className="cna-detail-label">Anticipo:</span>
                  <span className="cna-detail-value">
                    {config.config?.advance_percent || config.advance_percent || 100}% Anticipado
                  </span>
                </div>
              </div>
            </div>
          </div>

          {/* Paso 1: Datos Personales */}
          <div className={`cna-accordion-step ${activeStep === 'personal' ? 'active' : ''}`}>
            <div 
              className="cna-accordion-header"
              onClick={() => toggleStep('personal')}
            >
              <div className="cna-step-title">
                <span className="cna-step-number">1</span>
                <span className="cna-step-label">Datos Personales</span>
              </div>
              <div className="cna-step-status">
                {isPersonalStepValid ? (
                  <span className="cna-step-complete">✓ Completado</span>
                ) : (
                  <span className="cna-step-incomplete">Pendiente</span>
                )}
              </div>
            </div>
            {activeStep === 'personal' && (
              <div className="cna-accordion-content">
                <div className="cna-form-section">
                  <div className="cna-form-group">
                    <label>Nombres *</label>
                    <input
                      type="text"
                      value={userData.first_name}
                      onChange={(e) => handleUserFieldChange('first_name', e.target.value)}
                      className="cna-input"
                      required
                      placeholder="Ingresa tus nombres"
                    />
                  </div>

                  <div className="cna-form-group">
                    <label>Apellidos *</label>
                    <input
                      type="text"
                      value={userData.last_name}
                      onChange={(e) => handleUserFieldChange('last_name', e.target.value)}
                      className="cna-input"
                      required
                      placeholder="Ingresa tus apellidos"
                    />
                  </div>

                  <div className="cna-form-group">
                    <label>Correo Electrónico *</label>
                    <input
                      type="email"
                      value={userData.user_email}
                      onChange={(e) => handleUserFieldChange('user_email', e.target.value)}
                      className="cna-input"
                      required
                      placeholder="tu@email.com"
                    />
                  </div>

                  <div className="cna-form-group">
                    <label>Nacionalidad *</label>
                    <input
                      type="text"
                      value={userData.nationality}
                      onChange={(e) => handleUserFieldChange('nationality', e.target.value)}
                      className="cna-input"
                      required
                      placeholder="Ej: Salvadoreño"
                    />
                  </div>

                  <div className="cna-form-group">
                    <label>Teléfono *</label>
                    <input
                      type="tel"
                      value={userData.phone || ''}
                      onChange={(e) => handleUserFieldChange('phone', e.target.value)}
                      className="cna-input"
                      required
                      placeholder="Ej: 7000-0000"
                    />
                  </div>
                </div>
                {hasNextStep() && (
                  <div className="cna-step-actions">
                    <button
                      type="button"
                      onClick={goToNextStep}
                      disabled={!isNextButtonEnabled()}
                      className="cna-button cna-button-primary"
                    >
                      Siguiente
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Paso 2: Método de Entrega */}
          <div className={`cna-accordion-step ${activeStep === 'delivery_method' ? 'active' : ''}`}>
            <div 
              className="cna-accordion-header"
              onClick={() => toggleStep('delivery_method')}
            >
              <div className="cna-step-title">
                <span className="cna-step-number">2</span>
                <span className="cna-step-label">Método de Entrega</span>
              </div>
              <div className="cna-step-status">
                {isDeliveryMethodValid ? (
                  <span className="cna-step-complete">✓ Completado</span>
                ) : (
                  <span className="cna-step-incomplete">Pendiente</span>
                )}
              </div>
            </div>
            {activeStep === 'delivery_method' && (
              <div className="cna-accordion-content">
                <div className="cna-form-section">
                  <div className="cna-delivery-method-options">
                    <label 
                      className={`cna-delivery-method-option ${shipping.type === 'home' ? 'selected' : ''}`}
                      onClick={() => handleDeliveryMethodChange('home')}
                    >
                      <input
                        type="radio"
                        name="delivery-method"
                        value="home"
                        checked={shipping.type === 'home'}
                        onChange={() => handleDeliveryMethodChange('home')}
                      />
                      <div className="cna-delivery-method-content">
                        <strong>Entrega a Domicilio</strong>
                        <span className="cna-delivery-method-description">
                          Recibe tu pedido en la dirección que indiques
                        </span>
                      </div>
                    </label>

                    <label 
                      className={`cna-delivery-method-option ${shipping.type === 'pickup' ? 'selected' : ''}`}
                      onClick={() => handleDeliveryMethodChange('pickup')}
                    >
                      <input
                        type="radio"
                        name="delivery-method"
                        value="pickup"
                        checked={shipping.type === 'pickup'}
                        onChange={() => handleDeliveryMethodChange('pickup')}
                      />
                      <div className="cna-delivery-method-content">
                        <strong>Recoger en Tienda</strong>
                        <span className="cna-delivery-method-description">
                          Retira tu pedido en una de nuestras tiendas
                        </span>
                      </div>
                    </label>
                  </div>
                </div>
                {hasNextStep() && (
                  <div className="cna-step-actions">
                    <button
                      type="button"
                      onClick={goToNextStep}
                      disabled={!isNextButtonEnabled()}
                      className="cna-button cna-button-primary"
                    >
                      Siguiente
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Paso 3a: Direcciones de Envío (solo si es domicilio) */}
          {shipping.type === 'home' && (
            <div className={`cna-accordion-step ${activeStep === 'shipping' ? 'active' : ''}`}>
              <div 
                className="cna-accordion-header"
                onClick={() => toggleStep('shipping')}
              >
                <div className="cna-step-title">
                  <span className="cna-step-number">3</span>
                  <span className="cna-step-label">Dirección de Entrega</span>
                </div>
                <div className="cna-step-status">
                  {isShippingStepValid ? (
                    <span className="cna-step-complete">✓ Completado</span>
                  ) : (
                    <span className="cna-step-incomplete">Pendiente</span>
                  )}
                </div>
              </div>
              {activeStep === 'shipping' && (
                <div className="cna-accordion-content">
                  <div className="cna-form-section">
                    {/* Lista de direcciones guardadas */}
                    {savedAddresses.length > 0 && !editingAddressId && !showNewAddressForm && (
                      <div className="cna-addresses-list">
                        <h4>Direcciones de entrega ({savedAddresses.length})</h4>
                        {savedAddresses.map((addr) => (
                          <div key={addr.id} className={`cna-address-item ${selectedAddressId === addr.id ? 'selected' : ''}`}>
                            <label className="cna-address-radio">
                              <input
                                type="radio"
                                name="shipping-address"
                                value={addr.id}
                                checked={selectedAddressId === addr.id}
                                onChange={() => handleAddressSelect(addr.id)}
                              />
                              <div className="cna-address-content">
                                <div className="cna-address-header">
                                  <strong>{addr.label}</strong>
                                  {addr.is_default === 1 && (
                                    <span className="cna-address-default">Por defecto</span>
                                  )}
                                </div>
                                <div className="cna-address-details">
                                  {addr.address}, {addr.district}, {addr.municipality}, {addr.department}, {addr.country}
                                </div>
                                <div className="cna-address-actions">
                                  <button
                                    type="button"
                                    className="cna-link-button"
                                    onClick={(e) => {
                                      e.preventDefault();
                                      handleEditAddress(addr.id);
                                    }}
                                  >
                                    Editar dirección
                                  </button>
                                </div>
                              </div>
                            </label>
                          </div>
                        ))}
                        <button
                          type="button"
                          className="cna-add-address-button"
                          onClick={handleNewAddress}
                        >
                          + Agregar nueva dirección
                        </button>
                      </div>
                    )}

                    {/* Formulario de dirección (edición o nueva) */}
                    {(editingAddressId !== null || showNewAddressForm || savedAddresses.length === 0) && (
                      <>
                        {editingAddressId !== null && (
                          <div className="cna-form-group">
                            <h4>Editar dirección</h4>
                          </div>
                        )}
                        {showNewAddressForm && (
                          <div className="cna-form-group">
                            <h4>Nueva dirección</h4>
                          </div>
                        )}

                        {/* Leyenda de ubicaciones disponibles */}
                        {locationData.departments.length > 0 && (
                          <div className="cna-location-legend">
                            <strong>Ubicaciones disponibles para envío:</strong>
                            <span>
                              {locationData.departments.length} departamento(s) configurado(s)
                              {locationData.departments.length > 0 && (
                                <>: {locationData.departments.join(', ')}</>
                              )}
                            </span>
                          </div>
                        )}

                        <div className="cna-form-group">
                          <label>País</label>
                          <select value={shipping.country} disabled className="cna-select">
                            <option>El Salvador</option>
                          </select>
                        </div>

                        <div className="cna-form-group">
                          <label>Departamento *</label>
                          <select
                            value={shipping.department}
                            onChange={handleDepartmentChange}
                            className="cna-select"
                            required
                          >
                            <option value="">Seleccionar...</option>
                            {locationData.departments.map((dept) => (
                              <option key={dept} value={dept}>
                                {dept}
                              </option>
                            ))}
                          </select>
                        </div>

                        <div className="cna-form-group">
                          <label>Municipio *</label>
                          <select
                            value={shipping.municipality}
                            onChange={handleMunicipalityChange}
                            className="cna-select"
                            required
                            disabled={!shipping.department}
                          >
                            <option value="">
                              {shipping.department ? 'Seleccionar...' : 'Primero selecciona un departamento'}
                            </option>
                            {(locationData.municipalities[shipping.department] || []).map((muni) => (
                              <option key={muni} value={muni}>
                                {muni}
                              </option>
                            ))}
                          </select>
                        </div>

                        <div className="cna-form-group">
                          <label>Distrito *</label>
                          <select
                            value={shipping.district}
                            onChange={handleDistrictChange}
                            className="cna-select"
                            required
                            disabled={!shipping.municipality}
                          >
                            <option value="">
                              {shipping.municipality ? 'Seleccionar...' : 'Primero selecciona un municipio'}
                            </option>
                            {(locationData.districts[shipping.department]?.[shipping.municipality] || []).map((dist) => (
                              <option key={dist} value={dist}>
                                {dist}
                              </option>
                            ))}
                          </select>
                        </div>

                        {/* Campo de dirección */}
                        {shipping.district && (
                          <div className="cna-form-group">
                            <label>Dirección completa *</label>
                            <textarea
                              value={shipping.address}
                              onChange={(e) => setShipping({ ...shipping, address: e.target.value })}
                              className="cna-textarea"
                              rows={3}
                              required
                              placeholder="Calle, número, colonia, referencia..."
                            />
                          </div>
                        )}

                        {/* Botones de guardar/cancelar al editar o agregar */}
                        {(editingAddressId !== null || showNewAddressForm || savedAddresses.length === 0) && (
                          <div className="cna-form-group cna-address-form-actions">
                            <button
                              type="button"
                              className="cna-button cna-button-primary"
                              onClick={handleSaveAddress}
                              disabled={loading || !hasCompleteShippingAddress}
                            >
                              {loading ? 'Guardando...' : 'Guardar dirección'}
                            </button>
                            {savedAddresses.length > 0 && (
                              <button
                                type="button"
                                className="cna-button cna-button-secondary"
                                onClick={() => {
                                  setEditingAddressId(null);
                                  setShowNewAddressForm(false);
                                  const defaultAddress = savedAddresses.find(addr => addr.is_default === 1);
                                  if (defaultAddress) {
                                    handleAddressSelect(defaultAddress.id);
                                  } else {
                                    handleAddressSelect(savedAddresses[0].id);
                                  }
                                }}
                              >
                                Cancelar
                              </button>
                            )}
                          </div>
                        )}
                      </>
                    )}

                    {/* Checkbox de usar misma dirección de facturación */}
                    {isShippingStepValid && !editingAddressId && !showNewAddressForm && (
                      <div className="cna-form-group" style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid #eee' }}>
                        <label className="cna-checkbox-label">
                          <input
                            type="checkbox"
                            checked={useSameBillingAddress}
                            onChange={(e) => {
                              handleUseSameBillingChange(e.target.checked);
                            }}
                          />
                          <span>Usar la misma dirección de envío para la facturación</span>
                        </label>
                      </div>
                    )}
                    {hasNextStep() && (
                      <div className="cna-step-actions">
                        <button
                          type="button"
                          onClick={goToNextStep}
                          disabled={!isNextButtonEnabled()}
                          className="cna-button cna-button-primary"
                        >
                          Siguiente
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Paso 3b: Selección de Tienda (solo si es pickup) */}
          {shipping.type === 'pickup' && (
            <div className={`cna-accordion-step ${activeStep === 'store_selection' ? 'active' : ''}`}>
              <div 
                className="cna-accordion-header"
                onClick={() => toggleStep('store_selection')}
              >
                <div className="cna-step-title">
                  <span className="cna-step-number">3</span>
                  <span className="cna-step-label">Seleccionar Tienda</span>
                </div>
                <div className="cna-step-status">
                  {isStoreSelectionValid ? (
                    <span className="cna-step-complete">✓ Completado</span>
                  ) : (
                    <span className="cna-step-incomplete">Pendiente</span>
                  )}
                </div>
              </div>
              {activeStep === 'store_selection' && (
                <div className="cna-accordion-content">
                  <div className="cna-form-section">
                    {pickupStores.length > 0 ? (
                      <div className="cna-stores-list">
                        <h4>Tiendas disponibles ({pickupStores.length})</h4>
                        {pickupStores.map((store) => (
                          <div key={store.id} className={`cna-store-item ${shipping.store_id === store.id ? 'selected' : ''}`}>
                            <label className="cna-store-radio">
                              <input
                                type="radio"
                                name="pickup-store"
                                value={store.id}
                                checked={shipping.store_id === store.id}
                                onChange={() => setShipping({ ...shipping, store_id: store.id })}
                              />
                              <div className="cna-store-content">
                                <div className="cna-store-header">
                                  <strong>{store.name}</strong>
                                </div>
                                <div className="cna-store-details">
                                  {store.address}
                                  {store.department && store.municipality && (
                                    <span>, {store.municipality}, {store.department}</span>
                                  )}
                                </div>
                                {store.phone && (
                                  <div className="cna-store-phone">
                                    <span className="cna-icon cna-icon-phone">📞</span> {store.phone}
                                  </div>
                                )}
                                {store.hours && (
                                  <div className="cna-store-hours">
                                    <span className="cna-icon cna-icon-hours">⏰</span>
                                    <div className="cna-store-hours-list">
                                      {formatStoreHours(store.hours).map((schedule, index) => (
                                        <div key={index} className="cna-store-hour-item">
                                          <span className="cna-hour-day">{schedule.day}:</span> {schedule.text}
                                        </div>
                                      ))}
                                    </div>
                                  </div>
                                )}
                              </div>
                            </label>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p>No hay tiendas disponibles para recogida.</p>
                    )}
                    {hasNextStep() && (
                      <div className="cna-step-actions">
                        <button
                          type="button"
                          onClick={goToNextStep}
                          disabled={!isNextButtonEnabled()}
                          className="cna-button cna-button-primary"
                        >
                          Siguiente
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Paso 4: Facturación - Solo visible si no se usa la misma dirección */}
          {!useSameBillingAddress && (
            <div className={`cna-accordion-step ${activeStep === 'billing' ? 'active' : ''}`}>
              <div 
                className="cna-accordion-header"
                onClick={() => toggleStep('billing')}
              >
                <div className="cna-step-title">
                  <span className="cna-step-number">4</span>
                  <span className="cna-step-label">Datos de Facturación</span>
                </div>
                <div className="cna-step-status">
                  {isBillingStepValid ? (
                    <span className="cna-step-complete">✓ Completado</span>
                  ) : (
                    <span className="cna-step-incomplete">Pendiente</span>
                  )}
                </div>
              </div>
              {activeStep === 'billing' && (
                <div className="cna-accordion-content">
                  <div className="cna-form-section">
                    <div className="cna-billing-form">
                      <div className="cna-form-group">
                        <label>Dirección de Calle *</label>
                        <input
                          type="text"
                          value={billing.address_1}
                          onChange={(e) => handleBillingChange('address_1', e.target.value)}
                          className="cna-input"
                          required
                          placeholder="Nombre de la calle y número"
                        />
                      </div>

                      <div className="cna-form-group">
                        <label>Ciudad / Pueblo *</label>
                        <input
                          type="text"
                          value={billing.city}
                          onChange={(e) => handleBillingChange('city', e.target.value)}
                          className="cna-input"
                          required
                          placeholder="Ciudad o pueblo"
                        />
                      </div>

                      <div className="cna-form-group">
                        <label>Estado / Provincia *</label>
                        <input
                          type="text"
                          value={billing.state}
                          onChange={(e) => handleBillingChange('state', e.target.value)}
                          className="cna-input"
                          required
                          placeholder="Estado o provincia"
                        />
                      </div>

                      <div className="cna-form-group">
                        <label>País *</label>
                        <input
                          type="text"
                          value={billing.country}
                          onChange={(e) => handleBillingChange('country', e.target.value)}
                          className="cna-input"
                          required
                          placeholder="País"
                        />
                      </div>

                      <div className="cna-form-group">
                        <label>Notas Adicionales (opcional)</label>
                        <input
                          type="text"
                          value={billing.reference}
                          onChange={(e) => handleBillingChange('reference', e.target.value)}
                          className="cna-input"
                          placeholder="Información de referencia adicional"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Área B: Resumen Sticky */}
        <div className="cna-checkout-summary-sticky">
          <div className="cna-summary-card">
            <h3>Resumen de tu Suscripción</h3>

            {/* Mostrar información de entrega en el sticky */}
            {shipping.type && (
              <div className="cna-summary-shipping-info">
                <strong>
                  {shipping.type === 'home' ? 'Entrega a Domicilio' : 'Recoger en Tienda'}:
                </strong>
                {shipping.type === 'home' && shipping.department && shipping.municipality && shipping.district && (
                  <div>
                    <div>{shipping.department}, {shipping.municipality}, {shipping.district}</div>
                    {shipping.address && (
                      <div className="cna-shipping-address">{shipping.address}</div>
                    )}
                  </div>
                )}
                {shipping.type === 'pickup' && selectedStore && (
                  <div>
                    <div>{selectedStore.name}</div>
                    <div className="cna-shipping-address">{selectedStore.address}</div>
                  </div>
                )}
              </div>
            )}

            <div className="cna-summary-section">
              <div className="cna-summary-row">
                <span>
                  {getAdvancePercent(config) < 100
                    ? `Anticipo (${getAdvancePercent(config)}%)`
                    : `Producto (${getConfigQty(config)} unidades)`}
                </span>
                <span>
                  $
                  {(
                    orderTotals?.advance_amount ??
                    (config.variation?.price || 0) *
                      getConfigQty(config) *
                      (getAdvancePercent(config) / 100)
                  ).toFixed(2)}
                </span>
              </div>
            </div>

            <div className="cna-summary-section">
              <div className="cna-summary-row">
                <span>Envío</span>
                <span>
                  {orderTotals?.shipping_total === 0 || !orderTotals?.shipping_total
                    ? 'Gratis'
                    : `$${orderTotals.shipping_total.toFixed(2)}`}
                </span>
              </div>
            </div>

            {(orderTotals?.annual_fee ?? getAnnualFee(config)) > 0 && (
              <div className="cna-summary-section">
                <div className="cna-summary-row">
                  <span>Fee de Suscripción</span>
                  <span>
                    ${(orderTotals?.annual_fee ?? getAnnualFee(config)).toFixed(2)}
                  </span>
                </div>
              </div>
            )}

            <div className="cna-summary-section">
              <div className="cna-summary-row">
                <span>Subtotal</span>
                <span>
                  $
                  {(
                    orderTotals?.net_amount ??
                    computeCheckoutTotals(config, orderTotals?.shipping_total ?? 0, gatewayFee, gatewayFeeFixed)
                      .net_amount
                  ).toFixed(2)}
                </span>
              </div>
            </div>

            {/* Fee de tarjeta siempre visible si hay totales calculados */}
            {orderTotals && orderTotals.fee_amount > 0 && (
              <div className="cna-summary-section">
                <div className="cna-summary-row cna-fee-row">
                  <span>
                    Fee de Tarjeta ({(gatewayFee * 100).toFixed(1)}%
                    {gatewayFeeFixed > 0 ? ` + $${gatewayFeeFixed.toFixed(2)}` : ''})
                  </span>
                  <span>${orderTotals.fee_amount.toFixed(2)}</span>
                </div>
              </div>
            )}

            <div className="cna-summary-total">
              <div className="cna-summary-row">
                <strong>Total a Pagar</strong>
                <strong>
                  $
                  {(
                    orderTotals?.total_with_fee ??
                    computeCheckoutTotals(config, orderTotals?.shipping_total ?? 0, gatewayFee, gatewayFeeFixed)
                      .total_with_fee
                  ).toFixed(2)}
                </strong>
            </div>
          </div>

          {/* Checkbox de aceptación - Justo encima del botón */}
          <div className="cna-form-section cna-auto-renew-section" style={{ marginBottom: '1rem', marginTop: '1rem' }}>
            <div className="cna-form-group">
              <label className="cna-checkbox-label">
                <input
                  type="checkbox"
                  checked={acceptAutoRenew}
                  onChange={(e) => setAcceptAutoRenew(e.target.checked)}
                />
                <span>Renovar automática al finalizar el ciclo de entregas.</span>
              </label>
            </div>
          </div>

            <button
              type="button"
              onClick={handleCreateOrder}
              disabled={loading || !isFormValid}
              className="cna-button cna-button-primary cna-button-full-width"
            >
              {loading ? 'Procesando...' : 'Pagar Suscripción'}
            </button>

            {!isFormValid && (
              <p className="cna-validation-hint">
                Por favor completa todos los campos requeridos para continuar.
              </p>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default CheckoutWizard;