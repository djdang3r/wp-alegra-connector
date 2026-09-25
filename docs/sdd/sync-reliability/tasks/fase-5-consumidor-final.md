# Fase 5 — D1: Consumidor Final — engine read-only, conectar y self-heal 400 (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` |
| Documentos base | `proposal.md` · `spec.md` (§A) · `design.md` (D1 §2, §10) · `tasks.md` |
| Tareas que expande | **T5.1–T5.7** (skeleton `tasks.md:380-397`) |
| Decisión de diseño | **D1** (`design.md:221-463`) · FORK **Rama A `resolve_readonly()`** (`design.md:253-268`) |
| Versión objetivo | **2.6.0** |
| Estado | T5.1, T5.2, T5.3, T5.4, T5.6, T5.7 `ACTIVO` · **T5.5 `BLOQUEADO(Fase 0.6 / G6)`** |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`; mock `scripts/lib/alegra-mock.php`) |
| Depende de | Fase 1 (H3 CONTAINS, H5 invoices, **H3b paginación**), Fase 0 (G6). **No** depende de Fase 2–4. |

> **Regla de oro (heredada del skeleton).** Después de que un test pase, **revertir el fix**, correr
> `bash scripts/exec-test.sh` y confirmar que **ese** test falla. Volver a aplicar el fix. Sin
> `prove-it-catches` el test no se acepta.

> **Todos los `file:line` fueron re-verificados en HEAD.** Las citas mal del skeleton/design se
> corrigen y se listan abajo.

> **Convención de IDs de test (harness).** Todo test **nuevo** de esta fase se nombra `T29.{fase}{n}`:
> Fase 5 ⇒ `T29.51`..`T29.59`. Los sub-tests de una tarea partida llevan un dígito extra
> (`T29.{fase}{n}{s}`): `T5.2a` ⇒ `T29.52`, `T5.2b` ⇒ `T29.520`. Los IDs de **tarea** (`T5.1`…`T5.7`)
> no cambian. El **mapa canónico** es el índice de `T5.7` (10 IDs: `T29.51`, `T29.52`, `T29.520`,
> `T29.53`–`T29.59`); cada test inline usa **ese** ID.

---

## Correcciones de cita (verificadas en HEAD para Fase 5)

| # | Cita original (skeleton/design) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Consumidor_Final.php:181-184` (`limit=5`) | Confirmado: la llamada `get_contacts([...])` va de `:181` a `:184`; `'limit' => 5` está en **`:183`**. | T5.1/T5.2 no tocan `resolve()` (intacto); el nuevo `scan_candidates` usa `limit=30`. |
| C2 | `Consumidor_Final.php:201-213` (loop de match) | Confirmado: `foreach ($contacts as $contact)` `:201`, `matches()` `:206`, `return (string) $contact['id']` `:212`. | `scan_candidates` replica el patrón con `break 2`. |
| C3 | `Consumidor_Final.php:241-251` (`matches`) | Confirmado: `private static function matches(array $contact): bool` `:241`; exige `identificationObject.type === 'CC'` + `number === IDENTIFICATION`, o `identification` plano. | Se **mantiene** (igualdad exacta). `scan_candidates` lo reusa. |
| C4 | `Consumidor_Final.php:263-277` (guard de `create()`) | Confirmado: `:272-277` exige `Write_Gate::is_explicit()` **o** `push_customers_enabled`. | El self-heal (T5.5) no crea fuera de ese guard. |
| C5 | `Consumidor_Final.php:279-285` (`write_was_blocked`) | Confirmado: `:282` `Client::write_was_blocked($result)` → log + `return false`. | Con kill switch activo no hay POST ni cache falso. |
| C6 | `Consumidor_Final.php:392-397` (`invalidate_cache`) | Confirmado exacto: borra transient de cache, option y transient de metadata. | T5.5 lo llama antes de re-resolver. |
| C7 | `Consumidor_Final.php:458` (`store_metadata`) | Confirmado: `private static function store_metadata(array $contact): void` `:458-473`. | `scan_candidates` lo reusa al matchear. |
| C8 | `Consumidor_Final.php:480-491` (`log_error`) | Confirmado: `:480` firma; `:482-490` cuerpo. | Fail-loud (T6.3) sigue logueando además de la UI. |
| C9 | `tasks.md:385` cita `Consumidor_Final.php:138` (`resolve`) | Confirmado: `public static function resolve(?Client $client = null): string|false` `:138-232`. **`cache_id()` NO existe** hoy (es nuevo). | T5.1 crea `cache_id()`; `resolve()` queda intacto (`design.md:463`). |
| C10 | `Orders.php:121-129` (sólo log) | Confirmado: `if (is_wp_error($result))` `:121`, log `:123-126`, `return $result` `:129`. | T5.5 inserta el self-heal **antes** del log, en `:121`. |
| C11 | `Orders.php:266-297` (`find_existing_invoice`) | Confirmado: firma `:266`; filtro por `client_id` `:273-275`; match por `observations` `/Pedido WooCommerce #(\d+)/` `:289-292`. | T5.5 lo reusa **antes** del reintento (idempotencia). |
| C12 | `Orders.php:1428-1435` (`persist_contact_id`) | Confirmado: escribe `_billing_alegra_contact_id` en el pedido + `alegra_contact_id` en el user. | T5.6 borra el meta del pedido si no re-resuelve. |
| C13 | `Admin_Dashboard.php:1638-1743` (handler) | El método `ajax_test_connection()` va de **`:1638` a `:1738`** (`:1739` está en blanco). | T5.3 usa `:1722` (marca conectado) y `:1729` (send). |
| C14 | `tasks.md:386` cita `Admin_Dashboard.php:1722-1729` | Confirmado: `update_option('alegra_connector_connection_tested', true)` `:1722`; `wp_send_json_success([...])` `:1729-1737`. | T5.3 inserta **entre** `:1722` y `:1729`. |
| C15 | `Write_Gate.php:41` (entidad `contact`) y `:150` (`run_explicit`) | Confirmado: `'contact' => 'alegra_connector_push_customers_enabled'` `:41`; `run_explicit` `:150-158`. | T5.3/T5.4 usan `run_explicit`; la compuerta `contact` sigue siendo autoridad. |
| C16 | `alegra-mock.php:535-556` (`POST /invoices` sólo valida presencia) | Confirmado: `alegra_mock_validate_invoice()` `:535-556` **no** verifica que `client.id` exista en `contacts`. La ruta `POST /invoices` está en `:753-765`. | **H5 (`T1.5`)** agrega el 400 por client inexistente, prerrequisito de T5.5. |
| C17 | `alegra-mock.php:858-865` (igualdad exacta) | Confirmado: `return $num === $ident;` `:863`. | **H3 (`T1.3`)** agrega el modo CONTAINS. |
| C18 | **NUEVA — harness de paginación de `/contacts`** | `alegra_mock_filter_contacts()` (`alegra-mock.php:849-867`) **NO** aplica `start`/`limit`; devuelve **todos** los filtrados. Sin esto, `scan_candidates` **no pagina** en los tests y REQ-CF-05 no se prueba de verdad. | **H3b** (se implementa junto a H3/T1.3). **Prerrequisito de T5.1.** Ver §"Harness H3b". |
| C19 | `design.md:441` `new Client()` sin guard | `resolve()` sí guarda `class_exists(Client::class)` (`:175-178`). `probe()`/`resolve_readonly()` deben hacerlo para no fatalear si la clase no cargó. | Refinamiento defensivo de T5.2a. |
| C20 | `design.md:316` `try_self_heal_dead_client` con condición "$retried === false" pero sin parámetro | La firma del design **no** lleva `$retried`. Se implementa el "una sola vez" con un transient guard `alegra_cf_self_heal_{order_id}` (TTL 60). | Refinamiento de T5.5 (evita loop entre requests). |
| C21 | Tests que asumen `$order->get_order_notes()` | El stub `WC_Order` expone **`get_notes(): array`** (`scripts/lib/wp-stubs.php:1408`); `add_order_note()` (`:1403-1407`) escribe en `$this->notes`. **No** existe `get_order_notes()`. | T5.5/T5.6/T5.7 usan `$order->get_notes()`; corregido en este doc. |
| C22 | `design.md:344`/`:349` notas de pedido sin `$order->save()` | El stub `save()` es no-op (`wp-stubs.php:1363`); en producción `add_order_note()` ya persiste la nota, pero `delete_meta_data()` requiere `save()`. | T5.5/T5.6 llaman `$order->save()` tras modificar meta. |
| C23 | `design.md:329-330` self-heal re-resuelve con `get_or_create_id()` | **FIX-18 (Oracle D9):** `get_or_create_id()` → `resolve()` pide `limit=5` (`Consumidor_Final.php:183`); el barrido paginado vive en `scan_candidates` (T5.1). | T5.5 re-resuelve con `probe()` (paginado) y sólo cae a `get_or_create_id()` si el barrido completo dio `not_found`. |

