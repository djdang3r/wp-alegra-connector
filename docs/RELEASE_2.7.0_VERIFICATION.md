# Verificación en Producción — Alegra Connector 2.7.0

Esta guía es la **lista de validación** de la versión **2.7.0**. La 2.7.0 es una
**feature release** (sin cambio de esquema) de **propiedad única del stock, poll
honesto, cola de facturas y reconciliación**. El **titular** es el **doble
descuento**: con los defaults de 2.6.0 cada venta emitía un ajuste y la factura
manual volvía a descontar.

- **Diseño:** `docs/sdd/stock-ownership/design.md`.
- **Spec:** `docs/sdd/stock-ownership/spec.md`.
- **Logs:** `Alegra Connector → Logs` (en disco,
  `wp-content/uploads/alegra-logs/alegra-sync-AAAA-MM-DD.log`).
- **Ajustes:** `Alegra Connector → Configuración` (pestaña "Sincronización").

**Leyenda de riesgo**

| Riesgo | Significado |
|---|---|
| **Crítico** | Si falla, el stock de Alegra queda mal (doble descuento / sobreventa). **Bloquea** el uso real. |
| **Alto** | Una función nueva (cola/reconciliación) no cumple; hay que arreglarla. |
| **Medio** | Caso borde o comportamiento tolerable. |

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
- **Build determinista:** `build-release.sh` normaliza los mtimes a
  `1980-01-01` y usa `zip -X` con una lista ordenada (`LC_ALL=C sort`).
- **Verificación manual:**
  ```bash
  ( cd releases && sha256sum -c alegra-connector-v2.7.0.zip.sha256 )
  ```

---

## ★★★ Lo nuevo de la 2.7.0 — los 4 problemas

| # | Problema reportado | Pasos (WP admin) | Resultado esperado | Riesgo |
|---|---|---|---|---|
| 1 | **"Vendo 3 y Alegra descuenta 6"** | Ajustes → "Dueño del stock" = **Factura**; vender 3; verificar **cero** ajustes en el log. Cambiar a **Ajuste**; vender; abrir la factura a mano → aparece el diálogo y pide confirmación. | En `invoice`, una venta descuenta **una vez** y **cero** ajustes; en `adjustment`, la apertura manual exige confirmación y deja nota. | **Crítico** |
| 2 | **"El stock vuelve a subir solo"** | Producto con stock 10 en WC y Alegra; vender 3; correr "Sincronizar inventario". | WC **no** sube (queda en 7 o baja); el log muestra el ajuste, no una re-inflación. | **Crítico** |
| 3 | **"Debería quedar como notificación… por subir"** | Forzar un fallo (p. ej. kill switch o cliente sin resolver); entrar al admin; abrir **"Facturas por subir"**; reintentar. | La cola lista el pedido con motivo/código/intentos; badge = conteo; aviso dismissible; "Reintentar" sube y **no** duplica. | **Alto** |
| 4 | **"No puedo ver ni reparar la divergencia"** | Abrir **"Reconciliación de stock"**; reparar un producto explícitamente. | El informe lista el producto con **causa**; "Reparar" pide confirmación y emite **un** mecanismo (nunca ambos); deja nota + log. | **Alto** |

---

## Regresión del harness (antes del release)

| Gate | Comando | Resultado |
|---|---|---|
| Suite completa | `bash scripts/exec-test.sh` | **2438 assertions, 0 failed** |
| Smoke + PSR-4 | `bash scripts/smoke-test.sh` | `SMOKE OK` |
| Baseline previo (2.6.0) | `docs/RELEASE_2.6.0_VERIFICATION.md:71` | 1990 assertions, 0 failed |
| Baseline de entrada (Fases 0–5) | `bash scripts/exec-test.sh` | 2303 assertions, 0 failed |
| Nuevos | `T30.61`–`T30.67`, `T30.71`–`T30.77` | +135 aserciones |

> El conteo **no bajó** del piso de 1990; los tests nuevos son `T30.*`.

---

## Matriz de prove-it-catches (test → fix revertido → rojo)

> **Regla de oro.** Cada test nuevo se validó revirtiendo su fix: el test **falla**,
> se re-aplica y vuelve a verde. Las **5 tareas centrales** (`T2.1`, `T3.1`, `T3.2`,
> `T4.3`, `T5.4`) heredan su reversión de las fases 2–5; las de esta fase se
> revirtieron explícitamente.

| Test | Tarea central | Fix que protege | Reversión que lo pone en rojo |
|---|---|---|---|
| `T30.21` | `T2.1` | `auto` = condición doble de 2.6.0 | Simplificar a `push_orders_enabled` solo (C1) ⇒ falla |
| `T30.32` | `T3.2` | **El titular:** vender 3 → poll → WC no sube | Escribir WC incondicional en el poll ⇒ WC vuelve a 10 |
| `T30.33` | `T3.1` | El poll no pisa WC en `invoice` | Meter `invoice_owner` en `$handled` sin baseline ⇒ falla |
| `T30.46` | `T4.3` | Re-búsqueda post-error adopta la factura | Quitar `find_existing_invoice` antes de persistir ⇒ duplica |
| `T30.54` | `T5.4` | La reparación emite **un** mecanismo | Permitir ambos ⇒ falla |

