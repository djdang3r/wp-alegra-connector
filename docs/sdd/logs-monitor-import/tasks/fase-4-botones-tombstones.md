# Fase 4 — D4: Reanudar vs reimportar + tombstones (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documentos base | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Tareas que expande | **T4.1–T4.5** (skeleton `tasks.md:431-520`) |
| Decisión de diseño | **D4** (`design.md:584-687`) |
| Versión objetivo | **2.5.0** |
| Estado | T4.1, T4.2, T4.5 `ACTIVO` · T4.3a/T4.3b/T4.4 `BLOQUEADO(Fase 0.2 / G2)` para el cierre de la rama; la implementación base es idéntica en ambas ramas |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`, stubs `scripts/lib/wp-stubs.php`, framework `scripts/lib/test-framework.php`) |

> **Convención de test ID.** Los tests nuevos de este cambio usan **`T28.{fase}{n}`** (el harness ya
> usó `T1..T27`); esta fase es **`T28.41`–`T28.47`**. Renumerados desde los `T4.x` originales.

> **Regla de oro (heredada del skeleton).** Después de que un test pase, **revertir el fix**, correr
> `bash scripts/exec-test.sh` y confirmar que **ese** test falla. Volver a aplicar el fix. Sin
> `prove-it-catches` el test no se acepta.
>
> **Todos los `file:line` de este documento fueron re-verificados leyendo el código en HEAD.**
> Las citas del skeleton/design que estaban mal se corrigen y se listan abajo.

---

## Correcciones de cita (verificadas en HEAD para Fase 4)

| # | Cita original (skeleton/design) | Realidad verificada | Impacto |
|---|---|---|---|
| C1 | `design.md:904` (antes citado como `:837`, que es la allowlist "Rama B") firma `import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array\|\WP_Error`; el skeleton le sumaba `array $opts = []` | La firma real en `includes/Sync/Products.php:1248` es `import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array\|\WP_Error`. **No existe `array $opts = []`** y no hace falta: la política viaja por `Run_Context` (static), no por parámetro. | T4.4 **no** agrega `$opts`; consume la política desde `Run_Context`. |
| C2 | `design.md:905-906` (antes citado como `:838-839`) firmas `import_single_item_public(array $item, int $run_id = 0)` / `import_single_item_from_alegra(array $item, int $run_id = 0)` | Hoy son `import_single_item_public(array $item): bool\|string` (`Products.php:2365`) y `private function import_single_item_from_alegra(array $item): bool\|string` (`:1404`). **No tienen `$run_id`**. | T4.4 **no** necesita `$run_id` (la política es static). El `$run_id` que agrega T3.2 es para el stop, no para tombstones. |
| C3 | `tasks.md:473` cita `Tombstone_Manager.php:107-115` (`exists`) | `exists()` va de **`:107` a `:122`** (cierra en `:122`, `return (bool) $found;` en `:121`). | T4.3a usa `:107-122`. |
| C4 | `tasks.md:476-477` (T4.3) y `spec.md:660` dan por sentado que `on_post_delete` puede clasificar hoy | `on_post_delete` escribe `'reason' => 'manual_wc'` **hardcodeado** en `Tombstone_Manager.php:54`. Además `is_admin()` **no existe** como señal utilizable en el harness (`scripts/lib/wp-stubs.php:426` devuelve `false` fijo y no es configurable). | T4.3b **debe** hacer configurable `is_admin()` en el stub o el test de `bulk_wc` es imposible. Ver "Prerequisito de harness". |
| C5 | `tasks.md:478` (T4.3) asume que `exists_with_reason()` es testeable | `Alegra_Mock_Wpdb::get_var()` (`scripts/lib/wp-stubs.php:1592-1626`) **no maneja** la tabla `wp_alegra_tombstones`: devuelve `null`. `get_row()` (`:1642`) devuelve `null` siempre. Sin tocar el stub, `exists_with_reason()` siempre da `null`. | T4.3a **debe** extender `get_var()` para leer `$GLOBALS['alegra_db']['wp_alegra_tombstones']`. Ver "Prerequisito de harness". |
| C6 | `tasks.md:39` (corrección #4) dice que `set_transient('alegra_sync_progress', …)` está en `:1319` | Confirmado: el `if` está en `:1318`, la llamada en `:1319`. | Sin cambio. |
| C7 | `tasks.md:36` (corrección #1) dice que `Admin_Dashboard.php:2024` es `$this->api->reload_credentials();` y que `ajax_sync_start` no tiene guard de conexión | Confirmado: `:2024` es `reload_credentials()`; el único guard real está en `ajax_import_from_api:4081`. | T2.3 crea el guard; T4.x lo asume hecho. |
| C8 | `design.md:619-634` afirma que "vaciar papelera" llega como `action=delete_all` y el heurístico chequea `$action === 'delete_all'` | **Falso (Oracle D5).** WP **no** manda `action=delete_all`: "Empty Trash" es un submit con `name="delete_all"` (top) / `delete_all2` (bottom) (`wp-admin/includes/class-wp-posts-list-table.php:606` → `submit_button('Empty Trash','apply','delete_all')`), y `WP_Posts_List_Table::current_action()` (`:625-631`) devuelve `'delete_all'` con `isset($_REQUEST['delete_all']) \|\| isset($_REQUEST['delete_all2'])`, **sin** mirar `action`. Además el select de abajo manda `action2`, que el heurístico ignoraba. | T4.3b usa `isset($_REQUEST['delete_all'])`/`delete_all2` como señal primaria y `action2` como fallback. |

**Citas confirmadas exactas (no requieren corrección):** `templates/admin-products.php:92` (`.alegra-quick-sync`, `data-type="products"`, `data-requires-filter="1"`), `:102` (`.alegra-bulk-import`), `:88-105` (card de acciones), `:270-340` (modal de filtros `#alegra-import-filter-modal`); `admin.js:124-285` (`initSyncNow`), `:129-132` (rama `requires-filter`), `:207-282` (flujo chunked), `:212-227` (`alegra_sync_start`), `:294-306` (`openImportFilterModal`), `:345-351` (`run()`), `:353-365` (`collectFilters`); `Admin_Dashboard.php:2018-2068` (`ajax_sync_start`), `:2057-2063` (state + transient), `:2065-2067` (respuesta), `:2073-2225` (`ajax_sync_page`), `:2138` (`import_single_item_public($item)` sin run_id), `:2216-2221` (respuesta); `Products.php:1257` (`$cursor_key`), `:1264` (lectura cursor), `:1373` (escritura cursor), `:1378-1382` (borrado/actualización), `:1427-1440` (guard tombstone), `:1429` (`exists`); `Tombstone_Manager.php:32-59`, `:49-55` (`create`), `:54` (`manual_wc`), `:9-12` (docblock de `reason`); `Schema.php:159` (`reason VARCHAR(50) NOT NULL DEFAULT 'manual_wc'`); `alegra-connector.php:214` (`before_delete_post`); `uninstall.php:102` (borra `alegra_connector_products_import_cursor`; **no** existe todavía `products_import_total`).

---

## Prerequisito de harness (compartido por T4.3a, T4.3b y T4.4)

Sin estos dos cambios en `scripts/` los tests de tombstones **no se pueden escribir**. Son parte de
T4.3a/T4.3b, pero se documentan juntos acá porque los consumen las dos.

### H1 — `is_admin()` configurable (`scripts/lib/wp-stubs.php:426`)