**Citas confirmadas exactas (no requieren corrección):** `Consumidor_Final.php:20-31` (constantes), `:26-31` (`CACHE_TRANSIENT`/`CACHE_TTL`/`OPTION_KEY`), `:44-67` (`peek_id`), `:74-77` (`is_configured`), `:91-113` (`get_or_create_id`), `:219` (`create`), `:226` (`invalidate_cache` en el fallo), `:405-425` (`is_consumidor_final`), `:448-451` (`cache_key`); `Client.php:242` (`api_error` con `['code'=>$code,'response'=>$error_data]`), `:300` (`write_was_blocked`), `:365-368` (`get_contacts`), `:375-378` (`create_contact`), `:459-462` (`create_invoice`), `:761-764` (`get_inventory_adjustments`), `:770-773` (`create_inventory_adjustment`); `Write_Gate.php:108-125` (`block_reason`), `:123` (`ENTITY_DEFAULTS`); `Admin_Dashboard.php:39` (registro AJAX), `:1640` (`check_ajax_referer`), `:1644` (`manage_options`); `alegra-connector.php:414-457` (`$defaults`), `:463-486` (`$non_autoload`), `:488-492` (siembra).

---

## Harness H3b — paginación de `/contacts` (prerrequisito de T5.1)

Sin esto, el mock devuelve **todos** los contactos en cada página y `scan_candidates` "encuentra" el CF
en la página 0 aunque esté fuera del primer lote: el test pasaría **sin** probar la paginación.

**Archivo:** `scripts/lib/alegra-mock.php`, al final de `alegra_mock_filter_contacts()` (después de
`:865`, antes del `return $contacts;` de `:866`):

```php
    // H3b: honor start/limit so pagination is observable (REQ-CF-05).
    // `limit=0`/ausente ⇒ sin tope (comportamiento previo, sin regresión).
    $start = max(0, (int) ($query['start'] ?? 0));
    $limit = (int) ($query['limit'] ?? 0);
    if ($limit > 0) {
        $contacts = array_values(array_slice($contacts, $start, $limit));
    }
```

**Nota de regresión:** con H3b, `resolve()` (`limit=5`) recibe **hasta 5**. Los tests existentes que
siembran 1 CF no cambian. El worker debe correr la suite completa; si algún test viejo sembraba >5
contactos con la misma identificación y dependía de verlos todos, se ajusta el seed (es una mejora de
fidelidad, no un cambio de contrato de producción).

**Dependencia:** se registra junto a **H3 (`T1.3`)** en la sección de harness. `T1.3` es su dueño; T5.1
lo **consume**.

---

## Fase 5 — Objetivo

- **Conectar** resuelve el CF **read-only** (GET + match + caché), bajo `Write_Gate::run_explicit()`,
  sin crear nada. (REQ-CF-01, REQ-CF-04 Rama A)
- El **barrido** pagina (`limit=30`, `max_pages=10`) y sólo concluye `not_found` con un barrido
  **completo**; un tope ⇒ `unverified`. (REQ-CF-05)
- El dashboard distingue **Disponible / No verificado / No encontrado** y ofrece **"Verificar ahora"**.
  (REQ-CF-02, REQ-CF-03)
- Un **400 por client id muerto** en la factura se **auto-sana**: invalida, re-resuelve, pre-busca la
  factura (idempotencia) y reintenta **una** vez. (REQ-CF-06, REQ-CF-08, NFR-07)
- El fallo de resolución es **visible** (fail-loud), no sólo log. (REQ-CF-07)

**DoD de la fase:** REQ-CF-01..07 verdes; `probe()`/`resolve_readonly()` **nunca** POSTean; el self-heal
dispara **sólo** con 400+cliente+id CF y no duplica; el dashboard deja de decir "No encontrado" con el
contacto existente; no hay regresión en el camino de factura normal (NFR-01).

---

### T5.1 — `scan_candidates()` + `cache_id()`

**Objetivo**: barrer los candidatos del filtro `identification` (CONTAINS) por páginas, matchear por
igualdad exacta y cachear el id; nunca concluir "no existe" con un barrido incompleto.

**Descripción técnica**: hoy `resolve()` pide `limit=5` (`Consumidor_Final.php:183`). Como el filtro
`identification` de Alegra es **CONTAINS**, 5 falsos positivos pueden tapar al CF real y el código cae
en `create()` (`:219`) o en "No encontrado" (`templates/admin-dashboard.php:139`). Decisión **D1**
(`design.md:378-426`); cubre **REQ-CF-05**. Esta tarea agrega el motor de barrido y el cacheo; **no**
toca `resolve()` (`design.md:463`).

**Desarrollo técnico**

Archivo: `includes/Consumidor_Final.php`.

**1) `scan_candidates()`** — nuevo método privado (junto a `matches()` `:241`):

```php
/**
 * Barrido read-only de los candidatos del filtro `identification` (CONTAINS).
 *
 * Pagina con `limit=30` hasta `max_pages=10` (300 candidatos). Una página llena
 * NO es "fin": se pide la siguiente. Sólo un barrido COMPLETO habilita
 * `not_found`; un tope ⇒ `truncated` (⇒ el caller reporta `unverified`).
 * NUNCA POSTea: sólo GET + match + store_metadata.
 *
 * @return array{found:?string,complete:bool,scanned:int,reason:string}
 */
private static function scan_candidates(Client $client): array
{
    $per_page  = 30;   // máximo documentado de /contacts
    $max_pages = 10;   // 300 candidatos; más ⇒ unverified
    $found     = null;
    $scanned   = 0;
    $complete  = false;

    for ($p = 0; $p < $max_pages; $p++) {
        $batch = $client->get_contacts([
            'identification' => self::IDENTIFICATION,   // CONTAINS en Alegra
            'limit'          => $per_page,
            'start'          => $p * $per_page,
        ]);

        if (is_wp_error($batch)) {
            return ['found' => null, 'complete' => false, 'scanned' => $scanned, 'reason' => 'api_error'];
        }
        if (!is_array($batch)) {
            return ['found' => null, 'complete' => false, 'scanned' => $scanned, 'reason' => 'bad_response'];
        }

        foreach ($batch as $contact) {
            $scanned++;
            if (is_array($contact) && isset($contact['id']) && self::matches($contact)) {
                $found = (string) $contact['id'];
                self::store_metadata($contact);
                break 2;
            }
        }

        // Una página llena NO es "fin": pedir la siguiente.
        if (count($batch) < $per_page) {
            $complete = true;
            break;
        }
    }

    return [
        'found'    => $found,
        'complete' => $complete,
        'scanned'  => $scanned,
        'reason'   => $found !== null ? 'match' : ($complete ? 'not_found' : 'truncated'),
    ];
}
```

**2) `cache_id()`** — nuevo método privado (junto a `store_metadata()` `:458`):

```php
/**
 * Cachea el id del CF en transient + option (misma representación que
 * get_or_create_id()). Sólo lo llama el camino read-only tras un match exacto.
 */
private static function cache_id(string $id): void
{
    if ($id === '') {
        return;
    }
    set_transient(self::cache_key(self::CACHE_TRANSIENT), $id, self::CACHE_TTL);
    update_option(self::cache_key(self::OPTION_KEY), $id);
}
```

**3) Refactor no-op (recomendado, mantiene una sola representación).** En
`get_or_create_id()`, reemplazar las dos líneas de `:109-110`:

**ANTES** (`:109-110`):
```php
        set_transient(self::cache_key(self::CACHE_TRANSIENT), $resolved, self::CACHE_TTL);
        update_option(self::cache_key(self::OPTION_KEY), $resolved);
```
**DESPUÉS:**
```php
        self::cache_id($resolved);
```

> Este refactor **no cambia comportamiento** (mismas dos escrituras). Si el worker prefiere no tocarlo,
> `cache_id()` sigue siendo válido para `probe()`/`resolve_readonly()`. Se deja indicado para DRY.

**Orden de operaciones de `scan_candidates` (no invertir):**
1. `get_contacts` por página (GET).
2. Si `WP_Error` → `api_error` (no `not_found`).
3. Si `!is_array` → `bad_response`.
4. Por candidato: `matches()` (igualdad exacta) → `store_metadata()` + `break 2`.
5. Página llena (`count === 30`) ⇒ continuar; página corta ⇒ `complete=true`.
6. Tope de 10 páginas sin completar ⇒ `reason='truncated'`.

