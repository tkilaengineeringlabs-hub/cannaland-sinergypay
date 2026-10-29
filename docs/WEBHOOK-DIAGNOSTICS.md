# Diagnóstico del webhook SinergyPay

Publicado en 0.3.5: añade observabilidad, no un contrato nuevo ni un bypass de firma. Parte de 0.3.4;
no cambia creación SPEI, CLABE, claves, formato de referencia, monto RAW ni criterios de pago.
No se contactó Horsements ni SinergyPay ni se alteró el pedido real #31084.

## Registro y exposición

El archivo principal, en plugins_loaded, exige WC_Payment_Gateway y WC_VERSION >=8.2;
solo entonces instala el callback de rest_api_init que ejecuta Webhook::register().
Ruta: /cannaland-payments/v1/payment-notification; URL habitual:
/wp-json/cannaland-payments/v1/payment-notification. Método: POST; callback: Webhook::receive;
permission_callback: __return_true. No exige login, nonce ni API key para entrar: la autorización
financiera se decide por RSA/SHA-512 y correspondencia del evento. No hay args/validadores propios
previos al handler. El registro es correcto en código y en el smoke test del bootstrap.

Esto no prueba la exposición pública de Horsements: faltan dominio/URL final y evidencia de
entrega de SinergyPay Lab (destino exacto, método, hora, HTTP status y respuesta). Plugin inactivo,
WooCommerce ausente, filtros REST/auth, JSON inválido, redirects, TLS, proxy o WAF pueden impedir
el acceso. No se debe inferir de un log vacío que el proveedor no envió la solicitud.

## Logs

Habilitar Registro seguro y el logger global WooCommerce con umbral Info. Fuente:
**cannaland-payments**. Recibo/éxitos/duplicados son Info; rechazos/excepciones son Error.
Antes solo se registraban excepciones genéricas: los rechazos normales eran silenciosos.

Primer acto del handler: webhook_received, stage=webhook_entry, order_id=0,
signature_verified=false. Después: invalid_json, invalid_signature, reference_missing,
order_not_found, amount_mismatch, duplicate_webhook, payment_confirmed, webhook_rejected
o webhook_exception. Los eventos llevan stage, reason y http_status final. Solo se añade el
ID WooCommerce después de verificar la firma y localizar la orden. Nunca se registran valores
de referencia, CLABE, importe, firmas, security.key, API keys, Authorization ni cuerpos.
Las estructuras se describen mediante nombres de campos permitidos y tipos, no valores.

rest_post_dispatch observa pasivamente el resultado de esta ruta POST, sin modificarlo.
WordPress puede rechazar JSON antes del handler: invalid_json con stage=webhook_dispatch
indica rest_invalid_json del core. Otros fallos REST producen webhook_dispatch_rejected;
puede ser también un resumen adicional de un rechazo del handler. Sin webhook_received no
se ha demostrado entrada al callback. Este filtro no ve bloqueos del proxy/WAF anteriores a WP.
No desactiva autenticación ni validaciones. No registra mensajes arbitrarios de otros plugins.

Código oficial WordPress: has_valid_params invoca parse_json_params antes del callback:
https://developer.wordpress.org/reference/classes/wp_rest_request/has_valid_params/

## Prueba segura en una instalación WordPress

Ejecutar internamente con WP-CLI, con WordPress y el plugin cargados:

    wp eval-file scripts/probe-webhook.php

Es un despacho REST interno POST, no una petición externa a Horsements. El body fijo es:

    {"probe":"cannaland-payments-reachability"}

No tiene referencia, pedido, monto ni security/firma. Se rechaza antes de buscar órdenes y no
puede pagarlas. No añade modo de prueba a la ruta ni omite criptografía. No cambia settings.
Resultado esperado del handler: HTTP 401, datos de respuesta null; eventos webhook_received e
invalid_signature, reason=security_missing. Un filtro anterior puede devolver otro status:
se debe investigar, no modificar el probe para forzar éxito. El script no imprime bodies ajenos.
No pudo ejecutarse sobre WordPress real aquí: no hay instalación WP/WP-CLI disponible.
Sí se ejecutó su código con dobles REST y se verificó que no consulta ni paga pedidos.

