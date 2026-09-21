# Propuesta — Puertas de configuración y enforcement de escrituras (`config-gates`)

| Campo | Valor |
|---|---|
| Cambio | `config-gates` (enforcement de kill switch + control por entidad + honestidad de configuración) |
| Tipo | Corrección de bugs en producción + feature de control + limpieza |
| Versión analizada | 2.3.11 (`alegra-connector.php:6`) |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Estado | Propuesta — **varias verificaciones en vivo (Fase 0)** |
| Regla | Todo claim de código cita `archivo:línea`; lo no verificado se marca **SIN VERIFICAR / BLOQUEADO** |

> **Correcciones de ruta al audit de entrada.** El audit original cita rutas que no
> existen. Las rutas reales son:
> - `includes/API/KillSwitch.php` → **`includes/Kill_Switch.php`** (clase `Kill_Switch`, opción `alegra_kill_switch`).
> - `includes/Frontend/Public_.php` → **`public/Public/Public_.php`**.
> - `includes/Frontend/State_Sync.php` → **`includes/State_Sync.php`**.
> - `includes/API/Products.php` / `Customers.php` / `Categories.php` → **no existen**.
>   Las clases de entidad viven en `includes/Sync/Products.php`, `includes/Sync/Customers.php`
>   y `includes/Sync/Categories.php`; el único cliente HTTP es `includes/API/Client.php`.
>
> El resto de los hallazgos fue **verificado en el código** (ver `design.md` §0 y `spec.md`).

---

## 1. El problema, en lenguaje del dueño de tienda

> **"El plugin ignora lo que configurás."**

Hoy la configuración del plugin es **decorativa en los caminos que más importan**. Ejemplos
concretos que el comerciante ya puede reproducir:

1. **El barrido de pagos no se apaga.** La documentación dice que
   `alegra_connector_payment_reconcile_enabled` desactiva el barrido
   (`alegra-connector.php:592`), pero esa opción **no existe en la UI, no se registra y
   nadie la escribe**. Se lee con default `true` (`includes/Sync/Orders.php:495`) y el
   camino en tiempo real **ni siquiera la mira** (`Public_.php:65-67` → `Orders.php:456`).

2. **Los checkboxes mienten.** Las cuatro casillas "Entidades a sincronizar"
   (`templates/admin-settings.php:78-81`) se dibujan **tildadas** con
   `checked(get_option(..., true))`, pero el cron las lee con default `false`
   (`includes/Sync/Controller.php:171,208,236,262`) y la activación las siembra en `false`
   (`alegra-connector.php:409-412`). Una instalación que no las sembró muestra entidades
   activas que el cron saltea.

3. **El kill switch no frena las escrituras.** Cuando desconectás el plugin
   (`ajax_disconnect`, `admin/Admin/Admin_Dashboard.php:2904` activa `Kill_Switch`), el
   kill switch sólo se consulta en cron/import/poll. **Toda escritura saliente** —facturas,
   notas de crédito, pagos, productos, contactos, categorías, webhooks— pasa por
   `includes/API/Client.php:101` (`request()`), que **sólo mira `dry_run`** (`:106`). El
   comerciante cree que desconectó, pero el plugin sigue escribiendo en Alegra.

4. **Los pagos se siguen registrando aunque apagues la sincronización.** Los tres hooks
   de pago en tiempo real (`Public_.php:65-67`) están registrados **siempre**, sin kill
   switch y sin flag de configuración. Sólo el barrido horario respeta la opción.

5. **Cualquier admin puede escribir con el plugin "desconectado".** Los 8 handlers AJAX
   manuales y el `POST /sync` REST no chequean el kill switch: alcanza con
   `manage_woocommerce` para facturar mientras el plugin está muerto.

6. **No hay control por cliente.** El envío automático de clientes cuelga del mismo toggle
   que los productos (`Public_.php:72`): o subís los dos, o ninguno. No hay casilla
   independiente de clientes.

**El pedido del comerciante es explícito:** *"nada debe escribir en Alegra sin una acción
explícita mía para esa entidad, o una configuración que lo habilite explícitamente."*

---

## 2. Alcance

### 2.1 Dentro del alcance

- **Un único punto de enforcement** en el choke point de escritura (`Client::request()`),
  que garantice: kill switch activo ⇒ **cero** escrituras, desde **cualquier** camino
  (hooks, AJAX, REST, cron, render de dashboard).
- **Decisión de escritura por entidad** ("¿podemos escribir en Alegra para esta entidad?"),
  con taxonomía explícita y opción que gobierna cada entidad.
- **Honestidad de configuración**: opciones registradas, expuestas, sembradas y respetadas;
  UI == runtime == defaults.
- **Migración** para instalaciones existentes (que las casillas y las opciones nuevas
  coincidan con el comportamiento real, sin regresión).
- **Robustez** de los flujos por lotes (cancelación), del render del dashboard
  (`Consumidor_Final`) y de `ajax_disconnect`.