**Resultado esperado**
- Con 30 falsos positivos CONTAINS y el CF real en el lote 2, el barrido lo encuentra y **no** crea
  duplicado.
- Una página llena **no** se toma como fin: se pide un lote más.
- Un barrido completo sin match ⇒ `reason='not_found'`, `complete=true`.
- Tope agotado sin completar ⇒ `reason='truncated'`, `complete=false`.
- `cache_id('x')` deja `get_transient(...) === 'x'` y `get_option(...) === 'x'`.

**Dependencias**: **H3b** (paginación del mock) y **H3** (modo CONTAINS). Sin H3b el test de paginación
es vacuo; sin H3 no se pueden sembrar falsos positivos CONTAINS.

**Trazabilidad**: REQ-CF-05 (y base de REQ-CF-02/04).

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.51 scan_candidates paginates CONTAINS results and finds the real CF (REQ-CF-05)', function (): void {
    alegra_test_reset();
    alegra_mock_set_contact_identification_mode('contains');   // H3 (T1.3)

    // 30 falsos positivos que CONTIENEN el número, pero NO son iguales.
    for ($i = 1; $i <= 30; $i++) {
        alegra_mock_seed_contact('decoy-' . $i, [
            'identificationObject' => ['type' => 'CC', 'number' => '222222222222' . $i],
        ]);
    }
    // El CF real, en la página 2 (posición 31).
    alegra_mock_seed_contact('cf-real', [
        'name' => 'Consumidor Final',
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);

    $scan = alegra_call_private_static(\Alegra\Connector\Consumidor_Final::class, 'scan_candidates', make_api());
    TestRunner::assertSame('cf-real', $scan['found'], 'the real CF on page 2 must be found');
    TestRunner::assertSame(31, $scan['scanned'], 'the scan must have walked page 1 and page 2');

    // Tope agotado ⇒ truncated (no not_found).
    alegra_test_reset();
    alegra_mock_set_contact_identification_mode('contains');
    for ($i = 1; $i <= 301; $i++) {
        alegra_mock_seed_contact('d-' . $i, ['identificationObject' => ['type' => 'CC', 'number' => '222222222222' . $i]]);
    }
    $scan2 = alegra_call_private_static(\Alegra\Connector\Consumidor_Final::class, 'scan_candidates', make_api());
    TestRunner::assertNull($scan2['found'], 'no real CF exists');
    TestRunner::assertFalse($scan2['complete'], 'a capped scan is NOT complete');
    TestRunner::assertSame('truncated', $scan2['reason'], 'a capped scan must report truncated');
});
```
> `alegra_mock_set_contact_identification_mode()` lo crea **H3 (T1.3)**. `make_api()` está en
> `scripts/exec-test.php:52`. `alegra_call_private_static` está en `test-framework.php:313`.

**Prove-it-catches**: (a) cambiar `$per_page` a 5 en `scan_candidates` → `T29.51` falla (no llega al
lote 2, o `scanned` deja de ser 31). (b) quitar el `break 2` y el chequeo de página llena → el barrido
cortaría en la primera página. (c) quitar H3b del mock → el test de paginación queda vacuo (el CF
aparece en el lote 0); con H3b repuesto, vuelve a probar paginación.

**Riesgo**: **DR6** — concluir `not_found` con un barrido incompleto ⇒ crear duplicado. Guard: sólo
`complete=true` ⇒ `not_found`; tope ⇒ `truncated`/`unverified`. **DR16** — divergencia harness↔prod
(CONTAINS/paginación): H3 + H3b.

**Estimación**: M (2 h).

---

### T5.2a — `resolve_readonly()` + `probe()` (READ-ONLY, nunca POST)

**Objetivo**: exponer una resolución **read-only** (GET + match + caché) y un `probe()` que devuelva el
estado honesto sin escribir nunca en Alegra.

**Descripción técnica**: `resolve()` (`:138`) puede **crear** (`:219`); no es apto para "conectar" ni
para el render. Se agregan `resolve_readonly()` y `probe()` que **jamás** llaman a `create()` ni
`get_or_create_id()`. Decisión **D1 Rama A** (`design.md:253-268, 428-461`); cubre **REQ-CF-04 (Rama A),
REQ-CF-02, REQ-CF-07**.

**Desarrollo técnico**

Archivo: `includes/Consumidor_Final.php`.

**1) `resolve_readonly()`** — público (firma exacta `design.md:1033`):

```php
/**
 * Resuelve el CF SÓLO con GET + match + caché. NUNCA POSTea. D1 Rama A.
 *
 * @return string|false Id del CF, o false si no se encontró / no se pudo verificar.
 */
public static function resolve_readonly(?Client $client = null): string|false
{
    $peeked = self::peek_id();
    if ($peeked !== false) {
        return $peeked;
    }
    if ($client === null) {
        if (!class_exists(Client::class)) {
            return false;
        }
        $client = new Client();
    }
    $scan = self::scan_candidates($client);
    if ($scan['found'] !== null) {
        self::cache_id($scan['found']);
        return $scan['found'];
    }
    return false;
}
```

**2) `probe()`** — público (firma exacta `design.md:1034`):

```php
/**
 * Estado honesto del CF, sin escribir nunca.
 *
 * 1. cache/override ⇒ available (sin red).
 * 2. barrido read-only ⇒ match ⇒ cache_id() + available.
 * 3. barrido completo sin match ⇒ not_found.
 * 4. red caída / barrido truncado ⇒ unverified (NUNCA "no encontrado").
 *
 * @return array{state:'available'|'not_found'|'unverified',id:?string,reason:string,scanned:int}
 */
public static function probe(?Client $client = null): array
{
    $peeked = self::peek_id();
    if ($peeked !== false) {
        return ['state' => 'available', 'id' => $peeked, 'reason' => 'cached', 'scanned' => 0];
    }
    if ($client === null) {
        if (!class_exists(Client::class)) {
            return ['state' => 'unverified', 'id' => null, 'reason' => 'client_unavailable', 'scanned' => 0];
        }
        $client = new Client();
    }

    $scan = self::scan_candidates($client);
    if ($scan['found'] !== null) {
        self::cache_id($scan['found']);
        return ['state' => 'available', 'id' => $scan['found'], 'reason' => 'match', 'scanned' => $scan['scanned']];
    }
    if ($scan['complete']) {
        return ['state' => 'not_found', 'id' => null, 'reason' => 'not_found', 'scanned' => $scan['scanned']];
    }
    return ['state' => 'unverified', 'id' => null, 'reason' => $scan['reason'], 'scanned' => $scan['scanned']];
}
```

**Notas:**
- `probe()` es **cache-first** por diseño (`design.md:437-440`): si `peek_id()` devuelve un id
  (cache/override), devuelve `available` sin red. El dashboard sólo ofrece "Verificar ahora" en estado
  `unverified` (sin caché); un CF cacheado que se borra en Alegra lo detecta el **self-heal** (T5.5).
- `probe()`/`resolve_readonly()` **nunca** llaman `create()` ni `get_or_create_id()`. La única escritura
  que hacen es local (transient/option) vía `cache_id()`/`store_metadata()`.
- El guard `class_exists(Client::class)` es un refinamiento defensivo sobre `design.md:441` (C19),
  espejo de `resolve():175-178`.

**Resultado esperado**
- `probe()` con el CF cacheado ⇒ `available` + `reason='cached'`, **0 requests**.
- `probe()` con el CF existente y caché vacía ⇒ `available` + `reason='match'` + `cache_id()` aplicado.
- `probe()` sin CF y barrido completo ⇒ `not_found`.
- `probe()` con la API caída o tope agotado ⇒ `unverified` (`reason='api_error'`/`'truncated'`).
- En **todos** los casos, `alegra_mock_count('POST', '/contacts') === 0`.

**Dependencias**: T5.1 (`scan_candidates`, `cache_id`), H3, H3b.

**Trazabilidad**: REQ-CF-04 (Rama A), REQ-CF-02, REQ-CF-07.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.52 probe is read-only and reports available/not_found/unverified (REQ-CF-02/04/07)', function (): void {
    alegra_test_reset();

    // available por match, sin POST.
    alegra_mock_seed_contact('cf-1', [
        'name' => 'Consumidor Final',
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);
    $probe = \Alegra\Connector\Consumidor_Final::probe(make_api());
    TestRunner::assertSame('available', $probe['state'], 'the seeded CF must be available');
    TestRunner::assertSame('cf-1', $probe['id'], 'the id must be returned');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'probe must never POST');

    // not_found: barrido completo sin match.
    alegra_test_reset();
    $probe2 = \Alegra\Connector\Consumidor_Final::probe(make_api());
    TestRunner::assertSame('not_found', $probe2['state'], 'a complete empty scan is not_found');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'not_found must not create');

    // unverified: API caída.
    alegra_test_reset();
    alegra_mock_fail('GET', '/contacts', 500, ['message' => 'boom']);
    $probe3 = \Alegra\Connector\Consumidor_Final::probe(make_api());
    TestRunner::assertSame('unverified', $probe3['state'], 'an API error is unverified, never not_found');
    TestRunner::assertSame('api_error', $probe3['reason'], 'the reason must be readable');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'an API error must not create');

    // resolve() sigue creando (intacto) sólo en contexto explícito.
    alegra_test_reset();
    $created = \Alegra\Connector\Write_Gate::run_explicit(static fn () => \Alegra\Connector\Consumidor_Final::get_or_create_id());
    TestRunner::assertTrue($created !== false && $created !== '', 'resolve() must still be able to create under explicit context');
});
```

