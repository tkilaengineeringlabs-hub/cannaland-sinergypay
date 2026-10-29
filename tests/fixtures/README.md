# Fixture de contrato SPEI

sinergypay-spei-contract.json reproduce la estructura del ejemplo oficial de las páginas 6–8:
https://sinergypay.mx/wp-content/uploads/2026/05/V3-Comercios-SPEI.pdf
El ejemplo y la tabla especifican payments[].Product.id numérico 22 y Product.name SPEI.
El webhook añade security con key, version y signature. No es un evento real ni evidencia
criptográfica del proveedor. Conserva los campos del ejemplo, con referencia propia del
comercio en lugar de null y nombre de cliente sintético. No contiene datos de Horsements.
La referencia no nula está permitida por el contrato y es necesaria para la asociación local.

signature vacío es un marcador: la prueba lo sustituye por una firma RSA/SHA-512 sintética
sobre la cadena documentada, usando las llaves efímeras del harness existente. No se usa la
firma ilustrativa del PDF ni una llave oficial para fingir que se posee la llave privada del
proveedor. No hay llaves privadas en el fixture ni en Git.

La prueba llama al callback registrado real, Notification y Webhook de producción. Solo
WordPress/WooCommerce y sus datos se sustituyen por dobles en memoria. El doble existente
Order::payment_complete simula processing; no demuestra hooks de stock, emails o base de
datos de una instalación WooCommerce real. No crea órdenes remotas ni solicita CLABE.
