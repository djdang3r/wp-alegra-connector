# Propuesta — Subsistema de Inventario del Alegra Connector

| Campo | Valor |
|---|---|
| Cambio | `inventory` (subsistema de inventario) |
| Tipo | Feature + corrección de bug en producción |
| Estado | Propuesta — **bloqueada por verificaciones en vivo (Fase 0)** |
| Documento base | `docs/INVENTORY_DESIGN.md` (corregido, 2026-09-17) |
| Versión analizada | 2.3.3 (`alegra-connector.php:6`) |
| Artefactos SDD | `proposal.md`, `spec.md`, `design.md`, `tasks.md` |

> Todo claim de código cita `archivo:línea`. Todo claim de Alegra cita una URL de
> `developer.alegra.com`. Lo no verificable está marcado **SIN VERIFICAR**.

---

## 1. Intención

Hacer que el inventario del plugin **funcione de verdad en ambas direcciones** y
que el interruptor "Fuente de inventario" (`templates/admin-settings.php:93`)
cumpla lo que promete. Hoy no funciona en ninguna dirección (sección 1 de
`INVENTORY_DESIGN.md`).

En una frase: **un solo escritor de stock en WooCommerce, la factura abierta como
único mecanismo que mueve el stock de Alegra, y una migración opt-in con
simulación previa.**

## 2. Problema

1. **`_manage_stock` y `_stock_status` nunca se escriben.** Solo hay lecturas
   (`Products.php:525,1203`); no existe ningún `set_manage_stock()` ni
   `set_stock_status()` en producción. WooCommerce ignora el stock que el plugin
   escribe.
2. **La copia está muerta.** `sync_inventory_from_alegra()` (`Products.php:447`)
   solo la llama un script de pruebas (`scripts/exec-test.php:967,1184`). La
   acción `alegra_sync_inventory_from_alegra` documentada en
   `docs/RELEASE_2.1.9_DEPLOY.md:359` no está registrada.
3. **Hay dos escritores de stock** (`Products.php:1203-1209` en el import, y
   `Products.php:524-532` en el pull), y ninguno respeta una política común.
4. **Bug activo en producción (R2):** el push reenvía
   `inventory.initialQuantity` en cada `PUT /items`
   (`Products.php:282,361,440`), en cada edición de producto. **SIN VERIFICAR** si
   resetea el stock; si lo hace, está corrompiendo inventario hoy.
5. **Tensión borrador vs. stock:** con `invoice_status=draft` (default,
   `alegra-connector.php:386`) y facturación manual (default,
   `alegra-connector.php:372`), **no hay factura o queda en borrador**; Alegra no
   mueve stock y la copia re-inflaría lo vendido.
6. **Gating arquitectónico:** los hooks "al pagar" se registran solo si
   `push_orders_enabled` está activo (`Public_.php:49-59`), así que "abrir la
   factura al pagar" no puede dispararse en el modo por defecto.

## 3. Alcance

**Dentro:**

- Helper único de escritura de stock (`Inventory_Writer`) y eliminación del
  segundo escritor.
- Activar `_manage_stock` + `_stock_status` bajo un interruptor opt-in de
  migración, con dry-run.
- Conectar la copia (cron + botón admin + acción WP-CLI), respetando kill switch,
  lock, cancelación y `inventory_source`.
- Política de bodegas (leer la misma bodega que el push escribe).
- Política por tipo de producto (simple / variable / variación / servicio).
- Resolución D1: apertura de factura al pagar + estado de factura según pago.
- Corrección del push (R2) condicionada a la verificación.
- Reanudación e idempotencia del pull (R-PULL).
- Las 7 celdas de configuración C1–C7 (sección 4.6 del diseño).
- Informe de divergencia para el modo manual (pedidos vendidos sin facturar).

**Fuera de alcance (explícito):**

- **No** se cablea `create_inventory_adjustment()` (`Client.php:648`) al sync
  automático. Solo se documenta como reconciliación manual (sección 5.8 del
  diseño).
