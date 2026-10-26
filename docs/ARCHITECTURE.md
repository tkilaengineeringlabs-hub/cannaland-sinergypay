# Cannaland Payments — arquitectura multimétodo 0.3.1

La arquitectura propuesta anteriormente queda implementada con las aclaraciones del usuario.
El flujo implementado es SPEI directo; tarjeta y efectivo siguen como módulos pendientes. SinergyPay permanece como proveedor de infraestructura/API. La base anterior permanece en main/historial; no hay dependencia
operativa de sus endpoints, clases, IDs, consultas, webhooks ni recursos visuales.
Slug de plugin: `cannaland-payments`; nombre general: Cannaland Payments.

## Gateways independientes

| Método | ID | Opciones | Módulo |
|---|---|---|---|
| Tarjeta | cannaland_cards | woocommerce_cannaland_cards_settings | modules/cards |
| Transferencia SPEI | cannaland_spei | woocommerce_cannaland_spei_settings | Implementación SPEI existente en includes |
| Pago en efectivo | cannaland_cash | woocommerce_cannaland_cash_settings | modules/cash |

Methods es un registro local de clases, nombres y recursos; no dirige pagos ni contiene contratos
remotos. Cada gateway tiene habilitación, título, descripción, hook de guardado y script Blocks
propios. Los módulos pendientes se registran en administración, pero is_available/is_active y
canMakePayment impiden ofrecerlos al comprador hasta que su servicio sea operativo. No hay
selector interno ni éxito simulado. Sus POST directos fallan sin efectos.

La disponibilidad pendiente es deliberada: mostrar métodos incapaces de cobrar como seleccionables
no satisface una experiencia de pago real. Los tres podrán mostrarse como opciones independientes
al completar los contratos respectivos, sin alterar SPEI. No se inventa configuración financiera
para tarjetas/efectivo. Los settings SPEI no se convierten en configuración global de esos módulos.

Tarjetas y efectivo separan gateway/UI, PaymentService y presentación Blocks. Todavía no hay
cliente API porque no hay contrato. Incorporar el cliente, captura/UX, confirmación y procesamiento
real será trabajo de cada módulo. PendingGateway es una base administrativa temporal con rechazo
seguro; cada módulo debe implementar sus propios validate_fields/process_payment al hacerlo operativo.
No deben habilitarse solo cambiando is_ready: falta implementar el flujo completo.

No se recopilan PAN/CVV ni se asumen tokenización, formularios hospedados, PCI o redirecciones.
El cliente SinergyPayClient y callback actuales siguen siendo SPEI; no se reutilizan para fingir otros productos.
El transporte/logging solo se compartirán donde los contratos realmente coincidan.

## Implementación SPEI conservada

Las siguientes secciones describen exclusivamente SPEI. Sus clases de preparación, cliente proveedor,
firma, webhook, utilidades de montos, bloqueo y persistencia solo recibieron cambios de identidad
en 0.3.0; no cambios de lógica funcional.
La versión 0.3.0 usa los IDs/opciones neutrales de la tabla anterior y mantiene Transferencia SPEI como título predeterminado.
Los títulos guardados por el comerciante se conservan.

## Fuentes y decisiones

Fuente oficial: https://sinergypay.mx/documentacion/ y su PDF V3 Comercios SPEI
https://sinergypay.mx/wp-content/uploads/2026/05/V3-Comercios-SPEI.pdf.
Llaves oficiales: https://media.sinergypay.mx/sw/public_keys.zip.
Las confirmaciones del equipo comunicadas por el usuario prevalecen: notificación como única
confirmación, sin polling; texto original de amount para firma; CLABE de exactamente 18 dígitos;
las transferencias no aceptadas se devuelven por el proveedor.

Bases HTTPS: sandbox.sinergypay.mx/v2 y api.sinergypay.mx/v2. Autenticación Basic(API_KEY:),
User-Agent y JSON. Únicas operaciones salientes: POST /orders/ y POST /orders/{id}/clabe.
Se envían amount, description, reference y expiration_minutes. Se guarda id, expiration_date
y reference_num de la respuesta de creación, y clabe de la segunda respuesta.
Llaves: sinergy_public_sb.pem/sinergy_public_prod.pem fijas según entorno; versión de firma 1.
Cadena: id|currency|amount|description|reference|date; null vacío; RSA PKCS#1 v1.5/SHA-512.

