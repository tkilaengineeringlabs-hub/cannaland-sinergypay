# Operación y validación pendiente

## Registro y llaves

Proporcionar a SinergyPay la URL de callback mostrada por el gateway. No se invoca una API de
registro no documentada. Debe aceptar POST externo por HTTPS. Validar en sandbox los ACK mínimos
200/400/401/409/500 y su política de reintentos.

Las dos llaves empaquetadas son públicas, procedentes del ZIP oficial. El entorno configurado
selecciona una y solo una. security.key nunca controla archivos. Rotación: verificar distribución
oficial y desplegar actualización revisada; no hay descarga dinámica en el webhook.

El ambiente es global. No cambiar de sandbox a producción mientras haya operaciones sandbox
pendientes esperando notificación en la misma instalación. Usar staging separado para sandbox.

## Resultado incierto y conciliación

Revisar metadatos _csp_order_state, _csp_clabe_state y las notas del pedido. Si hay ambiguous o
sending interrumpido, consultar al equipo SinergyPay usando la referencia estable y el ID remoto
si existe. NO borrar el ID ni resetear el estado para volver a probar un POST.
No usar la existencia de una CLABE/transferencia como confirmación de pago.

Para un rechazo definitivo, corregir su causa y revisar con el proveedor antes de autorizar otro
intento. Esta versión no ofrece un botón de reset: la recuperación es administrativa, mediante
CRUD WooCommerce por personal autorizado y con evidencia del proveedor.

Un proceso abortado puede dejar csp_order_lock_{id}. Antes de eliminarlo, comprobar que no hay
un worker activo ni una operación financiera pendiente de conciliación. No tiene TTL ni takeover
que pueda permitir dos envíos simultáneos. Los claims csp_ticket_* no deben borrarse durante
reintentos ni usarse como permiso para volver a completar un pago.

Si confirmation_state=applying y la orden no figura pagada con el mismo transaction ID, hay una
ventana de incertidumbre entre hooks y persistencia. Revisar el estado financiero y los efectos
WooCommerce; la notificación repetida no volverá a ejecutar payment_complete. Si ya quedó pagada
con el mismo ticket, el reintento reconoce el éxito y finaliza el marcador sin repetir hooks.

No hay polling, reembolso remoto ni migración de credenciales/órdenes del plugin de referencia.
Los logs viejos y el historial Git no fueron purgados. La posible credencial histórica necesita
rotación si fue real. Las nuevas pruebas no usan credenciales reales ni las leen del entorno.

## Antes de producción

1. Configurar API key privada sandbox fuera de Git, callback y datos del comercio.
2. Capturar un evento RAW real de sandbox y comprobar firma con la llave oficial. Confirmar el
   eco de reference, amount textual, description, id del ticket, code y formato de Product/pagos.
3. Validar generación de orden y CLABE; vencimiento, montos máximos, ceros iniciales, rechazo,
   transferencia completa y no aceptada. No enviar importes reales sin autorización adicional.
4. Probar acuses, reintentos del proveedor y entrega tardía. El plugin no inventa estado por tiempo;
   una notificación de pago válido puede llegar después del vencimiento de la operación.
5. Instalar en WordPress/WooCommerce reales con HPOS habilitado SIN sincronización y con legacy;
   probar checkout clásico, Blocks, impuestos/cupones y pedidos de invitados/usuarios.
6. Ejecutar concurrencia con MySQL y workers reales; probar cortes entre POST/escritura,
   entre ambas operaciones y durante payment_complete, incluyendo hooks de stock/email.
7. Confirmar datos bancarios que el proveedor requiera mostrar (banco/beneficiario); esta revisión
   solo muestra los datos que conoce: CLABE, importe, referencias y vencimiento.
8. Revisar configuración de servidores/CDN/seguridad para no bloquear el callback ni almacenar
   cuerpos de webhook, firmas o encabezados de autorización en logs ajenos al plugin.

## Evidencia local

`php tests/run.php`: fixtures firmados con RSA sintética y llaves privadas efímeras en memoria;
respuestas API y objetos WooCommerce simulados. No son eventos reales del proveedor.
`php tests/bootstrap-smoke.php`: carga/registro con dobles WordPress.
Opcional `CSP_ORIGINAL_ROOT=/ruta/a/base php tests/bootstrap-smoke.php` carga además el código
original conservado para detectar colisiones de nombres. No requiere activar ese plugin en producción.
`python3 scripts/check.py`: lint PHP, sintaxis JS, diff y búsqueda de dependencias excluidas.

Las pruebas locales no prueban interoperabilidad con SinergyPay ni compatibilidad integral de
WooCommerce/HPOS/Blocks. No se afirma que se haya realizado un pago real.
