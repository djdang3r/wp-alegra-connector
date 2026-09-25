# Fase 8 — Lock shutdown + cron budget + cron real (D7) — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **8** — Lock a prueba de fatales, presupuesto global del cron y recomendación de cron real |
| Versión objetivo | **2.6.0** |
| Harness | `bash scripts/exec-test.sh` (**1626 assertions, 0 failed** — verificado en HEAD) · `bash scripts/smoke-test.sh` |
| Tareas del esqueleto | `T8.1`–`T8.6` (expandidas a 6 micro-tareas) |
| Depende de | Fase 7 (`sync_inventory_from_alegra` ya acepta `$deadline`; `run_inventory_sync` lo propaga) · `T1.7` (opciones) · `T1.11` (sección `T29`) |
| DoD de la fase | un fatal no deja **ningún** lock colgado (poll y global); el cron respeta un presupuesto global < TTL 600; Ajustes muestra la pista de cron real; tests `T29.81`–`T29.85` verdes |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde.
>
> **IDs de test.** `scripts/exec-test.php`, sección `// === sync-reliability (2.6.0) ===`, IDs `T29.8x`.
> `T8.x` son IDs de **tarea**.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 8)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Products.php:1290-1292` (el `finally` que un fatal saltea) | Confirmado: `:1290` `} finally {`, `:1291` `release_sync_lock_public('products', $lock)`, `:1292` `}`. | `T8.1` |
| C2 | `Controller.php:523-526` = `acquire_sync_lock_public` | Confirmado: `:523` firma, `:524` `return (new self(null, null))->acquire_sync_lock($type);`, `:526` `}`. `release_sync_lock_public` es `:531-534`. | `T8.1` (y nota cross-doc de `logs-monitor-import`) |
| C3 | `Controller.php:475` (clave) + `:482` (TTL 300) | Confirmado: `:475` `$key = 'alegra_sync_running_' . $type;`; `:482` `$token = self::acquire_lock($key, 300);`. | `T8.1` |
| C4 | `Controller.php:141` = lock global `alegra_cron_global` TTL 600 | Confirmado. | `T8.1`, `T8.2`, `T8.3` |
| C5 | `Controller.php:441` valida token al liberar | Confirmado: `:441` firma `release_lock`, `:445` compara `$existing['token'] === $token`. | `T8.1` |
| C6 | `register_shutdown_function` en producción | **0** coincidencias (confirmado por grep). | `T8.1` |
| C7 | `import_from_alegra` NO acepta deadline externo | Firma real `import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0)` (`Products.php:1295`); el design §10 **no** lista el cambio, pero `T8.2` y design §8.3 exigen pasárselo. | `T8.2` (corrección: 4º parámetro opcional) |
| C8 | `templates/admin-settings.php:466-476` = card de cron | Confirmado: `:466` comentario, `:468` header `Sincronización Periódica (Cron Polling)`, `:476` cierra el `div`. Está dentro de `tab-advanced` (arranca `:252`). El design §8.4 lo ubica bien en **Avanzado**. | `T8.4` |
| C9 | `DISABLE_WP_CRON`/`wp_doing_cron` en producción | **0** coincidencias (confirmado). | `T8.4` |
| C10 | `uninstall.php:221-227` (Action Scheduler) | Confirmado: `:221` comentario, `:222-224` loop `wp_clear_scheduled_hook`, `:225-227` `as_unschedule_all_actions` guardado por `function_exists`. | `T8.5` |
| C11 | `.github/workflows/release.yml` con `make_latest` | **Ya está presente**: `:74` `make_latest: "true"` (el doc de `logs-monitor-import` lo daba por ausente; se agregó en un release posterior). | `T9.5.c` (nota) |
| C12 | Opción `alegra_connector_cron_run_budget` | **0** matches hoy (la siembra `T1.7`). | `T8.2` |

**Citas confirmadas exactas:** `Controller.php:117-159` (`run_cron_sync`), `:153`
(`Run_Context::wrap('cron_sync_all', …)`), `:161` (`run_cron_sync_inner`), `:188` (import del cron),
`:209-210` (condición del poll), `:211` (`sync_inventory_from_alegra($run_id)`), `:414-433`
(`acquire_lock`), `:441-448` (`release_lock`), `:473-490` (`acquire_sync_lock`); `Products.php:1163`
(lock del poll), `:1290-1292` (finally); `templates/admin-settings.php:466-476`;
`uninstall.php:221-227`; `alegra-connector.php:414-457,463-486`.

---

## Mapa de cobertura Fase 8 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T8.1` | `register_shutdown_function` libera **ambos** locks (poll + global cron) | `includes/Sync/Products.php`, `includes/Sync/Controller.php` |
| `T8.2` | `cron_run_budget` + deadline global propagado a import y poll | `includes/Sync/Controller.php`, `Products.php`, `Admin_Dashboard.php` |
| `T8.3` | Aviso al reclamar un lock global vencido | `includes/Sync/Controller.php` |
| `T8.4` | UI de cron real (`DISABLE_WP_CRON` + crontab) | `templates/admin-settings.php` |
| `T8.5` | Action Scheduler diferido + fallback WP-Cron declarado | `uninstall.php`, `CHANGELOG.md` |
| `T8.6` | Tests de lock/cron | `scripts/exec-test.php` |

