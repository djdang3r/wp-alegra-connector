# Tareas — Subsistema de Inventario

| Campo | Valor |
|---|---|
| Cambio | `inventory` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `docs/INVENTORY_DESIGN.md` |
| Convención | `T<fase>.<n>` · `[ ]` pendiente · `[x]` hecho |
| Leyenda | `BLOQUEADO(verificación)` = no empezar hasta Fase 0 |

> **Regla de oro:** la **Fase 0 se ejecuta antes que cualquier código**. El diseño
> depende de sus respuestas. En particular, **R2 primero**: puede ser una
> corrupción activa en producción.

---

## Fase 0 — Verificaciones en vivo (NO escribir código antes)

**Objetivo:** responder las incógnitas que bloquean el spec.
**Entorno:** cuenta Alegra real o de prueba + item con stock conocido.
**Definition of Done de la fase:** las 7 verificaciones tienen resultado
registrado (en `docs/sdd/inventory/phase0-results.md` o en el issue), y cada
requerimiento `BLOQUEADO` tiene su rama elegida.

### T0.1 — R2: ¿`PUT /items` con `initialQuantity` resetea el stock? **PRIORIDAD MÁXIMA**

- **Por qué primero:** `Products.php:282,361,440` reenvían `initialQuantity` en
  cada `PUT /items` (`Products.php:128,141,196,230`). Ya está en producción.
- **Pasos:**
  1. Tomar un item con stock actual ≠ `initialQuantity` (haber vendido ya).
     Ej.: `availableQuantity=10`, `initialQuantity=20`.
  2. `GET /items/{id}?mode=advanced` → anotar `availableQuantity` (10).
  3. `PUT /items/{id}` con body `{"inventory":{"unit":"unit","initialQuantity":5}}`.
  4. `GET /items/{id}?mode=advanced` → leer `availableQuantity`.
- **Resultado esperado / ramas:**
  - **Rama A (sigue 10):** `initialQuantity` NO resetea. REQ-DIV-3 pasa a cosmético.
  - **Rama B (pasó a 5):** **BUG CRÍTICO.** Implementar T4.1 como hotfix antes de
    cualquier otra cosa.
- **DoD:** resultado escrito con el valor observado y la rama elegida.

### T0.2 — R1: ¿Una factura `draft` mueve stock? ¿Y `open`?

- **Pasos:**
  1. Item con stock 10. `GET /items/{id}?mode=advanced` → 10.
  2. `POST /invoices` con `status:"draft"` y 3 unidades.
  3. `GET /items/{id}?mode=advanced` → ¿10?
  4. `POST /invoices/{id}/open`.
  5. `GET /items/{id}?mode=advanced` → ¿7?
- **Ramas:**
  - **Rama A (10 → 7):** el borrador NO mueve stock. REQ-INV-1/2/4 y REQ-DIV-4 se
    implementan (T3.1–T3.3, T9.1).
  - **Rama B (baja en draft):** el borrador SÍ mueve stock. Se cancelan REQ-INV-1/2;
    se simplifica la sección 3 del diseño.
- **DoD:** valores observados en draft y en open, con rama elegida.

### T0.3 — R-CN: ¿La nota crédito restaura stock? (con y sin `id` de item)

