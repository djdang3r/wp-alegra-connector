# Propuesta — Filtros de importación de productos (`import-filters`)

| Campo | Valor |
|---|---|
| Cambio | `import-filters` |
| Tipo | Feature (UI + backend), sin migración de datos |
| Estado | Aprobado — Fase 0 bloquea la rama del filtro de tipo |
| Versión analizada | 2.3.7 (`alegra-connector.php:6`) |
| Artefactos SDD | `proposal.md`, `spec.md`, `design.md`, `tasks.md` |

> Todo claim de código cita `archivo:línea`. Todo claim de Alegra cita una URL de
> `developer.alegra.com`. Lo no verificable está marcado **SIN VERIFICAR**.

---

## 1. Intención

Permitir al comerciante elegir **qué productos** traer desde Alegra a WooCommerce,
desde la sección **Productos**, mediante un modal de filtros. Hoy la importación
siempre trae el catálogo completo (`Admin_Dashboard.php:1643-1647`) y solo existe
un filtro global activo/inactivo (`alegra_connector_sync_inactive_products`).

En una frase: **el botón "Traer desde Alegra" de Productos abre un modal; el
usuario filtra o trae todo, y sin filtros el comportamiento es idéntico al actual.**

## 2. Problema

1. **No existe filtro alguno en la importación de productos.** Confirmado en las
   tres rutas de importación:
   - Ruta A (página Importar): `ajax_import_from_api` → `Controller::import_from_alegra`
     → `Products::import_from_alegra` (`Products.php:1263`).
   - Ruta B (Productos/Dashboard): `ajax_sync_start` + `ajax_sync_page`
     (`Admin_Dashboard.php:1578,1618`).
   - Ruta C (modal dashboard): `ajax_sync_now` (`Admin_Dashboard.php:1526`) —
     **código muerto**, nada abre `#alegra-sync-modal` (`admin-dashboard.php:313`).
2. **El único filtro existente es global** (`status` activo/inactivo,
   `Admin_Dashboard.php:1644`).
3. **La API de Alegra sí soporta filtros** (`idItemCategory`, `status`,
   `inventariable`, `query`, `type`), pero el plugin no los usa.
   https://developer.alegra.com/reference/get_items

## 3. Alcance

**Dentro:**

- Modal de filtros en la sección Productos (Ruta B).
- Filtros: categoría (`idItemCategory`), estado (`status`), inventariable
  (`inventariable`), búsqueda (`query`), tipo (`type`).
- Endpoint de categorías para poblar el selector.
- Cálculo de total/páginas coherente con los filtros.
- Tests de no-regresión (sin filtros = comportamiento actual idéntico).

**Fuera de alcance (explícito):**

- Ruta A (página "Importar") — se mantiene intacta.
- Cron, webhooks y Ajustes — intactos.
- `Products::import_from_alegra` y `Controller` — intactos.
- Filtro por bodega (`idWarehouse`) y lista de precios — futuro.
- Persistencia de filtros como opciones (evita tocar `uninstall.php`).

## 4. Enfoque

1. **Verificar primero (Fase 0).** `metadata.total` con filtros, `type` de Alegra,
   combinación de filtros y paginación de categorías.
2. **Plomería por lotes.** Un helper único construye los parámetros; con filtros
   vacíos reproduce exactamente el comportamiento actual.
3. **Endpoint de categorías** paginado con `start`/`limit` (doc oficial).
4. **UI/JS aislados.** No se toca el binding `.alegra-quick-sync` del dashboard;
   el botón de Productos se intercepta con `data-requires-filter`.
5. **Limpieza opcional** del modal muerto del dashboard.

## 5. Criterios de éxito

- [ ] Sin filtros, la importación produce exactamente los mismos parámetros y
      resultado que hoy.
- [ ] Filtrar por categoría trae solo los productos de esa categoría.
- [ ] Estado, inventariable, búsqueda y tipo funcionan según spec.
- [ ] La barra de progreso refleja el total filtrado (excepto `variantParent`,
      documentado como aproximado).
- [ ] Cron, webhooks y página Importar no cambian.
- [ ] La suite `scripts/exec-test.php` pasa.

## 6. Riesgos

| Riesgo | Mitigación |
|---|---|
| Romper el flujo de importación existente | Sin filtros, `$api_params` idéntico; test T4.1 |
| `metadata.total` no respeta filtros | Verificación T0.1; si no, total por paginación |
| `type=variantParent` rechazado por la API (400) | No enviarlo; descarte en cliente (T1.4) |
| Categorías >30 | Endpoint paginado con `start` (T2.1) |
| Romper el dashboard | No se cambia la clase `.alegra-quick-sync`; solo `data-requires-filter` |
| Mock sin soporte de filtros/metadata | Extender el mock (T4.0) |

## 7. Preguntas abiertas que bloquean el spec

1. **T0.1:** ¿`GET /items?metadata=true` con `idItemCategory` devuelve el total
   filtrado?
2. **T0.2:** ¿`type=variantParent` filtra o devuelve todo? ¿400?