---

### T8.1 — Liberar los locks en shutdown (a prueba de fatales)

**Objetivo**: que un fatal de PHP no deje tomado **ninguno de los dos** locks de la corrida:
`alegra_sync_running_products` (TTL 300) **ni** `alegra_cron_global` (TTL 600).

**Descripción técnica**: hay **dos** locks en la corrida del cron:
1. **Lock del poll** — `Products.php:1163` (`acquire_sync_lock_public('products')` → clave
   `alegra_sync_running_products`, TTL 300, `Controller.php:475,482`), liberado en el `finally`
   (`:1290-1292`).
2. **Lock global del cron** — `run_cron_sync()` (`Controller.php:141`,
   `acquire_lock('alegra_cron_global', 600)`), liberado **solo** en el `finally` (`:156-158`).

Un **fatal** (`E_ERROR`, timeout duro, `exit` de otro plugin) **no ejecuta el `finally`** ⇒ el lock queda
tomado (300 s el del poll, **600 s** el global) y el próximo poll/tick se saltea (C3 de la propuesta;
Oracle D6). D7 §8.1: `register_shutdown_function` como primario, TTL como backstop.
`release_lock` valida el token (`Controller.php:441-448`), así que el handler nunca libera un lock ajeno
(DR10/R12). REQ-POLL-03.

**Desarrollo técnico** — **dos** archivos.

**(1) `includes/Sync/Products.php` (lock del poll)** — **inmediatamente después** de adquirir el lock
(`:1163-1168`) y antes del `try` (y **antes** del bloque `$was_syncing` de `T7.3`; ver FIX-16):

ANTES (`:1163-1170`):
```php
        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public('products');
        if ($lock === false) {
            $this->logger->info('Inventory sync skipped: another sync is running');
            $result['locked'] = true;
            return $result;
        }

        try {
```

DESPUÉS:
```php
        $lock = \Alegra\Connector\Sync\Controller::acquire_sync_lock_public('products');
        if ($lock === false) {
            $this->logger->info('Inventory sync skipped: another sync is running');
            $result['locked'] = true;
            return $result;
        }

        // D7 §8.1: un fatal saltea el finally (Products.php:1290-1292) y deja el
        // lock tomado 300 s. El shutdown handler lo libera; release_lock() valida
        // el token (Controller.php:441-448) ⇒ nunca libera un lock ajeno (DR10).
        $lock_key = 'alegra_sync_running_products';
        register_shutdown_function(static function () use ($lock_key, $lock): void {
            \Alegra\Connector\Sync\Controller::release_lock($lock_key, $lock);
        });

        try {
```

**(2) `includes/Sync/Controller.php` (lock global del cron)** — en `run_cron_sync()`,
**inmediatamente después** de adquirir el lock global (`:141-145`) y antes del `try` (`:149`):

ANTES (`:141-149`):
```php
        $global_lock = self::acquire_lock('alegra_cron_global', 600);
        if ($global_lock === false) {
            $this->logger->info('Cron sync skipped: another run is already in progress');
            return;
        }

        $this->logger->info('Starting cron synchronization (Alegra → WC only)');

        try {
```