**Prove-it-catches**: agregar un `create()` en `probe()` (o llamar `get_or_create_id()`) → el
`assertSame(0, ...'POST','/contacts')` de `not_found`/`api_error` falla. Cambiar el branch de `api_error`
a `not_found` → el `assertSame('unverified', ...)` falla.

**Riesgo**: **DR7** — que `probe()`/render POSTee por accidente. Guard: `probe()`/`resolve_readonly()`
no llaman a `create()`; test estático `T29.64` sobre el render. Que el cache-first oculte un CF borrado
(mitigado por el self-heal T5.5).

**Estimación**: M (2 h).

---

### T5.2b — `probe_state()` + persistencia de la opción `consumidor_final_probe`

**Objetivo**: que el render del dashboard lea el estado del CF **sin red**, desde una opción persistida.

**Descripción técnica**: el render (`templates/admin-dashboard.php`) no debe tocar la red (REQ-CF-02
escenario negativo, REQ-RB-1 de `config-gates`). Se persiste el resultado de `probe()` en la opción
`alegra_connector_consumidor_final_probe` (array, autoload no) y el render la lee con `probe_state()`.
Cubre **REQ-CF-02, REQ-CF-07**.

**Desarrollo técnico**

Archivo: `includes/Consumidor_Final.php`.

**1) `probe_state()`** — público (firma exacta `design.md:1035`):

```php
/**
 * Lee el último probe persistido SIN tocar la red (para el render).
 *
 * @return array{state:string,id:?string,reason:string,scanned:int,at:int}
 */
public static function probe_state(): array
{
    $default = ['state' => 'unverified', 'id' => null, 'reason' => '', 'scanned' => 0, 'at' => 0];
    $stored = get_option('alegra_connector_consumidor_final_probe', []);
    if (!is_array($stored)
        || !isset($stored['state'])
        || !in_array($stored['state'], ['available', 'not_found', 'unverified'], true)
    ) {
        return $default;
    }
    return array_merge($default, $stored);
}
```

**2) Persistencia** — helper opcional para no repetir la escritura en T5.3/T5.4:

```php
/**
 * Persiste el resultado de probe() con timestamp. Autoload off.
 */
public static function persist_probe(array $probe): array
{
    $probe['at'] = time();
    update_option('alegra_connector_consumidor_final_probe', $probe, false);
    return $probe;
}
```
> T5.3/T5.4 llaman `persist_probe()` (o inlines el `update_option(..., false)`). El diseño no nombra
> el helper; se agrega para una sola ruta de escritura (mismo espíritu que `cache_id()`).

**3) Opción en `$non_autoload`/`uninstall.php`** — **dueño: T1.7 (H8)**. `alegra_connector_consumidor_final_probe`
debe figurar en `$non_autoload` (`alegra-connector.php:463-486`) y en `uninstall.php`. Como se escribe
con `update_option(..., false)`, el autoload queda off aunque falte la siembra; **no** se agrega a
`$defaults` (es interna; `design.md:1107`).

**Resultado esperado**
- Sin opción persistida, `probe_state()` devuelve `['state'=>'unverified', ...]` con **0 requests**.
- Con la opción `['state'=>'available','id'=>'cf-1','reason'=>'match','at'=>123]`,
  `probe_state()` devuelve ese estado sin red.
- Una opción corrupta (`state` inválido) degrada al default (`unverified`), sin fatal.

**Dependencias**: T5.2a (produce el array), T1.7 (siembra/limpieza de la opción).

**Trazabilidad**: REQ-CF-02, REQ-CF-07, NFR-04.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.520 probe_state reads the persisted option without network', function (): void {
    alegra_test_reset();
    TestRunner::assertSame('unverified', \Alegra\Connector\Consumidor_Final::probe_state()['state'], 'default is unverified');

    update_option('alegra_connector_consumidor_final_probe', [
        'state' => 'available', 'id' => 'cf-1', 'reason' => 'match', 'scanned' => 2, 'at' => 123,
    ], false);
    $before = alegra_mock_count('GET', '/contacts');
    $state  = \Alegra\Connector\Consumidor_Final::probe_state();
    TestRunner::assertSame('available', $state['state'], 'the stored state is read');
    TestRunner::assertSame('cf-1', $state['id'], 'the stored id is read');
    TestRunner::assertSame($before, alegra_mock_count('GET', '/contacts'), 'probe_state must not touch the network');
});
```
> ID canónico de T5.2b: **`T29.520`** (`T29.52` es de T5.2a). El inline ya coincide con el índice de
> `T5.7` (C9 resuelto). **Ver §"Índice de tests" para el mapa final.**

**Prove-it-catches**: quitar el `in_array($stored['state'], ...)` → un estado corrupto se devolvería tal
cual; el test de default no lo cubre. Mejor: quitar el default `unverified` (devolver `[]`) → el test de
default falla.

**Riesgo**: que el render lea un estado viejo (aceptable: es el último verificado; `at` lo expone).
Que la opción se escriba con autoload on (guard: `update_option(..., false)`).

**Estimación**: S (1 h).

---

### T5.3 — Conectar resuelve el CF bajo `run_explicit()`

**Objetivo**: que la acción explícita de **conectar** resuelva el CF (read-only), lo cachee y lo
devuelva en el payload, sin crear nada.

**Descripción técnica**: hoy `ajax_test_connection()` guarda credenciales y marca
`alegra_connector_connection_tested=true` (`Admin_Dashboard.php:1722`) pero **nunca** resuelve el CF → la
caché queda vacía y el dashboard dice "No encontrado" (`templates/admin-dashboard.php:139`). Decisión
**D1** (`design.md:223-251`); cubre **REQ-CF-01**.

**Desarrollo técnico**

Archivo: `admin/Admin/Admin_Dashboard.php`, método `ajax_test_connection()` (`:1638-1738`).

**Punto de inserción:** **después** de `update_option('alegra_connector_connection_tested', true);`
(`:1722`) y **antes** de `$diagnostics = $result['diagnostics'] ?? [];` (`:1724`). Motivo: `wp_send_json_*()`
termina el request con `wp_die()`; la resolución debe completarse **antes** de responder (no hay
`finally`).

**ANTES** (`:1722-1737`):
```php
        update_option('alegra_connector_connection_tested', true);

        $diagnostics = $result['diagnostics'] ?? [];
        update_option('alegra_connector_diagnostics', $diagnostics, false);

        $this->log('info', 'Connection test successful', ['company' => $result['company'] ?? 'Unknown', 'diagnostics' => $diagnostics]);

        wp_send_json_success([
            'message' => __('Conexión exitosa', 'alegra-connector'),
            'company' => $result['company'] ?? '',
            'country' => $result['country'] ?? '',
            'email' => $result['email'] ?? '',
            'items_count' => $result['items_count'] ?? 0,
            'contacts_count' => $result['contacts_count'] ?? 0,
            'diagnostics' => $diagnostics,
        ]);
```

**DESPUÉS:**
```php
        update_option('alegra_connector_connection_tested', true);

        // D1 / REQ-CF-01: resolver el CF bajo contexto explícito, READ-ONLY.
        // probe() nunca POSTea; run_explicit() deja el contexto listo para el
        // sub-flujo explícito "Crear Consumidor Final" (mismo AJAX, create=true).
        $cf_probe = \Alegra\Connector\Write_Gate::run_explicit(
            static fn (): array => \Alegra\Connector\Consumidor_Final::probe()
        );
        \Alegra\Connector\Consumidor_Final::persist_probe($cf_probe);

        $diagnostics = $result['diagnostics'] ?? [];
        update_option('alegra_connector_diagnostics', $diagnostics, false);

        $this->log('info', 'Connection test successful', ['company' => $result['company'] ?? 'Unknown', 'diagnostics' => $diagnostics]);

        wp_send_json_success([
            'message' => __('Conexión exitosa', 'alegra-connector'),
            'company' => $result['company'] ?? '',
            'country' => $result['country'] ?? '',
            'email' => $result['email'] ?? '',
            'items_count' => $result['items_count'] ?? 0,
            'contacts_count' => $result['contacts_count'] ?? 0,
            'diagnostics' => $diagnostics,
            'consumidor_final' => $cf_probe,
        ]);
