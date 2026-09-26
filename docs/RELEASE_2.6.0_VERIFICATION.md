# Verificación en Producción — Alegra Connector 2.6.0

Esta guía es la **lista de validación** de la versión **2.6.0**. La 2.6.0 es una
**feature release** (sin cambio de esquema) de **fiabilidad de sincronización**:
Consumidor Final honesto, inventario bidireccional (un solo escritor) y poll con
presupuesto/cursor. El **titular** es la **sobreventa**: WC nunca empujaba stock a
Alegra y el poll re-inflaba lo vendido.

- **Checklist manual (los 5 problemas):** `docs/sdd/sync-reliability/MANUAL-ACCEPTANCE.md`.
- **Logs:** `Alegra Connector → Logs` (en disco,
  `wp-content/uploads/alegra-logs/alegra-sync-AAAA-MM-DD.log`).
- **Ajustes:** `Alegra Connector → Configuración` (pestañas "Sincronización" y "Avanzado").

**Leyenda de riesgo**

| Riesgo | Significado |
|---|---|
| **Alto/Crítico** | Si falla, el comerciante cree que el catálogo está sincronizado cuando no lo está, o hay sobreventa. **Bloquea** el uso real hasta resolverse. |
| **Medio** | Una función secundaria queda degradada. No bloquea facturar, pero hay que arreglarlo. |
| **Bajo** | Caso borde o comportamiento tolerable. |

---

## Política de artefactos de release (el ZIP lo construye el mantenedor)

> **Regla:** el ZIP de release se **construye y commitea localmente**; CI
> **solo lo publica**. El `.sha256` versionado en `releases/` es la **fuente de
> verdad** para verificar la descarga.

- **Quién construye:** el mantenedor corre `bash scripts/build-release.sh <X.Y.Z>`
  y commitea `releases/alegra-connector-v<X.Y.Z>.zip` + `.sha256` **antes** de
  crear el tag. El tag es el último paso.
- **Qué hace CI:** ante un push de tag `v*`, `.github/workflows/release.yml`
  **no recompila**. Verifica que el ZIP commiteado coincida con su `.sha256`
  (`sha256sum -c`) y recién entonces lo publica con `softprops/action-gh-release`
  (`make_latest: "true"`). Si el ZIP no está commiteado o el checksum no coincide,
  el job **falla** y no publica nada.
- **Por qué:** la v2.4.0 publicó un asset con los mismos archivos pero **distintos
  bytes** que el ZIP del repo, porque el workflow lo recompilaba en CI. Con esta
  política el asset es **byte-idéntico** al artefacto versionado.
- **Build determinista (bonus):** `build-release.sh` normaliza los mtimes a
  `1980-01-01` y usa `zip -X` con una lista ordenada (`LC_ALL=C sort`). Es una red
  de seguridad de reproducibilidad, **no** un permiso para recompilar en CI.
- **Verificación manual:**
  ```bash
  ( cd releases && sha256sum -c alegra-connector-v2.6.0.zip.sha256 )
  ```

---

## ★★★ Lo nuevo de la 2.6.0 — los 5 problemas

Los pasos exactos del comerciante viven en
`docs/sdd/sync-reliability/MANUAL-ACCEPTANCE.md`. Resultado verificable por problema:

| # | Problema reportado | Resultado verificable | REQ | Tests |
|---|---|---|---|---|
| 1 | CF "No encontrado" aunque existe (falso negativo) | Tras conectar, la fila dice **Disponible** (o **No verificado** honesto); nunca "No encontrado" con el contacto existente; "Verificar ahora" sin recargar; un CF borrado se auto-sana o deja nota accionable | REQ-CF-01..07 | `T29.51`, `T29.52`, `T29.520`, `T29.53`–`T29.59`, `T29.61`–`T29.64`, `T29.640` |
| 2 | "Vendí y el stock volvió a subir" (sobreventa) | Tras vender 3 en WC, un poll posterior **no** sube el stock; el ajuste WC→Alegra refleja el delta; con `push_orders=true` la factura es dueña (0 ajustes); grep de un solo escritor | REQ-INV-01..08 | `T29.34`, `T29.36`, `T29.37`, `T29.38`, `T29.29` |
| 3 | "El stock no se actualiza / variaciones viejas" | Un cambio de stock de variación se refleja por update; `preserve_fields` se respeta; `_stock_status` con backorders correcto (y con umbral `> 0`, R19) | REQ-INV-03/04/05 | `T29.21`, `T29.23`, `T29.24`, `T29.25`, `T29.41`–`T29.45` |
| 4 | "El sitio se ralentiza / el poll nunca termina" | El poll pausa por budget, persiste cursor, reporta `truncated=true`; el lock se libera ante un fatal; no hay cascada de push | REQ-POLL-01..04, NFR-02 | `T29.71`–`T29.76`, `T29.81`–`T29.83` |
| 5 | "Reanudar vs reimportar / nunca completa" | El cursor es visible/reanudable; el catálogo grande termina en varias corridas o pausa con señal; Ajustes muestra el cron real | REQ-POLL-01/02/05/07 | `T29.71`, `T29.73`, `T29.75`, `T29.84`, `T29.85` |
| 6 | Sin regresión (distribuido) | DIAN intacto; facturación/clientes/pagos/webhooks siguen; `inventory_source=woocommerce` sigue sin escribir stock | NFR-01/03/05 | `T29.91`–`T29.94`, `T29.59` |

---

## Regresión del harness (antes del release)

| Gate | Comando | Resultado |
|---|---|---|
| Suite completa | `bash scripts/exec-test.sh` | **1990 assertions, 0 failed** |
| Smoke + PSR-4 | `bash scripts/smoke-test.sh` | `SMOKE OK` |
| Baseline previo (2.5.1) | `bash scripts/exec-test.sh` | 1952 assertions, 0 failed |
| Piso Fase 9 | — | ≥ 1952 + nuevos (R14, R19, T29.91–T29.95) |

---

## Matriz de prove-it-catches (test → fix revertido → rojo)

> **Regla de oro.** Cada test nuevo se validó revirtiendo su fix: el test **falla**,
> se re-aplica y vuelve a verde. Las **5 tareas centrales** (`T2.1`, `T3.4`,
> `T5.5`, `T7.1`, `T8.1`) tienen su reversión documentada abajo.

| Test | Tarea | Fix que protege | Reversión que lo pone en rojo |
|---|---|---|---|
| `T29.21` | T2.1 | `Inventory_Writer::apply_stock()` usa `wc_update_product_stock()` (rama A) **y** conserva el fallback `set_stock_quantity()+save()` (rama B, R14) | Quitar el `function_exists` o el fallback → el source-scan de R14 falla; volver al status forzado → falla la derivación |
| `T29.23` | T2.1 | `_stock_status` derivado por WC respetando backorders **y** el umbral `notify_no_stock_amount` (R19) | Volver a `qty > 0 ? 'instock' : 'outofstock'` → el caso umbral (qty 1, umbral 2) queda `instock` y el test falla |
| `T29.24` | T2.3 | W1 (import) delega en el writer y respeta `source`/`preserve` | Quitar la compuerta `preserve`/`source` → falla |
| `T29.25` | T2.4 | W2 (poll) honra `preserve_fields` | Quitar la compuerta → el stock se escribe con `preserve` y falla |
| `T29.34` | T3.4 | **El titular:** vender 3 → poll → WC no sube | Escribir WC incondicional en el poll → WC vuelve a 10 y falla |
| `T29.36` / `T29.76` | T3.4 | El poll reintenta/reconcilia un `pending` aun con `set_syncing` activo (FIX-2) | Devolver `syncing` en `push_delta` desde el poll → el POST no sale y falla |
| `T29.37` / `T29.38` | T3.5 | Dueño `invoice`: la factura mueve stock, 0 ajustes; un solo dueño por movimiento | Hardcodear `owner()='adjustment'` → el test del dueño `invoice` falla |
| `T29.51` | T5.1 | Barrido CONTAINS paginado encuentra el CF real; tope ⇒ `unverified` (nunca `not_found`) | Quitar la paginación/`matches()` → falla |
| `T29.55` | T5.5 | Self-heal del 400 por CF borrado re-resuelve paginado y **no** duplica | Quitar la pre-búsqueda `find_existing_invoice` antes del reintento → duplica y falla |
| `T29.56` | T5.5 | La idempotencia pre-search evita una factura duplicada | Quitar la pre-búsqueda → falla |
| `T29.58` | T5.6 | Un CF irresoluble borra el meta muerto y deja nota accionable (sin loop) | No borrar el meta muerto → el loop/nota falla |
| `T29.71` | T7.1 | El poll pausa por budget y reanuda por cursor | Quitar el refresh por página / el gate del cursor → falla |
| `T29.74` | T7.3 | El poll corre con `set_syncing` sin disparar la cascada | Quitar el refresh por página → ve la cascada y falla |
| `T29.81` | T8.1 | El shutdown libera ambos locks y respeta tokens ajenos | Quitar la comparación de token en `release_lock` → libera un lock ajeno y falla |
| `T29.82` / `T29.83` | T8.2/8.3 | `cron_run_budget` (540) < TTL 600; deadline propagado; warning al reclamar vencido | No propagar el deadline → falla |
| `T29.91`–`T29.94` | T9.1 | Sin regresión end-to-end: factura, webhook, pago, `inventory_source=woocommerce` | Revertir el flujo correspondiente → falla |
| `T29.95` | T9.5.a | Versión 2.6.0 en 3 fuentes + `uninstall.php` con las 9 opciones + CHANGELOG | Quitar una opción de `uninstall.php` o la sección del CHANGELOG → falla |