**ANTES** (`scripts/lib/wp-stubs.php:426`):
```php
function is_admin() { return false; }
```
**DESPUÉS:**
```php
function is_admin() { return (bool) ($GLOBALS['alegra_test_is_admin'] ?? false); }
```
Y en `scripts/lib/test-framework.php` → `alegra_test_reset()` (junto a `$GLOBALS['alegra_test_referer_ok'] = true;`, `:173`):
```php
$GLOBALS['alegra_test_is_admin'] = false;
```

### H2 — `get_var()` entiende la tabla de tombstones (`scripts/lib/wp-stubs.php:1592`)

Agregar, **antes** del `return null;` final de `Alegra_Mock_Wpdb::get_var()` (`:1625`):
```php
if (strpos($query, 'alegra_tombstones') !== false
    && preg_match("/alegra_type\s*=\s*'([^']*)'/", $query, $mt)
    && preg_match("/alegra_id\s*=\s*'([^']*)'/", $query, $mi)) {
    $table = $this->prefix . 'alegra_tombstones';
    foreach (($GLOBALS['alegra_db'][$table] ?? []) as $row) {
        $resurrected = $row['resurrected_at'] ?? null;
        if ($resurrected !== null) { continue; }
        if (($row['alegra_type'] ?? '') === $mt[1] && (string) ($row['alegra_id'] ?? '') === $mi[1]) {
            return (string) ($row['reason'] ?? 'manual_wc');
        }
    }
    return null;
}
```
> El `prepare()` del stub ya reemplaza `%s` por `'valor'`, así que el `LIKE`/`=` con comillas que
> arma `exists_with_reason()` matchea estos regex. `$GLOBALS['alegra_db']` se resetea en
> `alegra_test_reset()` (`test-framework.php:157`).

**Prove-it-catches de H1+H2:** con los stubs viejos, el test `T28.43` (que inserta un tombstone y
espera `exists_with_reason() === 'bulk_wc'`) recibe `null` → falla. Con H1 viejo, el test `T28.44`
recibe `manual_wc` → falla.

---

## Fase 4 — Objetivo

Dos botones con semántica **inequívoca** en la página Productos:

| Botón | Semántica | Cursor | Tombstones | Confirmación |
|---|---|---|---|---|
| **"Traer desde Alegra"** (existente, `admin-products.php:92`) | Incremental: reanuda desde el cursor; si no hay, desde 0. Actualiza existentes, crea faltantes. | Lee/escribe `alegra_connector_products_import_cursor`; lo borra al completar. | `respect` (respeta **todos**) | No |
| **"Reimportar todo desde cero"** (nuevo) | Destructivo controlado: borra el cursor, corre desde 0. | `delete_option(cursor)` en `ajax_sync_start`. | `ignore_bulk` / `ignore_all` (ver T4.4) | Sí: `confirm()` + checkbox "recrear también los que borraste a mano" (default **tildado**) |

El cursor se **muestra** en la UI. El borrado masivo de WC se clasifica `bulk_wc` en el momento del
borrado (heurístico best-effort) y "desde cero" lo vence según la política; el borrado manual se
protege salvo que el comerciante destilde el checkbox.

**DoD de la fase:** REQ-RES-01/02/03, REQ-IMG-01 (parcial) verdes; el flujo "borrar todo →
reimportar desde cero" recrea el catálogo venciendo los tombstones `bulk_wc` sin tocar los
`manual_wc` (salvo checkbox) y sin tocar `alegra_deleted`.

---

### T4.1 — Dos botones + indicador de cursor + checkbox del reimport

**Objetivo**: que el comerciante vea **dos acciones distintas** (reanudar vs reimportar desde cero)
y sepa, sin adivinar, en qué ítem quedó la importación; y que el checkbox de tombstones exista en el
modal para que T4.5 lo cablee.

**Descripción técnica**: hoy `templates/admin-products.php:88-105` tiene un solo botón de traer
(`:92`, `.alegra-quick-sync`), más "Traer seleccionados" (`:102`, `.alegra-bulk-import`) sin
jerarquía de alcance (hallazgo C2 de la propuesta). El cursor existe (`Products.php:1264`,
`:1373-1381`) pero la UI no lo muestra (REQ-RES-01). Se agrega el segundo botón destructivo
(`ac-btn-danger`, clase ya definida en `admin/assets/css/admin.css:459`) y un badge de pausa arriba de la
card de acciones. El botón nuevo reusa el **mismo modal de filtros** (`#alegra-import-filter-modal`,
`:270-340`) que el botón existente; el checkbox de tombstones vive ahí, oculto salvo que el trigger
sea "desde cero". Decisión D4 (`design.md:584-608`); cubre REQ-RES-01/02, C1 y C2.

**Desarrollo técnico**

Archivo: `templates/admin-products.php`.

1) **Indicador de cursor**, inmediatamente **antes** de la card de acciones (antes de `:88`):
```php
<?php
$ac_cursor = (int) get_option('alegra_connector_products_import_cursor', 0);
$ac_import_total = (int) get_option('alegra_connector_products_import_total', 0);
if ($ac_cursor > 0): ?>
    <div class="ac-notice warning" style="margin-bottom:14px;">
        <span class="ac-badge warning"><?php echo esc_html(sprintf(
            /* translators: %1$s: ítem del cursor, %2$s: total o "?" */
            __('Pausado en el ítem %1$s de %2$s. "Traer desde Alegra" continúa desde ahí.', 'alegra-connector'),
            number_format_i18n($ac_cursor),
            $ac_import_total > 0 ? number_format_i18n($ac_import_total) : '?'
        )); ?></span>
    </div>
<?php endif; ?>
```
> `get_option` directo en el template es correcto: `render_products_page()` (`Admin_Dashboard.php:1323-1475`) incluye el template al final (`:1475`) y no pasa estas variables; el design §5.1 lo hace igual. `number_format_i18n` es core WP.

2) **Botón nuevo**, **después** del botón `:92` y antes de `:96` (botón de inventario):
```php
<button type="button" class="ac-btn ac-btn-danger ac-btn-sm alegra-quick-sync"
        data-type="products" data-from-zero="1" data-requires-filter="1"
        <?php echo !$connected ? 'disabled' : ''; ?>>
    <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;"></span>
    <?php esc_html_e('Reimportar todo desde cero', 'alegra-connector'); ?>
</button>
```
- `data-from-zero="1"` → lo lee el JS (T4.5/T4.2) y el server (T2.3).
- `data-requires-filter="1"` → reusa `openImportFilterModal()` (`admin.js:294`).
- `ac-btn-danger` → ya existe (`admin/assets/css/admin.css:459`).
- **No** usar `dashicons-database-import`: no existe en el set de dashicons del repo (0 coincidencias).

3) **Checkbox del reimport** dentro del modal de filtros, **antes** de `.ac-modal-actions`
(`admin-products.php:334`):
```php
<div id="ac-filter-from-zero-block" style="display:none;margin-top:12px;padding:10px 12px;border:1px solid var(--ac-warning);border-radius:6px;background:var(--ac-warning-bg);">
    <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
        <input type="checkbox" id="ac-filter-recreate-manual" checked>
        <span id="ac-filter-recreate-manual-label"></span>
    </label>
    <p style="margin:6px 0 0;font-size:11px;color:var(--ac-text-muted);">
        <?php esc_html_e('Si lo destildás, solo se recrean los productos que desaparecieron por un borrado masivo.', 'alegra-connector'); ?>
    </p>
</div>
```
- **El label NO se hardcodea.** Su texto se inyecta desde `S.confirmRecreateManual`, la clave
  **canónica** (dueño **T3.3.e**, `fase-3:588`; texto `¿Recrear también los productos que borraste a mano?`).
  El `<span>` arranca vacío y lo llena el `<script>` del paso 4: así hay **un solo** literal (en
  `get_script_strings()`) y no se duplica el copy (cierra **M6**).