## Componentes

| Archivo | Responsabilidad |
|---|---|
| cannaland-payments.php | Entrada, carga, registro del gateway y declaraciones HPOS/Blocks |
| includes/class-support.php | Configuración, decimales exactos, metadatos, mutex y búsqueda WooCommerce |
| includes/class-sinergypay-client.php | Transporte HTTPS, JSON decimal exacto y clasificación de resultados |
| includes/class-preparation.php | Estados persistentes de creación y CLABE, snapshots y espera |
| includes/class-notification.php | JSON acotado, lexemas numéricos, claves duplicadas y RSA |
| includes/class-webhook.php | REST POST, correlación, validación e idempotencia |
| includes/class-gateway.php | Ajustes, checkout e instrucciones escapadas |
| includes/class-blocks.php y scripts de cada método | Una instancia Blocks y un registro frontend independiente por gateway |
| includes/class-methods.php | Registro local de los tres métodos |
| includes/class-pending-gateway.php | Ajustes independientes y rechazo seguro de módulos pendientes |
| modules/cards y modules/cash | Gateways, servicios y UI Blocks pendientes de contratos reales |
| resources/keys/ | Llaves públicas oficiales locales |

No hay AJAX propio. La autorización del checkout queda en los flujos estándar de WooCommerce.
No se anuncia soporte de reembolsos; el método no llama APIs financieras fuera de crear orden/CLABE.

## Checkout

1. El gateway valida disponibilidad MXN; Preparation valida moneda y estado de la orden.
2. Adquiere mutex atómico por orden. Guarda referencia UUID estable y snapshots de entorno,
   monto, moneda y descripción antes del primer POST.
3. Persiste order_state=sending; crea orden. Guarda inmediatamente el ID remoto si es válido.
   Verifica el resto de la respuesta antes de order_state=succeeded.
4. Persiste clabe_state=sending; solicita CLABE para el ID guardado. Exige string de 18 dígitos,
   conservando ceros iniciales. Guarda clabe_state=succeeded y preparation=ready.
5. Bajo el mismo bloqueo, pone el pedido en on-hold. El gateway redirige a pedido recibido.
   Las instrucciones no hacen llamadas remotas y no marcan pagado.
6. Un checkout repetido reutiliza una preparación completa. No recrea operaciones inciertas.

Importes: hasta diez dígitos enteros y dos decimales; solo MXN. La API recibe un número JSON
con decimal exacto sin float. La respuesta de creación admite amount como string decimal, entero o float JSON finito
que represente exactamente centavos al reconvertir el decimal normalizado al mismo float.
Se rechazan fracciones de centavo, valores no numéricos y valores fuera de rango. La CLABE
debe seguir siendo string de 18 dígitos y solo se exige al generar CLABE, no al crear la orden.
La normalización numérica no se utiliza para el amount RAW firmado del webhook.
Formatos no admitidos fallan de manera segura y requieren revisión; validar contra sandbox.

## Notificación

Ruta REST propia: POST /wp-json/cannaland-payments/v1/payment-notification.
La URL real se obtiene con rest_url() y aparece en los ajustes. No hay registro remoto automático.

1. Lee body RAW. Máximo 64 KiB, profundidad JSON 16; rechaza claves duplicadas y JSON malformado.
2. Recupera campos estructurales sin usarlos para buscar órdenes ni ejecutar efectos.
   amount conserva el valor string o el lexema numérico original, sin float.
3. Exige versión 1 y firma Base64 estricta. Verifica con la llave local del entorno.
   security.key se comprueba solo como campo estructural; nunca selecciona la confianza.
4. Solo después verifica formato de la notificación SPEI y busca la referencia firmada mediante
   wc_get_orders. HPOS usa meta_query; legacy usa el filtro del data store de WooCommerce.
   Exige exactamente una orden con el gateway y toma su mutex.
5. Recarga la orden. Verifica referencia, entorno, preparación, descripción, monto exacto,
   moneda y total actual. code debe coincidir con el ID remoto guardado como control adicional.
6. El identificador financiero es el ticket raíz id firmado. code, payments y Product NO están
   cubiertos por la firma: son controles de consistencia, nunca correlación o identidad financiera.
   No se inventa un campo de estado ni se confía en la CLABE como prueba de pago.
