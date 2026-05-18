# Configuración de URLs en Pagadito

Este documento explica cómo configurar las URLs necesarias en tu cuenta de Pagadito para que el plugin funcione correctamente.

## URLs a Configurar en Pagadito

### 1. Return URL (URL de Retorno)

**Ubicación en Pagadito:** Configuración Técnica → Parámetros de Integración → Return URL

**URL a configurar:**
```
https://tudominio.com/wp-json/cna/v1/payment-return?subscription_id={subscription_id}&status={status}
```

**Nota:** Reemplaza `tudominio.com` con tu dominio real. Los parámetros `{subscription_id}` y `{status}` serán reemplazados automáticamente por Pagadito cuando redirija al usuario después del pago.

**Ejemplo real:**
```
https://lacanastacampesina.org/wp-json/cna/v1/payment-return?subscription_id=123&status=success
```

### 2. Webhook URL (URL de Notificaciones)

**Ubicación en Pagadito:** Configuración Técnica → Webhooks → Webhook URL

**URL a configurar:**
```
https://tudominio.com/wp-json/cna/v1/webhook/pagadito
```

**Ejemplo real:**
```
https://lacanastacampesina.org/wp-json/cna/v1/webhook/pagadito
```

**Importante:** 
- Esta URL debe ser HTTPS (no funciona con HTTP excepto en desarrollo local)
- Pagadito enviará notificaciones POST a esta URL cuando cambie el estado de una transacción
- El webhook procesa automáticamente los pagos y activa las suscripciones

## Validación de IP (Opcional)

El plugin incluye una función opcional para validar que los webhooks provengan de IPs específicas de Pagadito.

### Cómo Activar la Validación de IP

1. Ve a **Suscripciones → Ajustes → Pagos**
2. Selecciona **Pagadito** de la lista de métodos de pago
3. Marca la casilla **"Validar IP de Webhook"**
4. En el campo **"IPs Permitidas"**, ingresa las IPs de Pagadito (una por línea)
5. Guarda los cambios

**Nota:** Por defecto, la validación de IP está **desactivada**. Solo actívala si tienes las IPs oficiales de Pagadito y quieres mayor seguridad.

### Ejemplo de IPs Permitidas:
```
192.168.1.100
10.0.0.50
```

## Cómo Funciona el Flujo

### Flujo de Pago Completo:

1. **Usuario completa el checkout** → Se crea una suscripción con estado `pending`
2. **Se genera URL de pago** → Usuario es redirigido a Pagadito
3. **Usuario paga en Pagadito** → Pagadito procesa el pago
4. **Pagadito redirige al usuario** → Return URL (`/payment-return`)
   - El endpoint verifica el estado de la suscripción
   - Redirige al usuario a la página de confirmación
5. **Pagadito envía notificación** → Webhook URL (`/webhook/pagadito`)
   - El webhook procesa el pago automáticamente
   - Activa la suscripción si el pago fue exitoso
   - Guarda el token de tarjeta (si aplica)
   - Genera las fechas de entrega

### Diferencias con el Plugin de WooCommerce

El plugin oficial de WooCommerce usa:
- Return URL: `?wc-api=WC_Gateway_Pagadito&token={value}&order_id={ern_value}`
- Webhook: `?wc-api=WC_Webhook_Pagadito`

Nuestro plugin usa WordPress REST API estándar:
- Return URL: `/wp-json/cna/v1/payment-return`
- Webhook: `/wp-json/cna/v1/webhook/pagadito`

Esto es más moderno y sigue los estándares de WordPress REST API.

## Verificación de Configuración

Después de configurar las URLs en Pagadito, puedes verificar que funcionan:

1. **Return URL:** Realiza una compra de prueba y verifica que después del pago te redirige correctamente
2. **Webhook:** Revisa los logs de WordPress (`wp-content/debug.log`) para ver si se reciben las notificaciones

## Troubleshooting

### El Return URL no funciona
- Verifica que la URL esté correctamente configurada en Pagadito
- Asegúrate de que tu sitio tenga HTTPS habilitado
- Revisa los logs de WordPress para ver errores

### El Webhook no se recibe
- Verifica que la URL del webhook esté correctamente configurada en Pagadito
- Asegúrate de que tu sitio tenga HTTPS habilitado
- Si activaste la validación de IP, verifica que las IPs sean correctas
- Revisa los logs de WordPress para ver si hay errores de validación

### Error "IP no permitida"
- Si activaste la validación de IP, verifica que las IPs configuradas sean las correctas
- Puedes desactivar temporalmente la validación de IP para pruebas
- Contacta a Pagadito para obtener la lista oficial de IPs de sus servidores