- El checkbox está **tildado por defecto** (default `ignore_all`, `design.md:644-659`).
- `openImportFilterModal()` (T4.5) muestra el bloque sólo si el trigger tiene `data-from-zero`.

4) **Consumir la string canónica** al final de `templates/admin-products.php` (después de
   `<?php include __DIR__ . '/footer.php'; ?>`, mismo patrón que `admin-monitor.php`/`admin-import.php`:
   el payload `alegraConnector` se imprime con los scripts del footer, así que hay que esperar el DOM ready):
```php
<script>
jQuery(function ($) {
    var S = (window.alegraConnector && window.alegraConnector.strings) || {};
    var $label = $('#ac-filter-recreate-manual-label');
    if ($label.length && S.confirmRecreateManual) {
        $label.text(S.confirmRecreateManual);
    }
});
</script>
```
- **NO** se toca `get_script_strings()`: la clave ya la declara **T3.3.e** (`fase-3:588`). T4.1 sólo la
  consume (tabla canónica, `fase-6:683`). **NO tocar `languages/*`**.

**Resultado esperado**
- La página Productos muestra **dos** botones de traer con etiquetas inequívocas y colores distintos.
- Con `alegra_connector_products_import_cursor = 1500` y `..._total = 5000`, arriba de la card
  aparece "Pausado en el ítem 1.500 de 5.000…".
- Con cursor `0` o ausente, **no** aparece badge (ni `?`).
- El modal de filtros contiene `#ac-filter-recreate-manual` tildado por defecto y su label muestra el
  texto **canónico** `¿Recrear también los productos que borraste a mano?` (consumido de
  `S.confirmRecreateManual`, no hardcodeado).

**Dependencias**: ninguna (template puro + el `<script>` que consume la string de T3.3.e). T4.5 consume el checkbox; T4.2 consume `data-from-zero`.

**Trazabilidad**: REQ-RES-01, REQ-RES-02, C1, C2.

**Verificación**
1. Test (source-scan) en `scripts/exec-test.php`:
```php
TestRunner::test('T28.41 the products template has the from-zero button and the cursor indicator', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-products.php');
    TestRunner::assertStringContains('data-from-zero="1"', $tpl, 'the destructive button must be present');
    TestRunner::assertStringContains('Reimportar todo desde cero', $tpl, 'the destructive label must be present');
    TestRunner::assertStringContains("get_option('alegra_connector_products_import_cursor', 0)", $tpl, 'the cursor indicator must read the option');
    TestRunner::assertStringContains("if (\$ac_cursor > 0)", $tpl, 'the badge must be gated on a positive cursor');
    TestRunner::assertStringContains('ac-filter-recreate-manual', $tpl, 'the recreate-manual checkbox must exist');
    TestRunner::assertStringContains('ac-filter-recreate-manual" checked', $tpl, 'the checkbox must default to checked');
    TestRunner::assertStringContains('ac-filter-recreate-manual-label', $tpl, 'the label element must exist (populated from S)');
    TestRunner::assertStringContains('S.confirmRecreateManual', $tpl, 'the label must consume the canonical JS string');
    TestRunner::assertStringNotContains('que borré a mano', $tpl, 'the label must not hardcode the copy');
});
```
2. Manual: Ajustes → `wp option update alegra_connector_products_import_cursor 1500` y
   `..._total 5000`; abrir Productos → badge visible; `wp option delete ...cursor` → sin badge.
   Abrir el modal con "Reimportar todo desde cero" → el label del checkbox dice
   "¿Recrear también los productos que borraste a mano?" (mismo texto que `S.confirmRecreateManual`).

**Prove-it-catches**: cambiar `if ($ac_cursor > 0)` por `if (true)` → el test de "cursor 0 no
muestra pausa" (que se escribe como `assertStringContains("if (\$ac_cursor > 0)", $tpl)`) falla.
Volver a hardcodear el label (o quitar el `<script>`) → las aserciones
`S.confirmRecreateManual`/`ac-filter-recreate-manual-label` y `assertStringNotContains('que borré a mano')` fallan.

**Riesgo**: que el badge se muestre con cursor 0 (pausa falsa). Guard: la condición `$ac_cursor > 0`.
Que el botón quede habilitado sin conexión. Guard: `!$connected ? 'disabled' : ''`.
Que el label quede vacío si el JS no corre → inocuo: el bloque arranca `display:none` y sólo lo
muestra T4.5 (JS); si el JS no corre, el bloque nunca se ve.

**Estimación**: S (2 h).

---

### T4.2 — JS: enviar `from_zero`/`recreate_manual` en el POST de `ajax_sync_start`

**Objetivo**: que el JS mande `from_zero`/`recreate_manual` al arrancar el chunked, leyendo el estado
que T4.5 deja en `AlegraConnector`, **sin** re-implementar nada del lado servidor.

**Descripción técnica**: el **estado del servidor es propiedad exclusiva de T2.3** (Fase 2). T2.3 ya
lee `$_POST['from_zero']`/`$_POST['recreate_manual']`, hace `delete_option(cursor)` y
`delete_option('alegra_connector_products_import_total')`, fija `$start=0`, deriva `$state['policy']`
(`respect`/`ignore_bulk`/`ignore_all`), calcula `resuming` y responde `run_id`/`start`
(`fase-2:557-624`). **T4.2 NO duplica nada de eso.** Lo único que aporta es el lado JS: (a) incluir
ambos flags en el `data:` del request `alegra_sync_start`; (b) declarar sus defaults en el objeto
`AlegraConnector` para que el primer click no mande `undefined`. T4.5 es quien setea esos flags desde
el checkbox/trigger. Decisión D4 (`design.md:593`, `:676-686`); cubre REQ-RES-02.

> **Redundancia eliminada (Momus C1).** Este documento listaba antes el bloque servidor completo
> (shape de `$state`, derivación de `policy`, borrado del total, `resuming`) como "pendiente de T4.2".
> Eso es **código de T2.3**. Mantenerlo acá producía dos "DESPUÉS" incompatibles del mismo bloque
> (`T2.3` incluye `'images'` y no incluye `'page'`; `T4.2` hacía lo inverso) y rompía
> `merge_image_stats($state['images'], …)` de T3.4/T5.2 con "undefined key". **Dueño único del shape
> y del estado: T2.3.** T4.2 se reduce a JS.

**Desarrollo técnico**

Archivo: `admin/assets/js/admin.js` (`initSyncNow`, `:212-217`).

**ANTES**:
```js
data: {
    action: 'alegra_sync_start', _ajax_nonce: alegraConnector.nonce, sync_type: types,
    filters: JSON.stringify(AlegraConnector.pendingFilters || {})
},
```
**DESPUÉS**:
```js
data: {
    action: 'alegra_sync_start', _ajax_nonce: alegraConnector.nonce, sync_type: types,
    filters: JSON.stringify(AlegraConnector.pendingFilters || {}),
    from_zero: AlegraConnector.pendingFromZero ? 1 : 0,
    recreate_manual: AlegraConnector.pendingRecreateManual ? 1 : 0
},
```
`pendingFromZero`/`pendingRecreateManual` los setea T4.5 en `run()`; agregar sus defaults en el
objeto `AlegraConnector` (junto a `pendingFilters: {}`, `admin.js:41`):
```js
pendingFromZero: false,
pendingRecreateManual: false,
```