- **No** se cambia el comportamiento de `_backorders` (decisión D6).
- **No** se implementa batching de escrituras en el pull (optimización, no
  corrección; se puede diferir).
- **No** se toca la lógica de precios, impuestos, categorías ni imágenes.
- **No** se implementa el timbrado/emisión fiscal (no es parte del stock).
- **No** se resuelve el campo `warehouse` de `POST /invoices` (R4) más allá de
  reportarlo: si Alegra lo ignora, es una decisión aparte.

## 4. Enfoque

1. **Verificar primero (Fase 0).** R2 (¿`initialQuantity` resetea stock?), R1
   (¿borrador mueve stock?), R-CN (¿nota crédito restaura stock?), R7 (semántica
   de bodega ausente), R4, R6, R8. El diseño depende de las respuestas.
2. **Un solo escritor.** `Inventory_Writer::apply()` es el único lugar que llama
   `set_manage_stock()` / `set_stock_quantity()` / `set_stock_status()`. Tanto el
   pull como el import lo usan.
3. **Migración opt-in.** `alegra_connector_inventory_manage_stock_enabled=false`
   por defecto; dry-run por defecto; opt-out por producto.
4. **Factura atada al pedido.** `open_invoice_on_paid=true` por defecto; el estado
   de la factura depende del pago; hooks de apertura registrados fuera del gating
   de push.
5. **Corrección del push.** `initialQuantity` solo en creación.
6. **Exposición.** Cron + botón + acción WP-CLI apuntando al mismo método.

## 5. Criterios de éxito

- [ ] En `inventory_source=alegra` con migración activa, un cambio de stock en
      Alegra se refleja en WC en la próxima corrida del pull (simple, variación y
      variable padre correctamente).
- [ ] Un producto con stock 0 queda `outofstock` (no vendible) en WC.
- [ ] Existe **una sola** ruta de código que escribe `_stock` (verificable por
      grep de `set_stock_quantity`/`set_manage_stock`/`set_stock_status`).
- [ ] El pull respeta kill switch, lock, cancelación y `inventory_source`.
- [ ] La copia reanuda tras un error de API sin reprocesar todo.
- [ ] En `inventory_source=woocommerce`, el plugin no escribe stock.
- [ ] Un reembolso total/parcial restaura stock en Alegra **o** el sistema
      reporta explícitamente que no lo hace (según R-CN).
- [ ] Con `open_invoice_on_paid=true`, una factura borrador se abre al pagarse,
      **incluso en modo manual**.
- [ ] Ninguna edición de producto envía `initialQuantity` en un `PUT /items`
      (condicionado a R2).
- [ ] El dashboard advierte cuando la configuración produce stock fantasma.

## 6. Riesgos de la propuesta

| Riesgo | Mitigación |
|---|---|
| Activar `_manage_stock` en masa pisa stock real de WC | Opt-in + dry-run obligatorio + WARN en sobrescritura |
| R2 sin verificar | Fase 0 primero; si resetea, hotfix del push antes de cualquier feature |
| Apertura de factura al pagar en modo manual | El handler solo **abre** borradores existentes; nunca factura |
| El pull re-infla en modo manual sin facturas | Informe de divergencia + advertencia (D9) |
| Nota crédito no restaura stock | Verificar R-CN; si falla, reportar y diseñar ajuste explícito |

## 7. Preguntas abiertas que bloquean el spec

1. **R2:** ¿`PUT /items` con `initialQuantity` resetea `availableQuantity`?
2. **R1:** ¿Una factura `draft` mueve stock? ¿Y `open`?
3. **R-CN:** ¿Una nota crédito (con y sin `id` de item) restaura stock?
4. **R7:** ¿La ausencia de una bodega en `warehouses[]` significa "no asignada" o
   "cero"?
5. **R4:** ¿`POST /invoices` acepta `warehouse` a nivel raíz?