```

**Por qué `run_explicit()` aunque `probe()` sea read-only** (`design.md:247-251`): (1) una sola ruta
entre conectar y "Verificar ahora"; (2) deja el contexto listo si el comerciante usa el botón explícito
"Crear Consumidor Final". La compuerta `contact` (`Write_Gate.php:41`) y el guard de `create()`
(`Consumidor_Final.php:272-277`) siguen siendo la autoridad: con kill switch activo no hay POST.

**Resultado esperado**
- Conectar con el CF existente y la caché vacía ⇒ el payload trae
  `consumidor_final.state === 'available'`, la opción `alegra_connector_consumidor_final_probe` queda
  persistida y el dashboard muestra "Disponible".
- Conectar **no** emite `POST /contacts` (la resolución es read-only).
- El flujo de factura (`Orders::create_invoice`) **no** cambia por este requerimiento.

**Dependencias**: T5.2a, T5.2b, T1.8 (Write_Gate `inventory`; la entidad `contact` ya existe), T1.7
(opción). No depende de Fase 2–4.

**Trazabilidad**: REQ-CF-01, REQ-CF-04 (Rama A), NFR-06.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.53 connecting resolves the CF read-only and persists the probe (REQ-CF-01)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('cf-1', [
        'name' => 'Consumidor Final',
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);

    $_POST['email'] = 'harness@example.test';
    $_POST['token'] = 'harness-token';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_test_connection());

    TestRunner::assertTrue($resp->success, 'connection must succeed');
    TestRunner::assertSame('available', $resp->payload['consumidor_final']['state'] ?? null, 'the CF probe must be available');
    $stored = get_option('alegra_connector_consumidor_final_probe', []);
    TestRunner::assertSame('available', $stored['state'] ?? null, 'the probe must be persisted');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'connecting must not create the CF');
});
```

**Prove-it-catches**: quitar el bloque `run_explicit(...probe()...)` y el `persist_probe` → el
`assertSame('available', ...)` y el `get_option` fallan.

**Riesgo**: que `probe()` se cachee antes de que el comerciante vea el resultado (aceptable). Que
`wp_send_json` corte antes de persistir (mitigado: la persistencia va **antes** del send). Que conectar
tarde por el barrido (máx. 10 GET; aceptable en una acción explícita).

**Estimación**: S/M (1.5 h).

---

### T5.4 — AJAX `alegra_verify_consumidor_final` (+ botón explícito "Crear Consumidor Final")

**Objetivo**: exponer la misma resolución explícita por AJAX, con nonce + capacidad, y permitir la
creación **explícita** del CF bajo `run_explicit()`.

**Descripción técnica**: el render no puede resolver (REQ-RB-1); el comerciante necesita un botón
"Verificar ahora" que corra la misma ruta que conectar, y un botón "Crear Consumidor Final" cuando el
barrido completo concluyó `not_found`. Decisión **D1** (`design.md:285-297`); cubre **REQ-CF-03,
REQ-CF-04 (Rama B explícita), NFR-06**.

**Desarrollo técnico**

Archivo: `admin/Admin/Admin_Dashboard.php`.

**1) Registro** — junto a `:39` (`add_action('wp_ajax_alegra_test_connection', ...)`):
```php
        add_action('wp_ajax_alegra_verify_consumidor_final', [$this, 'ajax_verify_consumidor_final']);
```

**2) Handler** — método nuevo (junto a `ajax_test_connection()` `:1738`):

```php
/**
 * AJAX: verifica (y opcionalmente crea) el Consumidor Final.
 *
 * - action:  alegra_verify_consumidor_final
 * - nonce:   alegra_connector_nonce
 * - cap:     manage_options
 * - body:    create (bool, opcional)
 * - resp:    {success, data:{state, id, message}}  (message SIEMPRE, NFR-04)
 *
 * READ-ONLY salvo create=true, que corre get_or_create_id() bajo run_explicit().
 * Con el kill switch activo, la compuerta bloquea el POST y no se cachea nada.
 */
public function ajax_verify_consumidor_final(): void
{
    check_ajax_referer('alegra_connector_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('No tienes permisos.', 'alegra-connector')]);
    }

    $create = !empty($_POST['create'])
        && filter_var(wp_unslash($_POST['create']), FILTER_VALIDATE_BOOLEAN);

    $probe = \Alegra\Connector\Write_Gate::run_explicit(
        static function () use ($create): array {
            if ($create) {
                // Rama B explícita: crear SÓLO bajo acción explícita.
                $id = \Alegra\Connector\Consumidor_Final::get_or_create_id();
                if ($id !== false && $id !== '') {
                    return ['state' => 'available', 'id' => (string) $id, 'reason' => 'created', 'scanned' => 0];
                }
                // No se pudo crear (kill switch / sin datos): caer al barrido
                // read-only para no mentir con un estado "available" falso.
            }
            return \Alegra\Connector\Consumidor_Final::probe();
        }
    );

    $probe = \Alegra\Connector\Consumidor_Final::persist_probe($probe);

    wp_send_json_success([
        'state'   => $probe['state'],
        'id'      => $probe['id'],
        'message' => self::consumidor_final_message($probe),
    ]);
}

/**
 * Mensaje accionable por estado (NFR-04). Siempre no vacío.
 */
private static function consumidor_final_message(array $probe): string
{
    switch ((string) ($probe['state'] ?? '')) {
        case 'available':
            return __('Consumidor Final disponible.', 'alegra-connector');
        case 'not_found':
            return __('No se encontró el Consumidor Final en Alegra. Podés crearlo.', 'alegra-connector');
        default:
            $reason = (string) ($probe['reason'] ?? '');
            return $reason !== ''
                ? sprintf(__('No se pudo verificar el Consumidor Final: %s', 'alegra-connector'), $reason)
                : __('No se pudo verificar el Consumidor Final.', 'alegra-connector');
    }
}
```

**Contrato de respuesta:** `{success:true, data:{state, id, message}}` — mantiene `success`/`data` y
**agrega** `message` siempre (NFR-04). Los errores de permiso usan `wp_send_json_error(['message'=>...])`.

**Kill switch:** con el kill switch activo, `create=true` → `get_or_create_id()` → `resolve()` → `create()`
(`:263`) → `create_contact()` → `Client`/`Write_Gate` devuelve el marcador gate-blocked → `create()`
detecta `write_was_blocked` (`:282`) y devuelve `false` → cae a `probe()` (GET). **No hay POST.**

**Resultado esperado**
- Sin nonce válido o sin `manage_options` ⇒ `wp_send_json_error` y **ninguna** llamada a Alegra.
- `create` ausente/false ⇒ corre `probe()` (read-only) y responde el estado + `message`.
- `create=true` con el CF inexistente y kill switch inactivo ⇒ `POST /contacts` **una** vez y
  `state='available'`.
- `create=true` con kill switch activo ⇒ **0** `POST /contacts`; estado `not_found`/`unverified` con
  `message` accionable.
- La opción `alegra_connector_consumidor_final_probe` queda persistida.

**Dependencias**: T5.2a, T5.2b, T1.8, T1.7.

