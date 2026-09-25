# Fase 6 — D1: Consumidor Final — UI honesta + "Verificar ahora" (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` |
| Documentos base | `proposal.md` · `spec.md` (§A) · `design.md` (D1 §2.3) · `tasks.md` |
| Tareas que expande | **T6.1–T6.4** (skeleton `tasks.md:401-413`) |
| Decisión de diseño | **D1** (`design.md:270-297`) · invariante **REQ-RB-1** de `config-gates` |
| Versión objetivo | **2.6.0** |
| Estado | T6.1, T6.2, T6.3, T6.4 `ACTIVO` |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`; mock `scripts/lib/alegra-mock.php`) |
| Depende de | Fase 5 (T5.2a `probe()`/`probe_state()`, T5.4 AJAX). Es la última pieza de FRONT A. |

> **Regla de oro (heredada del skeleton).** Después de que un test pase, **revertir el fix**, correr
> `bash scripts/exec-test.sh` y confirmar que **ese** test falla. Volver a aplicar el fix. Sin
> `prove-it-catches` el test no se acepta.

> **Todos los `file:line` fueron re-verificados en HEAD.** Las citas mal del skeleton/design se
> corrigen y se listan abajo.

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T29.{fase}{n}`:
> Fase 6 ⇒ `T29.61`..`T29.64`, y el source-scan de `T6.4` ⇒ `T29.640`. Los IDs de **tarea**
> (`T6.1`…`T6.4`) no cambian. Mapa canónico: `T29.61`–`T29.64`, `T29.640` (5 IDs).

---

## Correcciones de cita (verificadas en HEAD para Fase 6)

| # | Cita original (skeleton/design) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `tasks.md:405` cita `templates/admin-dashboard.php:16-21,130-147` | Confirmado: bloque read-only `:16-21` (`is_configured()` `:19-21`); fila CF `:130-147` (`:131` dot, `:134-140` estados, `:143-145` acción). | T6.1 edita ambos bloques. |
| C2 | `spec.md:173` cita `:135` (no-conexión) y `:139` (no-encontrado) | Confirmado: `:135` `'No verificado (sin conexión)'`; `:139` `'No encontrado en Alegra'`. | T6.1 reemplaza por el modelo de 4 estados. |
| C3 | `design.md:290` cita el registro AJAX "junto a `:39`" | Confirmado: `add_action('wp_ajax_alegra_test_connection', ...)` en `Admin_Dashboard.php:39`. El registro se agrega en la misma zona. | T6.2 (el handler lo implementa T5.4). |
| C4 | `design.md:288-297` cita `get_script_strings()` en `Admin_Dashboard.php:652` | El método arranca en **`:630`** (`private static function get_script_strings(): array`), devuelve el array `:632-823` y cierra en `:824`. El comentario "`:652`" es una línea **dentro** del array. | T6.2/T6.3 agregan claves al final del array (antes de `:823`). |
| C5 | `design.md:294` dice que el AJAX responde `{success:true, data:{state,id,message}}` | `wp_send_json_success($data)` (`wp-stubs.php`) envuelve en `data`. El handler de T5.4 lo cumple. | T6.2 consume `r.data.{state,message}`. |
| C6 | `tasks.md:406` cita `assets admin (admin.js)` | El archivo real es **`admin/assets/js/admin.js`** (814 líneas), encolado en `Admin_Dashboard.php:596`. Usa jQuery, `var S = window.alegraConnector.strings`, `fmt()` (`:16`), `showNotice()` (`:25`), `safeMsg()` (`:35`). | T6.2 agrega el handler con ese estilo. |
| C7 | **NUEVA — `render_dashboard()` no pasa `$is_connected`** | `render_dashboard()` (`:826-839`) sólo setea `$is_connected` y `$company_name` locales y hace `include`; el template los re-defaults vía `get_option` (`:4-5`). | T6.1 puede computar `probe_state()` en el template (como ya hace con `is_configured()`), sin tocar `render_dashboard()`. |
| C8 | **NUEVA — no hay botón "Verificar ahora" hoy** | La acción actual de la fila (`:143-145`) sólo muestra la instrucción de crear el contacto en Alegra; no hay botón ni handler JS. | T6.2 crea el botón (T6.1) + el handler JS + las claves i18n. |

