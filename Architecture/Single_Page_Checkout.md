# Especificación Técnica: Refactorización de Checkout (Single Page)

## 1. Objetivo General

Consolidar el flujo de suscripción de múltiples pasos en una sola pantalla (**Single Page Checkout**) dentro del plugin de React. El objetivo es reducir la fricción, capturar datos faltantes del usuario de WordPress y presentar un desglose financiero transparente y dinámico.

## 2. Lógica de Datos del Usuario (WordPress Integration)

El componente debe actuar como una interfaz inteligente que detecta la completitud del perfil del usuario logueado.

**Lógica de Visualización:**
El sistema consultará los metadatos del usuario actual (`wp_current_user`). Para cada uno de los campos requeridos (Nombres, Apellidos, Correo, Nacionalidad), se aplicará la siguiente lógica:

1.  **Dato Existente:** Si el campo ya tiene valor en la base de datos, se mostrará como **texto estático (solo lectura)**. Esto valida al usuario sin obligarlo a reescribir información.
2.  **Dato Faltante:** Si el campo está vacío o es nulo, se renderizará automáticamente un **campo de entrada (input)** editable y obligatorio.

**Campos a validar:**
* Nombres (`first_name`)
* Apellidos (`last_name`)
* Correo Electrónico (`user_email`)
* Nacionalidad (meta_key: `nationality`)

**Persistencia:**
Cualquier dato capturado en esta etapa (porque faltaba previamente) debe marcarse para ser actualizado en el perfionl del usuario (`update_user_meta`) al momento de procesar la orden exitosamente.

## 3. Lógica de Dirección de Facturación (Nueva Funcionalidad)

Se incorpora la gestión de dirección fiscal separada de la dirección de entrega.

### Interfaz de Usuario
* **Selector de Copia:** Un control tipo checkbox o switch activado por defecto con la etiqueta: *"Usar la misma dirección de envío para la facturación"*.
* **Formulario Condicional:**
    * **Activado:** Los campos de facturación están ocultos visualmente.
    * **Desactivado:** Se despliega un segundo formulario completo (Calle, Colonia, Municipio, Departamento, etc.) para capturar la dirección fiscal.

### Requisitos de Base de Datos (Persistencia)
Para soportar esta funcionalidad, el backend debe estar preparado para recibir y almacenar estos datos explícitamente en la tabla de metadatos del usuario (`usermeta`) o en la tabla de la orden, según la arquitectura del plugin.

**Campos a mapear en Base de Datos:**
* `billing_address_1` (Dirección)
* `billing_city` (Municipio)
* `billing_state` (Departamento/Estado)
* `billing_reference` (Referencia opcional)

**Lógica de Guardado en DB:**
Al momento de enviar el formulario (Submit):
1.  Si el usuario eligió **"Usar misma dirección"**: El sistema debe duplicar internamente los valores capturados en los campos de *Envío* (`shipping_*`) y guardarlos en los campos de *Facturación* (`billing_*`) en la base de datos.
2.  Si el usuario eligió **"Dirección diferente"**: Se guardan los valores ingresados explícitamente en el formulario de facturación en sus respectivos campos de base de datos.

## 4. Estructura del Desglose Financiero (Calculadora)

El panel de resumen financiero debe operar como una calculadora reactiva que actualiza los totales instantáneamente sin recargar la página.

**Algoritmo de Cálculo (Paso a Paso):**

1.  **Cálculo de Producto:**
    * Multiplicar el *Precio Unitario de la Canasta* por la *Cantidad Seleccionada*.

2.  **Cálculo de Envío (Dinámico):**
    * Evaluar el método de entrega seleccionado.
    * *Si es "A Domicilio":* Multiplicar el *Costo de Envío Unitario* por la *Cantidad de Canastas*. (Nota: El envío se cobra por unidad, no por orden global).
    * *Si es "Recoger en Tienda":* El valor es 0.

3.  **Costo de Suscripción:**
    * Sumar el valor fijo del *Fee de Suscripción* (Anual o Mensual).

4.  **Cálculo de Fee de Tarjeta de Crédito:**
    * Obtener el *Subtotal Preliminar* sumando: (Total Producto + Total Envío + Fee Suscripción).
    * Calcular el porcentaje de comisión (ej. 3% o 5%) sobre este *Subtotal Preliminar*.
    * Este valor debe mostrarse explícitamente en una línea separada.

5.  **Total General:**
    * Sumar el *Subtotal Preliminar* + el valor calculado del *Fee de Tarjeta*.

## 5. Estructura Visual (Wireframe)

El diseño se divide en dos áreas principales para escritorio, adaptándose a una sola columna en móviles.

### Área A: Formulario de Datos (Izquierda)
1.  **Bloque de Identidad:** Muestra datos del usuario (mezcla de texto y inputs según disponibilidad).
2.  **Bloque de Ubicación y Entrega:**
    * Selectores jerárquicos (Depto -> Municipio -> Distrito).
    * Selector de método de entrega (Radio buttons o Cards).
    * Campos de dirección de envío.
3.  **Bloque de Facturación:**
    * Checkbox de "Misma dirección".
    * Formulario desplegable de facturación.

### Área B: Resumen de Orden "Sticky" (Derecha)
Este panel debe permanecer visible mientras el usuario hace scroll.
* **Tabla de detalle:** Filas para Producto, Envío, Membresía.
* **Subtotal:** Suma parcial clara.
* **Línea de Fee Financiero:** Muestra el recargo por uso de tarjeta.
* **Total Final:** Destacado visualmente.
* **Botón de Acción:** "Pagar Suscripción".

## 6. Consideraciones de Implementación

1.  **Validación de Integridad:** El botón de pago debe permanecer deshabilitado o mostrar error si:
    * Faltan campos de usuario requeridos (ej. Nacionalidad).
    * Se seleccionó "Facturación distinta" pero no se llenaron los campos.
2.  **Sincronización:** Asegurar que el cambio en la cantidad de canastas actualice inmediatamente tanto el costo del producto como el costo del envío en el resumen.
3.  **Experiencia Móvil:** En pantallas pequeñas, considerar colocar una barra inferior fija (sticky footer) que muestre solo el "Total a Pagar" y el botón de acción, con un acordeón para ver el desglose completo.