**Trazabilidad**: REQ-CF-03, REQ-CF-04 (Rama B), NFR-04, NFR-06.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.54 the verify AJAX requires nonce/cap and never POSTs unless create=true (REQ-CF-03/04)', function (): void {
    alegra_test_reset();

    // Sin capacidad → error de permiso, sin tocar la API.
    $GLOBALS['alegra_test_caps'] = [];   // ajustar al mecanismo real del stub
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp = alegra_capture_json(fn () => $admin->ajax_verify_consumidor_final());
    TestRunner::assertFalse($resp->success, 'without manage_options the AJAX must fail');
    TestRunner::assertSame(0, alegra_mock_count('GET', '/contacts'), 'no API call without permission');

    // Con capacidad, create=false → read-only.
    alegra_test_reset();
    alegra_mock_seed_contact('cf-1', [
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp2 = alegra_capture_json(fn () => $admin->ajax_verify_consumidor_final());
    TestRunner::assertTrue($resp2->success, 'the AJAX must succeed');
    TestRunner::assertSame('available', $resp2->payload['state'], 'the CF must be available');
    TestRunner::assertTrue(isset($resp2->payload['message']) && $resp2->payload['message'] !== '', 'message must always be present');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'create=false must not POST');

    // create=true → crea una vez.
    alegra_test_reset();
    $_POST['create'] = '1';
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    $resp3 = alegra_capture_json(fn () => $admin->ajax_verify_consumidor_final());
    TestRunner::assertSame('available', $resp3->payload['state'], 'the created CF must be available');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/contacts'), 'create=true must POST exactly once');
    unset($_POST['create']);
});
```
> El worker debe verificar el mecanismo real de `current_user_can`/nonce en el harness
> (`$GLOBALS['alegra_test_caps']`, `$GLOBALS['alegra_test_referer_ok']`, `test-framework.php:172-173`).

**Prove-it-catches**: quitar `current_user_can('manage_options')` → el primer bloque falla. Quitar el
`run_explicit()` de `create=true` → `create()` (`:272-277`) bloquea la creación y `POST /contacts` queda
en 0 (el test de `create=true` falla).

**Riesgo**: que `create=true` se dispare sin intención del comerciante (guard: es un botón explícito +
`run_explicit` + compuerta `contact`). Que el nonce no se valide (guard: `check_ajax_referer`).

**Estimación**: M (2 h).

---

### T5.5 — Self-heal del 400 por client id muerto · BLOQUEADO(Fase 0.6 / G6)

**Objetivo**: cuando el POST de factura devuelve **400 por un client id muerto** (el CF se borró o
cambió), invalidar la caché, re-resolver, pre-buscar la factura (idempotencia) y reintentar **una** vez.

**Descripción técnica**: hoy `create_invoice()` sólo loguea el `WP_Error` (`Orders.php:121-129`); el
meta `_billing_alegra_contact_id` se persiste **antes** del POST (`persist_contact_id()` `:1428-1435`;
POST `:119`), así que un id muerto queda clavado y la factura falla siempre. Decisión **D1**
(`design.md:299-376`); cubre **REQ-CF-06, REQ-CF-08, NFR-07**. **CRÍTICO: no romper el camino que hoy
funciona** — dispara **sólo** con 400 + mención de cliente + id CF + una sola vez.

**Condición exacta de disparo (conservadora, las 4 juntas):**
1. `$error->get_error_data()['code'] === 400` (el `Client` devuelve
   `new WP_Error('api_error', $msg, ['code'=>$code,'response'=>$error_data])`, `Client.php:242`), **y**
2. el error referencia al cliente (`response.client` presente **o** el mensaje matchea
   `/client|cliente/i`), **y**
3. el `client.id` usado es el **CF** (`Consumidor_Final::is_consumidor_final($id)`, `:405`), **y**
4. todavía no se reintentó (transient guard `alegra_cf_self_heal_{order_id}`, TTL 60).

Cualquier otro error (401, 422, red, 5xx) **retorna como en HEAD**.

**Desarrollo técnico**

Archivo: `includes/Sync/Orders.php`.

**1) Método nuevo** — junto a `find_existing_invoice()` (`:266`):

```php
/**
 * Auto-sanado de la caché podrida del CF ante un 400 por client id muerto.
 *
 * D1 / REQ-CF-06. Sólo dispara con 400 + mención de cliente + id CF + una vez.
 * Reusa find_existing_invoice() ANTES del reintento para no duplicar la factura.
 *
 * @return array|null  El resultado de la factura si se auto-sanó, null si no aplica.
 */
private function try_self_heal_dead_client(\WC_Order $order, array $data, \WP_Error $error): ?array
{
    // 1) Sólo un 400.
    $code = (int) (($error->get_error_data()['code'] ?? 0));
    if ($code !== 400) {
        return null;
    }

    // 2) El error debe referenciar al cliente.
    $body = $error->get_error_data()['response'] ?? [];
    $mentions_client = (is_array($body) && isset($body['client']))
        || stripos($error->get_error_message(), 'client') !== false
        || stripos($error->get_error_message(), 'cliente') !== false;
    if (!$mentions_client) {
        return null;
    }

    // 3) El client.id usado debe ser el CF cacheado.
    $old_id = (string) ($data['client']['id'] ?? '');
    if ($old_id === '' || !\Alegra\Connector\Consumidor_Final::is_consumidor_final($old_id)) {
        return null;
    }

    // 4) Reintento ÚNICO por pedido (guard entre requests; el design lo llama
    //    "$retried === false", la firma no lo lleva).
    $guard = 'alegra_cf_self_heal_' . (int) $order->get_id();
    if (get_transient($guard)) {
        return null;
    }
    set_transient($guard, 1, 60);

    // 5) Invalidar + re-resolver con el barrido PAGINADO (FIX-18 / Oracle D9).
    //    NO usar resolve()/get_or_create_id() directo: resolve() pide `limit=5`
    //    y, si el CF está detrás de >5 falsos positivos CONTAINS, lo pierde otra
    //    vez (el mismo bug que REQ-CF-05 arregla). probe() barre por páginas
    //    (scan_candidates, hasta 300) y sólo con un barrido COMPLETO sin match
    //    se cae a crear (get_or_create_id respeta la compuerta: en contexto
    //    automático sólo crea si push_customers_enabled).
    \Alegra\Connector\Consumidor_Final::invalidate_cache();
    $probe = \Alegra\Connector\Consumidor_Final::probe();
    if ($probe['state'] === 'available' && !empty($probe['id'])) {
        $new_id = (string) $probe['id'];
    } elseif ($probe['state'] === 'not_found') {
        $new_id = \Alegra\Connector\Consumidor_Final::get_or_create_id();
    } else {
        // unverified (api_error / truncated): NO crear a ciegas.
        $new_id = false;
    }
    if ($new_id === false || $new_id === '') {
        // T5.6: soltar el id muerto del pedido para no re-loopear + nota accionable.
        $order->delete_meta_data('_billing_alegra_contact_id');
        $order->add_order_note(__('[Alegra] El Consumidor Final cambió en Alegra y no se pudo re-resolver. Revisá el contacto.', 'alegra-connector'));
        $order->save();
        return null;
    }

    // 6) Idempotencia ANTES de reintentar (no duplicar la factura).
    $existing = $this->find_existing_invoice($order, (string) $new_id);
    if ($existing !== null && !empty($existing['id'])) {
        $this->persist_invoice_result($order, (string) $existing['id'], $existing);
        $order->add_order_note(__('[Alegra] Factura recuperada tras re-resolver el Consumidor Final (auto-sanado).', 'alegra-connector'));
        return ['id' => (string) $existing['id'], 'already_exists' => true, 'self_healed' => true];
    }

    // 7) Reintento ÚNICO con el nuevo client.
    $data['client'] = ['id' => $new_id] + (is_array($data['client'] ?? null) ? $data['client'] : []);
    $retry = $this->api->create_invoice($data);
    if (is_wp_error($retry)) {
        $order->add_order_note(sprintf(
            __('[Alegra] No se pudo facturar tras re-resolver el Consumidor Final: %s', 'alegra-connector'),
            $retry->get_error_message()
        ));
        return null;
    }
    if (isset($retry['id'])) {
        $this->persist_invoice_result($order, (string) $retry['id'], $retry);
        $order->add_order_note(__('[Alegra] Consumidor Final re-resuelto y factura creada (auto-sanado).', 'alegra-connector'));
    }
    return $retry;
}
```

**2) Wiring** — en `create_invoice()`, rama de error (`:121-129`).

**ANTES** (`:121-129`):
```php
            if (is_wp_error($result)) {
                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                return $result;
            }
```

**DESPUÉS:**
```php
            if (is_wp_error($result)) {
                // D1 / REQ-CF-06: auto-sanado de la caché podrida (400 por client id
                // muerto). Sólo dispara con 400 + mención de cliente + id CF + una vez.
                $healed = $this->try_self_heal_dead_client($order, $data, $result);
                if ($healed !== null) {
                    return $healed;
                }

                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                return $result;
            }
