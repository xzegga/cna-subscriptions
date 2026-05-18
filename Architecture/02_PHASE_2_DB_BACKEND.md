# 02_PHASE_2_DB_BACKEND.md: Base de Datos y Admin

## Objetivo
Crear la persistencia de datos y las interfaces de administración.

## Tareas

### 1. Activador e Instalador (`includes/Core/class-cna-activator.php`)
- Usar `dbDelta` para crear las 4 tablas definidas en `00_PROJECT_CONTEXT.md`.
- **Importante:** Asegurar que `cna_subscriptions` tenga el campo `pagadito_token` (VARCHAR 255) y `is_auto_renew` (TINYINT default 1).

### 2. Custom Post Type (`cna_product`)
- Registrar CPT 'Suscripción'.
- **Metabox Precios:** Inputs para Precio (S/M/L) y Fee Anual.
- **Metabox Logística:** Tabla dinámica que liste las zonas activas de `cna_shipping_zones` y permita guardar un precio de envío por zona.

### 3. Página de Ajustes (`includes/Admin/class-cna-settings.php`)
- **Credenciales:** UID Pagadito, WSK Pagadito, % Fee Pasarela.
- **Switch:** "Modo Sandbox" vs "Producción".
- **Gestor de Zonas:** CRUD simple para crear Zonas (ej: "Metropolitana") y asignar Distritos usando un array maestro de El Salvador.

### 4. API Helper de Ubicaciones
- Crear servicio PHP que retorne la lista de Departamentos/Municipios/Distritos de El Salvador para ser consumida por el Admin y el Frontend.