**Citas confirmadas exactas (no requieren corrección):** `templates/admin-dashboard.php:4-5` (`$is_connected`/`$company_name`), `:11-12` (`Runs::currently_running()` / `Kill_Switch::is_active()`), `:23` (`$page_title`), `:24` (include header), `:311` (include footer); `Admin_Dashboard.php:596` (enqueue `admin.js`), `:611-615` (`wp_localize_script('alegraConnector', ...)` con `ajaxUrl`/`nonce`/`strings`), `:630-824` (`get_script_strings`), `:826-839` (`render_dashboard`); `admin/assets/js/admin.js:11` (`var S`), `:16` (`fmt`), `:25` (`showNotice`), `:35` (`safeMsg`), `:83-85` (patrón `$.ajax` con `action` + `_ajax_nonce`).

---

## Fase 6 — Objetivo

- La fila del CF muestra **cuatro** estados distinguibles y honestos: `Disponible`, `No verificado`,
  `No encontrado en Alegra`, `No verificado (sin conexión)`. (REQ-CF-02)
- El botón **"Verificar ahora"** corre la misma resolución explícita que conectar y actualiza la fila
  **sin recargar**. (REQ-CF-03)
- El botón **"Crear Consumidor Final"** aparece sólo con un barrido **completo** sin match, y crea el
  contacto de forma **explícita**. (REQ-CF-04 Rama B)
- Un fallo de resolución se ve en pantalla con un **motivo legible** (fail-loud), no sólo en el log.
  (REQ-CF-07)
- **REQ-RB-1 (invariante):** el render del dashboard **NUNCA** emite `GET`/`POST /contacts`.

**DoD de la fase:** REQ-CF-02/03/07 verdes; el render no toca la red; "Verificar ahora" actualiza la fila
sin reload; nunca se muestra "No encontrado" sin un barrido completo; las claves i18n tienen dueño
único.

---

### T6.1 — Fila del CF con 4 estados honestos

**Objetivo**: reemplazar el binario "Disponible / No encontrado" por un modelo de estados que distinga
"nunca verificado" de "verificado y ausente".

**Descripción técnica**: hoy la fila (`templates/admin-dashboard.php:130-147`) muestra
"No verificado (sin conexión)" si `!$is_connected` (`:135`) y, con conexión y caché vacía,
"**No encontrado en Alegra**" (`:139`) **aunque el contacto exista** — el síntoma #1 del comerciante. El
único estado read-only es `is_configured()` (`:19-21`). Decisión **D1** (`design.md:270-284`); cubre
**REQ-CF-02** y prepara REQ-CF-03/04/07.

**Desarrollo técnico**

Archivo: `templates/admin-dashboard.php`.

**1) Cálculo del estado** — reemplazar el bloque `:14-21`.

**ANTES** (`:14-21`):
```php
// Billing health data.
$dry_run = (bool) get_option('alegra_connector_dry_run', false);
$consumidor_final_checked = $is_connected && class_exists('\Alegra\Connector\Consumidor_Final');
// REQ-RB-1: read-only check. Rendering the dashboard must never resolve or
// create the contact (is_available()/get_id() could POST /contacts).
$consumidor_final_available = $consumidor_final_checked
    ? \Alegra\Connector\Consumidor_Final::is_configured()
    : false;
```

**DESPUÉS:**
```php
// Billing health data.
$dry_run = (bool) get_option('alegra_connector_dry_run', false);
$consumidor_final_checked = $is_connected && class_exists('\Alegra\Connector\Consumidor_Final');

// REQ-RB-1 / REQ-CF-02: read-only. El render NUNCA resuelve ni crea el contacto
// (is_available()/get_id()/resolve() podrían POSTear). Sólo se lee la caché
// (peek_id) y el último probe persistido (probe_state), ambos sin red.
$cf_peeked = $consumidor_final_checked
    ? \Alegra\Connector\Consumidor_Final::peek_id()
    : false;
$cf_probe = $consumidor_final_checked
    ? \Alegra\Connector\Consumidor_Final::probe_state()
    : ['state' => 'unverified', 'id' => null, 'reason' => '', 'scanned' => 0, 'at' => 0];

if (!$is_connected) {
    $cf_state = 'disconnected';
} elseif ($cf_peeked !== false || $cf_probe['state'] === 'available') {
    $cf_state = 'available';
} elseif ($cf_probe['state'] === 'not_found') {
    $cf_state = 'not_found';
} else {
    $cf_state = 'unverified';
}
$consumidor_final_available = ($cf_state === 'available');

// REQ-CF-07: motivo legible por estado (fail-loud). Claves de probe()/scan_candidates().
$cf_reason_labels = [
    'api_error'          => __('la API de Alegra no respondió', 'alegra-connector'),
    'truncated'          => __('el barrido de contactos quedó incompleto', 'alegra-connector'),
    'bad_response'       => __('Alegra devolvió una respuesta inválida', 'alegra-connector'),
    'client_unavailable' => __('el cliente de Alegra no está disponible', 'alegra-connector'),
];
$cf_reason      = (string) ($cf_probe['reason'] ?? '');
$cf_reason_text = $cf_reason_labels[$cf_reason] ?? '';
```