```

**Orden de operaciones (no invertir):** invalidar caché → re-resolver → **pre-buscar** la factura →
reintentar. Invertir el paso 6 haría el duplicado.

**Resultado esperado**
- 400 por client muerto con CF re-resoluble ⇒ la caché se invalida, el CF se re-resuelve, la factura se
  reintenta **una** vez y se crea; el pedido queda con `_alegra_invoice_id` y nota "auto-sanado".
- **FIX-18 / Oracle D9:** si el CF real está detrás de >5 falsos positivos CONTAINS, la re-resolución
  usa el **barrido paginado** (`probe()`/`scan_candidates`) y lo encuentra; **no** se crea un CF
  duplicado (el viejo `resolve()` con `limit=5` lo habría perdido).
- Si el primer POST **sí** entró pero la respuesta se perdió ⇒ `find_existing_invoice()` la encuentra y
  **no** se crea una segunda.
- Un 400 que **no** menciona al cliente, o un 401/422/red/5xx ⇒ se devuelve el `WP_Error` **como en
  HEAD**, sin reintento.
- Un 400 por un client que **no** es el CF ⇒ sin cambios.
- No hay loop entre requests (transient guard).

**Dependencias**: **T0.6 (G6)** para el detector exacto (campo `response.client` vs regex), **T5.2a**
(`probe()`/`scan_candidates` paginado, FIX-18), **H5 (T1.5)**
(mock que devuelve 400 por client inexistente), T5.2b (opción de probe), T1.3.

**Trazabilidad**: REQ-CF-06, REQ-CF-08, NFR-01, NFR-07, R6/R7.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.55 a dead-CF 400 self-heals once without duplicating (REQ-CF-06/NFR-07)', function (): void {
    alegra_test_reset();

    // CF cacheado apuntando a un id MUERTO (no existe en contacts).
    update_option('alegra_connector_consumidor_final_contact_id', 'cf-dead', false);
    // FIX-18 / Oracle D9: el CF vivo está DETRÁS de >5 falsos positivos CONTAINS.
    // El resolve() viejo (limit=5) no lo vería; el barrido paginado de probe()
    // (scan_candidates) sí. Así el test ejerce la re-resolución paginada.
    alegra_mock_set_contact_identification_mode('contains');
    for ($i = 1; $i <= 6; $i++) {
        alegra_mock_seed_contact('decoy-' . $i, [
            'identificationObject' => ['type' => 'CC', 'number' => '222222222222' . $i],
        ]);
    }
    // El CF vivo con la identificación canónica (re-resolución lo encuentra).
    alegra_mock_seed_contact('cf-live', [
        'name' => 'Consumidor Final',
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);
    // El primer POST /invoices falla una vez por client inexistente (H5).
    alegra_mock_fail('POST', '/invoices', 400, ['message' => 'El cliente no existe'], 1);

    $order = alegra_make_order(7001, [
        'billing' => ['email' => 'x@y.test'],
        'meta'    => ['_billing_alegra_contact_id' => 'cf-dead'],
        'items'   => [['product_id' => 1000, 'qty' => 1, 'total' => 10.0]],
    ]);
    $orders = make_orders();
    $result = $orders->create_invoice($order);

    TestRunner::assertTrue(!is_wp_error($result), 'the invoice must be created after self-heal');
    TestRunner::assertSame(2, alegra_mock_count('POST', '/invoices'), 'exactly one failed attempt + one retry');
    TestRunner::assertSame(0, alegra_mock_count('POST', '/contacts'), 'the paginated re-resolve must not create a duplicate CF');
    TestRunner::assertTrue((string) $order->get_meta('_alegra_invoice_id', true) !== '', 'the invoice id must be persisted');
    TestRunner::assertStringContains('auto-sanado', implode("\n", $order->get_notes()), 'an auto-heal note must be added');
});

TestRunner::test('T29.56 the idempotency pre-search prevents a duplicate invoice (REQ-CF-06)', function (): void {
    alegra_test_reset();
    // Idempotencia: la factura ya existe → no reintenta.
    update_option('alegra_connector_consumidor_final_contact_id', 'cf-dead', false);
    alegra_mock_seed_contact('cf-live', [
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);
    alegra_mock_seed_invoice('inv-existing', [
        'client' => ['id' => 'cf-live'],
        'observations' => 'Pedido WooCommerce #7002',
        'status' => 'open',
    ]);
    alegra_mock_fail('POST', '/invoices', 400, ['message' => 'El cliente no existe'], 1);
    $order2 = alegra_make_order(7002, [
        'billing' => ['email' => 'x@y.test'],
        'meta'    => ['_billing_alegra_contact_id' => 'cf-dead'],
        'items'   => [['product_id' => 1000, 'qty' => 1, 'total' => 10.0]],
    ]);
    $orders2 = make_orders();
    $r2 = $orders2->create_invoice($order2);
    TestRunner::assertTrue(!is_wp_error($r2), 'the existing invoice must be recovered');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'the idempotency pre-search must prevent a second POST');
    TestRunner::assertSame('inv-existing', (string) $order2->get_meta('_alegra_invoice_id', true), 'the recovered id must be persisted');
});

TestRunner::test('T29.57 non-client 400s and other errors are returned unchanged (REQ-CF-08)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_consumidor_final_contact_id', 'cf-dead', false);
    alegra_mock_fail('POST', '/invoices', 400, ['message' => 'El campo items es obligatorio'], 1);
    $order = alegra_make_order(7003, [
        'billing' => ['email' => 'x@y.test'],
        'meta'    => ['_billing_alegra_contact_id' => 'cf-dead'],
        'items'   => [['product_id' => 1000, 'qty' => 1, 'total' => 10.0]],
    ]);
    $orders = make_orders();
    $r = $orders->create_invoice($order);
    TestRunner::assertTrue(is_wp_error($r), 'a non-client 400 must be returned as-is');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'no retry for a non-client error');
});
```
> El worker debe verificar la firma real de `alegra_make_order` (`test-framework.php:289`) y cómo el
> stub expone `get_notes()` (`wp-stubs.php:1408`)/`get_meta` antes de escribir el test. `alegra_mock_seed_invoice` está en
> `alegra-mock.php:75`.

**Prove-it-catches**: quitar el bloque `find_existing_invoice(...)` previo al reintento → el test de
idempotencia hace **2** POST (duplicado) → rojo. Quitar el guard `$code !== 400` → un 500 también
reintentaría (T29.57 rojo). Quitar `is_consumidor_final($old_id)` → un client normal muerto se
auto-sanaría (falso positivo).

**Riesgo**: **DR4/R6** — duplicar la factura. Guard: `find_existing_invoice` **antes** del reintento +
reintento único. **DR5/R7** — loop entre requests. Guard: transient guard + T5.6 borra el meta. **DR16**
— que el body real del 400 no traiga `client` ni diga "cliente": guard: el detector acepta `response.client`
**o** el regex `/client|cliente/i` (G6 elige cuál usar). **NFR-01** — no romper la factura normal: sólo
dispara con 400+cliente+CF. **FIX-18 / Oracle D9** — la re-resolución usa `probe()` (barrido paginado)
para no repetir el bug de `limit=5`; si el barrido queda `truncated`, **no** crea a ciegas.

**Estimación**: M (3 h).

---

### T5.6 — Meta muerta + nota accionable

**Objetivo**: si el CF no se puede re-resolver, soltar el `_billing_alegra_contact_id` muerto del pedido
y dejar una nota accionable — nunca un loop ni un fallo silencioso.

**Descripción técnica**: si el self-heal (T5.5) no logra re-resolver el CF (porque no existe y la
compuerta de `contact` impide crearlo), hay que evitar que el próximo intento de factura vuelva a usar
el mismo id muerto y loopear. Cubre **REQ-CF-06** (borde "reintento fallido deja mensaje accionable").

**Desarrollo técnico**

Ya incluido en `try_self_heal_dead_client()` (T5.5), pasos 5 y 7. **No** crear un método aparte. El
comportamiento exacto:

- **No re-resoluble** (`$new_id === false || ''`): `$order->delete_meta_data('_billing_alegra_contact_id');`
  + nota `[Alegra] El Consumidor Final cambió en Alegra y no se pudo re-resolver. Revisá el contacto.`
  + `$order->save();` + `return null` (el error original se loguea y se devuelve en `create_invoice`).
- **Re-resuelto pero el reintento falla** (`is_wp_error($retry)`): nota
  `[Alegra] No se pudo facturar tras re-resolver el Consumidor Final: %s` + `return null`. **No** se
  borra el meta en este caso (el id nuevo es válido; el fallo es otro).

**Por qué no borrar el meta en el segundo caso:** el nuevo id es correcto; borrarlo forzaría una
re-resolución innecesaria en el próximo intento. El transient guard (`:T5.5` paso 4, TTL 60) ya evita el
loop inmediato.

**Resultado esperado**
- CF no re-resoluble ⇒ el pedido **no** conserva `_billing_alegra_contact_id` y tiene una nota
  accionable; el log tiene la causa.
