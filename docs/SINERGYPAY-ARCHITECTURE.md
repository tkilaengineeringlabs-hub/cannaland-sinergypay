# Cannaland: integración directa SinergyPay

Decisión del usuario: Dapp sirve únicamente como referencia de arquitectura WooCommerce.
El pendiente de validación del lookup Dapp queda cancelado y no condiciona este producto.
Esta revisión documenta el diseño; no constituye una implementación SinergyPay operativa.

## Evidencia

- Confirmaciones directas del equipo, aportadas por el usuario: notificación como única confirmación;
  no integrar GET /payments ni polling; amount conserva su texto original para la firma;
  CLABE de 18 dígitos; las transferencias no aceptadas se devuelven automáticamente.
- Fuente oficial localizada: https://sinergypay.mx/documentacion/
- Contrato enlazado: https://sinergypay.mx/wp-content/uploads/2026/05/V3-Comercios-SPEI.pdf
- Llaves públicas: https://media.sinergypay.mx/sw/public_keys.zip
- No apareció documentación SinergyPay en el árbol del repositorio ni en las búsquedas de Drive.

Contrato resumido: bases sandbox.sinergypay.mx/v2 y api.sinergypay.mx/v2 por HTTPS.
Basic(API_KEY:), User-Agent obligatorio, JSON. POST /orders/ requiere amount y description;
reference, expiration_minutes, success_page y error_page son opcionales. Devuelve rc/msg/data
con id, creation_date, expiration_date, description, amount, currency, reference_num.
POST /orders/{order_id}/clabe devuelve data.clabe; no se describen parámetros adicionales.
Expiración predeterminada: seis días. Webhook POST JSON, con ticket id, code, reference,
reference_num, amount, currency, description, date, payments y security.
Firma versión 1: id|currency|amount|description|reference|date, null como vacío;
RSA PKCS#1 v1.5 con SHA-512. security contiene key, version y signature.

## Diseño propuesto (decisiones locales, no contrato del proveedor)

1. Gateway independiente `wc_cannaland_sinergypay`, namespace `Cannaland\SinergyPay`,
   opciones propias y dominio de traducción `cannaland-sinergypay`.
2. Checkout valida MXN, orden pendiente y datos mínimos; crea referencia aleatoria única
   persistida por CRUD, ligada a la orden y al ambiente antes de cualquier solicitud externa.
3. Bajo mutex atómico, crea la orden remota. Guarda inmediatamente el identificador recibido,
   monto/moneda esperados y fecha de vencimiento. Nunca convierte una respuesta incierta
   en permiso para repetir la creación.
4. Solicita CLABE para ese identificador. Solo acepta un string de exactamente 18 dígitos.
   La generación de CLABE puede tener incertidumbre propia: no asumir que repetir POST es idempotente.
5. Muestra instrucciones SPEI, CLABE escapada, monto, moneda y vencimiento.
   No vacía o paga la orden por navegar, recibir una CLABE ni visitar la página de agradecimiento.
6. Callback propio lee bytes RAW una vez. Un parseo estructural limitado permite recuperar los
   campos de firma sin usarlos todavía para localizar órdenes, elegir URLs o producir efectos.
   amount se conserva como texto; no pasa por float/int antes de verificar.
7. Selecciona exclusivamente una llave pública local de confianza para el ambiente y verifica
   el procedimiento del proveedor. Nunca descarga una llave elegida por el request.
8. Solo después de verificar, busca la referencia firmada por APIs WooCommerce, exige exactamente
   una orden del gateway/ambiente y adquiere el mismo mutex de esa orden. Recarga su estado.
9. Verifica referencia, monto exacto en decimal y moneda frente a los valores guardados;
   verifica correspondencia de identificadores según el alcance firmado real.
10. Si ya está pagada con ese ticket, reconoce el duplicado sin notas ni efectos adicionales.
    Si ya está pagada con otro ticket, rechaza y requiere conciliación.
11. Para un evento oficial válido, llama payment_complete(ticket), guarda evidencia mínima de
    idempotencia mediante CRUD y una nota sin secretos. Los detalles HTTP del acuse se cerrarán
    con el contrato de recepción. No hay consulta remota de confirmación ni fallback de polling.

## Límite criptográfico importante

El esquema firmado no incluye code, reference_num ni el arreglo payments.
No basta verificar la firma y después localizar la orden usando code: ese dato no está autenticado
por la cadena documentada. La referencia única enviada al crear la orden debe regresar en el
campo reference firmado y ser la correlación principal. Esto necesita una muestra verificable.
Tampoco se atribuye integridad criptográfica a Product, wallet ni payments[].id.
Como candidato a transaction ID se usa el ticket raíz id, que sí integra la firma; no se confunde
con el identificador remoto de la orden ni con un pago anidado no firmado.