DESPUÉS:
```php
        $global_lock = self::acquire_lock('alegra_cron_global', 600);
        if ($global_lock === false) {
            $this->logger->info('Cron sync skipped: another run is already in progress');
            return;
        }

        // D7 §8.1 (extendido): un fatal saltea el finally (:156-158) y deja el
        // lock global tomado 600 s ⇒ TODO el cron se saltea. El shutdown handler
        // lo libera; release_lock() valida el token (:441-448) ⇒ nunca libera un
        // lock ajeno (DR10/R12).
        register_shutdown_function(static function () use ($global_lock): void {
            \Alegra\Connector\Sync\Controller::release_lock('alegra_cron_global', $global_lock);
        });

        $this->logger->info('Starting cron synchronization (Alegra → WC only)');

        try {
```

**Por qué `release_lock` y no `release_sync_lock_public`:** el `finally` normal ya libera el lock y el
registro re-entrante (`Controller::$held_sync_locks`) es **request-scoped** (muere con el proceso). En
el shutdown, `release_lock` sobre un lock ya liberado es no-op (el option no existe o el token no
coincide). Usar la variante pública re-entrante también sería correcto, pero `release_lock` es el
contrato que fija el design §8.1 y evita depender del estado del registro.

**Orden de operaciones (FIX-16):**
- `Products.php`: adquirir lock del poll → registrar shutdown (este `T8.1`) → bloque `$was_syncing` de
  `T7.3` → `try { loop } finally { set_syncing(false); release }`.
- `Controller.php` (`run_cron_sync`): inspección de lock vencido de `T8.3` → `acquire_lock` global →
  registrar shutdown (este `T8.1`) → `$run_budget`/`$deadline` de `T8.2` → `try { wrap } finally { release }`.

En una corrida normal, el `finally` libera y el shutdown no encuentra token ⇒ no-op. En un fatal, el
shutdown libera **ambos** locks.

**Resultado esperado**: tras un fatal simulado en medio del poll, los options
`alegra_lock_alegra_sync_running_products` **y** `alegra_lock_alegra_cron_global` **no** quedan; el
próximo poll adquiere su lock sin esperar 300 s y el próximo tick del cron no espera 600 s.

**Dependencias**: Fase 7 (el `try/finally` del poll). `Controller::release_lock` ya existe (`:441`);
**no se modifica**. El `try/finally` del lock global (`Controller.php:149-158`) ya existe.

**Trazabilidad**: REQ-POLL-03, NFR-02; design §8.1; hallazgos C3/C4; Oracle D6 (FIX-5); DR10/R12.

**Verificación**: `T29.81` — disparar el poll con un stub que lanza una excepción **no capturada**
(`\Error`) y assertar que **ambos** options de lock (`alegra_lock_alegra_sync_running_products` y
`alegra_lock_alegra_cron_global`) quedaron libres; borde: un lock con token ajeno **no** se libera.
**Prove-it-catches:** quitar el `register_shutdown_function` del poll **o** el del lock global →
`T29.81` ve el lock correspondiente tomado y falla.

**Riesgo**: que `release_lock` libere un lock ajeno → imposible: compara token (`:445`). Que el shutdown
handler se registre dos veces si el poll se llama anidado → en ese caso el lock es el mismo token
(re-entrante) y liberarlo dos veces es no-op. Cobertura parcial ante OOM/SIGKILL (Oracle D16): el TTL
300/600 queda como backstop real.

**Estimación**: S (1,5 h).

---

### T8.2 — `cron_run_budget` + deadline global propagado (import + poll)

**Objetivo**: acotar la corrida completa del cron a un presupuesto menor que el TTL del lock global, para que no se solape.

**Descripción técnica**: el lock global `alegra_cron_global` tiene TTL **600** (`Controller.php:141`).
La corrida (import hasta 240 s + poll + clientes/categorías/órdenes/prune) puede **excederlo** ⇒ un
segundo tick entra y solapa (C4, R13). D7 §8.3: presupuesto global + deadline propagado. Opción nueva
`alegra_connector_cron_run_budget` (int, default **540**; la siembra `T1.7`; se registra en
`Admin_Dashboard` como el resto de los knobs, `:496-510`). El poll ya acepta `$deadline` (T7.1.a) y
`run_inventory_sync` lo propaga (T7.5); **el import todavía no** (corrección C7): se le agrega un 4º
parámetro opcional. REQ-POLL-03, REQ-POLL-06, NFR-02.

