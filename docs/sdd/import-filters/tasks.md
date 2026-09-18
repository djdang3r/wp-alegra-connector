# Tareas — Filtros de importación de productos (`import-filters`)

| Campo | Valor |
|---|---|
| Cambio | `import-filters` |
| Documentos | `proposal.md` · `spec.md` · `design.md` |
| Convención | `T<fase>.<n>` · `[ ]` pendiente · `[x]` hecho |
| Leyenda | `BLOQUEADO(verificación)` = no empezar hasta Fase 0 |

> Formato por tarea: **Objetivo · Descripción técnica · Desarrollo técnico ·
> Resultado esperado · DoD**.

---

## Fase 0 — Verificaciones en vivo

**Objetivo de la fase:** resolver la rama de `type` y confirmar la semántica de
`metadata.total` con filtros.
**DoD de la fase:** resultados en `phase0-results.md` y rama elegida.

- [ ] **T0.1 — `metadata.total` respeta `idItemCategory`**
  - **Objetivo:** confirmar el total filtrado (REQ-FILTER-7).
  - **Descripción técnica:** comparar `GET /items?metadata=true&limit=1` con y sin
    `idItemCategory`.
  - **Desarrollo técnico:** categoría con N conocido; comparar `metadata.total`.
  - **Resultado esperado:** total filtrado = N.
  - **DoD:** evidencia escrita. Si no respeta → T1.2 calcula total paginando.

- [ ] **T0.2 — `type=simple|kit|variantParent`**
  - **Objetivo:** decidir la rama de REQ-FILTER-6.
  - **Descripción técnica:** probar los tres valores; registrar HTTP y filtrado.
  - **Desarrollo técnico:** anotar si `variantParent` filtra o devuelve todo.
  - **Resultado esperado:** rama elegida. Default seguro: descarte en cliente.
  - **DoD:** resultado en `phase0-results.md`.

- [ ] **T0.3 — Combinación de filtros**
  - **Objetivo:** confirmar intersección (`idItemCategory`+`status`+`query`).
  - **Descripción técnica:** una llamada combinada.
  - **Resultado esperado / DoD:** intersección correcta con JSON de evidencia.

- [ ] **T0.4 — Paginación `/item-categories`**
  - **Objetivo:** confirmar `start`/`limit` (REQ-FILTER-9).
  - **Descripción técnica:** `start=0` y `start=30`.
  - **Resultado esperado / DoD:** páginas distintas; `limit>30` → error 903.

---

## Fase 1 — Backend: plomería de filtros

**Objetivo de la fase:** los filtros viajan del POST al `GET /items`; sin filtros,
comportamiento idéntico.
**DoD de la fase:** T4.1 y T4.2 verdes.

- [ ] **T1.1 — `sanitize_item_filters()`**
  - **Objetivo:** validar entrada (REQ-FILTER-11).
  - **Descripción técnica:** método privado en `Admin_Dashboard`; devuelve la forma
    de `design.md` 3.1.
  - **Desarrollo técnico:** `sanitize_text_field` (`idItemCategory`,`query`);
    `in_array` (`status`,`type`); `filter_var(...FILTER_VALIDATE_BOOLEAN)`
    (`inventariable`); defaults `''`/`'default'`/`false`.
  - **Resultado esperado:** entrada inválida → "todos"; nunca rompe.
  - **DoD:** test de sanitización (incluye JSON malformado).

- [ ] **T1.2 — `ajax_sync_start`: filtros + total**
  - **Objetivo:** REQ-FILTER-7 y persistir filtros.
  - **Descripción técnica:** en `:1578` leer `$_POST['filters']`, sanitizar, aplicar
    al `metadata` con `include_default_status=false`, guardar `$state['filters']`.
  - **Desarrollo técnico:** `json_decode(wp_unslash($_POST['filters'] ?? '{}'), true)`;
    `['metadata'=>'true','limit'=>1] + build_item_filter_params($f, false)`.
  - **Resultado esperado:** sin filtros, `$params` idénticos a hoy.
  - **DoD:** T4.3.

