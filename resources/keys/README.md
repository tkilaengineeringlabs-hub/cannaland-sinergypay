# Llaves públicas oficiales SinergyPay

Fuente: https://media.sinergypay.mx/sw/public_keys.zip, enlazada por la documentación SPEI oficial.
Se usan los archivos PUBLIC KEY del directorio pkcs8; no son claves privadas.
SHA-256 de los bytes empaquetados:

- sinergy_public_sb.pem: a35fb06c2afd31e6f65a3f2827cd4209fb45cc1c43a6cf5ebc42f12e1d2df9ff
- sinergy_public_prod.pem: 2b3e89d14edcb688e6b97b9de1f3bee688e49c495b33300eb9b241b7ef66786a

Solo el entorno configurado selecciona la llave; security.key no se usa para construir rutas.
La rotación exige una actualización revisada. No hay descarga durante el webhook.