**Desarrollo técnico** — tres archivos.

**(1) `includes/Sync/Controller.php`, `run_cron_sync()`** (`:117-159`), después del `register_shutdown_function`
del lock global de `T8.1` (que va justo después de `:145`) y antes del `try` (`FIX-16`):

```php
        // D7 §8.3: presupuesto global de la corrida < TTL 600 del lock global,
        // para que un tick no se solape con el anterior (R13).
        $run_budget = max(60, min(590, (int) get_option('alegra_connector_cron_run_budget', 540)));
        $deadline = microtime(true) + $run_budget;
```

y el closure pasa el deadline (ANTES `:153-155`):

ANTES:
```php
            Run_Context::wrap('cron_sync_all', function ($run_id) {
                $this->run_cron_sync_inner($run_id);
            }, 'cron');
```

DESPUÉS:
```php
            Run_Context::wrap('cron_sync_all', function ($run_id) use ($deadline) {
                $this->run_cron_sync_inner($run_id, $deadline);
            }, 'cron');
```

**(2) `run_cron_sync_inner()`** (`:161`) firma y llamadas:

ANTES:
```php
    private function run_cron_sync_inner(int $run_id): void
```
```php
                    $products_result = $this->products->import_from_alegra(1, 30, $run_id);
```
```php
            $inventory_result = $this->products->sync_inventory_from_alegra($run_id);
```

DESPUÉS:
```php
    private function run_cron_sync_inner(int $run_id, float $deadline = 0.0): void
```
```php
                    $products_result = $this->products->import_from_alegra(1, 30, $run_id, $deadline);
```
```php
            $inventory_result = $this->products->sync_inventory_from_alegra($run_id, $deadline);
```

**(3) `includes/Sync/Products.php`, firma del import** (`:1295`):

ANTES:
```php
    public function import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0): array|\WP_Error
```

DESPUÉS:
```php
    public function import_from_alegra(int $page = 1, int $per_page = 30, int $run_id = 0, float $run_deadline = 0.0): array|\WP_Error
```

y el cálculo del deadline interno (`:1318-1322`) incorpora el global:

ANTES:
```php
        $budget = (int) get_option('alegra_connector_import_time_budget', 240);
        if ($budget < 30) {
            $budget = 30;
        }
        $deadline = microtime(true) + $budget;
```

DESPUÉS:
```php
        $budget = (int) get_option('alegra_connector_import_time_budget', 240);
        if ($budget < 30) {
            $budget = 30;
        }
        $deadline = microtime(true) + $budget;
        if ($run_deadline > 0.0) {
            // El presupuesto global del cron manda cuando es más corto (D7 §8.3).
            $deadline = min($deadline, $run_deadline);
        }
```

**(4) Registro de la opción** — `admin/Admin/Admin_Dashboard.php`, junto a `:496-510`:

```php
        // D7 §8.3: presupuesto global de una corrida del cron. Debe quedar por
        // debajo del TTL 600 del lock global (alegra_cron_global).
        register_setting('alegra_connector_settings', 'alegra_connector_cron_run_budget', [
            'sanitize_callback' => fn($v) => max(60, min(590, (int) $v)),
            'default' => 540,
        ]);
```

> **Nota de corrección (C7):** el design §10 no listaba el cambio de firma de `import_from_alegra`; el
> 4º parámetro es **opcional**, así que los call sites existentes (`Controller.php:392`, y el chunked)
> siguen válidos. La opción se agrega a `$defaults`/`$non_autoload`/`uninstall.php` en `T1.7`; acá solo
> se **lee** y se registra la UI.

**Orden de operaciones:** `run_cron_sync` lee budget → calcula deadline → closure → `run_cron_sync_inner($run_id, $deadline)`
→ import con `min(240, deadline)` → poll con `min(60, deadline)` → resto de etapas → `prune` → release
del lock global. Cada etapa corta en `min(su budget, deadline)` y persiste su cursor.

**Resultado esperado**: con `cron_run_budget=540`, ninguna corrida del cron dura > ~540 s; el import y el
poll reciben el mismo deadline; el lock global (600) nunca expira durante una corrida que respeta el
presupuesto.