Rechazar sin efectos: firma ausente/inválida, versión no admitida, llave desconocida,
JSON malformado, campos ambiguos, orden inexistente, referencia/monto/moneda incorrectos,
evento no identificado como notificación oficial o pago distinto de uno ya contabilizado.
Duplicado auténtico del mismo ticket se reconoce sin volver a confirmar.

## Mapa de componentes

| Dapp original | Equivalente SinergyPay | Acción |
|---|---|---|
| Entrada, autoload y Hooks.php | Bootstrap/hook propios Cannaland | Reutilizar arquitectura, cambiar identidad |
| WC_DappWoo | Gateway SPEI | Reutilizar arquitectura; reescribir comportamiento |
| settings y SettingsTrait | API key privada, ambiente y configuración de callback/llaves | Reescribir |
| ApiConnector/ApiInterface | Transporte HTTPS SinergyPay | Reutilizar arquitectura y hardening |
| DappWooApi/DappWooSdk | Cliente SinergyPay | Reescribir; retirar cliente Dapp del runtime |
| RequestDepositAction | Preparación de orden y CLABE | Reescribir |
| PostCheckout/gateway.js | Instrucciones SPEI | Reescribir; retirar redirección/QR |
| Webhooks/HandlePaymentTrait | Verificador y procesador de notificación | Reescribir |
| DatabaseTrait | Búsqueda por referencia firmada mediante WooCommerce | Reutilizar arquitectura HPOS |
| Bloqueo y marca de creación incierta | Mutex y estados propios de preparación | Reutilizar arquitectura, renombrar y ampliar para CLABE |
| Logging restringido | Eventos mínimos Cannaland | Reutilizar arquitectura |
| CountryCurrencyTrait | Restricción MXN | Reutilizar tras revisar moneda de la orden |
| API de validación/registro de webhooks Dapp | Aprovisionamiento de callback SinergyPay | Eliminar; no copiar endpoints |
| Reembolso Dapp | Sin equivalente respaldado en este alcance | Eliminar del runtime nuevo; no anunciar soporte |
| Assets y metadatos Dapp | Assets e identificadores propios | Eliminar dependencia; no migrar pagos Dapp automáticamente |
| Tests legacy Dapp | Suite propia SPEI/criptografía | Reescribir; conservar referencia en historial |

No se realizará un reemplazo global de nombres. La base original permanece en main/historial.
No se incorporarán dominios Dapp, dapp-codes, QR ni sus IDs al runtime final.

## Datos aún necesarios

1. Relación exacta entre security.key y los archivos oficiales. El ejemplo usa un identificador
   hexadecimal; el ZIP contiene nombres sinergy_public_prod.pem y sinergy_public_sb.pem,
   en representaciones pkcs1 y pkcs8. No se asumirá que cualquier identificador es alias válido.
2. Evento RAW firmado verificable por cada ambiente, incluyendo caracteres no ASCII y referencia
   enviada por el comercio. Confirmar representación real de amount y encoding de la cadena;
   el ejemplo textual no constituye una prueba criptográfica completa del contrato operativo.
3. Registro del callback: procedimiento/API oficial o configuración manual del proveedor;
   no hay evidencia para reutilizar el endpoint de registro Dapp.
4. Acuse HTTP esperado, política de reintentos y eventos de pago aceptado frente a otros eventos.
5. Confirmar idempotencia o procedimiento de conciliación de creación y generación CLABE
   ante timeout/respuesta perdida. No se inventará una idempotency key del proveedor.
6. Para integración real: credenciales sandbox mediante canal seguro, datos bancarios de
   presentación (banco/beneficiario si proceden), configuración del comercio y pruebas SPEI.

La confirmación directa del usuario sobre CLABE prevalece sobre el ejemplo inconsistente del PDF.
La retirada de soporte de GET /payments comunicada por el usuario prevalece sobre su inclusión
histórica en el documento. No se implementará ese endpoint.

Llaves descargadas de la URL oficial, representaciones PUBLIC KEY (SHA-256 del archivo):
- producción: 2b3e89d14edcb688e6b97b9de1f3bee688e49c495b33300eb9b241b7ef66786a
- sandbox: a35fb06c2afd31e6f65a3f2827cd4209fb45cc1c43a6cf5ebc42f12e1d2df9ff

La descarga quedó en /tmp; no se copiaron claves privadas, credenciales ni un ZIP al repositorio.
