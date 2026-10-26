# Cannaland Payments

Pasarela de pagos integrada para Cannaland.
SinergyPay se utiliza como proveedor de infraestructura/API para SPEI.
Versión 0.3.0. Requiere WordPress 6.2+, WooCommerce 8.2+, PHP 8.0+ y OpenSSL.
Incluye registro independiente en checkout clásico y WooCommerce Blocks; no hay selector interno.

| Método | Gateway ID | Estado actual |
|---|---|---|
| Tarjeta | `cannaland_cards` | Estructura; sin procesamiento ni captura de tarjetas |
| Transferencia SPEI | `cannaland_spei` | Flujo implementado; validación sandbox real pendiente |
| Pago en efectivo | `cannaland_cash` | Estructura; sin generación de referencias o cobros |

Los tres aparecen por separado en WooCommerce → Ajustes → Pagos, con habilitación, título y
descripción independientes. Tarjetas y efectivo permanecen no disponibles para compradores,
aunque se guarde su habilitación, hasta implementar sus contratos reales. Su llamada directa
falla de forma segura: no modifica pedidos, no envía solicitudes ni devuelve éxito simulado.
La arquitectura los mostrará como opciones separadas en clásico y Blocks cuando sean operativos.
No se presenta su estructura como si ya fueran métodos utilizables.

## Instalación y configuración

Instala `dist/cannaland-payments.zip` desde Plugins → Añadir plugin → Subir plugin.
Activa WooCommerce y Cannaland Payments. En WooCommerce → Ajustes → Pagos → Transferencia SPEI configura:

- Habilitar/deshabilitar, título y descripción.
- Entorno `sandbox` o `production` y su API key **privada**, mediante el administrador de WordPress.
- `expiration_minutes`: entero positivo; predeterminado 8640.
- Logging seguro opcional, desactivado por defecto. Fuente: `cannaland-payments`.
- Moneda de tienda y pedido: MXN.

La versión 0.3.0 usa IDs y opciones neutrales. No reutiliza automáticamente los ajustes ni los
pedidos de versiones de desarrollo anteriores. Revisa la transición descrita en OPERATIONS.md
antes de reemplazar una instalación existente.

La API key se guarda en `woocommerce_cannaland_spei_settings`, protegida por los controles
administrativos de WooCommerce y mostrada como password. No se cifra en la base de datos:
protege administradores, base de datos y respaldos. No la añadas al código ni al repositorio.

## Callback que debes proporcionar a SinergyPay

`POST https://TU-DOMINIO/wp-json/cannaland-payments/v1/payment-notification`

La URL exacta de la instalación aparece en los ajustes y se genera con `rest_url()`, respetando
subdirectorios y la configuración de enlaces permanentes (puede usar `?rest_route=...`).
No hay registro remoto automático: solicita su configuración al equipo SinergyPay.
Usa HTTPS y permite el POST externo. El endpoint no exige login/nonce: autentica la firma RSA.

El checkout SPEI solicita una orden y una CLABE de 18 dígitos, guarda los datos por CRUD y deja el
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

Ver [arquitectura y metadatos](docs/ARCHITECTURE.md) y
[operación, límites y pruebas sandbox](docs/OPERATIONS.md).
El estado original del plugin de referencia permanece en `main` y el historial Git.
No se migran órdenes ni credenciales del plugin de referencia.

## Validación local y ZIP

```sh
php tests/run.php
php tests/bootstrap-smoke.php
node tests/blocks.test.js
python3 scripts/check.py
python3 scripts/build-zip.py
```

Los fixtures son sintéticos y las pruebas usan dobles de WordPress/WooCommerce. No demuestran
interoperabilidad real ni pagos exitosos en SinergyPay. Antes de producción, completa las pruebas
sandbox, HPOS real, Checkout Blocks y concurrencia con base de datos descritas en OPERATIONS.md.

## Extensión de tarjetas y efectivo

Cada módulo en `modules/cards/` y `modules/cash/` tiene su gateway, servicio de negocio y script
Blocks. El registro compartido en `Methods` contiene solo metadatos locales, no contratos API.
Los métodos guardan sus opciones en `woocommerce_<gateway_id>_settings`; no leen credenciales SPEI.
No se añadieron clientes remotos ficticios, endpoints, formularios PAN/CVV, SDKs ni redirecciones.
El cliente `SinergyPayClient` actual continúa siendo exclusivo SPEI.

Para activar tarjetas faltan documentación/API oficial, UX permitida, tokenización o campos
hospedados si corresponden, autenticación/notificaciones reales y definición PCI. Para efectivo
faltan documentación/API, emisión y presentación de comprobantes/referencias y confirmación.
Sus adaptadores UI y servicios se extenderán dentro de cada módulo; SPEI no necesita cambiar.

## Diagnóstico SPEI

El ZIP de diagnóstico mantiene la versión 0.3.0 y agrega el marcador de log `diagnostics=spei-v1`.
Con Registro seguro habilitado, buscar fuente `cannaland-payments` en WooCommerce > Estado >
Registros. El logger global debe estar activo y su umbral debe permitir Info para ver los hitos;
los fallos se registran como Error. Ver OPERATIONS.md para eventos, campos y límites de privacidad.
Las trazas no incluyen cuerpos, encabezados, CLABE, API keys ni textos externos arbitrarios.
No representan una prueba real exitosa de sandbox ni autorizan reintentar un POST ambiguo.