**Dependencias**: T7.1.a, T7.5 (poll + `run_inventory_sync` con deadline), T1.7 (siembra de la opción).

**Trazabilidad**: REQ-POLL-03, REQ-POLL-06, NFR-02; design §8.3; hallazgos C4/C7; R13; DR11.

**Verificación**: `T29.82` — con `cron_run_budget` bajo, el import y el poll reciben `$deadline` y cortan;
assert estático de que `run_cron_sync_inner` pasa el deadline a ambas llamadas.
**Prove-it-catches:** no propagar el deadline al poll → `T29.82` ve una corrida sin corte y falla.

**Riesgo**: que el import interno (240) + poll (60) + resto supere 540 en un catálogo enorme → el
deadline global los corta; los cursores permiten reanudar. TTL 600 > 540 deja margen de 60 s.

**Estimación**: M (2 h).

---

### T8.3 — Aviso al reclamar un lock global vencido

**Objetivo**: dejar señal cuando el cron reclama un lock global expirado (corrida anterior que se pasó).

**Descripción técnica**: `acquire_lock` (`Controller.php:414-433`) reclama un lock vencido en
`:421-428` **en silencio**. Si una corrida anterior se pasó del TTL (600), el solapamiento ocurre sin
señal. D7 §8.3 pide `logger->warning('cron lock reclaimed (previous run overran)')`. `acquire_lock` es
`static` y **no** tiene logger; en vez de cambiar su firma (la usan muchos call sites), el aviso se
emite en `run_cron_sync` **antes** de adquirir, inspeccionando el option. REQ-POLL-03.

**Desarrollo técnico** — `includes/Sync/Controller.php`, en `run_cron_sync()`, **antes** de `:141`:

ANTES (`:141`):
```php
        $global_lock = self::acquire_lock('alegra_cron_global', 600);
```

DESPUÉS:
```php
        // D7 §8.3: avisar si el lock global quedó vencido de una corrida anterior
        // (acquire_lock lo reclama en silencio, Controller.php:421-428).
        $cron_lock_option = 'alegra_lock_alegra_cron_global';
        $existing_lock = get_option($cron_lock_option);
        if (is_array($existing_lock)
            && isset($existing_lock['expires'])
            && (int) $existing_lock['expires'] < time()) {
            $this->logger->warning('cron lock reclaimed (previous run overran)', [
                'expired_at' => (int) $existing_lock['expires'],
                'now'        => time(),
            ]);
        }

        $global_lock = self::acquire_lock('alegra_cron_global', 600);
```

**Orden de operaciones:** kill switch → `sync_method` → inspeccionar el option del lock global (log si
vencido) → `acquire_lock('alegra_cron_global', 600)` → `register_shutdown_function` de `T8.1` →
`$run_budget`/`$deadline` de `T8.2` → resto igual (`FIX-16`).

**Resultado esperado**: si el option `alegra_lock_alegra_cron_global` existe con `expires < time()`,
queda una línea `cron lock reclaimed (previous run overran)` en el log **antes** de que `acquire_lock`
lo recree; si el lock está sano, no se loguea nada.

**Dependencias**: T8.2 (mismo método).

**Trazabilidad**: REQ-POLL-03; design §8.3; hallazgo C4; R13.

**Verificación**: `T29.83` — sembrar `alegra_lock_alegra_cron_global` con `expires` en el pasado, correr
`run_cron_sync` y assertar el warning en el log. **Prove-it-catches:** quitar la inspección previa →
`T29.83` no ve el warning y falla.

**Riesgo**: leer el option con la clave `alegra_lock_alegra_cron_global` correcta. `acquire_lock` arma
`'alegra_lock_' . $key` (`:416`) con `$key = 'alegra_cron_global'` ⇒ la clave es exacta.

**Estimación**: S (0,5 h).

---

### T8.4 — UI de cron real en Ajustes (`DISABLE_WP_CRON` + crontab)

**Objetivo**: que Ajustes recomiende un cron real del sistema con la constante y una línea de crontab copiable.