En HTTP externo, enviar ese mismo JSON sin cookies, Authorization ni nonce permite probar
la llegada de manera segura. En WordPress estándar, datos null producen cuerpo vacío, no el
texto JSON "null". No cambia el ACK existente. Referencia oficial:
https://developer.wordpress.org/reference/classes/wp_rest_server/serve_request/

## HTTP local ejecutado

    python3 tests/http-webhook-test.py

Servidor PHP limitado a 127.0.0.1; usa el bootstrap real del plugin y su callback registrado,
con routing/transporte WordPress simulados, sin base de datos. Cualquier lectura/escritura de
órdenes provoca error; todas las pruebas negativas terminaron sin esos accesos.

- POST JSON de prueba sin firma: 401, cuerpo vacío, webhook_received -> invalid_signature.
- POST JSON malformado al handler del adaptador: 400, cuerpo vacío,
  webhook_received -> invalid_json. En WP real el core puede interceptarlo previamente y
  devolver su propio objeto rest_invalid_json; ese caso se cubre aparte con el filtro pasivo.
- POST con nombres de campos sugeridos y sin firma válida: 401, cuerpo vacío,
  webhook_received -> invalid_signature.
- GET: el adaptador lo rechaza antes del handler. No prueba el routing real de WordPress.

La prueba de transporte HTTP local no valida DNS/TLS/WAF de Horsements ni entrega del proveedor.

## Compatibilidad potencial del payload indicado

La lista id, currency, amount, reference, reference_num, payments, security, code, clabe no
basta para confirmar compatibilidad. Estos son los requisitos actuales, no nuevos supuestos:

| Área | Requisito actual / posible incompatibilidad |
| --- | --- |
| Campos firmados raíz | id, currency, amount, description, reference, date deben existir. description/date ausentes fallan. null se convierte a vacío para la firma; fechas/referencia/monto vacíos no pagan. |
| amount | Texto o número JSON cuyo lexema se conserva exactamente; no se reconstruye desde float. Tras firma se compara a precisión de centavos con metadata y total WooCommerce. |
| security | Objeto con version numérico exactamente 1, key string y signature Base64 canónica. version string "1", campos anidados de otra forma o firmas con otro encoding fallan. key no selecciona archivos. |
| RSA | PKCS#1 v1.5 + SHA-512 y llave oficial del entorno; cadena id\|currency\|amount\|description\|reference\|date, sin cambios. |
| reference | csp-ID-UUID y coincidencia exacta con metadata. Solo después de firma se utiliza para búsqueda HPOS/CRUD. |
| payments | Array no vacío; cada elemento tiene id string válido y Product.id o product.id numérico 22. Se admiten ambos casing; si ambos aparecen se validan los dos. id string "22", omisión o contenedores inválidos se rechazan. |
| code | String igual a remote_order_id guardado. No se usa para seleccionar la orden. Confirmar con payload real qué representa. |
| id raíz | Actualmente identifica el ticket firmado y transaction ID. Hay que comprobar esa semántica con la notificación real; no sustituirlo por payments[].id sin evidencia. |
| reference_num / clabe | Son extras admitidos; no sustituyen reference ni demuestran pago. No se usan para confirmar. |

No se relajó ninguno de estos requisitos. Para adaptar el contrato hace falta la notificación
RAW exacta por un canal seguro, tipos/anidamiento, documentación oficial aplicable al entorno y
respuesta de entrega. No pegar firmas/datos sensibles en logs o Git. El diagnóstico registra
solo tipos y motivos. Una lista de claves o un fixture sintético no prueba interoperabilidad.