- [ ] **T1.3 — `ajax_sync_page`: aplicar filtros**
  - **Objetivo:** REQ-FILTER-2..5.
  - **Descripción técnica:** en `:1640-1647` sustituir el bloque `status` por
    `build_item_filter_params($filters, true)`.
  - **Desarrollo técnico:** preservar `['start','limit','mode'=>'advanced']`.
  - **Resultado esperado:** sin filtros = mismo array que hoy.
  - **DoD:** T4.1 y T4.2.

- [ ] **T1.4 — Descarte en cliente `variantParent`**
  - **Objetivo:** REQ-FILTER-6.
  - **Descripción técnica:** tras `if ($item_type === 'variant') continue;` añadir
    descarte si `filters.type==='variantParent'` y `$item_type !== 'variantParent'`.
  - **Desarrollo técnico:** incrementar `$state['skipped']`; `continue`.
  - **Resultado esperado:** solo se importan variantes padre cuando se pide.
  - **DoD:** T4.4.

- [ ] **T1.5 — Guardia de no-regresión**
  - **Objetivo:** REQ-FILTER-8.
  - **Descripción técnica:** comparar `build_item_filter_params([], true)` y
    `([], false)` con el comportamiento previo.
  - **Resultado esperado:** arrays idénticos en ambos valores de
    `sync_inactive_products`.
  - **DoD:** T4.1.

---

## Fase 2 — Endpoint de categorías

- [ ] **T2.1 — `ajax_get_item_categories`**
  - **Objetivo:** REQ-FILTER-9.
  - **Descripción técnica:** acción AJAX que lista categorías con `start`/`limit`,
    paginando hasta un tope.
  - **Desarrollo técnico:** `check_ajax_referer` + capability; bucle
    `get_item_categories(['start'=>$s,'limit'=>30])` hasta <30 o 10 páginas;
    devolver `{categories, has_more}`.
  - **Resultado esperado:** el modal puebla el selector.
  - **DoD:** T4.5 + respuesta manual 200.

- [ ] **T2.2 — Registro**
  - **Objetivo:** exponer el endpoint.
  - **Descripción técnica:** `add_action('wp_ajax_alegra_get_item_categories', ...)`
    junto a `:52-53`.
  - **Resultado esperado / DoD:** 200 con nonce; 403 sin él.

---

## Fase 3 — UI + JS

- [ ] **T3.1 — Modal HTML**
  - **Objetivo:** REQ-FILTER-1.
  - **Descripción técnica:** markup en `admin-products.php` antes de `footer.php`
    (~línea 262), reutilizando `.ac-modal-overlay`.
  - **Desarrollo técnico:** `#ac-filter-category` (select), `#ac-filter-type`,
    `#ac-filter-status`, `#ac-filter-inventariable`, `#ac-filter-query`; botón
    `#ac-filter-apply` y enlace `#ac-filter-all`; texto de ayuda por filtro.
  - **Resultado esperado:** modal claro, con defaults "Todos/Por defecto".
  - **DoD:** render con `esc_html`/`esc_attr`; sin ambigüedad.

- [ ] **T3.2 — Botón con `data-requires-filter`**
  - **Objetivo:** abrir el modal sin romper el dashboard.
  - **Descripción técnica:** en `admin-products.php:91` conservar
    `alegra-quick-sync`, añadir `data-requires-filter="1"`, cambiar etiqueta a
    "Traer desde Alegra".
  - **Resultado esperado:** Productos abre modal; dashboard igual.
  - **DoD:** verificación manual de ambos.

- [ ] **T3.3 — `initImportFilters()` + integración**
  - **Objetivo:** orquestar modal → importación.
  - **Descripción técnica:** handler del botón, carga de categorías,
    `pendingFilters`, `filter-confirmed`, re-trigger del click existente.
  - **Desarrollo técnico:** `alegra_sync_start` incluye `filters`; reset de
    `pendingFilters`/`filter-confirmed` al iniciar.
  - **Resultado esperado:** importación filtrada con la barra de progreso existente.
  - **DoD:** prueba manual "todo" y cada filtro.

- [ ] **T3.4 — Estados de UI y aviso de tipo**
  - **Objetivo:** robustez y no ambigüedad.
  - **Descripción técnica:** deshabilitar sin conexión; aviso si 0 categorías; si
    `type=variantParent`, mostrar "se recorre todo el catálogo; el total es
    aproximado".
  - **Resultado esperado:** sin callejones sin salida.
  - **DoD:** prueba con Alegra desconectado, 0 categorías y tipo "Con variantes".

