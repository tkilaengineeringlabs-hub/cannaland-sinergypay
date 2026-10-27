# Cannaland Payments — operación y validación pendiente

## Estado multimétodo

Los tres métodos se registran por separado en administración, clásico y Blocks. Solo SPEI
tiene procesamiento implementado. Tarjetas/efectivo tienen ajustes independientes y estructura
UI/negocio; habilitar su casilla no los vuelve seleccionables hasta contar con integración real.
Sus solicitudes directas se rechazan sin llamadas API, redirecciones ni cambios en órdenes.
Tarjetas requiere contrato y definición PCI; efectivo requiere contrato de emisión y confirmación.
No se debe desplegar una simple modificación de is_ready para ofrecerlos: implementar y probar
por completo sus respectivos módulos primero.

Las operaciones y validaciones siguientes son SPEI. Su flujo no fue reemplazado.

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

Revisar metadatos _cannaland_payments_order_state, _cannaland_payments_clabe_state y las notas del pedido. Si hay ambiguous o
sending interrumpido, consultar al equipo SinergyPay usando la referencia estable y el ID remoto
si existe. NO borrar el ID ni resetear el estado para volver a probar un POST.
No usar la existencia de una CLABE/transferencia como confirmación de pago.

Para un rechazo definitivo, corregir su causa y revisar con el proveedor antes de autorizar otro
intento. Esta versión no ofrece un botón de reset: la recuperación es administrativa, mediante
CRUD WooCommerce por personal autorizado y con evidencia del proveedor.

Un proceso abortado puede dejar cannaland_payments_order_lock_{id}. Antes de eliminarlo, comprobar que no hay
un worker activo ni una operación financiera pendiente de conciliación. No tiene TTL ni takeover
que pueda permitir dos envíos simultáneos. Los claims cannaland_payments_ticket_* no deben borrarse durante
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
`node tests/blocks.test.js`: scripts Blocks reales con dobles Woo/React; tres registros y
disponibilidad separados, sin selector. No es una prueba de navegador.
`python3 scripts/check.py`: lint PHP, sintaxis JS, diff y búsqueda de dependencias excluidas.

Las pruebas locales no prueban interoperabilidad con SinergyPay ni compatibilidad integral de
WooCommerce/HPOS/Blocks. No se afirma que se haya realizado un pago real.

## Transición de desarrollo a identidad 0.3.0

La carpeta y entrada instalables son cannaland-payments/cannaland-payments.php. Los tres IDs y
opciones cambian a cannaland_cards, cannaland_spei y cannaland_cash y sus opciones
woocommerce_<gateway_id>_settings. El namespace es Cannaland\Payments, el text domain y fuente
segura de logging son cannaland-payments y los metadatos usan _cannaland_payments_.

Callback nuevo: POST /wp-json/cannaland-payments/v1/payment-notification. Debe proporcionarse
a SinergyPay antes de probar las notificaciones; no hay alias de la ruta anterior.

Este cambio reemplaza identificadores en código. No ejecuta una migración automática de una base
de datos de WordPress ni convierte órdenes existentes. Reconfigurar los tres ajustes en staging.
Si hay pedidos de desarrollo pendientes, conciliarlos antes de reemplazar el plugin; cualquier
traslado de sus metadatos, gateway, bloqueos y claims debe preservar íntegramente referencias,
IDs remotos, entorno, monto y evidencia de idempotencia mediante CRUD y revisión administrativa.
No reiniciar operaciones ni usar el cambio de nombre para generar una segunda orden remota.
No activar ambas versiones del producto sobre las mismas operaciones pendientes.

El valor opaco reference conserva su formato existente csp-ID-UUID: se transmite y firma como
dato del proveedor. Solo cambia el nombre local de su metadato. Las llaves públicas oficiales,
campos de payload, rutas remotas y procedimiento criptográfico no se renombraron.

## Diagnóstico SPEI seguro — build spei-v1

En Transferencia SPEI, habilitar Registro seguro y guardar. En WooCommerce > Estado > Registros,
filtrar por la fuente EXACTA `cannaland-payments`. La configuración general del logger de
WooCommerce también debe estar habilitada; para ver los avances usar umbral Info o sin umbral.
Un umbral Error muestra fallos pero no hitos Info; un umbral más alto puede ocultar también errores.
No se modifica automáticamente la configuración global ni se fuerza la escritura en archivos ajenos.
Referencia oficial: https://developer.woocommerce.com/docs/best-practices/data-management/logging/

Un intento nuevo completo produce, en orden:

1. process_payment_started
2. reference_prepared
3. create_order_request
4. create_order_response
5. remote_order_saved
6. create_clabe_request
7. create_clabe_response
8. clabe_saved
9. order_on_hold
10. process_payment_success