- **Por qué:** el payload no envía `status` (`Orders.php:485-493`) y la doc de
  `POST /credit-notes` no documenta `status`
  (https://developer.alegra.com/reference/post-credit-notes).
- **Pasos:**
  1. Factura abierta por 3 (stock 10→7).
  2. Nota crédito **parcial** con una línea **sin `id`** (como `Orders.php:461-470`).
  3. `GET /items/{id}` → ¿sube?
  4. Nota crédito **total** reusando items con `id` (como `Orders.php:444-460`).
  5. `GET /items/{id}` → ¿vuelve a 10?
  6. `void_invoice` sobre una factura abierta → ¿restaura?
- **Ramas:**
  - **Rama A (restaura):** REQ-CN-1 = test de regresión (T10.1).
  - **Rama B (no restaura, sobre todo parcial):** REQ-CN-1 = reportar + evaluar
    ajuste compensatorio (T10.2).
- **DoD:** resultado por cada uno de los 3 subcasos.

### T0.4 — R7: ¿Bodega ausente en `warehouses[]` significa "no asignada" o "cero"?

- **Pasos:** item asignado solo a la bodega A; leer `inventory.warehouses[]`; ver
  si la bodega B aparece con `0` o **no aparece**.
- **Ramas:** Ausente = no asignada (SKIP+WARN) vs. Ausente = cero.
- **DoD:** captura del JSON de `warehouses[]` y conclusión.

### T0.5 — R4: ¿`POST /invoices` acepta `warehouse` a nivel raíz?

- **Pasos:** crear factura con `warehouse.id` explícito; comparar
  `availableQuantity` total vs. `warehouses[id].availableQuantity`.
- **DoD:** ¿bajó el total, la bodega, o ambos? Nota: el esquema de
  `POST /credit-notes` sí documenta `warehouse`.

### T0.6 — R6: `_stock_status` no se recalcula solo (WC)

- **Pasos:** en un producto de prueba, `set_stock_quantity(0); save();` y leer
  `get_stock_status()` **sin** setearlo.
- **DoD:** confirmar que sigue `instock` (o no) en la versión de WC instalada.

### T0.7 — R8: `WC_Product_Variable::sync()` y el estado del padre

- **Pasos:** producto variable con todas las variaciones en 0; llamar `sync()` y
  leer `get_stock_status()` del padre.
- **DoD:** confirmar si el padre deriva `outofstock`.

---

## Fase 1 — Fundación (escritor único)

**Depende de:** nada (no bloqueada). **Puede empezar en paralelo a Fase 0**, salvo
T1.2 que debe respetar la rama de R2 al tocar el push.
**Definition of Done de la fase:** existe `Inventory_Writer` como único escritor;
el pull lo usa; kill switch, lock y cancelación funcionan; los tests de Fase 1
pasan.

- [ ] **T1.1** Crear `includes/Sync/Inventory_Writer.php` con `apply()`,
  `resolve_quantity()`, `apply_stock()`. (REQ-DIV-1)
- [ ] **T1.2** Implementar la política de cantidades: servicio, ausente/null,
  negativo (clamp), no numérico, opt-out. (REQ-TYPE-1..6, REQ-FAIL-1..3,
  REQ-MIG-2)
- [ ] **T1.3** Agregar kill switch al inicio y por página del pull; verificar lock
  y cancelación. (REQ-GATE-1, REQ-GATE-2)
- [ ] **T1.4** Implementar resolución de bodega con normalización `''`/`'0'` y
  SKIP+WARN si falta. (REQ-WH-1, REQ-WH-2, REQ-WH-3, REQ-WH-4)
- [ ] **T1.5** Reemplazar `Products.php:524-532` por `Inventory_Writer::apply()`.
  (REQ-SRC-1)
- [ ] **T1.6** Tests: servicio, nulo, negativo, basura, bodega ausente, kill
  switch, modo WC-wins. (REQ-SRC-2, REQ-FAIL-1..3)

## Fase 2 — Un solo escritor en el import

**Depende de:** Fase 1.
**DoD:** `update_product_from_alegra()` no escribe stock; el import usa el helper;
grep confirma un solo escritor.

- [ ] **T2.1** Eliminar `Products.php:1203-1209` y delegar en
  `Inventory_Writer::apply()`. (REQ-DIV-2)
- [ ] **T2.2** Asegurar que la importación de productos nuevos (simple, variable,
  variación) pase por el helper. (REQ-TYPE-1..5)
- [ ] **T2.3** Test de regresión: importar un producto nuevo escribe stock por el
  helper y no por una segunda ruta. (REQ-DIV-1, REQ-DIV-2)

## Fase 3 — Facturación (D1) `BLOQUEADO(R1)`

**Depende de:** T0.2 (R1) y T0.1 (R2, por R-HOOK no). **No empezar** hasta que R1
tenga rama elegida.
**DoD:** estado de factura según pago y apertura al pagar en modo manual; tests de
facturación pasan.

- [ ] **T3.1** `Orders::prepare_invoice_data()`: regla de 3 pasos en
  `Orders.php:614` + helper `order_is_paid()`. (REQ-INV-2)
- [ ] **T3.2** Nuevo handler `Orders::on_order_paid_open_invoice()` + registro de
  hooks **fuera** del gating en `Public_.php:49-59`. (REQ-INV-1, R-HOOK)
- [ ] **T3.3** Guardrail de advertencia + texto de UI de `invoice_status`.
  (REQ-INV-3, REQ-INV-4)
- [ ] **T3.4** Tests: pedido pagado nace open; no pagado respeta draft;
  `open_invoice_on_paid=false` conserva comportamiento; handler no crea facturas.
- **Rama B (R1 = draft mueve stock):** marcar T3.1–T3.3 como **canceladas** y
  documentar la simplificación.

## Fase 4 — Corrección del push `BLOQUEADO(R2)`

**Depende de:** T0.1 (R2).
**DoD:** ningún `PUT /items` envía `initialQuantity` (si Rama B); tests de push.

- [ ] **T4.1** `is_create` en `prepare_simple_product_data()`,
  `prepare_variation_data()` y `apply_warehouse()`; omitir `initialQuantity` en
  update. (REQ-DIV-3)
- [ ] **T4.2** No enviar `inventory` si el producto no gestiona stock. (REQ-DIV-5,
  REQ-WC-1)
- [ ] **T4.3** Test: `update_item()` no incluye `initialQuantity`; `create_item()`
  sí.
- **Rama A (R2 = no resetea):** T4.1 pasa a limpieza opcional; priorizar T4.2.
- **Nota:** si T0.1 da Rama B, **T4.1 es un hotfix de producción** y se adelanta
  por encima de Fase 1.

## Fase 5 — Migración (opt-in + dry-run)

**Depende de:** Fase 1.
**DoD:** sin opt-in no escribe; dry-run genera informe; guardas de sobrescritura
activas.

- [ ] **T5.1** Registrar `inventory_manage_stock_enabled` y
  `inventory_dry_run`; gatear la escritura. (REQ-GATE-3, REQ-MIG-1)
- [ ] **T5.2** Informe de diferencias de dry-run + WARN de sobrescritura.
  (REQ-MIG-1, REQ-MIG-3)
- [ ] **T5.3** Documentar el flujo de migración para el comerciante (sección 6.3
  del diseño).
- [ ] **T5.4** Tests: dry-run no escribe; opt-in habilita; WARN de sobrescritura.

## Fase 6 — Exposición del pull

**Depende de:** Fase 1.
**DoD:** cron + botón + acción llaman al mismo método.

- [ ] **T6.1** Cron: `Controller.php:107-124` invoca el pull si
  `inventory_source=alegra`.
- [ ] **T6.2** Botón admin "Sincronizar inventario" + AJAX + nonce.
- [ ] **T6.3** Registrar `alegra_sync_inventory_from_alegra` (bootstrap).
  (`docs/RELEASE_2.1.9_DEPLOY.md:359`)
- [ ] **T6.4** Test: la acción WP-CLI dispara el pull.

## Fase 7 — Robustez del pull (R-PULL)

**Depende de:** Fase 1.
**DoD:** cursor persistente, reanudación, truncación reportada, idempotencia.

- [ ] **T7.1** Cursor `alegra_connector_inventory_pull_cursor`: persistir por
  página, reanudar, guardar ante error de API. (REQ-FAIL-4, REQ-FAIL-5)
- [ ] **T7.2** Reportar `truncated=true` al topar `max_pages`. (REQ-FAIL-6)
- [ ] **T7.3** Tests: error a mitad no pierde progreso; reanudación; idempotencia.

## Fase 8 — Celdas de configuración C1–C7

**Depende de:** Fase 1 (C1, C6), Fase 2 (C4).
**DoD:** cada celda tiene comportamiento y test.

- [ ] **T8.1** C1 — opt-out por producto `_alegra_manage_stock_opt_out`. (REQ-MIG-2)
- [ ] **T8.2** C2 — variable con variaciones mixtas: padre `manage=no` + WARN.
  (REQ-TYPE-5)
- [ ] **T8.3** C6 — bodega activada sin id (`''`/`'0'`) ⇒ sin bodega + advertencia.
  `BLOQUEADO(R7)` (REQ-WH-3)
- [ ] **T8.4** C4 — producto solo-WC: no generar inventario falso. (REQ-WC-1,
  REQ-DIV-5)
- [ ] **T8.5** C3 — producto borrado en Alegra: referenciar el webhook
  `delete-item` (`Handlers.php:76`) y reportar huérfanos. (REQ-DEL-1)
- [ ] **T8.6** C5 — negativos: clamp + WARN (ya en T1.2; test explícito).
  (REQ-FAIL-2)
- [ ] **T8.7** C7 — WC-wins + facturación automática: test de no doble conteo.
  (REQ-SRC-2)

## Fase 9 — Divergencia en modo manual `BLOQUEADO(R1)`

**Depende de:** T0.2 (R1).
**DoD:** informe de pedidos vendidos sin facturar + advertencia.

- [ ] **T9.1** Informe "pedidos vendidos pero no facturados" (pedidos
  `processing`/`completed` sin `_alegra_invoice_id`). (REQ-DIV-4)
- [ ] **T9.2** Advertencia de interacción con el pull (decisión D9).
- **Rama B (R1 = draft mueve stock):** el informe sigue siendo útil pero baja de
  prioridad.

## Fase 10 — Notas crédito `BLOQUEADO(R-CN)`

**Depende de:** T0.3 (R-CN).
**DoD:** comportamiento de restauración verificado y cubierto.

- [ ] **T10.1 (Rama A)** Test de regresión de restauración de stock. (REQ-CN-1)
- [ ] **T10.2 (Rama B)** Reportar que no restaura + evaluar `warehouse`/`status` o
  `POST /inventory-adjustments` compensatorio con lock e idempotencia.
- **No** cablear `create_inventory_adjustment()` al sync automático (sección 5.8).

---

## Resumen de dependencias

```
Fase 0 (verificaciones) ──┬──► Fase 3 (R1)      [BLOQUEADO]
                          ├──► Fase 4 (R2)      [BLOQUEADO — hotfix si Rama B]
                          ├──► Fase 9 (R1)      [BLOQUEADO]
                          └──► Fase 10 (R-CN)   [BLOQUEADO]

Fase 1 ──┬──► Fase 2 ──► Fase 8 (C1,C2,C4,C5)
         ├──► Fase 5
         ├──► Fase 6
         ├──► Fase 7
         └──► Fase 8 (C3,C6)  [C6 BLOQUEADO por R7]
```

## Tareas bloqueadas — resumen y ramas

| Tarea | Bloqueada por | Rama A | Rama B |
|---|---|---|---|
| T3.1–T3.3 | R1 | `draft` no mueve ⇒ implementar | `draft` sí mueve ⇒ cancelar |
| T4.1 | R2 | resetea ⇒ omitir en update (**hotfix**) | no resetea ⇒ opcional |
| T8.3 | R7 | ausente = no asignada ⇒ SKIP | ausente = cero ⇒ evaluar (aún SKIP) |
| T9.1 | R1 | informe + advertencia | informe informativo |
| T10.1/T10.2 | R-CN | restaura ⇒ regresión | no restaura ⇒ reportar + ajuste |