**Resultado esperado**
- El POST de `alegra_sync_start` incluye **siempre** `from_zero` y `recreate_manual` (0 por default).
- El botón normal "Traer desde Alegra" manda `from_zero=0&recreate_manual=0`; "desde cero" manda los
  valores que fijó T4.5.
- El servidor (T2.3) es el único que deriva `policy`/`resuming`, limpia el cursor y arranca en 0.

**Dependencias**: T2.3 (server-side, **dueño del estado**), T4.1 (botón). T4.5 (co-requisito: setea los flags que T4.2 sólo envía; el orden de build es T4.2 → T4.5).

**Trazabilidad**: REQ-RES-02, REQ-RES-04.

**Verificación** (source-scan, `scripts/exec-test.php`):
```php
TestRunner::test('T28.42 the start request carries the from-zero and recreate-manual flags', function (): void {
    $js = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/assets/js/admin.js');
    TestRunner::assertStringContains('from_zero: AlegraConnector.pendingFromZero ? 1 : 0', $js, 'the start request must send from_zero');
    TestRunner::assertStringContains('recreate_manual: AlegraConnector.pendingRecreateManual ? 1 : 0', $js, 'the start request must send recreate_manual');
    TestRunner::assertStringContains('pendingFromZero: false', $js, 'the from-zero flag must default to false');
    TestRunner::assertStringContains('pendingRecreateManual: false', $js, 'the checkbox flag must default to false');
});
```
> El comportamiento servidor (cursor borrado, `start=0`, `policy`) se verifica en los tests `T2.3` de
> Fase 2 (`fase-2:640-651`). **No** se re-testea acá para no duplicar la cobertura.

**Prove-it-catches**: quitar `from_zero:` del `data:` del request → el source-scan de `T28.42` falla.

**Riesgo**: mandar `undefined` en el primer click (el server lo trataría como `false`, silencioso).
Guard: los defaults `pendingFromZero`/`pendingRecreateManual` en `AlegraConnector`.

**Estimación**: S (1 h).

---

### T4.3a — `Tombstone_Manager::exists_with_reason()` + `exists()` delega · BLOQUEADO(Fase 0.2 / G2)

**Objetivo**: exponer el `reason` de un tombstone (no sólo si existe) para que la política de T4.4
pueda decidir, sin romper `exists()`.

**Descripción técnica**: hoy `exists()` (`Tombstone_Manager.php:107-122`) devuelve un `bool` y
descarta el `reason`. La política de D4 (`design.md:639-674`) necesita saber si el tombstone es
`bulk_wc`, `manual_wc` o `alegra_deleted`. Se agrega `exists_with_reason(): ?string` y `exists()`
delega. `reason` es `VARCHAR(50)` sin enum (`Schema.php:159`) → **sin migración**. Cubre REQ-RES-03.

**Desarrollo técnico**

Archivo: `includes/Tombstone_Manager.php`. Insertar el método nuevo **después** de `exists()`
(`:122`) y reescribir `exists()`:

**ANTES** (`:107-122`):
```php
public static function exists(string $alegra_type, string $alegra_id): bool
{
    global $wpdb;
    $table = $wpdb->prefix . 'alegra_tombstones';

    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table
         WHERE alegra_type = %s AND alegra_id = %s
         AND resurrected_at IS NULL
         LIMIT 1",
        $alegra_type,
        $alegra_id
    ));

    return (bool) $found;
}
```
**DESPUÉS:**
```php
/**
 * Devuelve el `reason` del tombstone vigente, o null si no hay.
 * reason: 'manual_wc' | 'bulk_wc' | 'alegra_deleted' (Schema.php:159, VARCHAR(50)).
 */
public static function exists_with_reason(string $alegra_type, string $alegra_id): ?string
{
    global $wpdb;
    $table = $wpdb->prefix . 'alegra_tombstones';

    $reason = $wpdb->get_var($wpdb->prepare(
        "SELECT reason FROM $table
         WHERE alegra_type = %s AND alegra_id = %s
         AND resurrected_at IS NULL
         LIMIT 1",
        $alegra_type,
        $alegra_id
    ));

    return $reason !== null ? (string) $reason : null;
}

/**
 * @deprecated Usar exists_with_reason() cuando haga falta la política (D4).
 */
public static function exists(string $alegra_type, string $alegra_id): bool
{
    return self::exists_with_reason($alegra_type, $alegra_id) !== null;
}
```
Actualizar el docblock de la clase (`:9-12`) para documentar `'bulk_wc'`:
```
 *   - 'manual_wc'      : user deleted the product/customer in WP admin
 *   - 'bulk_wc'        : deleted through the WP/WC bulk-delete action (2.5.0)
 *   - 'alegra_deleted' : webhook received from Alegra that the item/client
 *                        was deleted in Alegra (added in 2.1.9)
```

**Prerequisito**: H1 + H2 (ver arriba) — sin H2, `exists_with_reason()` siempre da `null` en el harness.

**Resultado esperado**
- `exists_with_reason('item', $id)` devuelve el `reason` crudo (`'bulk_wc'`/`'manual_wc'`/`'alegra_deleted'`) o `null`.
- `exists()` devuelve exactamente lo mismo que `exists_with_reason() !== null`.
- Un tombstone con `resurrected_at` seteado **no** cuenta (ambos métodos).

**Dependencias**: H1, H2. (T4.3b puede ir en paralelo; T4.4 depende de este.)