- Re-resuelto pero reintento fallido ⇒ nota accionable; el meta queda con el id nuevo.
- En ningún caso hay más de un reintento ni un loop entre requests.

**Dependencias**: T5.5.

**Trazabilidad**: REQ-CF-06, REQ-CF-07, NFR-07.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.58 an unresolvable CF drops the dead meta and leaves an actionable note (REQ-CF-06)', function (): void {
    alegra_test_reset();
    // Sin CF en Alegra y sin permiso de crear (push_customers off): no re-resuelve.
    update_option('alegra_connector_push_customers_enabled', false, false);
    update_option('alegra_connector_consumidor_final_contact_id', 'cf-dead', false);
    alegra_mock_fail('POST', '/invoices', 400, ['message' => 'El cliente no existe'], 1);

    $order = alegra_make_order(7004, [
        'billing' => ['email' => 'x@y.test'],
        'meta'    => ['_billing_alegra_contact_id' => 'cf-dead'],
        'items'   => [['product_id' => 1000, 'qty' => 1, 'total' => 10.0]],
    ]);
    $orders = make_orders();
    $r = $orders->create_invoice($order);

    TestRunner::assertTrue(is_wp_error($r), 'the invoice still fails');
    TestRunner::assertSame('', (string) $order->get_meta('_billing_alegra_contact_id', true), 'the dead meta must be dropped');
    TestRunner::assertStringContains('no se pudo re-resolver', implode("\n", $order->get_notes()), 'an actionable note must be added');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'no retry when the CF cannot be re-resolved');
});
```

**Prove-it-catches**: quitar el `delete_meta_data('_billing_alegra_contact_id')` → el assert del meta
vacío falla. Quitar la nota → el assert de la nota falla.

**Riesgo**: borrar un meta que otro flujo necesitaba (es exactamente el meta muerto; el flujo de factura
lo re-resuelve). Que la nota se duplique entre requests (guard: el transient guard de T5.5 hace que el
segundo intento ni entre al self-heal).

**Estimación**: S (0.5 h).

---

### T5.7 — Tests del CF (cierre de fase)

**Objetivo**: consolidar la cobertura REQ-CF-01..07 y los `prove-it-catches` de la fase.

**Descripción técnica**: agrupa `T29.51`–`T29.59`, más un test de **no regresión** de la factura normal.
Cubre REQ-CF-01..07.

**Desarrollo técnico**

Archivo: `scripts/exec-test.php`, sección `// === sync-reliability (2.6.0) ===`.

Mapa final de IDs (único, sin colisiones):
| Test | Qué fija | Tarea |
|---|---|---|
| `T29.51` | `scan_candidates` pagina CONTAINS y encuentra el CF real; tope ⇒ truncated. | T5.1 |
| `T29.52` | `probe()` read-only: available/not_found/unverified; `resolve()` intacto. | T5.2a |
| `T29.520` | `probe_state()` lee la opción sin red; default unverified. | T5.2b |
| `T29.53` | Conectar resuelve read-only, persiste el probe, no POSTea. | T5.3 |
| `T29.54` | AJAX verify: nonce/cap; create=false read-only; create=true POST una vez. | T5.4 |
| `T29.55` | Self-heal: re-resuelve y reintenta una vez; nota auto-sanado. | T5.5 |
| `T29.56` | Idempotencia: `find_existing_invoice` evita el duplicado. | T5.5 |
| `T29.57` | 400 no-cliente / 401 / 5xx se devuelven sin cambios. | T5.5 |
| `T29.58` | CF no re-resoluble: borra el meta + nota accionable. | T5.6 |
| `T29.59` | **No regresión:** factura normal (sin 400) no dispara self-heal. | T5.7 |

Esqueleto del test de no regresión:
```php
TestRunner::test('T29.59 a normal invoice is unaffected by the self-heal (NFR-01)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_contact('cf-1', [
        'identificationObject' => ['type' => 'CC', 'number' => \Alegra\Connector\Consumidor_Final::IDENTIFICATION],
    ]);
    update_option('alegra_connector_consumidor_final_contact_id', 'cf-1', false);

    $order = alegra_make_order(7005, [
        'billing' => ['email' => 'x@y.test'],
        'meta'    => ['_billing_alegra_contact_id' => 'cf-1'],
        'items'   => [['product_id' => 1000, 'qty' => 1, 'total' => 10.0]],
    ]);
    $orders = make_orders();
    $r = $orders->create_invoice($order);

    TestRunner::assertTrue(!is_wp_error($r), 'the normal invoice must be created');
    TestRunner::assertSame(1, alegra_mock_count('POST', '/invoices'), 'exactly one POST, no self-heal');
    TestRunner::assertStringNotContains('auto-sanado', implode("\n", $order->get_notes()), 'no self-heal note on a normal invoice');
});
```

**Resultado esperado**
- `T29.51`–`T29.59` verdes; `bash scripts/exec-test.sh` sin `failed` nuevos.
- Los tests centrales (`T29.51`, `T29.55`, `T29.56`) con `prove-it-catches` documentado.

**Dependencias**: T5.1–T5.6, H3, H3b, H5.

**Trazabilidad**: REQ-CF-01..07, NFR-01, NFR-07.

**Verificación**: `bash scripts/exec-test.sh` → `EXEC-TEST OK`. Manual: borrar el CF en Alegra (cuenta de
prueba) → facturar → nota "auto-sanado" o mensaje accionable; el dashboard deja de decir "No encontrado"
con el contacto existente.

**Prove-it-catches**: documentado en cada tarea; el de fase es revertir el `find_existing_invoice`
previo al reintento (T5.5) → `T29.56` rojo.

**Riesgo**: tests que asuman helpers inexistentes (el worker verifica `test-framework.php` y
`alegra-mock.php` antes). Tests que dependan de `limit=5` de `resolve()` (H3b cambia la semántica del
mock; correr la suite completa).

**Estimación**: M (3 h).

---

## DoD Fase 5

- REQ-CF-01..07 verdes; REQ-CF-08 (sólo el 400+CF cambia el camino de factura).
- `probe()`/`resolve_readonly()` **nunca** POSTean (tests + `T29.64`).
- El barrido pagina y sólo `not_found` con barrido **completo** (H3b aplicado).
- Conectar resuelve read-only bajo `run_explicit()` y persiste el probe.
- El AJAX "Verificar ahora" exige nonce + `manage_options`; `create=true` es explícito.
- El self-heal dispara **sólo** con 400+cliente+id CF, no duplica y no loopea.
- `T29.51`–`T29.59` verdes con `prove-it-catches` aplicados.
- **H3b** implementado (junto a H3/T1.3) y la suite completa sigue verde.

## Índice de tests nuevos (Fase 5)

| Test | Tipo | Archivo |
|---|---|---|
| `T29.51 scan_candidates paginates CONTAINS results and finds the real CF` | runtime | `scripts/exec-test.php` |
| `T29.52 probe is read-only and reports available/not_found/unverified` | runtime | `scripts/exec-test.php` |
| `T29.520 probe_state reads the persisted option without network` | runtime | `scripts/exec-test.php` |
| `T29.53 connecting resolves the CF read-only and persists the probe` | runtime | `scripts/exec-test.php` |
| `T29.54 the verify AJAX requires nonce/cap and never POSTs unless create=true` | runtime | `scripts/exec-test.php` |
| `T29.55 a dead-CF 400 self-heals once without duplicating` | runtime | `scripts/exec-test.php` |
| `T29.56 the idempotency pre-search prevents a duplicate invoice` | runtime | `scripts/exec-test.php` |
| `T29.57 non-client 400s and other errors are returned unchanged` | runtime | `scripts/exec-test.php` |
| `T29.58 an unresolvable CF drops the dead meta and leaves an actionable note` | runtime | `scripts/exec-test.php` |
| `T29.59 a normal invoice is unaffected by the self-heal` | runtime | `scripts/exec-test.php` |

## Trazabilidad tarea → requerimiento

| Requerimiento | Tareas |
|---|---|
| REQ-CF-01 | T5.3 |
| REQ-CF-02 | T5.2a, T5.2b |
| REQ-CF-03 | T5.4 |
| REQ-CF-04 (Rama A) | T5.2a, T5.3; (Rama B explícita) T5.4 |
| REQ-CF-05 | T5.1 (H3 + H3b) |
| REQ-CF-06 | T5.5, T5.6 |
| REQ-CF-07 | T5.2a, T5.2b, T6.3 |
| REQ-CF-08 | T5.5, T5.7 |
| NFR-01 | T5.7 |
| NFR-04 | T5.2b, T5.4 |
| NFR-06 | T5.3, T5.4 |
| NFR-07 | T5.5, T5.6 |
