# 00_PROJECT_CONTEXT.md: CNA Subscriptions Plugin

## 1. Visión General
Desarrollo de un plugin de WordPress a medida para la venta de suscripciones de productos agrícolas (ej: Canastas).
El sistema gestiona logística de entregas (días fijos), zonificación geográfica y cobros recurrentes automáticos mediante tokenización de tarjetas.

## 2. Stack Tecnológico
- **Backend:** PHP 7.4+ (WP Plugin API), MySQL.
- **Frontend:** React 18, TypeScript, Vite (Bundler), SCSS Modules.
- **Pasarela:** Pagadito (El Salvador) con soporte de Tokenización.

## 3. Lógica de Negocio Crítica

### A. Producto y Precios
- **Variables:** Tamaño (S/M/L), Cantidad (Min 4), Frecuencia (Semanas), Anticipo (50% o 100%).
- **Fórmula de Cobro Inicial:**
  `Total = (Precio_Base * Qty * %Anticipo) + Fee_Anual + (Envío_Zona * Qty)`
- **Reverse Fee:** El cliente paga la comisión de la pasarela.
  `Cobro_Final = Total_Neto / (1 - %Comision)`

### B. Logística (El Scheduler)
- **Día de Entrega:** Jueves.
- **Corte (Cutoff):** Miércoles.
- **Regla:** Si compra antes del miércoles, recibe este jueves. Si compra miércoles/jueves, pasa a la siguiente semana.

### C. Recurrencia y Renovación (CRÍTICO)
- **Tokenización:** Al pagar la primera vez, se guarda un token seguro de la tarjeta.
- **Ciclo de Vida:** La suscripción es indefinida hasta que el usuario la cancele.
- **Renovación Automática:** Al completar las entregas contratadas (ej: tras la 4ta canasta), el sistema cobra automáticamente el siguiente ciclo.
- **Regla de Fee Anual en Renovación:**
  - Si la renovación ocurre en el mismo año contrato: No se cobra Fee Anual.
  - Si la renovación coincide con aniversario: Se suma el Fee Anual.

## 4. Esquema de Base de Datos (Tablas Custom)

1.  **`cna_shipping_zones`**: `id`, `name`, `is_active`.
2.  **`cna_shipping_locations`**: `id`, `zone_id`, `department`, `municipality`, `district`.
3.  **`cna_subscriptions`** (Maestra):
    - `id`, `user_id`, `product_id`, `status` ('active', 'pending', 'cancelled', 'payment_failed').
    - `pagadito_token`: (String) Token para cobros futuros.
    - `is_auto_renew`: (Boolean) Preferencia del usuario.
    - `next_renewal_date`: (Date) Cuándo toca el siguiente cobro.
    - `shipping_address_json`: (JSON) Snapshot de dirección.
    - `variant_details`: (JSON) {size, qty, frequency, advance_percent}.
4.  **`cna_deliveries`** (Detalle):
    - `id`, `subscription_id`, `scheduled_date`, `payment_status`, `amount_to_collect` (COD), `delivery_status`.