**2) La fila** — reemplazar el bloque `:130-147`.

**ANTES** (`:130-147`):
```php
        <div class="alegra-health-row">
            <span class="alegra-health-dot <?php echo $consumidor_final_available ? 'is-green' : 'is-amber'; ?>"></span>
            <span class="alegra-health-label"><?php esc_html_e('Consumidor Final', 'alegra-connector'); ?></span>
            <span class="alegra-health-status">
                <?php if (!$is_connected): ?>
                    <?php esc_html_e('No verificado (sin conexión)', 'alegra-connector'); ?>
                <?php elseif ($consumidor_final_available): ?>
                    <?php esc_html_e('Disponible', 'alegra-connector'); ?>
                <?php else: ?>
                    <strong><?php esc_html_e('No encontrado en Alegra', 'alegra-connector'); ?></strong>
                <?php endif; ?>
            </span>
            <span class="alegra-health-action">
                <?php if ($consumidor_final_checked && !$consumidor_final_available): ?>
                    <?php esc_html_e('Créalo en Alegra con identificación CC 222222222222.', 'alegra-connector'); ?>
                <?php endif; ?>
            </span>
        </div>
```

**DESPUÉS:**
```php
        <div class="alegra-health-row" id="alegra-cf-row" data-cf-state="<?php echo esc_attr($cf_state); ?>">
            <span class="alegra-health-dot <?php echo $cf_state === 'available' ? 'is-green' : ($cf_state === 'not_found' ? 'is-red' : 'is-amber'); ?>"></span>
            <span class="alegra-health-label"><?php esc_html_e('Consumidor Final', 'alegra-connector'); ?></span>
            <span class="alegra-health-status" id="alegra-cf-status">
                <?php if ($cf_state === 'disconnected'): ?>
                    <?php esc_html_e('No verificado (sin conexión)', 'alegra-connector'); ?>
                <?php elseif ($cf_state === 'available'): ?>
                    <?php esc_html_e('Disponible', 'alegra-connector'); ?>
                <?php elseif ($cf_state === 'not_found'): ?>
                    <strong><?php esc_html_e('No encontrado en Alegra', 'alegra-connector'); ?></strong>
                <?php else: ?>
                    <strong><?php esc_html_e('No verificado', 'alegra-connector'); ?></strong>
                    <?php if ($cf_reason_text !== ''): ?>
                        <span class="description"> — <?php echo esc_html($cf_reason_text); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
            <span class="alegra-health-action" id="alegra-cf-action">
                <?php if ($cf_state === 'disconnected'): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=alegra-connector-settings')); ?>"><?php esc_html_e('Conectar ahora', 'alegra-connector'); ?> &rarr;</a>
                <?php elseif ($cf_state === 'not_found'): ?>
                    <button type="button" class="ac-btn ac-btn-sm ac-btn-primary alegra-cf-create" data-create="1"><?php esc_html_e('Crear Consumidor Final', 'alegra-connector'); ?></button>
                <?php elseif ($cf_state === 'unverified'): ?>
                    <button type="button" class="ac-btn ac-btn-sm alegra-cf-verify" data-create="0"><?php esc_html_e('Verificar ahora', 'alegra-connector'); ?></button>
                <?php endif; ?>
            </span>
        </div>
```

**Tabla de estados (contrato exacto):**

