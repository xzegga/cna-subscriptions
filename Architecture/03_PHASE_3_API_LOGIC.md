# 03_PHASE_3_API_LOGIC.md: API, Cron y Pagadito

## Objetivo
Implementar la lógica transaccional, cálculo de fechas y el robot de cobros automáticos.

## Tareas

### 1. Scheduler (`includes/Model/class-cna-scheduler.php`)
- Método `calculate_dates($start_date, $qty, $frequency)`.
- Implementar reglas de Jueves/Miércoles.
- Retornar array de fechas.

### 2. Endpoints REST (`includes/API/class-cna-rest.php`)
- `GET /shipping-rate`: Recibe (producto, distrito). Devuelve precio según zona.
- `POST /create-order`:
  - Recibe datos del checkout.
  - Calcula totales con Reverse Fee.
  - Llama a Pagadito API solicitando **Tokenización**.
  - Crea suscripción en BD con status `pending`.
  - Devuelve URL de pago.
- `POST /webhook/pagadito`:
  - Valida pago.
  - Guarda `pagadito_token` en `cna_subscriptions`.
  - Activa suscripción y genera fechas en `cna_deliveries`.

### 3. Sistema de Auto-Renovación (`includes/Core/class-cna-cron.php`)
- Registrar Cron Job diario: `cna_daily_renewal_check`.
- **Lógica del Job:**
  1. Buscar suscripciones activas donde `next_renewal_date` == Hoy y `is_auto_renew` == 1.
  2. Calcular monto (Verificar si aplica Fee Anual).
  3. Llamar API Pagadito (Cobro por Token).
  4. **Si Exitoso:**
     - Extender `next_renewal_date`.
     - Generar nuevas filas en `cna_deliveries`.
     - Email al cliente.
  5. **Si Fallido:**
     - Marcar `payment_failed`.
     - Email de alerta.