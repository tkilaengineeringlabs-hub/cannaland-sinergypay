=== Cannaland SinergyPay ===
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 0.1.0
Tags: woocommerce, spei, payments

Cobros SPEI mediante integración directa SinergyPay con CLABE y notificación RSA/SHA-512.

== Description ==
Gateway cannaland_sinergypay para WooCommerce 8.2 o posterior. Requiere MXN y OpenSSL.
Compatible por diseño con CRUD/HPOS y con checkout clásico/Checkout Blocks.
No se ha validado todavía contra sandbox real. Las pruebas disponibles son sintéticas.

== Installation ==
1. Activa WooCommerce y sube el ZIP del plugin desde WordPress.
2. Configura entorno, API key privada y vencimiento en WooCommerce > Ajustes > Pagos.
3. Proporciona a SinergyPay la URL de callback mostrada en esos ajustes.
4. Completa la validación sandbox antes de habilitar cobros en producción.

== Frequently Asked Questions ==
= ¿Cuál es el callback? =
POST /wp-json/cannaland-sinergypay/v1/payment-notification en tu dominio.
La URL exacta de la instalación aparece en los ajustes.

= ¿Qué ocurre si se pierde una respuesta de creación? =
Se bloquea el reintento automático y se requiere conciliación administrativa.

= ¿Se consulta el pago periódicamente? =
No. Solo la notificación válida puede confirmar automáticamente un pago nuevo.

== Changelog ==
= 0.1.0 =
Integración directa SPEI, CLABE, verificación de notificaciones e idempotencia conservadora.