**Descripción técnica**: por defecto WordPress dispara el cron **cuando alguien visita el sitio**; en
tiendas de poco tráfico la sincronización puede demorar horas. Hoy no hay `DISABLE_WP_CRON` ni
`wp_doing_cron` en producción (0 matches, C9). D7 §8.4: nueva tarjeta en la pestaña **Avanzado**, junto
a la card de "Sincronización Periódica (Cron Polling)" (`templates/admin-settings.php:466-476`). Es
**informativa**, nunca un error (REQ-POLL-05 borde). No se escribe `wp-config.php`. REQ-POLL-05.

**Desarrollo técnico** — `templates/admin-settings.php`, insertar **después** del cierre de la card de
cron (`:476`), dentro de `tab-advanced` (que cierra en `:478`):

```php
<!-- ==================== CRON REAL (RECOMENDADO) ==================== -->
<div class="ac-card" style="margin-top:20px;border-left:4px solid var(--ac-amber);">
<div class="ac-card-header"><h2><?php esc_html_e('Sincronización con cron real (recomendado)','alegra-connector');?></h2></div>
<div class="ac-notice info" style="margin-bottom:16px;"><?php esc_html_e('Por defecto, WordPress dispara el cron cuando alguien visita el sitio. En tiendas con poco tráfico la sincronización puede demorar horas. Un cron real del sistema la ejecuta a horario.','alegra-connector');?></div>

<p><?php esc_html_e('1) Desactivá el cron por visitas: agregá en wp-config.php','alegra-connector');?></p>
<pre><code>define('DISABLE_WP_CRON', true);</code></pre>

<p><?php esc_html_e('2) Agregá esta línea a tu crontab (crontab -e), reemplazando la ruta si tu hosting la cambia:','alegra-connector');?></p>
<pre><code>*/15 * * * * wget -q -O - <?php echo esc_html(home_url('/wp-cron.php')); ?>?doing_wp_cron &gt;/dev/null 2&gt;&amp;1</code></pre>

<p class="description"><?php esc_html_e('Si tu host permite PHP CLI:','alegra-connector');?></p>
<pre><code>*/15 * * * * cd /ruta/a/wordpress &amp;&amp; wp cron event run --due-now &gt;/dev/null 2&gt;&amp;1</code></pre>
</div>
```

**Orden de operaciones:** (1) card nueva después de `:476`; (2) la URL real sale de
`home_url('/wp-cron.php')` escapada con `esc_html()`; (3) todo el texto con `esc_html_e()`/`esc_html()`
para que entre al `.pot` (`make-pot.php` lo extrae en T9.5.a).

**Resultado esperado**: la pestaña **Avanzado** muestra la card con `DISABLE_WP_CRON`, la URL real del
sitio y el fallback WP-CLI; es informativa (no un `ac-notice error`); `home_url('/wp-cron.php')` se
renderiza con el dominio real.

**Dependencias**: ninguna (cosmética). T0.5/G5 decide el copy (rama A informativa vs rama B accionable);
por defecto se usa el copy informativo de arriba.

**Trazabilidad**: REQ-POLL-05; design §8.4; hallazgos C6/C9.

**Verificación**: `T29.84` (source-scan) — `templates/admin-settings.php` contiene `DISABLE_WP_CRON`,
`home_url('/wp-cron.php')` y `wp cron event run --due-now`. **Prove-it-catches:** quitar la card →
`T29.84` falla.

**Riesgo**: que el comerciante aplique `DISABLE_WP_CRON` sin configurar el crontab y se quede sin cron →
la card lo explica en dos pasos numerados. No se escribe `wp-config.php` automáticamente.

**Estimación**: M (1,5 h).

---

### T8.5 — Action Scheduler diferido (fallback WP-Cron declarado)

**Objetivo**: dejar explícito que el poll **no** depende de Action Scheduler y que WP-Cron es el fallback garantizado.

**Descripción técnica**: el repo solo menciona Action Scheduler en la limpieza (`uninstall.php:221-227`,
`as_unschedule_all_actions` guardado por `function_exists`). D7 §8.5 decide **no adoptar** AS: no está
garantizado en hosts distribuidos, y el poll ya cumple con WP-Cron + budget + cursor (Fase 7). Esta
micro-tarea **no agrega código de producción**: agrega una nota en el `CHANGELOG` (T9.5.a) y un test
estático de que el poll/cron no llaman funciones `as_*`. REQ-POLL-07.

