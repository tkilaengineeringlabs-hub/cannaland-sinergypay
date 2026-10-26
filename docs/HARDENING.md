# Hardening del plugin Dapp original

> Documento histórico. El objetivo Dapp y su lookup pendiente quedaron sustituidos por la
> integración directa descrita en [SINERGYPAY-ARCHITECTURE.md](SINERGYPAY-ARCHITECTURE.md).
> No continuar el desarrollo del flujo Dapp.

Estado: revisión parcial; NO lista para producción. No cambia los endpoints ni implementa SinergyPay.

## Bloqueo de confirmación

Se eliminó la aprobación por presencia de `data`, incluido el bypass `CHECKOUT_TEST`.
La consulta autenticada sigue ejecutándose, pero no se llama a `payment_complete()` para pagos nuevos.
Falta verificar el contrato de GET `/dapp-codes/{id}/payment`: identificador, vínculo con código/orden,
estado liquidado, monto, moneda, unidades monetarias, reversos y errores funcionales.
No se inventó un campo `status` ni se adoptó el JSON de una notificación como contrato de esa consulta.

Fuente consultada: https://dapp.mx/commerce/ecommerce/pagos (secciones generar código y notificación).
Esa página describe una notificación con detalles de transacción y un bloque de seguridad;
no se implementó una firma sin verificar el procedimiento ni se confió en campos del webhook.
La UI de ajustes advierte del bloqueo. El webhook devuelve fallo para confirmaciones nuevas,
permitiendo que el proveedor reintente según su política (aún no verificada).

## Autorización y transporte

AJAX exige nonce del gateway, ID entero positivo, orden existente con ese gateway y pendiente de pago.
Órdenes de clientes registrados exigen sesión del propietario; las de invitados exigen order key.
El frontend transmite la key del recibo. No se autoriza un usuario distinto por conocer solo el ID.
Los mensajes de error se insertan como texto; no se muestran cuerpos del proveedor.

HTTP usa timeout de 20 segundos y cero redirecciones para no reenviar autenticación a otro destino.
Acepta 2xx; 204 vacío se representa como array vacío, insuficiente para crear/confirmar un pago.
Errores de red, HTTP y JSON retornan WP_Error sin copiar datos externos sensibles.
Solo se registran eventos fijos y códigos HTTP, exclusivamente con logging habilitado.
La validación fallida y la desactivación ya no borran credenciales.
PHP mínimo anunciado corregido a 8.0 por el uso preexistente de `mixed`.

## Exclusión mutua e incertidumbre de creación

No se usa un transient ni un read-then-write de metadatos como mutex.
Se usa `add_option` de WordPress con nombre exclusivo por orden, apoyado en el índice único
`option_name`; los datos de pago siguen usando CRUD de órdenes WooCommerce, también con HPOS.
El mismo mutex serializa creación y procesamiento del webhook/página de agradecimiento.
Se libera en `finally`. No hay expiración ni takeover automático que permita solapar procesos lentos.
Si el proceso muere, puede quedar un bloqueo: requiere revisión operativa antes de eliminarlo.

Antes del POST se guarda `_DAPP_CREATION_PENDING=yes` usando WooCommerce. Si el resultado es ambiguo,
no se repite el POST, incluso después de liberar el mutex. No se atribuye idempotencia remota a Dapp.
El ID recibido se guarda antes de validar el enlace para evitar recreación por URL incompleta.
Si existe ID se consulta y exige que la respuesta corresponda al mismo código.
Se permiten enlaces HTTPS `/c/{id}` en los hosts Dapp del ambiente; sandbox cambia solo el host,
no realiza un reemplazo global. Nuevos hosts/rutas necesitarán evidencia del proveedor.

Conciliación manual: localizar el código real en Dapp y vincularlo a la orden usando WooCommerce.
Antes de retirar `_DAPP_CREATION_PENDING` o `dappwoo_order_lock_{order_id}`, comprobar que no queda
un proceso activo ni un código creado. No hay botón de reintento automático que eluda esa revisión.

Una orden ya pagada con código y ticket Dapp almacenados se reconoce sin repetir consultas,
notas ni `payment_complete()`. No certifica retroactivamente la validación del plugin antiguo.

## HPOS

La búsqueda usa `wc_get_orders`, rechaza resultados ambiguos y filtra por gateway.
HPOS usa `meta_query`; almacenamiento legacy usa el filtro documentado del data store CPT.
No hay SQL directo a postmeta. Se conserva la declaración HPOS.
Fuente: https://developer.woocommerce.com/docs/features/orders/wc-get-orders/
Requiere probar ambos almacenes en WooCommerce real antes del despliegue.

## Credenciales y pruebas

El valor literal de tests/config.php se sustituyó por DAPP_TEST_API_TOKEN o un placeholder.
Si era una credencial real debe rotarse. Sigue en el historial Git; no se reescribió la base.
Los logs antiguos tampoco se borraron y pueden contener credenciales.

`php tests/hardening/run.php`: harness offline sin dependencias externas, con dobles de WordPress,
WooCommerce y HTTP. Cubre autorización, JSON, red, HTTP, logging, lookup HPOS/legacy,
reentrada simulada durante HTTP, incertidumbre de creación y notificaciones repetidas.
No constituye prueba multiproceso con MySQL, integración WordPress ni pago real.

La suite legacy requiere WordPress/WooCommerce/PHPUnit externos y su bootstrap rechaza PHP 8+.
Contiene aserciones antiguas incompatibles con el hardening (incluida aprobación simulada y logs
arbitrarios); no se considera validada ni se afirman resultados de esa suite.
Para completar: fixtures reales anonimizadas del lookup, validador de liquidación y pruebas de
aprobación/monto/moneda/reverso, integración HPOS/legacy, concurrencia real y sandbox.