- **Higiene**: eliminar superficie muerta y completar `uninstall.php`.

### 2.2 Fuera del alcance

- **DIAN / facturación electrónica colombiana**: sin `stamp`, sin `paymentForm`, sin
  cambios de esquema fiscal. La entidad `contact` mantiene su payload actual.
- **Rediseño de la UI de settings** más allá de agregar los controles de las opciones
  nuevas y corregir los defaults mentirosos.
- **Cambiar el default de `payment_reconcile_enabled`** (ver §6 y `design.md` §6).
- **Multisite** más allá de lo que ya soporta `uninstall.php`.
- **Nuevas entidades de Alegra** (price lists, estimates, taxes, inventory adjustments):
  se clasifican como `other` y no se agrega UI para ellas.

### 2.3 Restricción transversal

El plugin es **distribuido**: todo cambio debe funcionar para **cualquier** configuración
existente. **Sin regresión de comportamiento** y el harness
(`scripts/exec-test.sh`, 768 aserciones en runtime; `scripts/smoke-test.sh`) debe quedar
**verde**. Cuando un cambio altere el comportamiento de instalaciones existentes, la
propuesta lo declara como riesgo y exige migración + nota de release (`design.md` §7).

---

## 3. Los 15 hallazgos, agrupados por tema

Leyenda: **[BUG]** = comportamiento incorrecto / promesa incumplida · **[FEATURE]** = control
que falta · **[HYG]** = limpieza.

### Tema A — Enforcement (1, 2, 4, 5, 9)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| 1 | El kill switch no se evalúa en el choke point de escritura. `request()` sólo mira `dry_run`. | `includes/API/Client.php:101-111` (`:106` sólo `dry_run`) | **[BUG]** crítico |
| 2 | Los hooks de pago en tiempo real ignoran kill switch **y** `payment_reconcile_enabled`. | `public/Public/Public_.php:65-67`, `:278-314`; `includes/Sync/Orders.php:456-484` | **[BUG]** crítico |
| 4 | Refunds y cambios de método de pago escriben sin condición. `State_Sync::register_hooks()` se registra siempre, sin `push_orders_enabled` y sin kill switch. | `includes/State_Sync.php:37-39,54-57`; `alegra-connector.php:310` | **[BUG]** |
| 5 | Los pushes manuales admin/REST saltean las puertas por diseño y también el kill switch. | `Admin_Dashboard.php:2273,2399,2765,2825,2170,2126,2537,2710`; `Public_.php` REST `POST /sync` | **[BUG]** de seguridad operativa |
| 9 | Los flujos AJAX por lotes ignoran la bandera de cancelación a mitad de corrida; los importadores por ítem saltean kill switch/cancel. | `Admin_Dashboard.php:1832,2825`; `Sync/Products.php:1419`; `Sync/Customers.php:328` | **[BUG]** |

### Tema B — Control / configuración (3, 6, 7, 11, 12, 15)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| 3 | `payment_reconcile_enabled` / `payment_reconcile_batch` son incontrolables: se leen con default, no se registran, no están en `$defaults`, cero escritores. | `Orders.php:495,499`; `Admin_Dashboard.php:380-564` (sin registro); `alegra-connector.php:403-424` (sin default) | **[BUG]** de promesa + **[FEATURE]** |
| 6 | Los cuatro `sync_*` de la UI contradicen el runtime. | `templates/admin-settings.php:78-81` vs `Controller.php:171,208,236,262` vs `alegra-connector.php:409-412` | **[BUG]** |
| 7 | Guardar Settings borra `field_mapping`/`tax_mapping`: registrados en dos grupos, renderizados sólo en Mapping. | `Admin_Dashboard.php:454,459` (settings) y `:563,564` (mapping) | **[BUG]** (comportamiento de `options.php` **CONFIRMADO**, ver spec REQ-CFG-3) |
| 11 | "Run now"/"Sincronizar ahora" no hace nada en modo real-time/disabled. | `Admin_Dashboard.php:3134,1622`; `Controller.php:129-135` | **[BUG]** UX |
| 12 | No hay toggle independiente de clientes: `push_products_enabled` gobierna también los hooks de cliente. | `Public_.php:72-78`; `templates/admin-settings.php:100` (el label sí lo aclara) | **[FEATURE]** |
| 15 | Las 6 `add_settings_section()` son decorativas: `do_settings_sections()` nunca se llama. | `Admin_Dashboard.php:566-571`; 0 llamadas a `do_settings_sections` | **[HYG]** |

### Tema C — Robustez (8, 10, 14)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| 8 | `Consumidor_Final::create` puede dispararse en el render del dashboard (POST /contacts no intencional). | `templates/admin-dashboard.php:18` → `Consumidor_Final.php:42` (`:66` resolve, `:168` create, `:212-214` POST) | **[BUG]** |
| 10 | `ajax_disconnect` ignora la respuesta dry-run/bloqueada y reporta borrados de un contador local. | `Admin_Dashboard.php:2897-2955` (contra `ajax_delete_webhooks:2710-2755`) | **[BUG]** |
| 14 | El endpoint de webhook es público (`permission_callback => '__return_true'`), token-gated adentro, sin kill switch. Sólo muta estado local. | `includes/Webhooks/Receiver.php:33-37`, `:65` | **[BUG]** de superficie (riesgo acotado) |