---

## Riesgos de upgrade (2.5.1 → 2.6.0)

- **Sin cambio de esquema. Sin migración.** Se puede volver a 2.5.1 sin tocar la DB.
- **Cambios intencionales documentados:**
  - `_stock_status` con `backorders=yes` + stock 0 ⇒ `onbackorder` (antes `outofstock`).
  - Con `woocommerce_notify_no_stock_amount > 0`, WC deriva `outofstock` en el umbral
    (antes 2.5.1 forzaba `instock`). Ver **R19**.
  - El poll ahora **honra** `preserve_fields`: con `inventory` preservado **no**
    escribe stock (antes lo ignoraba).
- **Decisión D2 vs `docs/sdd/inventory/DD-8`:** el plugin ahora **cablea**
  `POST /inventory-adjustments` como dueño del movimiento cuando
  `push_orders=false` (default). Contradice deliberadamente DD-8; la intención de
  DD-8 (evitar doble conteo) se preserva vía el dueño a nivel tienda + las guardas
  del pusher.
- **Opciones nuevas** (9, defaults seguros):
  `alegra_connector_push_inventory_enabled`,
  `alegra_connector_inventory_manage_stock_enabled`,
  `alegra_connector_inventory_poll_budget`,
  `alegra_connector_inventory_poll_max_pages`,
  `alegra_connector_cron_run_budget`,
  `alegra_connector_open_invoice_on_paid`,
  `alegra_connector_inventory_pull_cursor`,
  `alegra_connector_inventory_pull_total`,
  `alegra_connector_consumidor_final_probe`.
  **`uninstall.php` borra las 9.**

---

## Rollback

1. Desinstalar 2.6.0 / reinstalar **2.5.1**. **Sin cambio de esquema**: no hay
   migración que revertir.
2. Opcional: limpiar las 9 opciones nuevas (las borra `uninstall.php`).
3. Verificar que el stock vuelve a comportarse como 2.5.1 (el titular reaparece —
   por eso el rollback es sólo una contingencia).

---

## Verificación del release

```bash
( cd releases && sha256sum -c alegra-connector-v2.6.0.zip.sha256 )   # OK
unzip -l releases/alegra-connector-v2.6.0.zip | grep -E 'scripts/|docs/' || echo "ZIP limpio"
COMMITTED=$(awk '{print $1}' releases/alegra-connector-v2.6.0.zip.sha256)
gh release download v2.6.0 -p 'alegra-connector-v2.6.0.zip' -O /tmp/alegra-2.6.0.zip
test "$COMMITTED" = "$(sha256sum /tmp/alegra-2.6.0.zip | awk '{print $1}')" && echo "ASSET OK"
gh release view v2.6.0 --json isLatest,assets --jq '{isLatest, assets: [.assets[].name]}'
```