**Trazabilidad**: REQ-RES-03.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T28.43 exists_with_reason returns the reason and exists() delegates', function (): void {
    alegra_test_reset();
    \Alegra\Connector\Tombstone_Manager::create([
        'alegra_id' => 'itm-1', 'alegra_type' => 'item', 'wc_post_id' => 10,
        'deleted_by' => 1, 'reason' => 'bulk_wc',
    ]);
    TestRunner::assertSame('bulk_wc', \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', 'itm-1'), 'reason must round-trip');
    TestRunner::assertTrue(\Alegra\Connector\Tombstone_Manager::exists('item', 'itm-1'), 'exists() must delegate');

    // LIMITACIÓN DE HARNESS (opción b): `mark_resurrected()` usa `$wpdb->update`, que el stub
    // NO aplica (`wp-stubs.php:1733-1736`). Se siembra la fila `resurrected_at` directamente
    // para cubrir el filtro `resurrected_at IS NULL` que H2 replica en `get_var()`.
    $GLOBALS['alegra_db']['wp_alegra_tombstones'][0]['resurrected_at'] = '2026-01-01 00:00:00';
    TestRunner::assertSame(null, \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', 'itm-1'), 'a resurrected tombstone is gone');
    TestRunner::assertFalse(\Alegra\Connector\Tombstone_Manager::exists('item', 'itm-1'), 'exists() mirrors the reason query');
});
```
> **Limitación de harness — decisión: opción (b), acotar + chequeo manual.** El stub `update()`
> (`scripts/lib/wp-stubs.php:1733-1736`) es un no-op, así que `mark_resurrected()` no muta
> `resurrected_at` en `$GLOBALS['alegra_db']`. La opción (a) —agregar mutación genérica al `update()`
> del stub— toca infraestructura compartida por muchos tests, aumenta la superficie de regresión y
> solapa con el trabajo H1/H2 de Fase 1/7 (que este documento **referencia**, no edita). En cambio el
> harness **sí** puede verificar el filtro `resurrected_at IS NULL` sembrando la fila directamente
> (arriba), porque H2 ya saltea las filas resurrected al leer `get_var()`. El único camino no
> cubierto por el harness es el **write** real de `mark_resurrected()`.
>
> **Chequeo manual** (cubre ese write): con un tombstone `bulk_wc` sembrado,
> `wp eval '\Alegra\Connector\Tombstone_Manager::mark_resurrected("item","<id>");'` y luego
> `wp eval 'var_dump(\Alegra\Connector\Tombstone_Manager::exists_with_reason("item","<id>"));'`
> → `NULL` (la fila quedó con `resurrected_at` no nulo).

**Prove-it-catches**: volver `exists()` a su consulta `SELECT id` y **no** crear `exists_with_reason` →
el test no compila/fatalea (método ausente) o `assertSame('bulk_wc', …)` falla.

**Riesgo**: romper llamadores de `exists()` (sólo `Products.php:1429`). Guard: `exists()` conserva la
firma y el resultado; el test de delegación lo cubre.

**Estimación**: S/M (1.5 h, incluye H2).

---

### T4.3b — `classify_delete_reason()` + `on_post_delete` escribe `bulk_wc` · BLOQUEADO(Fase 0.2 / G2)

**Objetivo**: distinguir, best-effort, un borrado masivo de WC de uno individual en el momento del
borrado, para que el tombstone nazca con el `reason` correcto.

**Descripción técnica**: `on_post_delete` (`Tombstone_Manager.php:32-59`) escribe
`'reason' => 'manual_wc'` hardcodeado (`:54`) → masivo e individual son indistinguibles (problema de
fondo de REQ-RES-03, `spec.md:653-664`). La decisión del design (tercera opción, `design.md:616-637`)
es clasificar en el request: WC usa la lista de WP; el borrado individual llega
`action=delete&post=123` (escalar), el masivo `action=delete&post[]=1&post[]=2` (array), y "vaciar
papelera" **NO** llega como `action=delete_all` (corrección C8/D5): es un submit con
`name="delete_all"` / `delete_all2` y `action` en `-1`; `WP_Posts_List_Table::current_action()`
devuelve `'delete_all'` mirando `isset($_REQUEST['delete_all'])`. El default seguro es `manual_wc`
(WP-CLI/REST/programático no tienen `$_REQUEST`). Cubre REQ-RES-03.

**Desarrollo técnico**

Archivo: `includes/Tombstone_Manager.php`. Agregar el método privado (antes de `on_post_delete`,
`:32`):
```php
/**
 * Clasifica best-effort el borrado. Default seguro: manual_wc (WP-CLI, REST y
 * llamadas programáticas no tienen $_REQUEST). D4, design.md:616-637.
 *
 * WordPress NO manda `action=delete_all`: "Empty Trash" es un submit
 * `name="delete_all"` (top) / `delete_all2` (bottom), y
 * WP_Posts_List_Table::current_action() devuelve 'delete_all' con
 * isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2']).
 * Ver wp-admin/includes/class-wp-posts-list-table.php:606 y :625-631.
 */
private static function classify_delete_reason(): string
{
    if (!is_admin()) {
        return 'manual_wc';
    }

    // 1) "Empty Trash": señal PRIMARIA, no `action=delete_all` (D5).
    if (isset($_REQUEST['delete_all']) || isset($_REQUEST['delete_all2'])) {
        return 'bulk_wc';
    }

    // 2) La acción viaja en `action` (select de arriba) o `action2` (select de
    //    abajo). WP ignora `-1`, así que se cae al fallback `action2`.
    $action = isset($_REQUEST['action'])
        ? sanitize_key(wp_unslash((string) $_REQUEST['action']))
        : '';
    if ($action === '' || $action === '-1') {
        $action = isset($_REQUEST['action2'])
            ? sanitize_key(wp_unslash((string) $_REQUEST['action2']))
            : '';
    }
    if ($action === 'delete_all') { // defensivo: algunos plugins lo postean así
        return 'bulk_wc';
    }

    // 3) Selección masiva: WP manda `post[]` (array). Un solo ítem (array de
    //    largo 1) cae en manual_wc (default seguro).
    $post = $_REQUEST['post'] ?? null;
    if (is_array($post) && count($post) > 1) {
        return 'bulk_wc';
    }

    return 'manual_wc';
}
```

**ANTES** (`on_post_delete`, `:49-55`):
```php
self::create([
    'alegra_id' => $alegra_id,
    'alegra_type' => 'item',
    'wc_post_id' => $post_id,
    'deleted_by' => get_current_user_id(),
    'reason' => 'manual_wc',
]);
```
**DESPUÉS:**
```php
self::create([
    'alegra_id' => $alegra_id,
    'alegra_type' => 'item',
    'wc_post_id' => $post_id,
    'deleted_by' => get_current_user_id(),
    'reason' => self::classify_delete_reason(),
]);
```

**Cobertura del heurístico — todos los caminos que pueden disparar `before_delete_post`**
(`alegra-connector.php:214` → `Tombstone_Manager::on_post_delete`, `:32-59`):

| Camino de borrado | Shape del request | ¿Lo detecta? | `reason` escrito |
|---|---|---|---|
| Fila individual "Borrar" | `action=delete&post=123` (escalar) | Sí (default) | `manual_wc` |
| Fila individual "Mover a papelera" | `action=trash&post=123` | Sí (default) | `manual_wc` |
| Bulk **top** "Aplicar" | `action=delete&post[]=1&post[]=2` | Sí (`post[]` > 1) | `bulk_wc` |
| Bulk **bottom** "Aplicar" (`action2`) | `action=-1&action2=delete&post[]=1&post[]=2` | Sí (fallback `action2` + `post[]`) | `bulk_wc` |
| **"Empty Trash"** (`delete_all`) | `delete_all=Empty+Trash&post_status=trash` | **Sí (fix D5)** | `bulk_wc` |
| REST `DELETE /wp-json/wp/v2/product/{id}` | sin `$_REQUEST` / `is_admin()=false` | **No** | `manual_wc` (default seguro) |
| WP-CLI `wp post delete …` | sin `$_REQUEST` | **No** | `manual_wc` (default seguro) |
| `wp_delete_post()` / `wc_delete_product()` programático | sin `$_REQUEST` | **No** | `manual_wc` (default seguro) |
| Bulk con **1** ítem tildado | `post[]` de largo 1 | No (por diseño) | `manual_wc` (default seguro) |

> **Caminos indetectables → fallback.** REST, WP-CLI y los borrados programáticos no tienen
> `$_REQUEST`, así que no hay forma de distinguirlos de un borrado manual: se clasifican `manual_wc`
> (default seguro). **Red de seguridad explícita (mitigación de diseño):** el checkbox "Recrear
> también los productos que borraste a mano" viene **tildado por defecto** (`ignore_all`,
> `design.md:650-659`). Por eso, aunque el heurístico falle y un borrado masivo quede como
> `manual_wc`, el flujo real del comerciante "borrar todo → reimportar desde cero" **igual recrea**
> el catálogo. Una misclasificación **nunca bloquea** ese workflow; sólo si el comerciante
> **destilda** el checkbox (`ignore_bulk`) un bulk no detectado no se recrea. El copy de UI **no**
> debe prometer que el borrado masivo se detecta siempre.

**Prerequisito**: H1 (`is_admin()` configurable). Sin H1, `is_admin()` es `false` fijo y el test de
`bulk_wc` no puede pasar.

**Resultado esperado**
- `$_REQUEST = ['action'=>'delete','post'=>['1','2']]` + `is_admin()=true` → tombstone `bulk_wc`.
- `$_REQUEST = ['action'=>'delete','post'=>'123']` + `is_admin()=true` → `manual_wc`.
- `$_REQUEST = ['delete_all'=>'Empty Trash']` + `is_admin()=true` → `bulk_wc` (D5).
- `$_REQUEST = ['action'=>'-1','action2'=>'delete','post'=>['1','2']]` + `is_admin()=true` → `bulk_wc`.
- `$_REQUEST = ['action'=>'delete','post'=>['1']]` (bulk de 1) + `is_admin()=true` → `manual_wc`.
- Fuera de admin (`is_admin()=false`) o sin `$_REQUEST` → `manual_wc` (default seguro).

**Dependencias**: H1.

**Trazabilidad**: REQ-RES-03.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T28.44 classify_delete_reason distinguishes bulk from individual', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_is_admin'] = true;

    $_REQUEST = ['action' => 'delete', 'post' => ['1', '2']];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'array post[] with >1 items is bulk');

    $_REQUEST = ['action' => 'delete', 'post' => '123'];
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'scalar post is individual');

    // D5: "Empty Trash" es un submit name=delete_all, NO action=delete_all.
    $_REQUEST = ['delete_all' => 'Empty Trash'];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'the delete_all submit (Empty Trash) is bulk');

    // Bottom "Apply": la acción viaja en action2 y action queda en -1.
    $_REQUEST = ['action' => '-1', 'action2' => 'delete', 'post' => ['1', '2']];
    TestRunner::assertSame('bulk_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'the bottom Apply travels in action2');

    // Bulk de un solo ítem: default seguro.
    $_REQUEST = ['action' => 'delete', 'post' => ['1']];
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'a one-item selection is the safe default');

    $_REQUEST = ['action' => 'delete', 'post' => ['1', '2']];
    $GLOBALS['alegra_test_is_admin'] = false;
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'outside admin the default is the safe manual_wc');

    $_REQUEST = [];
    $GLOBALS['alegra_test_is_admin'] = true;
    TestRunner::assertSame('manual_wc', alegra_call_private_static(
        \Alegra\Connector\Tombstone_Manager::class, 'classify_delete_reason'
    ), 'no request context is the safe default');
});
```
Y el test de integración de `on_post_delete` (seam de `create` → `$GLOBALS['alegra_db']`):
```php
TestRunner::test('T28.45 on_post_delete records bulk_wc on a bulk delete', function (): void {
    alegra_test_reset();
    $GLOBALS['alegra_test_is_admin'] = true;
    $GLOBALS['wp_posts'][55] = (object) ['ID' => 55, 'post_type' => 'product'];
    update_post_meta(55, '_alegra_item_id', 'itm-55');
    $_REQUEST = ['action' => 'delete', 'post' => ['55', '56']];
    \Alegra\Connector\Tombstone_Manager::on_post_delete(55);
    $rows = $GLOBALS['alegra_db']['wp_alegra_tombstones'] ?? [];
    TestRunner::assertSame('bulk_wc', $rows[0]['reason'] ?? null, 'the tombstone must be bulk_wc');

    // "Empty Trash" (delete_all como submit name) también debe nacer bulk_wc.
    $GLOBALS['alegra_db']['wp_alegra_tombstones'] = [];
    $GLOBALS['wp_posts'][66] = (object) ['ID' => 66, 'post_type' => 'product'];
    update_post_meta(66, '_alegra_item_id', 'itm-66');
    $_REQUEST = ['delete_all' => 'Empty Trash', 'post_status' => 'trash'];
    \Alegra\Connector\Tombstone_Manager::on_post_delete(66);
    $rows = $GLOBALS['alegra_db']['wp_alegra_tombstones'] ?? [];
    TestRunner::assertSame('bulk_wc', $rows[0]['reason'] ?? null, 'empty trash must be bulk_wc');
});
```
> `on_post_delete` usa `get_post()` (stub `:794`), `wp_is_post_revision()` (`:795`, siempre false),
> `get_post_meta()` (`:252`) y `Entity_Map::remove()` (`:58`). El `create()` del stub inserta en
> `$GLOBALS['alegra_db']` (`:1725-1731`). `get_current_user_id()` devuelve 1 (`:430`).

