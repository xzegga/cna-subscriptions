# DOCUMENTO MAESTRO: CNA Subscriptions Plugin
**Versión:** 1.0.0
**Tipo:** Especificación Funcional y Técnica
**Alcance:** Backend (WP) y Frontend (React)

---

## 1. Visión General del Proyecto
El objetivo es desarrollar un **plugin de WordPress a medida (Bespoke)** para la gestión y venta de suscripciones de productos agrícolas (ej: Canastas de Orgánicos).

A diferencia de soluciones genéricas como WooCommerce, este sistema está optimizado para una lógica de negocio específica: **Entregas recurrentes con fechas fijas, zonificación logística estricta y modelos de pago fraccionado (Anticipo + Contra Entrega).**

### Objetivos Clave
1.  **Eliminar fricción:** Checkout simplificado en una sola página (One-Page Checkout).
2.  **Control Logístico:** Algoritmo automático de fechas de entrega y generación de hojas de ruta.
3.  **Transparencia Financiera:** Traslado exacto del fee de la pasarela de pagos al cliente final ("Reverse Fee Calculation").
4.  **Flexibilidad Geográfica:** Sistema de zonas de envío granular (Departamento -> Municipio -> Distrito).

---

## 2. Definiciones de Producto y Negocio

### 2.1 Entidad: Producto de Suscripción
El producto no es un ítem estático, es un contrato configurable por el usuario con las siguientes variables:
* **Tamaño:** Pequeño, Mediano, Grande (Cada uno tiene un precio base diferente).
* **Cantidad de Entregas:** Mínimo 4 unidades. Seleccionable por el usuario.
* **Frecuencia:** Cada cuánto se recibe (ej: 1 semana, 2 semanas, 4 semanas).
* **Anticipo:** El usuario decide si paga el 50% o el 100% del valor del producto por adelantado.
* **Fee Anual:** Un cobro único (ej: $10.00) que se aplica al primer pago y se renueva anualmente, este valor debe ser configurable por producto.

### 2.2 Modelo de Precios y Pagos
El cálculo financiero se compone de capas acumulativas:

1.  **Subtotal Producto:** `(Precio_Base_Tamaño * Cantidad_Total)`.
2.  **Monto Anticipo:** `Subtotal_Producto * (%_Seleccionado / 100)`.
    * *Nota:* El remanente (si eligió 50%) se convierte en deuda COD (Cash on Delivery) cobrable en cada entrega física.
3.  **Costo de Envío:** `(Tarifa_Zona_Producto * Cantidad_Total)`.
    * *Nota:* El envío siempre se paga 100% por adelantado si es a domicilio.
4.  **Fee Anual:** Valor fijo configuración por producto (ej: $10.00).

#### Fórmula del "Reverse Fee" (Integración Pagadito)
El negocio requiere recibir el monto neto exacto. La comisión de la pasarela (ej: 6%) se traslada al cliente.

**Variables:**
* `Neto_Esperado` = Monto Anticipo + Costo Envío + Fee Anual.
* `Tasa_Pasarela` = % Configurable (ej: 0.06).

**Fórmula Final al Checkout:**
$$Total\_Cobrar = \frac{Neto\_Esperado}{1 - Tasa\_Pasarela}$$

---

## 3. Arquitectura Logística

### 3.1 Zonificación (Matriz Geográfica)
El sistema no usa tarifas planas globales, sino una matriz de **Zona vs. Producto**.

1.  **Zonas Globales:** Se definen en Ajustes (ej: "Zona Metropolitana", "Zona Occidente"). Agrupan una lista de Distritos.
2.  **Tarifas por Producto:** En la edición de cada producto, se asigna un precio a cada zona activa.
    * *Ejemplo:* Canasta Básica -> Zona Metro: $4.00.
    * *Ejemplo:* Canasta Básica -> Zona Occidente: $8.00.

### 3.2 Algoritmo de Fechas ("El Scheduler")
El sistema calcula automáticamente las fechas exactas de entrega al momento de la compra.

* **Día de Entrega:** Jueves.
* **Día de Corte (Cutoff):** Miércoles.
* **Lógica:**
    * Si `Fecha_Compra` < Miércoles: Primera entrega es **este Jueves**.
    * Si `Fecha_Compra` >= Miércoles: Primera entrega es el **Jueves de la próxima semana**.
    * Las fechas subsiguientes se calculan sumando `Frecuencia_Semanas` a la fecha anterior.

---

## 4. Especificación Técnica (Frontend)

Se utilizará una arquitectura de **"React Islands"** montadas sobre WordPress mediante shortcodes. El Build Tool es **Vite**.