La página de instrucciones agrega instructions_started e instructions_displayed (o instructions_skipped).
Una preparación reutilizada agrega preparation_reused y NO vuelve a generar orden/CLABE.
Los eventos *_saved significan que regresó la llamada CRUD sin excepción, no certifican por sí
solos el almacenamiento externo ni la interoperabilidad sandbox.

Los fallos generan preparation_failed, preparation_exception, process_payment_failed o
process_payment_exception (o instructions_exception en presentación); las respuestas HTTP fallidas también usan nivel Error. Buscar el
order_id y leer el PRIMER fallo específico; process_payment_failed es el resumen final.
El campo diagnostics=spei-v1 distingue este ZIP de una instalación anterior con la misma versión.

Campos seguros: stage, order_id, endpoint lógico (sin ID remoto), http_status cuando existe,
wp_error_code conocido, rc numérico acotado, response_type, claves conocidas de respuesta,
tipos de campos, reason, result, certainty y stored_state cuando corresponda.
No se registran valores de referencia, CLABE, montos, datos de clientes, headers ni cuerpos.
Mensajes del proveedor y códigos externos desconocidos se omiten; solo se permiten mensajes
literales conocidos. De WP_Error se conserva un código conocido y número cURL, no su texto libre.
Los Throwable conservan clase y una categoría de mensaje segura; textos arbitrarios, SQL, rutas
anónimas o secretos en excepciones se omiten. El logger nunca recibe payload/objeto de excepción
completo ni backtrace. Si el logger falla, no se altera el flujo ni se repite una operación financiera.

result: success/local_error/rejected/ambiguous/blocked/reused/in_progress/not_sent.
certainty distingue confirmed, definitive_local, definitive_rejection, ambiguous y not_attempted.
Un 5xx, timeout o estructura inesperada continúa siendo ambiguo; el diagnóstico no cambia esa regla.

Corrección 0.3.1: la evidencia sandbox de Horsements mostró HTTP 200, rc=0, msg=Ok,
ID string y amount double, sin CLABE en la creación. La validación local exigía string
y bloqueaba una orden remota ya creada. Ahora admite enteros y floats finitos válidos
a precisión de centavos, además de strings decimales. CLABE solo se valida en su propia respuesta.

Ejemplos de errores aún rechazados:
- order_response_validation + amount_type_invalid: importe inválido, no finito, fuera de rango
  o con fracciones de centavo; amount_mismatch: importe válido distinto al pedido.
- clabe_response_validation + invalid_clabe_length/type/characters: CLABE inválida, sin mostrar su valor.
- order_state_validation/clabe_state_validation + phase_blocked: operación previa requiere revisión.
- remote_order_persistence/clabe_persistence + preparation_exception: fallo de la llamada CRUD.
- redirect + process_payment_exception: el fallo ocurrió después de preparar el pago, al producir el retorno.

El código anterior solo registraba cuatro eventos genéricos de nivel Info; devoluciones tempranas y
validaciones de respuesta eran silenciosas. Eso es una deficiencia de observabilidad demostrada,
separada del bug de tipo de importe confirmado por la evidencia de Horsements. El cliente confirmó posteriormente con 0.3.1 el éxito real de creación de orden, CLABE
y checkout hasta process_payment_success. El webhook real aún requiere validación. Un pedido ambiguo debe conciliarse: no borrar
sus estados ni sus identificadores para forzar un POST. Este build no recupera respuestas históricas.
No se hicieron llamadas financieras reales durante este cambio.


## Presentación de pedidos y emails — 0.3.2

- Hooks existentes: woocommerce_thankyou_cannaland_spei y woocommerce_view_order.
  Solo cambia su presentación; se conservan los guardas y eventos de diagnóstico.
- woocommerce_email_before_order_table (4 argumentos): único punto de inserción en emails
  HTML/texto plano del comprador; excluye emails administrativos, otros gateways, pedidos
  pagados/cancelados/fallidos y preparaciones incompletas. Solo hay instrucciones cuando
  preparation=ready, CLABE válida y estado pending/on-hold.
- woocommerce_email_order_details (prioridad 1): inicia el ámbito de un render de email.
  Una clave por objeto email, pedido y formato evita duplicar instrucciones dentro de ese
  render. Un nuevo render permite el reenvío legítimo y multipart HTML/texto independiente.
- woocommerce_admin_order_data_after_order_details: consulta de solo lectura con capability
  edit_shop_order para ese pedido; funciona con el objeto WC_Order tanto en HPOS como clásico.

Se leen _cannaland_payments_preparation, order_state, clabe_state, confirmation_state,
clabe, amount, currency, reference, reference_num, remote_order_id y expiration_date
(todas con el mismo prefijo _cannaland_payments_). No se escriben ni migran datos.
El ID remoto solo aparece en administración. Las referencias existentes se conservan, sin
cambiar cuál se envía a SinergyPay ni cuál se firma. No se añaden datos sensibles.