| `$cf_state` | Texto | Dot | Acción | Condición |
|---|---|---|---|---|
| `disconnected` | "No verificado (sin conexión)" | `is-amber` | "Conectar ahora" (link) | `!$is_connected` |
| `available` | "Disponible" | `is-green` | — | `peek_id() !== false` **o** probe `available` |
| `not_found` | "No encontrado en Alegra" | `is-red` | "Crear Consumidor Final" | probe `not_found` (barrido **completo**) |
| `unverified` | "No verificado" + motivo | `is-amber` | "Verificar ahora" | probe `unverified` / sin probe |

**Selectores estables para JS/tests:** `#alegra-cf-row[data-cf-state]`, `#alegra-cf-status`,
`#alegra-cf-action`, `.alegra-cf-verify`, `.alegra-cf-create`.

**Resultado esperado**
- Conectado + sin caché + sin probe ⇒ "**No verificado**" + "Verificar ahora" (nunca "No encontrado").
- Conectado + CF cacheado/probe `available` ⇒ "Disponible" (green), sin acción.
- Conectado + probe `not_found` (barrido completo) ⇒ "No encontrado en Alegra" + "Crear Consumidor
  Final".
- Sin conexión ⇒ "No verificado (sin conexión)" + "Conectar ahora".
- Probe `unverified` con `reason='api_error'` ⇒ "No verificado — la API de Alegra no respondió".
- El render **no** emite requests (REQ-RB-1).

**Dependencias**: T5.2a (`probe_state()`), T5.2b (opción). **No** toca `render_dashboard()`.

**Trazabilidad**: REQ-CF-02, REQ-CF-07, REQ-RB-1 (`config-gates`).

**Verificación** (source-scan + runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.61 the dashboard CF row renders the four honest states (REQ-CF-02)', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-dashboard.php');
    TestRunner::assertStringContains("probe_state()", $tpl, 'the render must read the persisted probe');
    TestRunner::assertStringContains("'No verificado (sin conexión)'", $tpl, 'disconnected state');
    TestRunner::assertStringContains("'Disponible'", $tpl, 'available state');
    TestRunner::assertStringContains("'No encontrado en Alegra'", $tpl, 'not_found state');
    TestRunner::assertStringContains("'No verificado'", $tpl, 'unverified state');
    TestRunner::assertStringContains('data-cf-state', $tpl, 'the row must expose the state to JS');
});
```
Runtime: incluir el template con `probe_state` persistido y assertar el texto (ver `T29.64`).

**Prove-it-catches**: volver a poner el binario `is_configured()` (sin `probe_state`) → el source-scan
falla. Quitar el branch `not_found` y dejar que `unverified` muestre "No encontrado" → el test de
"nunca No encontrado sin barrido" (`T29.64`) falla.

**Riesgo**: que el render llame a `probe()` (con red) en vez de `probe_state()` → REQ-RB-1 roto;
guard: `T29.64`. Que un `reason` desconocido muestre un texto vacío (el template lo omite; aceptable).

**Estimación**: M (2 h).

---

### T6.2 — Botón "Verificar ahora" + JS (actualiza sin reload)

**Objetivo**: que "Verificar ahora" corra el AJAX de T5.4 y actualice la fila en el lugar, mostrando el
`message`.

**Descripción técnica**: la fila necesita un botón que dispare la resolución explícita y refleje el
resultado sin recargar (REQ-CF-03). El handler AJAX es de **T5.4**
(`alegra_verify_consumidor_final`, `Admin_Dashboard.php:39` + método). Esta tarea agrega el JS y las
claves i18n. Cubre **REQ-CF-03**.

**Desarrollo técnico**

Archivo: `admin/assets/js/admin.js`. El archivo define el objeto `AlegraConnector` (`:39`); sus handlers
se registran en `AlegraConnector.init()` (`:47-60`), que se invoca en `$(document).ready` (`:810-812`).
**Dos cambios:**

**(a) Registrar el handler** en `init()` (`:47-60`), después de `this.initConnectionTest();` (`:49`):
```js
            this.initConsumidorFinal();