**Prove-it-catches**: volver `'reason' => 'manual_wc'` → el test de integración falla.
Volver al chequeo viejo `$action === 'delete_all'` (sin `isset($_REQUEST['delete_all'])`) → el caso
"Empty Trash" de `T28.44`/`T28.45` falla (D5). Quitar el fallback `action2` → el caso "bottom Apply"
de `T28.44` falla. Quitar el `if (!is_admin())` → el test "outside admin" falla.

**Riesgo residual del heurístico (LEER)**
- **No es 100% confiable** (ver la tabla de cobertura de arriba). Los caminos sin `$_REQUEST`
  (REST/WP-CLI/`wp_delete_post()` programático) y el bulk de **un solo** ítem caen en `manual_wc`
  (default seguro). El heurístico **sí** cubre ahora los 4 caminos de la UI de WP: fila individual,
  bulk top (`post[]`), bulk bottom (`action2`) y "Empty Trash" (`delete_all`).
- **Red de seguridad explícita**: el checkbox de "desde cero" tiene default **`ignore_all`** (recrear
  todo), así que aunque el heurístico falle, el flujo "borré todo → reimporté desde cero" **igual
  recrea** el catálogo. El botón normal "Traer" siempre es `respect`, así que un borrado puntual no
  resucita en un resume.
- **Fallback si G2 demuestra que el heurístico no es fiable en esta tienda**: dejar
  `classify_delete_reason()` devolviendo `manual_wc` (sin heurístico) y confiar 100% en el checkbox
  `ignore_all` por default. El comportamiento del botón normal no cambia; "desde cero" recrea todo.
  Documentar la limitación en `CHANGELOG.md`.
- **Fase 0 (T0.2)** debe reproducir el shape real de `$_REQUEST` del bulk delete de WC en la tienda y
  registrar el resultado; si el shape observado **no** matchea ninguno de los de la tabla, ajustar el
  clasificador **antes** de cerrar la tarea.

**Estimación**: S/M (1.5 h, incluye H1).

---

### T4.4 — Política de tombstones en `Run_Context` + `Products.php:1429` · BLOQUEADO(Fase 0.2 / G2)

**Objetivo**: que "desde cero" venza los tombstones `bulk_wc` (y `manual_wc` si el checkbox está
tildado) sin resucitar nunca un `alegra_deleted`, y que el botón normal respete todos.

**Descripción técnica**: `import_single_item_from_alegra` (`Products.php:1404-1440`) saltea la
creación si `Tombstone_Manager::exists('item', $alegra_id)` (`:1429`), sin mirar el `reason` ni la
política. `Run_Context` (creado en T1.1b) ya expone `set_tombstone_policy(string)` /
`tombstone_policy(): string` (`design.md:814-817`). La matriz de D4 (`design.md:641-645`):

| Política | `bulk_wc` | `manual_wc` | `alegra_deleted` |
|---|---|---|---|
| `respect` (default del botón "Traer") | respeta | respeta | respeta |
| `ignore_bulk` (checkbox destildado) | **ignora/recrea** | respeta | respeta |
| `ignore_all` (checkbox tildado, default) | **ignora/recrea** | **ignora/recrea** | respeta |

Cubre REQ-RES-03.

