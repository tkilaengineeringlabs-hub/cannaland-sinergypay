# Cannaland SinergyPay

Plugin WooCommerce para cobros directos SPEI con SinergyPay. Gateway: `cannaland_sinergypay`.
Versión 0.1.0. Requiere WordPress 6.2+, WooCommerce 8.2+, PHP 8.0+ y OpenSSL.
Incluye integración con checkout clásico y registro de método para Checkout Blocks.

## Instalación y configuración

Instala `dist/cannaland-sinergypay.zip` desde Plugins → Añadir plugin → Subir plugin.
Activa WooCommerce y Cannaland SinergyPay. En WooCommerce → Ajustes → Pagos configura:

- Habilitar/deshabilitar, título y descripción.
- Entorno `sandbox` o `production` y su API key **privada**, mediante el administrador de WordPress.
- `expiration_minutes`: entero positivo; predeterminado 8640.
- Logging seguro opcional, desactivado por defecto. Fuente: `cannaland-sinergypay`.
- Moneda de tienda y pedido: MXN.

La API key se guarda en `woocommerce_cannaland_sinergypay_settings`, protegida por los controles
administrativos de WooCommerce y mostrada como password. No se cifra en la base de datos:
protege administradores, base de datos y respaldos. No la añadas al código ni al repositorio.

## Callback que debes proporcionar a SinergyPay

`POST https://TU-DOMINIO/wp-json/cannaland-sinergypay/v1/payment-notification`

La URL exacta de la instalación aparece en los ajustes y se genera con `rest_url()`, respetando
subdirectorios y la configuración de enlaces permanentes (puede usar `?rest_route=...`).
No hay registro remoto automático: solicita su configuración al equipo SinergyPay.
Usa HTTPS y permite el POST externo. El endpoint no exige login/nonce: autentica la firma RSA.

El checkout solicita una orden y una CLABE de 18 dígitos, guarda los datos por CRUD y deja el
pedido `on-hold`. La página de pedido recibido y Mi cuenta muestran CLABE, importe, referencia
y vencimiento. Solo un webhook firmado y coherente puede confirmar un pago nuevo.
No hay polling, consulta de pagos, redirección de pago externo ni reembolsos automáticos.

## Seguridad y conciliación

Se usan exclusivamente las llaves públicas oficiales empaquetadas, según el entorno.
`security.key` no selecciona archivos ni URLs. No se descargan llaves durante un webhook.
La firma es RSA PKCS#1 v1.5/SHA-512 sobre la cadena documentada, conservando el texto de amount.
Se rechazan claves JSON duplicadas, campos ambiguos, firmas inválidas y datos inconsistentes.

Los POST no se reintentan automáticamente después de resultados ambiguos. Si una operación
queda en revisión, concilia primero con SinergyPay; no borres metadatos para probar de nuevo.
Un proceso terminado abruptamente puede dejar un bloqueo persistente que requiere revisión.
Los rechazos definitivos también requieren revisión antes de otro intento.

Ver [arquitectura y metadatos](docs/SINERGYPAY-ARCHITECTURE.md) y
[operación, límites y pruebas sandbox](docs/OPERATIONS.md).
El estado original del plugin de referencia permanece en `main` y el historial Git.
No se migran órdenes ni credenciales del plugin de referencia.

## Validación local y ZIP

```sh
php tests/run.php
php tests/bootstrap-smoke.php
python3 scripts/check.py
python3 scripts/build-zip.py
```

Los fixtures son sintéticos y las pruebas usan dobles de WordPress/WooCommerce. No demuestran
interoperabilidad real ni pagos exitosos en SinergyPay. Antes de producción, completa las pruebas
sandbox, HPOS real, Checkout Blocks y concurrencia con base de datos descritas en OPERATIONS.md.