```

**(b) Definir el método** `initConsumidorFinal` **después** de `initConnectionTest` (que cierra en
`:125`), con el estilo de los demás handlers (usa `S`, `showNotice`, `safeMsg`):

```js
        // D1 / REQ-CF-03: "Verificar ahora" / "Crear Consumidor Final".
        initConsumidorFinal: function() {
            $(document).on('click', '.alegra-cf-verify, .alegra-cf-create', function() {
            var $btn  = $(this);
            var label = $btn.text();
            var create = $btn.data('create') ? 1 : 0;

            $btn.prop('disabled', true).text(S.cfVerifying);

            $.ajax({
                url: alegraConnector.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'alegra_verify_consumidor_final',
                    _ajax_nonce: alegraConnector.nonce,
                    create: create
                },
                success: function(r) {
                    if (!r || !r.success) {
                        showNotice(safeMsg(r, S.cfVerifyError), 'error');
                        $btn.prop('disabled', false).text(label);
                        return;
                    }
                    var d    = r.data || {};
                    var $row = $('#alegra-cf-row');

                    $row.attr('data-cf-state', d.state || 'unverified');
                    $('#alegra-cf-status').text(d.message || '');

                    if (d.state === 'available') {
                        $('#alegra-cf-action').empty();
                        $row.find('.alegra-health-dot').removeClass('is-amber is-red').addClass('is-green');
                        showNotice(d.message || S.cfVerifyOk, 'success');
                    } else {
                        showNotice(d.message || S.cfVerifyNotFound, 'warning');
                        $btn.prop('disabled', false).text(label);
                    }
                },
                error: function() {
                    showNotice(S.connectionError, 'error');
                    $btn.prop('disabled', false).text(label);
                }
            });
            });
        },
```

**Orden de operaciones:**
1. Click en `.alegra-cf-verify` (`create=0`) o `.alegra-cf-create` (`create=1`).
2. POST a `admin-ajax.php` con `action=alegra_verify_consumidor_final`, nonce y `create`.
3. `success` con `data.state`/`data.message` → actualiza `#alegra-cf-status` y `data-cf-state`.
4. Si `available` → vacía la acción, dot green, notice success. Si no → notice warning y re-habilita el
   botón.
5. `error` de red → `showNotice(S.connectionError)` y re-habilita.

**Claves i18n nuevas (dueño: T6.2)** en `get_script_strings()` (`Admin_Dashboard.php:630-824`, agregar
antes de `:823`):
```php
            // D1 / REQ-CF-03: Consumidor Final (verificar/crear). Dueño único: T6.2.
            'cfVerifying'        => __('Verificando...', 'alegra-connector'),
            'cfVerifyOk'         => __('Consumidor Final disponible.', 'alegra-connector'),
            'cfVerifyNotFound'   => __('No se encontró el Consumidor Final en Alegra. Podés crearlo.', 'alegra-connector'),
            'cfVerifyError'      => __('No se pudo verificar el Consumidor Final.', 'alegra-connector'),
            'cfCreateOk'         => __('Consumidor Final creado.', 'alegra-connector'),
```
> `cfVerifying` ya existe en el array (`:692`, `'verifying' => __('Verificando...')`), pero con otra
> clave. **No** reusar `verifying` (es de "Verificar Endpoints"); declarar `cfVerifying` para no acoplar.

**Resultado esperado**
- Click en "Verificar ahora" ⇒ llama el AJAX con `create=0`; con el CF existente, la fila pasa a
  "Disponible" y se muestra el `message`, **sin recargar**.
- Click en "Crear Consumidor Final" ⇒ `create=1`; el CF se crea (bajo `run_explicit`, T5.4) y la fila
  pasa a "Disponible".
- Sin red / error ⇒ notice de error y el botón se re-habilita.
- El texto del botón se restaura si el resultado no es `available`.

**Dependencias**: T6.1 (botones/selectores), T5.4 (AJAX + `message`), T5.2a.

**Trazabilidad**: REQ-CF-03, NFR-04 (message), NFR-06 (nonce/cap en el server).

**Verificación** (source-scan, `scripts/exec-test.php`):
```php
TestRunner::test('T29.62 the CF verify button wires the AJAX and updates the row without reload (REQ-CF-03)', function (): void {
    $js = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/assets/js/admin.js');
    TestRunner::assertStringContains("action: 'alegra_verify_consumidor_final'", $js, 'the JS must call the verify action');
    TestRunner::assertStringContains("_ajax_nonce: alegraConnector.nonce", $js, 'the JS must send the nonce');
    TestRunner::assertStringContains("#alegra-cf-status", $js, 'the JS must update the row in place');

    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'cfVerifying'", $admin, 'the i18n key must be declared');
});
```
Manual: conectar con CF existente ⇒ "Disponible"; sin verificar ⇒ "No verificado" + "Verificar ahora";
pulsarlo ⇒ "Disponible" sin recargar.