**Desarrollo técnico**:
1. **Sin cambio de código** en `includes/` ni `public/`. El único uso de AS queda en `uninstall.php:225-227`
   (limpieza, guardado).
2. **Nota para `CHANGELOG.md`** (la aplica T9.5.a):
   > **Action Scheduler (diferido).** El poll de inventario corre por WP-Cron con presupuesto y cursor.
   > No se agrega dependencia de Action Scheduler: en hosts que no lo tienen, el poll sigue funcionando
   > igual. Es una mejora futura, no un requisito.
3. **Test estático** `T29.85`: `grep` sobre `includes/Sync/Products.php` y `includes/Sync/Controller.php`
   de `as_enqueue_async_action|as_schedule_|as_next_scheduled_action` ⇒ 0 matches.

**Resultado esperado**: el poll funciona sin AS (fallback WP-Cron); el `CHANGELOG` declara la decisión;
el test estático confirma que no hay dependencia.

**Dependencias**: ninguna. Se documenta en T9.5.a.

**Trazabilidad**: REQ-POLL-07; design §8.5; decisión abierta §10-9 de `tasks.md`.

**Verificación**: `T29.85` (source-scan). **Prove-it-catches:** agregar una llamada `as_schedule_single_action`
en el poll → `T29.85` falla.

**Riesgo**: ninguno (decisión de no-cambio).

**Estimación**: S (0,5 h).

---

### T8.6 — Tests de lock/cron

**Objetivo**: cubrir la liberación de **ambos** locks en shutdown, la propagación del budget, el aviso de lock vencido y la UI de cron.

**Descripción técnica**: tests en `scripts/exec-test.php`, sección `T29`, IDs `T29.81`–`T29.85`. El
harness ya modela `add_option`/`get_option` y `register_shutdown_function` (stub) — si el stub de
`register_shutdown_function` no ejecuta los callbacks, `T1.1`–`T1.6` deben cubrirlo (verificar en T1.1);
si no, el test usa `\Alegra\Connector\Sync\Controller::release_lock` directamente para el borde del token.
REQ-POLL-03/05/06/07.

**Desarrollo técnico** — agregar en `scripts/exec-test.php`:
1. `T29.81` — shutdown libera **ambos** locks (poll + global cron, con fatal simulado) y **no** libera un token ajeno.
2. `T29.82` — `cron_run_budget` propaga el deadline al import y al poll (spy sobre los métodos).
3. `T29.83` — lock global vencido ⇒ warning en el log.
4. `T29.84` — source-scan de la card de cron real.
5. `T29.85` — source-scan de ausencia de `as_*` en poll/cron.

**Resultado esperado**: `bash scripts/exec-test.sh` verde con `T29.81`–`T29.85`; prove-it-catches
documentados en `docs/RELEASE_2.6.0_VERIFICATION.md` (T9.6).

**Dependencias**: T8.1–T8.5, T1.6, T1.11.

**Trazabilidad**: REQ-POLL-03/05/06/07; matriz R12/R13.

**Verificación**: la corrida del harness + el prove-it-catch de cada test.

**Riesgo**: que el stub de `register_shutdown_function` no ejecute los callbacks al final del proceso →
si el harness no lo soporta, `T1.1` lo agrega; mientras tanto, `T29.81` puede llamar el callback
registrado directamente vía un helper del stub (documentarlo).

**Estimación**: M (2 h).

---

## DoD Fase 8 (checklist de cierre)

- [ ] `T8.1`: `register_shutdown_function` libera `alegra_sync_running_products` **y** `alegra_cron_global`; token ajeno intacto.
- [ ] `T8.2`: `cron_run_budget` (540) + deadline a `import_from_alegra` y `sync_inventory_from_alegra`; opción registrada.
- [ ] `T8.3`: warning `cron lock reclaimed (previous run overran)` cuando el lock global está vencido.
- [ ] `T8.4`: card de cron real con `DISABLE_WP_CRON` + `home_url('/wp-cron.php')` + WP-CLI.
- [ ] `T8.5`: sin dependencia de AS; nota en `CHANGELOG` (T9.5.a); test estático.
- [ ] `T8.6`: `T29.81`–`T29.85` verdes con prove-it-catches.
