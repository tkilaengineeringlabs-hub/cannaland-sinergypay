=== Cannaland Payments ===
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 0.3.4
Tags: woocommerce, spei, payments

Pasarela de pagos integrada para Cannaland.

== Description ==
Tres gateways independientes para WooCommerce 8.2 o posterior. SPEI requiere MXN y OpenSSL.
Tarjetas y efectivo no procesan pagos hasta recibir sus contratos oficiales.
Sus ajustes son independientes; permanecen ocultos al comprador aunque se guarde su habilitación.
Compatible por diseño con CRUD/HPOS y con checkout clásico/Checkout Blocks.
Creación de orden, CLABE y checkout probados por el cliente en Sandbox real con 0.3.1.
Confirmación por webhook real pendiente. Las pruebas locales usan dobles y fixtures sintéticos.

== Installation ==
1. Activa WooCommerce y sube el ZIP del plugin desde WordPress.
2. Configura entorno, API key privada y vencimiento en WooCommerce > Ajustes > Pagos > Transferencia SPEI.
3. Proporciona a SinergyPay la URL de callback mostrada en esos ajustes.
4. Completa la validación sandbox antes de habilitar cobros en producción.

== Frequently Asked Questions ==
= ¿Cuál es el callback? =
POST /wp-json/cannaland-payments/v1/payment-notification en tu dominio.
La URL exacta de la instalación aparece en los ajustes.

= ¿Qué ocurre si se pierde una respuesta de creación? =
Se bloquea el reintento automático y se requiere conciliación administrativa.

= ¿Se consulta el pago periódicamente? =
No. Solo la notificación válida puede confirmar automáticamente un pago nuevo.

== Changelog ==
= 0.3.4 =
Identidad visual oscura y verde de la tarjeta SPEI web, con CSS aislado y estados de copia.
Sin cambios en pagos, referencias ni emails.

= 0.3.3 =
Tarjeta SPEI responsive con beneficiario Cannaland y botones de copia accesibles sin dependencias.
Referencia íntegra, datos reales del pedido y emails sin JavaScript. Sin cambios de pagos.

= 0.3.2 =
Presentación SPEI con fecha/zona WordPress e importe WooCommerce.
Instrucciones en emails HTML/texto y consulta administrativa mediante CRUD, sin migración.
Sin cambios en creación remota, CLABE, idempotencia ni verificación del webhook.

= 0.3.1 =
Acepta importes JSON enteros y decimales válidos al crear órdenes SinergyPay.
Conserva controles de importe, firma, idempotencia y logging seguro.

= 0.3.0 =
Identidad Cannaland Payments, IDs y metadatos neutrales, callback y empaquetado propios.
Proveedor SinergyPay conservado; sin cambios en la lógica SPEI.

= 0.2.0 =
Arquitectura multimétodo: gateways/ajustes/Blocks independientes; tarjetas y efectivo pendientes.
SPEI conserva identificador, opciones y flujo de pago.

= 0.1.0 =
Integración directa SPEI, CLABE, verificación de notificaciones e idempotencia conservadora.