- [ ] **T3.5 — `$connected` en el template**
  - **Objetivo:** habilitar/deshabilitar según conexión.
  - **Descripción técnica:** `render_products_page` (`:1107`) define `$connected`.
  - **Resultado esperado / DoD:** botón deshabilitado si no hay conexión.

- [ ] **T3.6 — Strings localizados**
  - **Objetivo:** textos sin hardcode.
  - **Descripción técnica:** añadir strings a `get_script_strings()` (`:529`).
  - **Resultado esperado / DoD:** JS usa `S.*`; sin textos crudos.

---

## Fase 4 — Tests

- [ ] **T4.0 — Extender el mock**
  - **Objetivo:** hacer testeable el filtrado.
  - **Descripción técnica:** en `scripts/lib/alegra-mock.php:835` añadir a
    `alegra_mock_filter_items`: `idItemCategory` (contra `itemCategory.id`),
    `status`, `inventariable` (presencia de `inventory`), `query` (nombre o
    referencia, insensible a mayúsculas), `type`. En `GET /items` (`:654`) devolver
    `{metadata:{total:N}, data:[...]}` cuando `metadata=true`.
  - **Desarrollo técnico:** reutilizar el patrón de filtros existente; total =
    `count()` tras filtrar.
  - **Resultado esperado:** el mock refleja el contrato real.
  - **DoD:** T4.2/T4.3/T4.5 ejecutables.

- [ ] **T4.1 — No regresión sin filtros**
  - **Objetivo:** REQ-FILTER-8.
  - **Desarrollo:** invocar el helper con `[]` en `include_default_status`
    true/false; comparar arrays exactos.
  - **DoD:** verde.

- [ ] **T4.2 — Mapeo de cada filtro**
  - **Objetivo:** REQ-FILTER-2..6.
  - **Desarrollo:** capturar `$_POST['filters']` + `ajax_sync_start`/`ajax_sync_page`
    con `alegra_capture_json()`; verificar la query de
    `alegra_mock_last_request('GET','/items')`.
  - **DoD:** un test por filtro.

- [ ] **T4.3 — Total filtrado**
  - **Objetivo:** REQ-FILTER-7.
  - **Desarrollo:** sembrar N items de una categoría; `ajax_sync_start`; verificar
    `total_items`.
  - **DoD:** total = N.

- [ ] **T4.4 — Descarte `variantParent`**
  - **Objetivo:** REQ-FILTER-6.
  - **Desarrollo:** `type=variantParent`; un item `simple` no se importa, uno
    `variantParent` sí; `skipped` incrementa.
  - **DoD:** sin productos falsos.

- [ ] **T4.5 — Paginación de categorías**
  - **Objetivo:** REQ-FILTER-9.
  - **Desarrollo:** sembrar >30 categorías; verificar `start` y `has_more` (el mock
    ya soporta `start`/`limit` en `/item-categories`, `:620`).
  - **DoD:** dos páginas con conjuntos distintos.

---

## Fase 5 — Limpieza (opcional)

- [ ] **T5.1 — Eliminar modal muerto del dashboard**
  - **Objetivo:** REQ-FILTER-12.
  - **Descripción técnica:** quitar `#alegra-sync-modal` (`admin-dashboard.php:313`)
    y handlers `#alegra-sync-start`/`#alegra-sync-cancel` (`admin.js:266-282`).
  - **DoD:** grep sin referencias + dashboard funcional.

- [ ] **T5.2 — Decidir `ajax_sync_now`**
  - **Objetivo:** retirar o conservar el handler huérfano.
  - **DoD:** decisión documentada.

---

## Resumen de dependencias

```
Fase 0 (verificaciones) ──► T1.4 (rama de tipo)
Fase 1 (T1.1) ──► T1.2, T1.3 ──► T1.5
Fase 2 (T2.1) ──► T2.2 ──► T3.3
Fase 3 (T3.1, T3.2) ──► T3.3 ──► T3.4
Fase 1+2+3 ──► Fase 4 (tests)
Fase 5 (opcional, independiente)
```

**Camino crítico:** T0.2 → T1.1 → T1.2/T1.3 → T3.3 → T4.x.