### 4.1 Componente: Configurador de Producto
* **Ubicación:** Página individual del producto (`single-cna_product`).
* **Funcionalidad:**
    * Selectores UI para Tamaño, Cantidad, Frecuencia y Anticipo.
    * Validación inmediata (ej: no permitir menos de 4 unidades).
    * Actualización de precio en tiempo real (Client-side math).
    * Botón "Suscribirse": Guarda el estado en `SessionStorage` y redirige al Checkout.

### 4.2 Componente: Wizard de Checkout
* **Ubicación:** Página `/finalizar-suscripcion`.
* **Paso 1: Datos de Envío**
    * Formulario con Selects en cascada (Departamento -> Municipio -> Distrito).
    * Datos: JSON de El Salvador (local o API).
* **Paso 2: Cotización (API Call)**
    * Consulta asíncrona al backend: `¿Cuánto cuesta enviar Producto X al Distrito Y?`.
    * Si hay cobertura: Muestra opción "Domicilio ($ Precio)".
    * Si no hay cobertura: Solo muestra "Recoger en Tienda".
* **Paso 3: Resumen y Pago**
    * Muestra desglose transparente: Subtotal, Envío, Fee Anual, **Fee Tarjeta**.
    * Integración con botón de pago (Redirección a Pagadito).

---

## 5. Especificación Técnica (Backend)

Desarrollado en PHP nativo sobre la API de Plugins de WordPress. Ultima version disponible https://developer.wordpress.org/

### 5.1 Base de Datos (Tablas Personalizadas)
No se usará `wp_postmeta` para pedidos ni entregas por razones de rendimiento.

1.  **`wp_cna_shipping_zones`**: Definición de zonas macro.
2.  **`wp_cna_shipping_locations`**: Relación Zona <-> Distritos Geográficos.
3.  **`wp_cna_subscriptions`**: La cabecera del contrato.
    * Guarda `shipping_address` como un JSON congelado (snapshot) para no depender del perfil de usuario.
    * Estado: `active`, `cancelled`, `completed`.
4.  **`wp_cna_deliveries`**: El desglose de entregas.
    * Columnas críticas: `scheduled_date`, `amount_to_collect` (Deuda pendiente para el motorista).

### 5.2 Área Administrativa (WP Admin)
* **Dashboard:** KPIs simples (Suscripciones activas, entregas de la semana).
* **Gestor de Productos:** Metaboxes custom para precios y tabla de tarifas de envío.
* **Panel Logístico:**
    * Filtro por fecha.
    * Generador de **"Hoja de Ruta"**: Tabla imprimible con Dirección, Teléfono y **Monto a Cobrar (COD)** por cliente.
* **Ajustes:**
    * API Keys Pagadito.
    * Porcentaje Fee Pasarela.
    * Gestor CRUD de Zonas.

### 5.3 API REST Endpoints (`/wp-json/cna/v1/`)
* `GET /shipping-rate`: Recibe producto + distrito, devuelve precio.
* `POST /create-order`: Recibe payload del checkout, valida, crea registros en BD y devuelve URL de pago.
* `POST /webhook/pagadito`: (Público) Recibe confirmación de pago, activa suscripción y dispara el Scheduler de fechas.

---

## 6. Casos de Uso (User Stories)

### Caso A: El Cliente Nuevo
> "Como cliente, quiero comprar 4 canastas pequeñas. Vivo en Santa Tecla. Quiero pagar solo la mitad ahora para probar."
1.  Entra al producto, selecciona "Pequeña", "4 unidades", "50% Anticipo".
2.  En Checkout, elige "Santa Tecla". El sistema le cobra $4.00 extra por envío (x4 canastas).
3.  Paga con tarjeta el 50% del producto + 100% del envío + Fee Anual + Fee Pasarela.
4.  Recibe correo de confirmación con las 4 fechas exactas de entrega.

### Caso B: El Motorista
> "Como repartidor, necesito saber cuánto cobrar este Jueves."
1.  El admin imprime la hoja del Jueves.
2.  El motorista ve que el Cliente A tiene entrega.
3.  En la columna "Cobrar", aparece el 50% restante del valor de esa canasta específica (ej: $10.00).
4.  Entrega la canasta y recibe el efectivo.

### Caso C: El Administrador (Expansión)
> "Como dueño, quiero empezar a vender en San Miguel, pero el envío allá cuesta $10."
1.  Va a Ajustes > Zonas. Crea "Zona Oriente" y agrega el distrito "San Miguel".
2.  Va al producto "Canasta Campesina".
3.  En la tabla de envíos, aparece la nueva "Zona Oriente". Le pone precio $10.00.
4.  Guarda. Inmediatamente los clientes de San Miguel pueden comprar.

---

## 7. Requisitos de Entorno
* **WordPress:** 6.0 o superior.
* **PHP:** 7.4 o superior (Compatible con 8.x).
* **Node.js:** (Solo para desarrollo/compilación de assets).
* **Servidor:** Apache/Nginx con soporte para Permalinks.