**Prove-it-catches**: quitar el `data.action` del `$.ajax` → el source-scan falla. Quitar
`$('#alegra-cf-status').text(...)` → el source-scan de actualización falla.

**Riesgo**: que el AJAX no esté registrado (T5.4) y el POST devuelva `0` (guard: orden T5.4 → T6.2).
Que el botón quede deshabilitado si el success no trae `data` (guard: se re-habilita en el `else`).

**Estimación**: S/M (1.5 h).

---

### T6.3 — Fail-loud del CF: motivo legible en la UI

**Objetivo**: que un estado `unverified` muestre un motivo legible, no sólo un log.

**Descripción técnica**: hoy `Consumidor_Final::log_error()` (`:480-491`) escribe **sólo** al log; la UI
nunca se entera. Con T6.1, el estado `unverified` muestra `$cf_reason_text`. Esta tarea fija el mapeo
`reason` → texto y garantiza que **nunca** quede sólo en el log. Cubre **REQ-CF-07**.

**Desarrollo técnico**

Archivo: `templates/admin-dashboard.php` (el mapeo `$cf_reason_labels` ya se introduce en T6.1). El
contrato es:

| `reason` (de `scan_candidates`/`probe`) | Texto UI |
|---|---|
| `api_error` | "la API de Alegra no respondió" |
| `truncated` | "el barrido de contactos quedó incompleto" |
| `bad_response` | "Alegra devolvió una respuesta inválida" |
| `client_unavailable` | "el cliente de Alegra no está disponible" |
| `''` / `cached` / `match` / `not_found` | (sin sufijo; esos estados no son `unverified`) |

**Regla:** para `state='unverified'`, si `reason` no está en el mapa, se muestra "No verificado" a
secas (sin sufijo), pero **nunca** se muestra "No encontrado". El motivo técnico completo sigue en el
log (vía `log_error`).

**Resultado esperado**
- Probe `unverified`/`api_error` ⇒ la fila dice "No verificado — la API de Alegra no respondió".
- Probe `unverified`/`truncated` ⇒ "No verificado — el barrido de contactos quedó incompleto".
- Un fallo **nunca** se muestra como "No encontrado".
- El log conserva la causa técnica.

**Dependencias**: T6.1, T5.2a, T5.2b.

**Trazabilidad**: REQ-CF-07, NFR-03 (degradar honesto).