expiration_date ISO (incluidos microsegundos y offset) se interpreta como un instante y se
muestra con wp_date(date_format + time_format, timestamp, wp_timezone()). La localización
la aporta WordPress; no se fija UTC-6. El valor original permanece intacto. Para formato largo
en español, configurar idioma español y el formato de fecha/hora deseado en Ajustes > Generales.
wc_price aporta símbolo y separadores configurados en WooCommerce; se muestra el código MXN.
En 0.3.2 la selección de texto permitía copiar CLABE/referencia sin controles adicionales.
0.3.3 añade los controles descritos abajo, sin frameworks.

Las pruebas de emails/admin son locales con dobles de WordPress/WooCommerce: falta comprobar
la apariencia final en el tema real de Horsements, entrega SMTP y sus plantillas de correo.
Las plantillas personalizadas deben conservar los hooks estándar de WooCommerce.
Creación de orden, CLABE y checkout de 0.3.1: probados en Sandbox real por el cliente.
Confirmación por webhook real: PENDIENTE. Este cambio no envía notificaciones ni marca pagos.


## Tarjeta y portapapeles — 0.3.3

Datos para transferencia SPEI presenta beneficiario Cannaland, CLABE, referencia completa,
importe MXN y fecha localizada. La referencia del proveedor, si ya existe, se muestra aparte
como información secundaria; Copiar referencia copia exclusivamente reference sin anotaciones.
Beneficiario es una constante central de presentación, no una modificación del titular bancario.
Los botones usan datos visibles (sin JSON oculto, secretos ni IDs internos) y solo actúan dentro
de su componente. Copiar datos copia las cinco filas principales con sus etiquetas.

navigator.clipboard.writeText es la primera opción. Sin soporte o si se rechaza, se intenta
un textarea temporal readOnly con execCommand('copy'), se retira y restaura el foco. Si el
navegador también bloquea ese fallback, se anuncia la alternativa de copiar manualmente;
no se anuncia éxito falso. Copiado ✓ vuelve a Copiar/Copiar datos tras 2,2 segundos.
Controles nativos type=button, aria-label específico, foco visible y región aria-live.
Los controles están ocultos hasta que arranca JS; sin JS se mantiene el texto seleccionable.
Los emails jamás incluyen los controles, atributos de copia ni JavaScript.

El CSS se limita a .cannaland-payments-spei. Hereda colores y tipografía, usa bordes suaves,
importe destacado y breakpoint de 600px. minmax(0,1fr), overflow-wrap:anywhere y word-break
permiten mostrar referencia/CLABE completas. Botones con altura mínima de 44px y acción
principal a ancho completo en móvil. No se modifican estilos globales ni el resto del pedido.

Pruebas de portapapeles con dobles DOM: valores exactos por campo, copia de conjunto, aislamiento
entre pedidos, feedback/restauración, fallback por falta de API o permisos, limpieza/foco y errores.
No equivalen a una prueba con navegadores/clientes de correo reales. Falta revisar apariencia
con el tema Horsements y compatibilidad del portapapeles en sus navegadores objetivo.
No se hicieron llamadas financieras ni se añadió un evento de webhook en esta tarea.


## Identidad visual web — 0.3.4

El CSS de la tarjeta deja de heredar colores del tema: fondo #171b1f/#20262b, blanco principal,
#c7cdd1 secundario y #9BE22D para importe, acento, foco y botones principales/copiados.
Bordes blancos suaves, radio 14px y sombra ligera. Vencimiento y nota usan cajas verdes sutiles.
Todos los selectores están bajo .cannaland-payments-spei, incluidos hover/focus y media queries;
no hay reglas globales para botones, tablas ni WooCommerce. En móvil <=600px se apilan label,
valor y botón (44px mínimo). La referencia se mantiene íntegra con wrapping.
Solo se añade data-copy-state al feedback actual del JS para aplicar el estilo de copiado;
no se cambian portapapeles, valores ni tiempos. La nota final web usa «confirmación válida de
pago». No se modifican las ramas de renderizado de email ni se les aplica este CSS oscuro.
Validación local automatizada; apariencia final con el tema Horsements pendiente de revisión visual.


## Investigación de recepción webhook

Ver [WEBHOOK-DIAGNOSTICS.md](WEBHOOK-DIAGNOSTICS.md): ruta, eventos desde entrada, rechazos
del core, probe interno sin firma, pruebas HTTP loopback y requisitos potencialmente incompatibles.
El diagnóstico no confirma accesibilidad pública ni recepción real de SinergyPay Lab.
