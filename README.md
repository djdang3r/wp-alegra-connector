# Alegra Connector

![Script Develop](https://scriptdevelop.com.co/logo/logo.svg)

**WooCommerce - Alegra integration plugin for bidirectional synchronization.**

Developed by [Script Develop](https://scriptdevelop.com.co)

Version: 2.1.7 | PHP 8.0+ | WP 5.8+ | WC 6.0+

## Description

Alegra Connector allows you to synchronize your WooCommerce store with the Alegra accounting system. Features include:

* **Products Sync**: Synchronize products and services bidirectionally with images
* **Customers Sync**: Keep your customers updated across both platforms (WC lookup table)
* **Orders to Invoices**: Automatically create invoices in Alegra from WooCommerce orders with number feedback
* **Payment Recording**: Register payments against Alegra invoices from WC orders
* **Categories Sync**: Synchronize product categories
* **Multi-currency**: Support for COP, USD, MXN, EUR, and more
* **Real-time + Cron**: Both real-time webhooks and scheduled synchronization
* **Tax Mapping**: Configurable tax synchronization
* **Logging**: Complete audit trail of all synchronization activities
* **40+ Payment Gateways**: Automatic mapping to Alegra payment methods (PSE, Nequi, Bancolombia, MercadoPago, Stripe, etc.)

## Installation

1. Download the latest release from the `releases` folder
2. Upload the plugin to your WordPress site via Plugins > Add New > Upload Plugin
3. Activate the plugin
4. Go to Alegra Connector > Settings to configure

## Configuration

1. **Connection**: Enter your Alegra API email and token
2. **Sync Settings**: Choose sync frequency and what to synchronize
3. **Currency**: Set your default currency
4. **Warehouses**: Configure warehouse for inventory management
5. **Advanced**: Configure conflict resolution, bank accounts, payment terms, and logging

## Requirements

* WordPress 5.8+
* PHP 8.0+
* WooCommerce 6.0+
* Alegra account with API access

## Changelog

### 1.0.4
* **Webhooks**: Full Alegra webhook subscription system (new-item, edit-item, new-client, edit-client, new-invoice, edit-invoice)
* **HPOS/COT**: Full compatibility with High-Performance Order Storage
* **API fixes**: Removed incorrect inventory sync (PUT /items not supported), fixed pagination (start offset), fixed image upload Authorization header, fixed duplicate sale price
* **Orders**: New on-hold hook, SKU-based item resolution, multi-tax support, chunked "Facturar pendientes"
* **UI/UX**: Spinner animations, dynamic button labels, bulk selection counter, URL fixes, duplicate label removed
* **Performance**: Paginated sync_all, cancellation flag for imports

### 1.0.3
* **Customers**: Full bidirectional sync with WC lookup table population (appear in WooCommerce > Customers)
* **Customers**: Fixed API field names (phonePrimary, address.address) per official Alegra docs
* **Customers**: Contacts without email are gracefully skipped with clear messaging
* **Customers**: "Limpiar emails placeholder" button to clean up test data
* **Customers**: SQL-based stats for accurate counts (no more limit=200 sampling)
* **Orders**: Fixed payment API payload (bankAccount instead of account, invoices array)
* **Orders**: Invoice number properly extracted from numberTemplate.fullNumber
* **Orders**: "Facturar pendientes" batch button with dedicated AJAX handler
* **Orders**: WC order notes on invoice creation and payment registration
* **Orders**: Fixed PDF download link
* **Products**: Fixed image import via mode=advanced on all sync paths
* **Products**: Fixed pagination (page → start offset) in import_from_alegra
* **Products**: Variants skipped in chunked sync to prevent errors
* **Dashboard**: SQL-based statistics (no more WC API limits of 500)
* **Dashboard**: Fixed extract($stats) so statistics page shows real data
* **Footer**: Generic "registros" label instead of "productos"

### 1.0.2
* Fixed chunked sync pagination (start offset)
* Added mode=advanced for product images
* Professional design system with CSS variables
* Progress modal with timer and cancel

### 1.0.1
* Added bulk sync actions
* CSV export for products and customers
* Webhook rate limiting (60 req/min)

### 1.0.0
* Initial release
* Products, customers, orders, and categories synchronization
* Multi-currency support
* Real-time and cron-based synchronization
* File-based logging system

## License

GPL v2 or later
