# Especificación — Filtros de importación de productos (`import-filters`)

| Campo | Valor |
|---|---|
| Cambio | `import-filters` |
| Documento base | `proposal.md` · `design.md` |
| Formato | Requerimientos con escenarios Given/When/Then verificables por test |
| Estados | `ACTIVO` · `BLOQUEADO` (espera Fase 0) |

> **Reglas de lectura.** Cada requerimiento tiene al menos un escenario
> verificable. Los claims de código citan `archivo:línea`; los de Alegra, URL.

---

## Convenciones

- **Ruta B** = `ajax_sync_start` (`Admin_Dashboard.php:1578`) + `ajax_sync_page`
  (`Admin_Dashboard.php:1618`).
- **Sin filtros** = `{}` o todos los valores en "todos/por defecto".
- **Helper** = `build_item_filter_params($f, $include_default_status)` (ver diseño 3.3).

---

## A. Punto de entrada

### REQ-FILTER-1 — Modal de filtros en Productos `ACTIVO`

El botón de la sección Productos (`admin-products.php:91`) abre un modal de
filtros con un botón primario **"Aplicar y traer"** y un enlace **"Traer todo sin
filtros"**.

```gherkin
Escenario: El botón abre el modal
  Dado que el usuario está en la página Productos y está conectado a Alegra
  Cuando hace clic en "Traer desde Alegra"
  Entonces se muestra el modal de filtros
  Y NO se inicia ninguna importación todavía

Escenario: Traer todo conserva el comportamiento actual
  Dado el modal abierto
  Cuando el usuario pulsa "Traer todo sin filtros"
  Entonces se ejecuta el flujo por lotes con filtros vacíos
  Y el resultado es idéntico al comportamiento previo
```

## B. Filtros

### REQ-FILTER-2 — Categoría `ACTIVO`

La categoría elegida se envía como `idItemCategory` (una sola).

```gherkin
Escenario: Solo la categoría seleccionada
  Dado el filtro categoría = "Ropa" (idItemCategory=cat-9)
  Cuando corre el flujo por lotes
  Entonces cada GET /items incluye idItemCategory=cat-9
  Y no se importan productos de otras categorías
```

### REQ-FILTER-3 — Estado `ACTIVO`

"Por defecto" respeta `sync_inactive_products`; "Activos" fuerza `status=active`;
"Inactivos" fuerza `status=inactive`.

```gherkin
Escenario: Por defecto respeta el ajuste global
  Dado status = "default" y sync_inactive_products = false
  Cuando corre el flujo
  Entonces GET /items incluye status=active

Escenario: Inactivos explícito
  Dado status = "inactive"
  Cuando corre el flujo
  Entonces GET /items incluye status=inactive
```

### REQ-FILTER-4 — Inventariable `ACTIVO`

```gherkin
Escenario: Solo con inventario
  Dado inventariable = true
  Cuando corre el flujo
  Entonces GET /items incluye inventariable=true
```

Nota: "solo servicios" no está documentado; se omite.

### REQ-FILTER-5 — Búsqueda por texto `ACTIVO`

```gherkin
Escenario: Búsqueda por nombre o referencia
  Dado query = "camisa"
  Cuando corre el flujo
  Entonces GET /items incluye query=camisa
```

### REQ-FILTER-6 — Tipo `BLOQUEADO por T0.2`

`simple`/`kit` → parámetro `type`. `variantParent` → sin `type` + descarte en
cliente.

```gherkin
Escenario: Sencillos usa el parámetro documentado
  Dado type = "simple"
  Cuando corre el flujo
  Entonces GET /items incluye type=simple

Escenario: Con variantes se descarta en cliente
  Dado type = "variantParent"
  Cuando llega un item de tipo "simple"
  Entonces el item se omite sin importarse
```

**Rama alternativa:** si T0.2 demuestra que `type=variantParent` es aceptado, se
envía como parámetro y se elimina el descarte en cliente.

### REQ-FILTER-7 — Total y progreso coherentes `ACTIVO`

```gherkin
Escenario: Total filtrado
  Dado idItemCategory=cat-9 con 12 productos y el catálogo total 500
  Cuando se inicia el flujo
  Entonces total_items = 12
  Y total_pages = 1
```

**Limitación documentada:** con `type=variantParent` el total es el del catálogo
completo (la API no filtra por ese tipo).

### REQ-FILTER-8 — No regresión sin filtros `ACTIVO`

```gherkin
Escenario: Sin filtros los parámetros son idénticos
  Dado filtros vacíos y sync_inactive_products = false
  Cuando se construye $api_params para la página
  Entonces $api_params = ['start'=>N,'limit'=>30,'mode'=>'advanced','status'=>'active']

Escenario: El total sin filtros no cambia
  Dado filtros vacíos
  Cuando se construyen los parámetros del metadata
  Entonces NO se incluye status implícito
```

### REQ-FILTER-9 — Fuente de categorías `ACTIVO`

Endpoint AJAX paginado con `start`/`limit` (doc oficial), no `page`.

```gherkin
Escenario: Listado de categorías
  Dado que el usuario abre el modal
  Cuando se solicitan las categorías
  Entonces se llama GET /item-categories con start y limit
  Y se listan {id, name} en el selector
```

## C. Aislamiento y seguridad

### REQ-FILTER-10 — Aislamiento `ACTIVO`

Cron (`Controller::run_cron_sync`), webhooks y `ajax_import_from_api` (página
Importar) no reciben filtros.

```gherkin
Escenario: El cron ignora los filtros
  Dado que el cron corre run_cron_sync()
  Entonces no se leen filtros del usuario
  Y el import usa sus parámetros de siempre
```

### REQ-FILTER-11 — Seguridad `ACTIVO`

Nonce (`check_ajax_referer('alegra_connector_nonce')`) y
`current_user_can('manage_woocommerce')` en cada acción nueva; sanitización de
todos los filtros.

```gherkin
Escenario: Entrada inválida se degrada
  Dado filters = {"status":"hack","type":"<script>","inventariable":"x"}
  Cuando se sanitizan
  Entonces status="default", type="", inventariable=false
```

### REQ-FILTER-12 — Limpieza del modal muerto `ACTIVO` (opcional)

Se elimina `#alegra-sync-modal` y sus handlers; no rompe ningún flujo.

---

## Matriz de trazabilidad

| Requerimiento | Estado | Tarea |
|---|---|---|
| REQ-FILTER-1 | ACTIVO | T3.1, T3.2, T3.3 |
| REQ-FILTER-2 | ACTIVO | T1.3, T4.2 |
| REQ-FILTER-3 | ACTIVO | T1.3, T4.2 |
| REQ-FILTER-4 | ACTIVO | T1.3, T4.2 |
| REQ-FILTER-5 | ACTIVO | T1.3, T4.2 |
| REQ-FILTER-6 | BLOQUEADO (T0.2) | T1.4, T4.4 |
| REQ-FILTER-7 | ACTIVO | T1.2, T4.3 |
| REQ-FILTER-8 | ACTIVO | T1.5, T4.1 |
| REQ-FILTER-9 | ACTIVO | T2.1, T4.5 |
| REQ-FILTER-10 | ACTIVO | T1.2, T1.3, T3.2 |
| REQ-FILTER-11 | ACTIVO | T1.1, T2.1, T2.2 |
| REQ-FILTER-12 | ACTIVO | T5.1, T5.2 |
