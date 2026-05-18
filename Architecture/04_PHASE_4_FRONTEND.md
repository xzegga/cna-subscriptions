# 04_PHASE_4_FRONTEND.md: Componentes React

## Objetivo
Interfaz interactiva para compra y gestión de cuenta.

## Tareas

### 1. Configurador (`<ProductConfigurator />`)
- Selectores: Tamaño, Cantidad, Frecuencia, Anticipo.
- Math: Calcular total en tiempo real.
- Validar mínimo 4 unidades.
- Guardar en SessionStorage y redirigir.

### 2. Checkout (`<CheckoutWizard />`)
- **Paso 1: Envío:** Selects en Cascada (Depto->Muni->Distrito). Fetch precio de envío a la API.
- **Paso 2: Resumen:**
  - Mostrar desglose: Producto, Envío, Fee Anual, Fee Pasarela.
  - **Legal:** Checkbox obligatorio: *"Acepto la renovación automática al finalizar el ciclo."*
- **Acción:** Enviar a `/create-order` y redirigir a Pagadito.

### 3. Mi Cuenta (`<MyAccountDashboard />`)
- Listar suscripciones activas del usuario.
- Mostrar próximas fechas de entrega.
- **Toggle Switch:** "Auto-renovación".
  - Al cambiar, llamar a endpoint `POST /toggle-renew` para actualizar BD.
- Botón "Actualizar Tarjeta" (Opcional: flujo para borrar token y pedir nuevo).