> **Contrato de valores `reason` (write ↔ read).** Los valores que **escriben** el borrado
> (`manual_wc` / `bulk_wc`, T4.3b) y el webhook de Alegra (`alegra_deleted`, `Handlers.php:91`) son
> **exactamente** los que **lee** `tombstone_policy_allows()` (`bulk_wc`, `alegra_deleted`) y la
> matriz de arriba. `Schema.php:159` es `VARCHAR(50) NOT NULL DEFAULT 'manual_wc'`, sin enum →
> `bulk_wc` entra sin migración. Un typo entre escritura y lectura rompería la política en silencio
> (un `reason` desconocido cae al `return false` de `respect`); el test `T28.46` cubre las 9
> combinaciones para atraparlo.

**Desarrollo técnico**

**Parte 1 — `ajax_sync_page` setea la política desde el estado.** Archivo
`admin/Admin/Admin_Dashboard.php` (`ajax_sync_page`, `:2073-2225`). Justo después de
`Run_Context::resume($state['run_id'], 'chunked_import')` (que agrega T2.4) y **antes** del loop de
productos (`:2119`):
```php
\Alegra\Connector\Run_Context::set_tombstone_policy((string) ($state['policy'] ?? 'respect'));
```
> El cron y la ruta manual **no** la setean → queda el default `'respect'` del static
> (`design.md:105`). **Aclaración (cierra la inconsistencia I5).** `Run_Context` (T1.1b) **no**
> resetea `tombstone_policy` en `finish()`/`resume()`, y **no hace falta** que lo haga: sus statics
> son **request-scoped** y PHP los reinicializa en cada request, así que la política **nunca** se
> filtra entre requests. Dentro de un request, `ajax_sync_page` la setea **en cada página** desde
> `$state` (`'respect'`/`'ignore_bulk'`/`'ignore_all'`), y manual/cron usan el default `'respect'`.
> Agregar un reset en `Run_Context::finish` sería **código muerto** y crearía una dependencia falsa
> con Fase 1. El único caso que lo justificaría es que un mismo request setee la política y después
> corra un cron (hoy no ocurre); el guard entonces sería setear `'respect'` al final de
> `ajax_sync_page`, **no** tocar `Run_Context`.

**Parte 2 — helper de decisión + consumo en `Products.php`.**

Archivo `includes/Sync/Products.php`. Agregar un helper privado estático (cerca de
`import_single_item_from_alegra`, antes de `:1404`):
```php
/**
 * ¿La política vigente permite recrear un producto con este tombstone? (D4)
 * `alegra_deleted` NUNCA se resucita, en ninguna política.
 */
private static function tombstone_policy_allows(string $reason): bool
{
    if ($reason === 'alegra_deleted') {
        return false;
    }
    $policy = \Alegra\Connector\Run_Context::tombstone_policy();
    if ($policy === 'ignore_all') {
        return true; // recrea bulk_wc + manual_wc
    }
    if ($policy === 'ignore_bulk') {
        return $reason === 'bulk_wc';
    }
    return false; // respect
}
```

**ANTES** (`Products.php:1427-1440`):
```php
// TOMBSTONE GUARD: skip if user previously deleted this product in WC.
// Only blocks CREATION, not updates of existing products.
if (\Alegra\Connector\Tombstone_Manager::exists('item', $alegra_id)) {
    $existing_for_update = $this->get_product_by_alegra_id($alegra_id);
    if (!$existing_for_update) {
        $this->logger->info('Skipped product import: tombstone exists (user deleted in WC)', [
            'alegra_id' => $alegra_id,
            'name' => $name,
        ]);
        return 'skipped';
    }
    // Product exists in WC despite tombstone (resurrected) - clear the tombstone
    \Alegra\Connector\Tombstone_Manager::mark_resurrected('item', $alegra_id);
}
```
**DESPUÉS:**
```php
// TOMBSTONE GUARD: skip CREATION unless the active policy overrides it (D4).
// Only blocks CREATION, not updates of existing products.
$tombstone_reason = \Alegra\Connector\Tombstone_Manager::exists_with_reason('item', $alegra_id);
if ($tombstone_reason !== null && !self::tombstone_policy_allows($tombstone_reason)) {
    $existing_for_update = $this->get_product_by_alegra_id($alegra_id);
    if (!$existing_for_update) {
        $this->logger->info('Skipped product import: tombstone respected by policy', [
            'alegra_id' => $alegra_id,
            'name' => $name,
            'reason' => $tombstone_reason,
            'policy' => \Alegra\Connector\Run_Context::tombstone_policy(),
        ]);
        return 'skipped';
    }
    // Product exists in WC despite tombstone (resurrected) - clear the tombstone
    \Alegra\Connector\Tombstone_Manager::mark_resurrected('item', $alegra_id);
}
```
> Si la política **ignora** el tombstone, el bloque entero se saltea y el flujo sigue a la creación
> (más abajo, `:1483` `wp_insert_post`). El tombstone no se marca `resurrected` en ese momento; en la
> próxima corrida el producto ya existe → el bloque entra por `$existing_for_update` y lo marca.
> Idempotente.

**Resultado esperado**
- `respect` + tombstone `manual_wc` → `'skipped'`, no crea.
- `ignore_bulk` + `bulk_wc` → crea; + `manual_wc` → `'skipped'`.
- `ignore_all` + `bulk_wc`/`manual_wc` → crea.
- Cualquier política + `alegra_deleted` → `'skipped'`.

**Dependencias**: T1.1b (`Run_Context` con `set_tombstone_policy`/`tombstone_policy`), T4.3a
(`exists_with_reason`), T2.3 (`state['policy']` en el server), T4.2 (JS que envía los flags), H2.

**Trazabilidad**: REQ-RES-03.

**Verificación** (runtime, `scripts/exec-test.php`; matriz 3×3):
```php
TestRunner::test('T28.46 the tombstone policy matrix is honoured on creation', function (): void {
    $cases = [
        // policy, reason, must_create
        ['respect',     'bulk_wc',      false],
        ['respect',     'manual_wc',    false],
        ['respect',     'alegra_deleted', false],
        ['ignore_bulk', 'bulk_wc',      true],
        ['ignore_bulk', 'manual_wc',    false],
        ['ignore_bulk', 'alegra_deleted', false],
        ['ignore_all',  'bulk_wc',      true],
        ['ignore_all',  'manual_wc',    true],
        ['ignore_all',  'alegra_deleted', false],
    ];
    foreach ($cases as [$policy, $reason, $must_create]) {
        alegra_test_reset();
        \Alegra\Connector\Run_Context::set_tombstone_policy($policy);
        \Alegra\Connector\Tombstone_Manager::create([
            'alegra_id' => 'itm-x', 'alegra_type' => 'item', 'wc_post_id' => 0,
            'deleted_by' => 1, 'reason' => $reason,
        ]);
        $r = make_products()->import_single_item_public([
            'id' => 'itm-x', 'name' => 'Producto X', 'type' => 'simple', 'reference' => 'SKU-X',
        ]);
        if ($must_create) {
            TestRunner::assertSame(true, $r, "$policy/$reason must create");
        } else {
            TestRunner::assertSame('skipped', $r, "$policy/$reason must skip");
        }
    }
});
```
> Requiere H2 para que `exists_with_reason` lea el tombstone. `import_single_item_public` no recibe
> `$run_id` (firma real, corrección C2): la política es static, así que no hace falta.

**Prove-it-catches**: volver el guard a `exists()` sin política → los casos `ignore_bulk`/`ignore_all`
que deben crear fallan. Quitar el early `return false` de `alegra_deleted` → el caso
`ignore_all`/`alegra_deleted` crea (debe skipear) y falla.

**Riesgo**: resucitar un borrado manual no deseado. Guard: el botón normal es `respect`; el default
`ignore_all` sólo aplica a "desde cero" (destructivo, confirmado); `alegra_deleted` nunca se
resucita. R6 de `fase-7` (matriz de ejecución).