7. Un ticket ya aplicado a esa orden responde 200 sin repetir notas/payment_complete.
   Una orden ya pagada con otro ticket se rechaza. Un claim global evita usar el mismo ticket
   para dos órdenes. Se guarda confirmation_state=applying ANTES de payment_complete(ticket).
8. Tras éxito, guarda estado paid y nota segura. Si la llamada queda interrumpida sin constancia
   suficiente de éxito, requiere conciliación y no vuelve a invocar efectos de pago.

Los null se convierten en vacíos únicamente para la cadena firmada. La aceptación financiera
además exige identificadores, referencia y monto válidos y coherentes. Campos con | se rechazan
por ambigüedad del formato delimitado. No se reformatea el texto firmado ni se ordena JSON.

ACK local mínimo (sin body contractual): 200 procesado/duplicado, 400 malformado, 401 firma,
409 orden inexistente/inconsistente, 500 error interno/bloqueo/conciliación de confirmación.
Respuestas sin datos de orden. Estas convenciones deben validarse con SinergyPay en sandbox.

## Metadatos de orden (CRUD WooCommerce)

| Clave | Uso |
|---|---|
| _cannaland_payments_reference | Referencia única estable firmada y correlación principal |
| _cannaland_payments_environment | Entorno de la operación |
| _cannaland_payments_amount | Snapshot decimal normalizado de monto |
| _cannaland_payments_currency | Snapshot de moneda MXN |
| _cannaland_payments_description | Descripción enviada y esperada firmada |
| _cannaland_payments_remote_order_id | ID de orden SinergyPay |
| _cannaland_payments_reference_num | Referencia numérica del proveedor, cuando viene |
| _cannaland_payments_expiration_date | Vencimiento recibido, preservado con zona horaria |
| _cannaland_payments_clabe | CLABE validada de 18 dígitos |
| _cannaland_payments_order_state | Estado de creación de orden |
| _cannaland_payments_clabe_state | Estado de generación CLABE |
| _cannaland_payments_preparation | ready cuando terminó la preparación |
| _cannaland_payments_transaction_id | Ticket firmado de confirmación |
| _cannaland_payments_confirmation_state | applying o paid |

El transaction ID estándar de WooCommerce se guarda mediante payment_complete(ticket).
Opciones auxiliares sin autoload: cannaland_payments_order_lock_{id} (mutex) y cannaland_payments_ticket_{SHA256(env|ticket)}
(claim permanente de ticket). Ajustes: woocommerce_cannaland_spei_settings.
No hay consultas SQL directas a posts/postmeta, secretos en metadatos ni payloads persistidos.

## Estados y límites de recuperación

Por fase: ausencia (sin intento), local_error (no enviado, corregible sin riesgo remoto), sending
(persistido antes de enviar), succeeded (respuesta validada), rejected (rechazo explícito conocido)
y ambiguous (resultado incierto). Un sending encontrado tras interrupción se transforma en ambiguous.

Un WP_Error de transporte, HTTP 5xx, respuesta truncada/inválida/incompleta o timeout se considera
ambiguo. Un rechazo explícito rc no cero en 2xx o determinados 4xx se clasifica rejected;
HTML de proxy, 408 y 429 permanecen ambiguos. No hay bucles de reintento ni idempotencia remota
atribuida al proveedor. Incluso rejected necesita revisión para autorizar una nueva operación.

Ver OPERATIONS.md antes de cualquier intervención. La prevención de efectos duplicados es
conservadora: ante un fallo entre hooks y escrituras, puede sacrificar confirmación automática
para exigir conciliación. No se promete una transacción distribuida exactamente-una-vez.

## Identidad y transición 0.3.0

Producto: Cannaland Payments; descripción: Pasarela de pagos integrada para Cannaland.
Namespace principal Cannaland\Payments; text domain cannaland-payments; constantes
CANNALAND_PAYMENTS_FILE y CANNALAND_PAYMENTS_VERSION. El cliente específico del proveedor es
SinergyPayClient. La separación es producto → gateways → servicios → proveedor.
La firma y las URLs de SinergyPay se conservan; sus nombres son referencias técnicas intencionales.
Las claves de errores/lookup, locks y claims usan cannaland_payments_; el formato del valor
reference no cambia. Ver OPERATIONS.md: no hay migración automática de datos de desarrollo.