### Tema D — Higiene (13)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| 13 | Superficie muerta: 3 opciones sólo-escritura; 14 métodos de escritura de `Client` sin llamadores; 3 `sync_all()` sin llamador; `uninstall.php` no borra `payment_reconcile_*`. | `Admin_Dashboard.php:1599,1602,2943`; `Client.php` (14 métodos); `Sync/{Products,Customers,Categories}.php` (`sync_all`); `uninstall.php:48-123` | **[HYG]** |

---

## 4. Honestidad: qué es bug y qué es feature faltante

- **Bugs de promesa incumplida** (lo que la UI o la doc promete y el código no cumple):
  1, 2, 3, 6, 7, 9, 10, 11, 15. Son los más graves porque el comerciante **ya cree** que
  están resueltos.
- **Bugs de seguridad operativa** (capacidades que no deberían existir): 4, 5, 8, 14.
- **Features que faltan** (control que nunca existió): 12 (toggle de clientes) y la mitad
  de 3 (UI del barrido).
- **Deuda técnica sin impacto directo** (13): no rompe nada hoy, pero engaña a la próxima
  auditoría y deja opciones huérfanas en la base.

**El kill switch es el hallazgo #1 y la causa raíz de 2, 4, 5 y 8:** existe, se respeta en
los caminos que "importan menos" (cron/import/poll) y se ignora en los que "importan más"
(escrituras). El diseño lo convierte en una puerta real en el único lugar por donde pasan
todas las escrituras.

---

## 5. Criterios de éxito

1. **Cero escrituras con kill switch activo.** Para cualquier camino (hook, AJAX, REST,
   cron, render), con `alegra_kill_switch` presente, `alegra_mock_count()` de **todas** las
   escrituras es `0` y la respuesta es un marcador de bloqueo distinguible de un error real.
2. **Control por entidad.** Con kill switch apagado y la entidad deshabilitada, una
   escritura **automática** para esa entidad se bloquea; una **explícita** del comerciante
   se permite.
3. **UI == runtime == defaults.** Para las cuatro `sync_*` y para las nuevas opciones de
   reconciliación/clientes, lo que muestra la UI coincide con lo que lee el runtime y con
   lo que siembra la activación.
4. **Guardar Settings no destruye datos.** `field_mapping` y `tax_mapping` sobreviven a
   guardar la página de Settings.
5. **"Run now" es honesto.** O ejecuta, o dice exactamente por qué no puede.
6. **Sin regresión.** `bash scripts/exec-test.sh` y `bash scripts/smoke-test.sh` verdes,
   con las aserciones nuevas sumadas (no reemplazadas).
7. **Migración silenciosa y correcta.** Una instalación existente, tras actualizar, ve la UI
   coincidir con su comportamiento previo; nada se apaga ni se enciende por sorpresa.

---

## 6. Riesgo principal

**Convertir el kill switch en una puerta real cambia el comportamiento de instalaciones
existentes.** Hoy, con el plugin "desconectado", un admin puede seguir facturando y
registrando pagos manualmente. Después del cambio, eso se bloquea (es el objetivo). Una
instalación que dependa de esa permisividad verá sus pushes manuales rechazados.

**Mitigación** (detallada en `design.md` §7 y `tasks.md` Fase 6):

1. El bloqueo **no es un error fatal**: devuelve un marcador con `reason` y se registra en
   el log (`[WRITE GATE] Blocked …`).
2. Los handlers AJAX/REST devuelven un mensaje claro ("Bloqueado: el plugin está
   desconectado. Reactivá la conexión o limpiá el kill switch.").
3. Se mantienen los chequeos por callback como defensa en profundidad (no se eliminan).
4. Nota de release explícita + entrada en `CHANGELOG.md`.
5. El comerciante puede salir del estado con el botón existente
   `ajax_clear_kill_switch` (`Admin_Dashboard.php:69`).

El segundo riesgo es **quitar los 14 métodos muertos de `Client`** si algún consumidor
externo (no en este repo) los usa. Se marca **BLOQUEADO(Fase 0.2)** y se decide con
evidencia antes de borrar (`spec.md` REQ-HYG-1).

---

## 7. Relación con otros SDD

- `docs/sdd/payments` ya definió el barrido y la reconciliación. Esta propuesta **no
  reabre** esas decisiones: agrega la puerta que falta y expone la opción
  `payment_reconcile_enabled` que aquel spec asumía como existente.
- `docs/sdd/inventory` e `docs/sdd/import-filters` no se tocan salvo por la puerta de
  escritura (inventario escribe `POST /inventory-adjustments` → entidad `other`, sólo
  explícito).