La confirmación válida conserva: ticket único por entorno, bloqueo por orden, checks de monto,
moneda, descripción, estado y vínculo remoto; payment_complete una sola vez; duplicado ya
confirmado => HTTP 200 sin repetir efectos. Los tests positivos usan RSA sintética y pedidos
en memoria, nunca la orden #31084. El webhook real sigue PENDIENTE de validación.

## Evidencia real #31087 y diagnóstico de Product/product (0.3.6)

El cliente confirmó en Horsements: webhook_received, luego webhook_rejected en webhook_shape,
reason=payment_product_invalid, signature_verified=true, HTTP 400; finalmente el resumen
webhook_dispatch_rejected. Esto confirma exposición pública, entrega Lab y verificación RSA
para ese intento. No confirma la estructura de payments ni una orden pagada mediante webhook.

La condición actual agrupa cinco comprobaciones: elemento payments[] objeto, su id string
válido según Support::id, Product (P mayúscula) objeto, Product.id número JSON preservado
como JsonNumber y texto numérico exactamente '22'. No busca product en minúscula ni convierte
'22' string o 22.0 a 22. No se cambió esta condición.

Al rechazar, y solo después de firma válida, el mismo evento incorpora payment_product_shape:
- index: índice del elemento rechazado (base cero).
- payment_type / payment_id_type.
- Product_type / Product_id_type (mayúscula).
- product_type / product_id_type (minúscula, solo observación).
- failed_check: payment_not_object, payment_id_invalid, Product_not_object,
  Product_id_not_number o Product_id_not_expected_token.

Tipos posibles: missing (campo ausente o padre no objeto), null, string, json_number,
object, array, boolean. JsonNumber es la representación interna que conserva el lexema JSON;
no se registra ese lexema ni ningún ID. El último chequeo solo informa que no coincide con
el token requerido actualmente, nunca el valor recibido. No se enumeran otras claves anidadas.
No se registran referencias, CLABE, firmas, key, tokens, credenciales ni payloads.
El bloque anidado no está incluido en la cadena de firma actual: se observa su forma, sin
tratar sus valores como autenticados ni usarlos para seleccionar la orden.

Aún no se recibió la estructura real del elemento rechazado. Por eso los fixtures nuevos
representan hipótesis controladas (casing, string/número, campos ausentes, null, arrays, etc.),
no una reproducción atribuida a SinergyPay. Hace falta un reenvío controlado con este código
instalado y el bloque payment_product_shape del log para decidir si existe evidencia suficiente
para cambiar la validación. No se alteraron referencias, conciliación, creación, CLABE ni RSA.


## Corrección confirmada: product en minúscula

El cliente aportó la forma real observada tras firma válida: payment_type=object,
payment_id_type=string, Product ausente, product objeto y product.id json_number;
el chequeo que falló fue Product_not_object. Esa evidencia justifica admitir product
además de Product (variante publicada en el PDF). No se considera un payload RAW completo.

Webhook::product_error valida únicamente los dos nombres permitidos, exige contenedor
objeto e id JsonNumber con el mismo token 22 que antes. Si ambos están presentes, ambos
deben ser válidos; no hay fallback de un contenedor inválido hacia otro válido. No se cambia
firma, asociación, comparación monetaria, snapshots ni idempotencia. El diagnóstico de un
rechazo minúsculo usa product_not_object/product_id_not_number/product_id_not_expected_token.

Pruebas sintéticas integradas: cada casing y ambos válidos llegan por el callback de producción
a payment_confirmed; el mismo evento repetido devuelve duplicate_webhook sin efectos extra.
También se prueban contenedores ausentes/inválidos, IDs distintos, variantes contradictorias
y rechazo de firma/monto/moneda/referencia/code incorrectos con product minúsculo.
No se cambia creación SPEI ni CLABE, no se hacen llamadas financieras y no se genera ZIP.
Falta comprobar la confirmación final del pedido con el webhook real una vez instalado el cambio.