**Verificación** (source-scan + runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.63 an unverified CF is surfaced with a readable reason (REQ-CF-07)', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-dashboard.php');
    TestRunner::assertStringContains('$cf_reason_labels', $tpl, 'the reason map must exist');
    TestRunner::assertStringContains("'api_error'", $tpl, 'api_error must map to a readable text');
    TestRunner::assertStringContains("'truncated'", $tpl, 'truncated must map to a readable text');

    // Runtime: un probe unverified no debe decir "No encontrado".
    alegra_test_reset();
    update_option('alegra_connector_consumidor_final_probe', [
        'state' => 'unverified', 'id' => null, 'reason' => 'api_error', 'scanned' => 0, 'at' => 1,
    ], false);
    // ... incluir el template (ver T29.64) y assertar el texto ...
    TestRunner::assertStringNotContains('No encontrado en Alegra', $html, 'an API error must never read as not_found');
});
```

**Prove-it-catches**: quitar el mapeo `$cf_reason_labels` → el source-scan falla. Cambiar el default de
`unverified` a `not_found` → el assert "not contains No encontrado" falla.

**Riesgo**: que el motivo sea demasiado técnico para el comerciante (los textos son en lenguaje llano;
el detalle va al log). Que un `reason` nuevo no tenga texto (degrada a "No verificado" sin sufijo).

**Estimación**: S (1 h).

---

### T6.4 — Tests/source-scan del render (REQ-RB-1)

**Objetivo**: probar que el render del dashboard **nunca** emite `GET`/`POST /contacts`, y que los
estados se renderizan correctamente.

**Descripción técnica**: REQ-RB-1 de `config-gates` prohíbe que el render resuelva/crea el contacto
(hoy `is_configured()` es read-only, pero un refactor podría reintroducir `get_id()`/`is_available()`).
Se cubre con un **source-scan** (no puede haber `get_or_create_id`/`get_id`/`is_available`/`resolve(` en
el template) **y** un test de runtime que incluye el template y cuenta requests del mock. Cubre
**REQ-CF-02, REQ-CF-07, REQ-RB-1**.

**Desarrollo técnico**

Archivo: `scripts/exec-test.php`.

**Test de runtime** — incluir el template con `ob_start()` y contar requests:
```php
TestRunner::test('T29.64 rendering the dashboard never touches /contacts (REQ-RB-1/REQ-CF-02)', function (): void {
    alegra_test_reset();
    // Estado conectado, sin caché y sin probe: el peor caso (tentaría resolver).
    update_option('alegra_connector_connection_tested', true, false);

    $is_connected = true;
    $company_name = 'Test Co';
    $last_sync    = false;
    $stats        = ['products' => 0, 'customers' => 0, 'orders' => 0, 'categories' => 0];

    ob_start();
    include $GLOBALS['alegra_plugin_root'] . 'templates/admin-dashboard.php';
    $html = (string) ob_get_clean();

    TestRunner::assertSame(0, alegra_mock_count('GET', '/contacts'), 'the render must not GET /contacts');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'the render must not POST /contacts');
    TestRunner::assertStringContains('No verificado', $html, 'an unverified CF must read as No verificado');
    TestRunner::assertStringNotContains('No encontrado en Alegra', $html, 'without a complete scan it must NOT read as not_found');
});
```
> El template incluye `header.php`/`footer.php` (sin red: verificado). `$GLOBALS['alegra_plugin_root']`
> es la raíz del plugin en el harness.

**Source-scan (invariante REQ-RB-1):**
```php
TestRunner::test('T29.640 the dashboard template has no write-capable CF call (REQ-RB-1)', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-dashboard.php');
    TestRunner::assertStringNotContains('get_or_create_id', $tpl, 'the render must not resolve/create');
    TestRunner::assertStringNotContains('::get_id(', $tpl, 'the render must not use get_id()');
    TestRunner::assertStringNotContains('is_available', $tpl, 'the render must not use is_available()');
    TestRunner::assertStringNotContains('::resolve(', $tpl, 'the render must not call resolve()');
    TestRunner::assertStringContains('probe_state()', $tpl, 'the render must read the persisted probe');
    TestRunner::assertStringContains('peek_id()', $tpl, 'the render must use the read-only peek');
});
```

**Resultado esperado**
- Incluir el template con conexión activa y sin CF ⇒ `GET/POST /contacts` en **0**.
- El HTML contiene "No verificado" y **no** "No encontrado en Alegra" (sin barrido completo).
- El source-scan confirma que el template no usa APIs que escriban.

**Dependencias**: T6.1, T6.3, T5.2a, T5.2b.

**Trazabilidad**: REQ-CF-02, REQ-CF-07, REQ-RB-1, R9.

**Verificación**: `bash scripts/exec-test.sh` → `T29.61`–`T29.64`/`T29.640` verdes.

**Prove-it-catches**: reemplazar en el template `probe_state()` por `get_or_create_id()` → el
source-scan y el runtime (POST > 0) fallan. Cambiar el branch `unverified` para mostrar "No encontrado"
→ el `assertStringNotContains` falla.

**Riesgo**: que el template dependa de variables no seteadas al incluirlo aislado (guard: el template
usa `isset(...) ?? get_option(...)` en `:4-7`). Que `header.php`/`footer.php` hagan red (verificado: no).

**Estimación**: S/M (1.5 h).

---

## Tabla canónica de strings i18n (dueño único)

`get_script_strings()` (`Admin_Dashboard.php:630-824`) es un **único array**: dos tareas que declaren la
misma clave se pisan en silencio. Cada clave tiene **un dueño**; las demás fases la **consumen** vía
`S.<key>`/`fmt(S.<key>, …)` y **no** la redeclaran.

### Claves JS (van en `get_script_strings()`)

| Clave | Texto canónico | Dueño (declara) | Consumidor |
|---|---|---|---|
| `cfVerifying` | `Verificando...` | **T6.2** | T6.2 (`admin.js`) |
| `cfVerifyOk` | `Consumidor Final disponible.` | **T6.2** | T6.2 (`admin.js`) |
| `cfVerifyNotFound` | `No se encontró el Consumidor Final en Alegra. Podés crearlo.` | **T6.2** | T6.2 (`admin.js`) |
| `cfVerifyError` | `No se pudo verificar el Consumidor Final.` | **T6.2** | T6.2 (`admin.js`) |
| `cfCreateOk` | `Consumidor Final creado.` | **T6.2** | T6.2 (`admin.js`) |

> **No confundir con `verifying`** (`:692`, "Verificando..." de "Verificar Endpoints"). Se declara
> `cfVerifying` para no acoplar dos flujos con la misma clave.

### Literales PHP (van en `templates/admin-dashboard.php`, no en `get_script_strings()`)

| Literal (vía `esc_html_e`) | Dueño |
|---|---|
| `No verificado (sin conexión)` | T6.1 |
| `Disponible` | T6.1 |
| `No encontrado en Alegra` | T6.1 |
| `No verificado` | T6.1 |
| `Conectar ahora` | T6.1 |
| `Crear Consumidor Final` | T6.1 |
| `Verificar ahora` | T6.1 |
| `Consumidor Final` (label) | T6.1 (existente) |
| `la API de Alegra no respondió` | T6.3 |
| `el barrido de contactos quedó incompleto` | T6.3 |
| `Alegra devolvió una respuesta inválida` | T6.3 |
| `el cliente de Alegra no está disponible` | T6.3 |

### Literales PHP (van en `includes/Consumidor_Final.php` / `Orders.php`)

| Literal | Dueño | Uso |
|---|---|---|
| `Consumidor Final disponible.` / `No se encontró...` / `No se pudo verificar...` | **T5.4** | `consumidor_final_message()` |
| `[Alegra] El Consumidor Final cambió en Alegra y no se pudo re-resolver...` | **T5.6** | nota de pedido |
| `[Alegra] Factura recuperada tras re-resolver el Consumidor Final (auto-sanado).` | **T5.5** | nota de pedido |
| `[Alegra] No se pudo facturar tras re-resolver el Consumidor Final: %s` | **T5.5** | nota de pedido |
| `[Alegra] Consumidor Final re-resuelto y factura creada (auto-sanado).` | **T5.5** | nota de pedido |

> **Regla para el worker.** Si una fase necesita una clave de esta tabla, la **consume**; **no** la
> agrega de nuevo. Los mensajes PHP del handler (T5.4) y de las notas (T5.5/T5.6) son de Fase 5; T6.2
> **no** los redeclara (sólo los muestra).

---

## DoD Fase 6

- [ ] `T29.61`–`T29.64`/`T29.640` verdes, cada uno con su `prove-it-catches`.
- [ ] REQ-CF-02, REQ-CF-03, REQ-CF-07 verdes; REQ-RB-1 intacto.
- [ ] El render **nunca** emite `GET`/`POST /contacts` (runtime + source-scan).
- [ ] "Verificar ahora" y "Crear Consumidor Final" actualizan la fila sin reload.
- [ ] `unverified` muestra un motivo legible; nunca se lee como "No encontrado".
- [ ] La tabla canónica i18n no tiene claves duplicadas.
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK`; `bash scripts/smoke-test.sh` → `SMOKE OK`.

## Índice de tests nuevos (Fase 6)

| Test | Tipo | Archivo |
|---|---|---|
| `T29.61 the dashboard CF row renders the four honest states` | source-scan (+ runtime en T29.64) | `scripts/exec-test.php` |
| `T29.62 the CF verify button wires the AJAX and updates the row without reload` | source-scan | `scripts/exec-test.php` |
| `T29.63 an unverified CF is surfaced with a readable reason` | source-scan + runtime | `scripts/exec-test.php` |
| `T29.64 rendering the dashboard never touches /contacts` | runtime | `scripts/exec-test.php` |
| `T29.640 the dashboard template has no write-capable CF call` | source-scan | `scripts/exec-test.php` |

## Trazabilidad tarea → requerimiento

| Requerimiento | Tareas |
|---|---|
| REQ-CF-02 | T6.1, T6.4 |
| REQ-CF-03 | T6.2 |
| REQ-CF-04 (Rama B UI) | T6.1 (botón "Crear"), T6.2 (create=1) |
| REQ-CF-07 | T6.1, T6.3 |
| REQ-RB-1 (`config-gates`) | T6.4 |
| NFR-04 | T6.2 (`message`) |
| NFR-06 | T6.2 (nonce/cap en T5.4) |
