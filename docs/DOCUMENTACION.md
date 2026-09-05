# Alegra Connector para WooCommerce

![Script Develop](https://scriptdevelop.com.co/logo/logo.svg) **x** ![Alegra](https://cdn-websites.alegra.com/logos/alegra-logo-base.svg)

**Plugin de integracion bidireccional entre WooCommerce y el sistema contable Alegra**

Desarrollado por [Script Develop](https://scriptdevelop.com.co) | Version: 1.0.4

---

## Indice de Documentacion

| Documento | Descripcion |
|-----------|-------------|
| [01 - Vision General](#01-vision-general) | Objetivo, funcionalidades, flujo de datos |
| [02 - Arquitectura](#02-arquitectura) | Estructura del plugin, clases, namespaces |
| [03 - Instalacion](#03-instalacion) | Requisitos, instalacion, activacion |
| [04 - Configuracion](#04-configuracion) | Conexion, sincronizacion, moneda, avanzado |
| [05 - API Reference](#05-api-reference) | Endpoints Alegra utilizados, metodos, parametros |
| [06 - Sincronizacion en Tiempo Real](#06---sincronizacion-en-tiempo-real-woocommerce--alegra) | Hooks WooCommerce, polling Alegra→WC |
| [07 - Sincronizacion](#07-sincronizacion) | Flujos de sync, conflictos, inventario |
| [08 - Seguridad](#08-seguridad) | Encriptacion, nonces, permisos, estandares |
| [09 - Dashboard](#09-dashboard) | Menu, paginas, estadisticas, mapeo |
| [10 - Resolucion de Problemas](#10-resolucion-de-problemas) | Errores comunes, logs, debugging |

---

## 01 Vision General

### Objetivo

Sincronizar **bidireccionalmente** los datos entre una tienda WooCommerce y el sistema contable Alegra, permitiendo:

- Mantener inventario actualizado en tiempo real
- Generar facturas automaticamente al completar pedidos
- Sincronizar clientes y productos
- Registrar pagos contra facturas
- Gestionar devoluciones como notas credito
- Visualizar trazabilidad completa de cada entidad

### Entidades Sincronizadas

```
┌─────────────────────┐         ┌─────────────────────┐
│    WooCommerce      │◄───────►│       Alegra        │
├─────────────────────┤         ├─────────────────────┤
│ Productos           │ ←──────→│ Items               │
│ Variaciones         │ ←──────→│ Items (variant)     │
│ Categorias          │ ←──────→│ Item Categories     │
│ Clientes            │ ←──────→│ Contacts            │
│ Pedidos             │ ───────→│ Invoices            │
│ Reembolsos          │ ───────→│ Credit Notes        │
│ Pagos               │ ───────→│ Payments            │
│ Inventario          │◄───────►│ Inventory           │
└─────────────────────┘         └─────────────────────┘
```

### Modos de Sincronizacion

| Modo | Gatillo | Latencia |
|------|---------|----------|
| **Tiempo Real** | WooCommerce hooks (`woocommerce_new_product`, `woocommerce_order_completed`, etc.) | Instantaneo |
| **Periodica (Cron)** | WP-Cron configurable (5/15/30/60 min) | Segun intervalo |
| **Manual** | Boton "Sincronizar Ahora" en Dashboard | Inmediato |
| **Polling (Alegra→WC)** | El cron verifica si facturas vinculadas fueron pagadas en Alegra | Segun intervalo del cron |

---

## 02 Arquitectura

### Estructura de Archivos

```
alegra-connector/
├── alegra-connector.php          # Entry point, plugin headers, autoloader
├── uninstall.php                  # Cleanup on uninstall
├── admin/
│   ├── Admin/
│   │   └── Admin_Dashboard.php   # Admin interface manager
│   └── assets/
│       ├── css/admin.css          # Styles
│       └── js/admin.js            # Scripts
├── includes/
│   ├── API/
│   │   └── Client.php            # Alegra REST API client
│   ├── Sync/
│   │   ├── Controller.php        # Sync orchestrator
│   │   ├── Products.php          # Product sync logic
│   │   ├── Customers.php         # Customer sync logic
│   │   ├── Orders.php            # Order→Invoice sync logic
│   │   └── Categories.php        # Category sync logic
│   └── Encryption.php            # AES-256-CBC encryption
├── public/
│   └── Public/
│       └── Public_.php           # WooCommerce hooks, REST API, webhooks
├── logger/
│   └── Logger/
│       └── Logger.php            # File-based logging system
├── templates/                     # Admin page templates
│   ├── admin-dashboard.php        # Main dashboard
│   ├── admin-statistics.php       # Statistics & charts
│   ├── admin-products.php         # Products list
│   ├── admin-product-detail.php   # Product detail (bidirectional)
│   ├── admin-customers.php        # Customers list
│   ├── admin-customer-detail.php  # Customer detail
│   ├── admin-orders.php           # Orders list
│   ├── admin-order-detail.php     # Order detail + payment tracking
│   ├── admin-logs.php             # Logs viewer
│   ├── admin-import.php           # CSV & API import
│   ├── admin-settings.php         # Settings (5 tabs)
│   └── admin-mapping.php          # Field mapper
├── docs/                          # Esta documentacion
├── languages/                     # Traducciones (i18n)
└── releases/                      # ZIPs de release
```

### Clases y Namespaces (PSR-4)

| Namespace | Clase | Archivo | Responsabilidad |
|-----------|-------|---------|-----------------|
| `Alegra\Connector` | `Alegra_Connector` | `alegra-connector.php` | Singleton principal, hooks, activacion |
| `Alegra\Connector` | `Encryption` | `includes/Encryption.php` | Utilidades de encriptacion (no usadas actualmente - token en plaintext) |
| `Alegra\Connector\API` | `Client` | `includes/API/Client.php` | Cliente HTTP REST Alegra |
| `Alegra\Connector\Admin` | `Admin_Dashboard` | `admin/Admin/Admin_Dashboard.php` | Panel de administracion |
| `Alegra\Connector\Logger` | `Logger` | `logger/Logger/Logger.php` | Logs en archivo |
| `Alegra\Connector\Public` | `Public_` | `public/Public/Public_.php` | Hooks WC, REST API, webhooks |
| `Alegra\Connector\Sync` | `Controller` | `includes/Sync/Controller.php` | Orquestador sync |
| `Alegra\Connector\Sync` | `Products` | `includes/Sync/Products.php` | Logica de productos |
| `Alegra\Connector\Sync` | `Customers` | `includes/Sync/Customers.php` | Logica de clientes |
| `Alegra\Connector\Sync` | `Orders` | `includes/Sync/Orders.php` | Logica de pedidos |
| `Alegra\Connector\Sync` | `Categories` | `includes/Sync/Categories.php` | Logica de categorias |

### Diagrama de Dependencias

```
Alegra_Connector (Singleton)
    │
    ├── Logger (logger/Logger/Logger.php)
    │   └── File-based logging con rotacion
    │
    ├── API\Client (includes/API/Client.php)
    │   ├── Basic Auth (email:token → base64)
    │   ├── Rate limiting (150 req/min)
    │   ├── Retry logic (2 intentos)
    │   └── 50+ endpoints REST
    │
    ├── Sync\Controller (includes/Sync/Controller.php)
    │   ├── Sync\Products (variantParent + variant + simple)
    │   ├── Sync\Customers (con duplicate detection)
    │   ├── Sync\Orders (invoice + payment + credit note)
    │   └── Sync\Categories
    │
    ├── Public\Public_ (public/Public/Public_.php)
    │   ├── WooCommerce hooks (real-time sync)
    │   ├── REST API endpoints (/alegra-connector/v1/*)
    │   └── Alegra webhooks (HMAC validation)
    │
    └── Admin\Admin_Dashboard (admin/Admin/Admin_Dashboard.php)
        ├── Dashboard + Statistics (Chart.js)
        ├── Products/Customers/Orders CRUD views
        ├── Settings (5 tabs)
        ├── Field Mapper UI
        └── Logs viewer + Import
```

---

## 03 Instalacion

### Requisitos

| Componente | Version Minima |
|------------|:---:|
| PHP | 8.0+ |
| WordPress | 5.8+ |
| WooCommerce | 6.0+ |
| Cuenta Alegra | Con API habilitada |

### Instalacion

1. Descarga `alegra-connector-v1.0.0.zip` de la carpeta `releases/`
2. WordPress Admin → Plugins → Anadir nuevo → Subir plugin
3. Selecciona el ZIP y haz clic en "Instalar ahora"
4. Activa el plugin

### Post-Activacion

```
Menu lateral: Alegra Connector
    ├── Dashboard      ← Stats + sync rapido
    ├── Estadisticas   ← Graficos y KPIs
    ├── Productos      ← Listado con estado sync
    ├── Clientes       ← Listado con estado sync
    ├── Pedidos        ← Facturacion + pagos
    ├── Mapeo          ← Tax mapping + defaults
    ├── Logs           ← Auditoria
    ├── Importar       ← CSV + API
    └── Configuracion  ← 5 tabs
```

---

## 04 Configuracion

### Conexion con Alegra

1. Ve a **Alegra Connector → Configuracion → Conexion**
2. Ingresa el **email** de tu cuenta Alegra
3. Ingresa el **token API** (obtenido en app.alegra.com → Configuracion → API)
4. Haz clic en **Probar Conexion**
5. Si es exitoso, veras el nombre de tu empresa

### Sincronizacion

| Configuracion | Opciones | Recomendado |
|---------------|----------|:-----------:|
| Metodo | Tiempo real / Periodica / Ambos | Ambos |
| Frecuencia | 5 / 15 / 30 / 60 minutos | 15 min |
| Productos | on/off | on |
| Clientes | on/off | on |
| Pedidos | on/off | on |
| Categorias | on/off | on |
| Fuente inventario | Alegra / WooCommerce / Combinar | Alegra |

### Moneda

COP (Peso Colombiano), USD, MXN, EUR, ARS, PEN, CLP

### Avanzado

| Configuracion | Descripcion |
|---------------|-------------|
| Resolucion de conflictos | Alegra gana (recomendado) / WooCommerce gana |
| Retencion de logs | Dias (1-365, default 30) |
| Webhooks | Habilitar sincronizacion en tiempo real |
| Cuenta bancaria | ID de cuenta en Alegra para registrar pagos |
| Termino de pago | ID de termino en Alegra para vencimientos |
| Webhook Secret | Clave HMAC-SHA256 para validar webhooks entrantes |

---

## 05 API Reference

### Base URL
```
https://api.alegra.com/api/v1
```

### Autenticacion
```
Authorization: Basic base64(email:token)
```

### Endpoints Implementados

#### Items (Productos/Servicios)
```
GET    /items                          Listar items
GET    /items/{id}                     Obtener item
POST   /items                          Crear item
PUT    /items/{id}                     Actualizar item
DELETE /items/{id}                     Eliminar item
```

#### Contacts (Clientes/Proveedores)
```
GET    /contacts                       Listar contactos
GET    /contacts/{id}                  Obtener contacto
POST   /contacts                       Crear contacto
PUT    /contacts/{id}                  Actualizar contacto
DELETE /contacts/{id}                  Eliminar contacto
```

#### Invoices (Facturas de Venta)
```
GET    /invoices                       Listar facturas
GET    /invoices/{id}                  Obtener factura
POST   /invoices                       Crear factura
PUT    /invoices/{id}                  Actualizar factura
POST   /invoices/{id}/void             Anular factura
POST   /invoices/{id}/open             Reabrir factura
POST   /invoices/{id}/stamp            Timbrar factura
PUT    /invoices/{id}/retentions-applied  Editar retenciones
```

#### Credit Notes (Notas Credito)
```
GET    /credit-notes                   Listar notas credito
GET    /credit-notes/{id}              Obtener nota credito
POST   /credit-notes                   Crear nota credito
PUT    /credit-notes/{id}              Actualizar nota credito
DELETE /credit-notes/{id}              Eliminar nota credito
POST   /credit-notes/{id}/void         Anular nota credito
```

#### Payments (Pagos)
```
GET    /payments                       Listar pagos
GET    /payments/{id}                  Obtener pago
POST   /payments                       Crear pago
PUT    /payments/{id}                  Actualizar pago
DELETE /payments/{id}                  Eliminar pago
POST   /payments/{id}/void             Anular pago
POST   /payments/{id}/open             Reabrir pago
```

#### Categories (Categorias de Items)
```
GET    /item-categories                 Listar categorias
GET    /item-categories/{id}            Obtener categoria
POST   /item-categories                 Crear categoria
PUT    /item-categories/{id}            Actualizar categoria
DELETE /item-categories/{id}            Eliminar categoria
```

#### Otros Endpoints
```
GET    /taxes                           Listar impuestos
GET    /taxes/{id}                      Obtener impuesto
POST   /taxes                           Crear impuesto
GET    /warehouses                      Listar bodegas
GET    /currencies                      Listar monedas
GET    /price-lists                     Listar listas de precios
POST   /price-lists                     Crear lista de precios
PUT    /price-lists/{id}                Actualizar lista de precios
DELETE /price-lists/{id}                Eliminar lista de precios
GET    /number-templates                Listar numeraciones
GET    /number-templates/{id}           Obtener numeracion
GET    /terms                           Listar terminos de pago
GET    /terms/{id}                      Obtener termino de pago
GET    /retentions                      Listar retenciones
GET    /bank-accounts                   Listar cuentas bancarias
GET    /company                         Datos de la empresa
GET    /sellers                         Listar vendedores
GET    /variant-attributes              Listar atributos variante
POST   /variant-attributes              Crear atributo variante
GET    /inventory-adjustments           Listar ajustes inventario
POST   /inventory-adjustments           Crear ajuste inventario
GET    /estimates                       Listar cotizaciones
POST   /estimates                       Crear cotizacion
GET    /cost-centers                    Listar centros de costo
```

---

## 06 - Sincronizacion en Tiempo Real (WooCommerce → Alegra)

### Hooks de WooCommerce (outbound)

El plugin se suscribe a los siguientes hooks de WooCommerce para sincronizacion en tiempo real hacia Alegra:

| Hook WooCommerce | Accion |
|------------------|--------|
| `woocommerce_new_product` | Sincroniza producto nuevo a Alegra |
| `woocommerce_update_product` | Actualiza producto en Alegra |
| `woocommerce_delete_product` | Elimina producto de Alegra |
| `woocommerce_new_order` | Crea factura en Alegra (borrador) |
| `woocommerce_order_status_completed` | Crea factura + registra pago + actualiza inventario |
| `woocommerce_order_status_refunded` | Crea nota credito en Alegra + restaura inventario |
| `woocommerce_order_status_cancelled` | Anula factura en Alegra + restaura inventario |
| `woocommerce_new_customer` | Sincroniza cliente a Alegra |

Estos hooks se disparan instantaneamente cuando ocurre el evento en WooCommerce. La sincronizacion respeta la configuracion de `sync_method` (real-time, cron, both).

### Sincronizacion Alegra → WooCommerce (inbound)

El plugin **no depende de webhooks** de Alegra porque la documentacion oficial no los documenta. En su lugar, usa **polling via cron**:

- **Cada vez que corre el cron** (segun la frecuencia configurada: 5/15/30/60 min), el plugin:
  1. Sincroniza pedidos pendientes a Alegra (`sync_recent`)
  2. **Verifica facturas vinculadas**: consulta a Alegra el estado de cada factura con `_alegra_invoice_id`
  3. Si `auto_complete_order` esta activado y la factura aparece como `paid` con balance 0, marca el pedido WC como `completed`

Este polling reemplaza el enfoque anterior de webhooks que no estaba respaldado por documentacion oficial.

---

## 07 Sincronizacion

### Flujo: Producto Simple

```
WooCommerce: Nuevo producto
    → Hook: woocommerce_new_product
    → Public_::on_new_product()
    → Controller::sync_entity('product', id, 'create')
    → Products::sync_to_alegra()
    → prepare_simple_product_data()
        name, reference, description, type: 'simple'
        price: [{idPriceList: 1, price: ...}]
        inventory: {unit: 'unit', initialQuantity: ...}
        tax: [{id: taxId}]
        category: {name: ...}
    → API::create_item(data)
    → Guarda _alegra_item_id en postmeta
```

### Flujo: Producto Variable con Variaciones

```
WooCommerce: Producto variable
    → Products::sync_variable_product()
    → Para cada variacion:
        → sync_variation() 
          type: 'variant'
          name: "Padre - Atributo"
    → Luego el padre:
        type: 'variantParent'
        subitems: [{id: var1_id, price, quantity}, ...]
    → Se crean/actualizan como kit de variantes
```

### Flujo: Pedido → Factura

```
WooCommerce: Pedido completado
    → Orders::create_invoice_with_payment()
    → 1. ensure_customer_synced()
    → 2. prepare_invoice_data()
        date: fecha del pedido
        dueDate: calculado con payment-term de Alegra
        client: {id: contactId}
        items: [{id, name, price, quantity, tax}]
        numberTemplate: {id: auto-detectado}
        currency: {code: COP/USD/...}
        paymentForm/paymentMethod: mapeado desde gateway
    → 3. API::create_invoice(data)
    → 4. sync_inventory_to_alegra()
    → 5. prepare_payment_data() + API::create_payment()
```

### Flujo: Reembolso → Nota Credito

```
WooCommerce: Pedido reembolsado
    → Orders::create_credit_note()
    → invoice: {id: alegra_invoice_id}
    → items: productos del pedido
    → observations: "Reembolso pedido #X"
    → API::create_credit_note(data)
    → sync_inventory_to_alegra() ← restaura stock
```

### Resolucion de Conflictos

```
Producto editado en AMBAS plataformas entre sincronizaciones:
    
    Config: "Alegra gana"
        → WC actualiza Alegra → Alegra sobreescribe WC
        → La version de Alegra prevalece
    
    Config: "WooCommerce gana"  
        → WC actualiza Alegra → WC prevalece
        → La version de WooCommerce prevalece
```

### Deteccion de Duplicados (Clientes)

```
Cliente se sincroniza a Alegra:
    1. Busca por alegra_contact_id en user_meta
    2. Si no existe, busca por email en contacts de Alegra
    3. Si no existe, busca por identification (NIT/RFC)
    4. Si encuentra duplicado, aplica conflicto resolution
    5. Si no, crea nuevo contacto
```

---

## 08 Seguridad

### Capa de Seguridad

```
┌─────────────────────────────────────────────┐
│  WordPress Core                             │
├─────────────────────────────────────────────┤
│  Alegra Connector                           │
│  ┌───────────────────────────────────────┐  │
│  │ Autenticacion API Alegra              │  │
│  │ • Basic Auth (email:token base64)     │  │
│  │ • Token encriptado AES-256-CBC en DB  │  │
│  │ • Key derivada de WP salts            │  │
│  ├───────────────────────────────────────┤  │
│  │ Control de Acceso                     │  │
│  │ • manage_woocommerce (dashboard)      │  │
│  │ • manage_options (settings)           │  │
│  │ • upload_files (CSV import)           │  │
│  │ • current_user_can() en cada pagina   │  │
│  ├───────────────────────────────────────┤  │
│  │ Proteccion CSRF                       │  │
│  │ • wp_create_nonce() en cada form      │  │
│  │ • check_ajax_referer() en cada AJAX   │  │
│  │ • wp_verify_nonce() en acciones       │  │
│  ├───────────────────────────────────────┤  │
│  │ Sanitizacion                          │  │
│  │ • sanitize_text_field()               │  │
│  │ • sanitize_email()                    │  │
│  │ • intval(), floatval()                │  │
│  │ • rest_sanitize_boolean()             │  │
│  ├───────────────────────────────────────┤  │
│  │ Output Escaping                       │  │
│  │ • esc_html(), esc_attr()              │  │
│  │ • esc_url()                           │  │
│  │ • wp_kses_post() para HTML seguro     │  │
│  ├───────────────────────────────────────┤  │
│  │ SQL Protection                        │  │
│  │ • $wpdb->prepare() en todas las queries│  │
│  │ • Type casting (int)                  │  │
│  ├───────────────────────────────────────┤  │
│  │ Webhook Security                      │  │
│  │ • HMAC-SHA256 obligatorio             │  │
│  │ • Sin secret = todos rechazados       │  │
│  │ • hash_equals() para timing-safe      │  │
│  ├───────────────────────────────────────┤  │
│  │ File Security                         │  │
│  │ • ABSPATH guard en todos los archivos │  │
│  │ • .htaccess deny all en logs          │  │
│  │ • index.php en directorios vacios     │  │
│  ├───────────────────────────────────────┤  │
│  │ API Security                          │  │
│  │ • Rate limiting 30 req/min            │  │
│  │ • Retry logic con backoff             │  │
│  │ • Timeout 30s                         │  │
│  │ • Debug logging solo con WP_DEBUG     │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
```

---

## 09 Dashboard

### Menu Lateral

```
Alegra Connector ← menu principal (posicion 30)
├── Dashboard           stats + sync rapido
├── 📊 Estadisticas      KPIs + graficos Chart.js
├── 📦 Productos         listado con badges sync
├── 👥 Clientes          listado con badges sync
├── 🧾 Pedidos           facturacion + trazabilidad pagos
├── 🔄 Mapeo            tax mapping + defaults
├── 📋 Logs             visor + download + cleanup
├── 📥 Importar         CSV + desde Alegra API
└── ⚙️ Configuracion    5 tabs
```

### Paginas

| Pagina | Slug | Funcion |
|--------|------|---------|
| Dashboard | `alegra-connector` | KPIs, sync button |
| Estadisticas | `alegra-connector-stats` | Charts (Chart.js), filtros por periodo |
| Productos | `alegra-connector-products` | Lista con estado sync, detalle bidireccional |
| Clientes | `alegra-connector-customers` | Lista con estado sync, detalle bidireccional |
| Pedidos | `alegra-connector-orders` | Lista con facturacion, detalle con pagos |
| Mapeo | `alegra-connector-mapping` | Tax classes → Alegra taxes |
| Logs | `alegra-connector-logs` | Visor filtrable |
| Importar | `alegra-connector-import` | CSV upload + API import |
| Configuracion | `alegra-connector-settings` | 5 tabs de settings |

### Estadisticas

**KPIs:** Ventas completadas, Total pedidos, Ticket promedio, Nuevos clientes, Cancelados/Reembolsados, Pendientes/Procesando, Sync status

**Graficos (Chart.js 4.4):**
- Ventas diarias (bar chart)
- Metodos de pago (doughnut chart)
- Pedidos por estado (bar chart)
- Top 10 productos (table)

**Filtros de periodo:** Hoy, 7d, 30d, 90d, Este mes, Mes pasado, Este ano, Personalizado (date range)

---

## 10 Resolucion de Problemas

### Logs

Los logs se almacenan en:
```
wp-content/uploads/alegra-logs/alegra-sync-YYYY-MM-DD.log
```

Niveles: INFO, WARNING, ERROR, CRITICAL, DEBUG (solo con WP_DEBUG)

Accede desde: **Alegra Connector → Logs**

### Problemas Comunes

| Problema | Causa probable | Solucion |
|----------|---------------|----------|
| "No conectado" en Dashboard | Credenciales invalidas | Verifica email y token en Configuracion → Conexion |
| Productos no sincronizan | Falta mapeo de impuestos | Configura Mapeo de Campos → Impuestos |
| Facturas sin numero | Sin numeracion configurada en Alegra | Crea number-template en Alegra |
| Pagos no se registran | Sin cuenta bancaria configurada | Configura ID en Avanzado → Cuenta bancaria |
| Error 401 en API | Token expirado o invalido | Regenera token en app.alegra.com |
| Plugin no aparece en menu | Error de permisos | Verifica que el usuario tenga rol Administrator |
| "WooCommerce incompatible" | Version WC no declarada | Actualiza plugin o verifica compatibilidad |
| Webhooks no procesan | Secret no configurado | Configura Webhook Secret en Avanzado |
| Limite 500 items en stats | Tienda muy grande | Los conteos usan muestreo, no afecta sync real |

### Debug Mode

Para habilitar logs detallados, agrega a `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Los errores PHP apareceran en `wp-content/debug.log`
Los logs del plugin en `wp-content/uploads/alegra-logs/`

---

## Glosario

| Termino | Definicion |
|---------|-----------|
| HPOS | High-Performance Order Storage - Nuevo sistema de tablas de pedidos de WooCommerce |
| COT | Custom Order Tables - Nombre alternativo para HPOS |
| PSR-4 | Estandar de autoloading de PHP basado en namespaces |
| HMAC | Hash-based Message Authentication Code |
| CSRF | Cross-Site Request Forgery |
| DIAN | Direccion de Impuestos y Aduanas Nacionales (Colombia) |
| NIT | Numero de Identificacion Tributaria |
| WC | WooCommerce |

---

## 11 - Registro de Cambios (Changelog)

### v1.0.4 (2026-07-22)

**Push WC → Alegra activado por defecto** ⚡
- `push_enabled` ahora es `true` por defecto. Cuando un cliente compra en WC, automáticamente se crea la factura en Alegra y se registra el pago. Sin acción manual del usuario.
- Se agregó un **re-entrancy guard** (`Public_::$is_syncing`) para evitar loops infinitos: cuando se importa un producto desde Alegra, los hooks de push a Alegra se desactivan temporalmente. Así no se crean duplicados en Alegra por la importación misma.
- El inventario en Alegra se descuenta automáticamente al crear la factura (los items se envían con `id` vinculado al item de Alegra, que ya tiene `initialQuantity` correcto desde la importación).

**WooCommerce Flow — Correcciones críticas**
- **Nuevo hook `woocommerce_payment_complete`**: Cuando un gateway confirma el pago (MercadoPago, Stripe, PayU, PSE, Nequi, etc.), se crea la factura en Alegra y se registra el pago inmediatamente, sin esperar a que el pedido llegue a "completado". Esto soluciona el gap donde pedidos en estado `processing` nunca se facturaban.
- **Nuevo hook `woocommerce_order_status_failed`**: Si el pago falla y el pedido pasa a `failed`, se anula la factura draft en Alegra (si existe). Esto evita facturas huérfanas sin pago.
- **Nuevo hook `woocommerce_update_customer`**: Cuando el usuario actualiza su perfil (nombre, dirección, teléfono, NIT), Alegra se actualiza automáticamente.
- **Guest checkout ahora crea contacto en Alegra**: `ensure_customer_synced()` busca el email del guest en Alegra. Si no existe, crea el contacto con los datos de billing del pedido. Si vuelve a comprar, reutiliza el contacto existente. Antes solo enviaba datos inline sin crear contacto.
- **Reembolso parcial**: `create_credit_note()` ahora detecta reembolsos parciales via `$order->get_refunds()`. Si hay refunds, crea la nota crédito solo con los items reembolsados. Si es reembolso total, mantiene el comportamiento anterior.
- **Inventario**: Se eliminó `sync_inventory_to_alegra()` que usaba `PUT /items` con `availableQuantity` (no soportado por la API de Alegra). El inventario ahora se descuenta/restaura automáticamente al crear facturas/notas crédito con items vinculados por ID.

**Webhooks (Nuevo — Procesamiento Inline)**
- Implementado sistema completo de webhooks via `POST /webhooks/subscriptions` de Alegra
- Nuevos archivos: `includes/Webhooks/Receiver.php`, `includes/Webhooks/Handlers.php`
- REST endpoint: `alegra-connector/v1/webhook` con validacion HMAC-SHA256
- Eventos soportados: `new-item`, `edit-item`, `delete-item`, `new-client`, `edit-client`, `delete-client`, `new-invoice`, `edit-invoice`
- **Procesamiento inline**: No depende de cron. El webhook se procesa inmediatamente en la misma request REST. Sin colas, sin workers, sin WP-Cron.
- Botones "Registrar webhooks" y "Eliminar webhooks" en Configuracion > Avanzado
- Auto-cleanup de webhooks al desconectar la cuenta
- Los webhooks son complementarios al polling via cron (no lo reemplazan)

**HPOS / COT (High-Performance Order Storage)**
- Nuevo helper `includes/HPOS.php` con deteccion automatica de HPOS
- Migradas todas las consultas SQL en Dashboard, Estadisticas y Pedidos para funcionar con HPOS
- Compatible con WooCommerce COT activado y desactivado

**Correcciones de API**
- Eliminado `sync_inventory_to_alegra()` que usaba `PUT /items` con `inventory.availableQuantity` (no soportado por Alegra)
- El inventario ahora se descuenta automaticamente al crear facturas con items vinculados
- Corregida paginacion en Customers y Categories: `page` → `start` (offset)
- Simplificados `get_items_count()` y `get_contacts_count()` usando `metadata=true`
- Corregido precio de oferta duplicado (mismo `idPriceList` generaba error)
- Corregido `upload_item_image()` que perdía el header `Authorization`

**Mejoras en Pedidos**
- Los items sin `_alegra_item_id` ahora se buscan por SKU en Alegra antes de enviarse sin vinculo
- `map_item_taxes()` ahora soporta multiples impuestos por item
- Extraccion de `invoice_number` simplificada y robusta
- "Facturar pendientes" ahora usa chunking (10 pedidos por request)

**UI/UX**
- Spinner animado en botones de sincronizacion individual
- Boton "Enviar" cambia a "Actualizar" si el item ya esta sincronizado
- Contador de items seleccionados para acciones en lote
- Eliminada fila duplicada "Nombre" en detalle de cliente
- Corregidas URLs con `_ajax_nonce` faltante (PDF, logs)
- Corregido FormData en importacion CSV
- Feedback visual al guardar configuracion
- Variables CSS migradas de `:root` a `.alegra-connector-wrap` para evitar conflicto con Elementor

**Performance**
- `sync_all()` ahora itera paginas completas (antes solo 30 productos)
- Cancelacion de importacion via transient `alegra_sync_cancelled`
- Queries de estadisticas optimizadas con HPOS
- `async: false` eliminado de llamada AJAX (deprecado en jQuery 3)

### v1.0.3 (2026-06-17)

**Clientes**
- Campos de API corregidos segun documentacion oficial: `phonePrimary`, `address.address`
- Sincronizacion completa con tabla `wp_wc_customer_lookup` para visibilidad en WooCommerce > Clientes
- Contactos sin email se omiten gracefulmente (sin generar errores)
- Boton "Limpiar emails placeholder" para eliminar datos de prueba de versiones anteriores
- Stats de clientes sincronizados ahora usan SQL directo (sin limite de 200)

**Pedidos**
- Payload de pago corregido: `bankAccount` en vez de `account`, `invoices` array
- Numero de factura extraido correctamente de `numberTemplate.fullNumber`
- Notas de pedido automaticas al crear factura y registrar pago
- Boton "Facturar pendientes" con handler AJAX dedicado
- Link de descarga PDF corregido

**Productos**
- Importacion de imagenes en TODAS las rutas de sync (Traer, webhook, cron, individual)
- Paginacion corregida: `page` → `start` offset en Alegra API
- Variantes omitidas en sync chunked (no se intentan crear como productos independientes)
- Chunked sync usa clase Products en vez de `wp_insert_post` inline

**Dashboard / Estadisticas**
- Stats calculadas con SQL directo (eliminados limites de 500 de WC API)
- `extract($stats)` agregado para que las variables del template se inicialicen correctamente
- Mensaje generico "registros" en modal de progreso (antes decia solo "productos")

### v1.0.2
- Paginacion chunked sync con `start` offset
- `mode=advanced` para importacion de imagenes de productos
- Sistema de diseno profesional con variables CSS
- Modal de progreso con timer y boton cancelar

### v1.0.1
- Acciones de sync en lote
- Exportacion CSV para productos y clientes
- Rate limiting de webhooks (60 req/min)

### v1.0.0
- Lanzamiento inicial
- Sincronizacion bidireccional de productos, clientes, pedidos y categorias
- Soporte multi-moneda
- Sincronizacion en tiempo real y por cron
- Sistema de logging en archivos

---

**Version del documento:** 1.0.4 | **Ultima actualizacion:** 2026-07-22