**Estimación**: M (3 h).

---

### T4.5 — Confirm destructivo del reimport + checkbox + strings

**Objetivo**: que el botón "Reimportar todo desde cero" pida confirmación explícita, muestre el
checkbox de tombstones y mapee su estado a `recreate_manual` en el POST.

**Descripción técnica**: el botón nuevo (T4.1) tiene `data-from-zero="1"` y reusa el modal de filtros.
Falta: (a) el `confirm()` destructivo antes de abrir el modal; (b) que `openImportFilterModal` muestre
`#ac-filter-from-zero-block` sólo para ese trigger; (c) que `run()` lea el checkbox y lo deje en
`AlegraConnector.pendingRecreateManual` (y `pendingFromZero`). Decisión D4 (`design.md:607-608`);
cubre REQ-RES-02.

**Desarrollo técnico**

Archivo: `admin/assets/js/admin.js`.

1) **Confirm antes de abrir el modal** en `initSyncNow` (`:129-132`). **ANTES:**
```js
if ($(this).data('requires-filter') && !$(this).data('filter-confirmed')) {
    AlegraConnector.openImportFilterModal($(this));
    return;
}
```
**DESPUÉS:**
```js
if ($(this).data('requires-filter') && !$(this).data('filter-confirmed')) {
    if ($(this).data('from-zero') && !confirm(S.confirmReimport)) {
        return; // cancelar no toca nada (ni cursor ni import)
    }
    AlegraConnector.openImportFilterModal($(this));
    return;
}
```

2) **Mostrar/ocultar el bloque del checkbox** en `openImportFilterModal` (`:294-306`). Agregar al
final del método, antes del cierre:
```js
var fromZero = !!($btn && $btn.data('from-zero'));
$('#ac-filter-from-zero-block').toggle(fromZero);
if (fromZero) { $('#ac-filter-recreate-manual').prop('checked', true); } // default: recrear todo
```

3) **Leer el checkbox en `run()`** (`:345-351`). **ANTES:**
```js
function run($btn, filters) {
    AlegraConnector.pendingFilters = filters || {};
    closeModal();
    if ($btn && $btn.length) {
        $btn.data('filter-confirmed', true).trigger('click');
    }
}
```
**DESPUÉS:**
```js
function run($btn, filters) {
    AlegraConnector.pendingFilters = filters || {};
    AlegraConnector.pendingFromZero = !!($btn && $btn.data('from-zero'));
    AlegraConnector.pendingRecreateManual = $('#ac-filter-recreate-manual').is(':checked');
    closeModal();
    if ($btn && $btn.length) {
        $btn.data('filter-confirmed', true).trigger('click');
    }
}
```

4) **Strings** en `Admin_Dashboard::get_script_strings()` (`:652+`, junto a `confirmClearLogs`
`:705`). **Dueño único: T3.3.e** (`fase-3:584`), que ya declara `confirmReimport`. T4.5 **no** la
re-declara (dos claves iguales en el mismo array se pisan en silencio, Oracle D13): sólo la consume
vía `S.confirmReimport` en el `confirm()` de arriba. Si el copy debe mencionar explícitamente que
puede recrear productos borrados, el ajuste va en T3.3.e (coordinar), **no** acá.
`confirmRecreateManual` (label del checkbox) la consume **T4.1** vía `S.confirmRecreateManual` (paso 4
de T4.1); T4.5 **no** la redeclara ni la lee. **NO tocar `languages/*`** (restricción del comerciante).

**Resultado esperado**
- Click en "Reimportar todo desde cero" → `confirm()` con el copy destructivo.
- Rechazar → no se abre el modal, no se limpia el cursor, no se importa.
- Aceptar → modal con el checkbox tildado; "Aplicar y traer" → POST con
  `from_zero=1&recreate_manual=1`.
- El botón normal "Traer desde Alegra" → sin `confirm`, sin bloque de checkbox, POST
  `from_zero=0&recreate_manual=0`.

**Dependencias**: T4.1 (botón + checkbox), T4.2 (envía `pendingFromZero`/`pendingRecreateManual` en el POST), T2.3 (server).

**Trazabilidad**: REQ-RES-02.

**Verificación**
1. Source-scan (`scripts/exec-test.php`):
```php
TestRunner::test('T28.47 the reimport flow confirms and reads the checkbox', function (): void {
    $js = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/assets/js/admin.js');
    TestRunner::assertStringContains('confirm(S.confirmReimport)', $js, 'the destructive button must confirm');
    TestRunner::assertStringContains('ac-filter-recreate-manual', $js, 'the JS must read the recreate-manual checkbox');
    TestRunner::assertStringContains('pendingRecreateManual', $js, 'the checkbox state must travel to the start request');
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'confirmReimport'", $admin, 'the string must be localizable');
});
```
2. Manual: click → confirm; rechazar → sin cambios; aceptar → Network tab muestra
   `from_zero=1&recreate_manual=1`.

**Prove-it-catches**: quitar el `if ($(this).data('from-zero') && !confirm(...)) return;` → el
source-scan de `confirm(S.confirmReimport)` falla.

**Riesgo**: que el checkbox quede `checked` para el botón normal (resucitaría manuales en un resume).
Guard: `openImportFilterModal` sólo lo muestra/tilda con `data-from-zero`; `pendingRecreateManual`
sólo se setea en `run()` con el trigger correcto.

**Estimación**: S (1.5 h).

---

## DoD Fase 4

- REQ-RES-01/02/03 verdes.
- Dos botones inequívocos; cursor visible cuando `>0`; "desde cero" limpia el cursor y arranca en 0.
- El flujo "borrar todo (bulk) → reimportar desde cero" recrea el catálogo venciendo `bulk_wc`.
- El botón normal nunca resucita un `manual_wc` (`respect`).
- `alegra_deleted` nunca se resucita, en ninguna política.
- `bash scripts/exec-test.sh` verde con los tests `T28.41`–`T28.47` y sus `prove-it-catches` aplicados.

## Índice de tests nuevos (Fase 4)

| Test | Tipo | Archivo |
|---|---|---|
| `T28.41 products template has the from-zero button and the cursor indicator` | source-scan | `scripts/exec-test.php` |
| `T28.42 the start request carries the from-zero and recreate-manual flags` | source-scan (JS) | `scripts/exec-test.php` |
| `T28.43 exists_with_reason returns the reason and exists() delegates` | runtime (`$wpdb` stub H2) | `scripts/exec-test.php` |
| `T28.44 classify_delete_reason distinguishes bulk from individual` | runtime (H1) | `scripts/exec-test.php` |
| `T28.45 on_post_delete records bulk_wc on a bulk delete` | runtime (H1 + `$GLOBALS['alegra_db']`) | `scripts/exec-test.php` |
| `T28.46 the tombstone policy matrix is honoured on creation` | runtime (H2) | `scripts/exec-test.php` |
| `T28.47 the reimport flow confirms and reads the checkbox` | source-scan | `scripts/exec-test.php` |

## Trazabilidad tarea → requerimiento

| Requerimiento | Tareas |
|---|---|
| REQ-RES-01 | T4.1 |
| REQ-RES-02 | T4.1, T4.2, T4.5 (estado del server: T2.3) |
| REQ-RES-03 | T4.3a, T4.3b, T4.4 |
| REQ-RES-04 | T2.3 (server: cursor/`start`/`policy`), T4.2 (JS) |
| REQ-IMG-01 (parcial) | T4.1 (el flujo desde cero comparte el import de imágenes) |