**Reversiones verificadas de la Fase 6/7 (todas rojas, luego re-aplicadas):**

| Test | Fix que protege | Reversión que lo pone en rojo |
|---|---|---|
| `T30.61` | La cola distingue nunca-intentado (`data-state="never"`) | Rotular el sin-ledger como `failed_retriable` ⇒ falla |
| `T30.63` | El badge lee el conteo cacheado (option), no una query | Devolver `0` en `invoice_queue_menu_title()` ⇒ falla |
| `T30.64` | El aviso dismissible respeta el dismiss per-user | Quitar la comparación del hash en user meta ⇒ vuelve a mostrarse |
| `T30.65` | El selector de dueño + nota literal + coerción UI | Renombrar `name="alegra_connector_stock_owner"` ⇒ falla |
| `T30.66` | La fila de divergencia linkea a la cola | Quitar el `<a>` del template ⇒ falla |
| `T30.67` | El bulk `scope=failed` sale del ledger | Usar la lógica `pending` ⇒ devuelve 4 en vez de 2 |
| `T30.71` | `auto` reproduce 2.6.0 en las 4 combinaciones | Simplificar `owner()` a `push_orders_enabled` ⇒ falla |
| `T30.72` | `uninstall.php` desagenda el cron de reintento | Quitar `alegra_connector_invoice_retry` del barrido ⇒ falla |
| `T30.73` | El CHANGELOG documenta los cambios intencionales | Quitar `## [2.7.0]` ⇒ falla |
| `T30.74` | La reparación cambia el dueño a `invoice` **antes** de compensar | Quitar el `update_option('alegra_connector_stock_owner', …)` ⇒ falla |
| `T30.75` | El ZIP excluye `scripts/` y `docs/` | Quitar `scripts/` de `.distignore` ⇒ falla |
| `T30.76` | Cada AJAX nuevo exige nonce/cap | Quitar `check_ajax_referer` ⇒ falla |

---

## Riesgos de upgrade (2.6.0 → 2.7.0)

- **Sin cambio de esquema. Sin migración.** Se puede volver a 2.6.0 sin tocar la DB.
- **`stock_owner=auto` (default) conserva 2.6.0** en las 4 combinaciones de
  `push_orders_enabled` × `open_invoice_on_paid` (`T30.71`).
- **Cambios intencionales documentados en `CHANGELOG.md`:**
  - El reporte "Ventas sin factura" se muestra **siempre** (antes se ocultaba con
    `push_orders_enabled=true`) y detecta `draft`/`void`/meta vacío.
  - `open_invoice_on_paid` se **coerciona** según el dueño (ON con `invoice`,
    OFF con `adjustment`).
  - El poll ya no re-infla ni pisa un cambio local pendiente.
- **D1 vs `docs/sdd/inventory/DD-8`:** se mantiene la intención de DD-8 (evitar
  doble conteo) vía el dueño único; el baseline de factura fija `synced = WC qty`
  al abrir la factura.
- **El ledger `_alegra_stock_synced` de 2.6.0 no se limpia ni migra** (`T30.71`).
  El ledger de fallos es **aditivo**: un pedido sin meta no aparece en la cola.
- **Opciones nuevas (8):** `alegra_connector_stock_owner` (`auto`),
  `alegra_connector_invoice_retry_enabled` (`false`),
  `alegra_connector_invoice_retry_max_attempts` (`5`),
  `alegra_connector_invoice_retry_batch` (`20`),
  `alegra_connector_invoice_failures_count` (`0`),
  `alegra_connector_invoice_failures_hash` (`''`),
  `alegra_connector_stock_divergence` (`[]`),
  `alegra_connector_stock_owner_epoch` (`0`). Se siembran sin pisar valores y se
  limpian en `uninstall.php`.
- **Cron de reintento (opt-in):** `alegra_connector_invoice_retry` corre sólo si
  `invoice_retry_enabled=true`; el botón "Reintentar" siempre está disponible.

---

## Rollback

1. Reinstalar el ZIP de **2.6.0** (`releases/alegra-connector-v2.6.0.zip`).
2. No hay migración que revertir: el ledger nuevo es aditivo y 2.6.0 lo ignora.
3. Opcional: borrar las 8 opciones nuevas con WP-CLI si se quiere dejar el sitio
   como estaba.

---

## Verificación de artefactos

```bash
bash scripts/exec-test.sh | tail -3     # EXEC-TEST OK: 2438 assertions passed, 0 failed
bash scripts/smoke-test.sh              # SMOKE OK
( cd releases && sha256sum -c alegra-connector-v2.7.0.zip.sha256 )
unzip -l releases/alegra-connector-v2.7.0.zip | grep -E 'scripts/|docs/' || echo "ZIP limpio"
```
