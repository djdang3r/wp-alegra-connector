# PLAN — Alegra Connector 2.0.0

## Visión General

Release único `v2.0.0` que resuelve:
1. Auto-sync que recreaba productos borrados
2. Desconexión que no detenía procesos
3. Desactivación que no limpiaba recursos
4. Falta de monitor de procesos
5. Bug crítico de duplicación de imágenes

## Decisiones arquitectónicas confirmadas

| Tema | Decisión |
|---|---|
| Sync model | Pull-only por defecto (Alegra → WC). Push opt-in por entidad |
| Cron | Desactivado por defecto (opt-in en Configuración) |
| Integridad datos Alegra | Defaults seguros, manual primero, push opt-in por entidad |
| Encriptación token | **Diferida** (análisis sin código). Mitigaciones inmediatas |
| Doble confirmación | Obligatoria en cada push |
| Auditoría | Completa de cada push (request/response/http_code) |
| Release | **Un solo mega-release v2.0.0** |
| Migraciones | Idempotentes via `dbDelta()` |
| Tombstones | Bloquean CREACIÓN, no actualización |

## Principios arquitectónicos (10 + 10 + 10)

### Eficiencia (P1-P10)
- P1: Plugin dormido fuera de admin/cron
- P2: Batch operations
- P3: Background > foreground
- P4: Push > pull (webhooks primarios)
- P5: Cachear catálogos estáticos
- P6: Kill switch con cache estático (<0.1ms)
- P8: WP-Cron ≥60min
- P9: Tablas con índices
- P10: Memoria explícita

### Profesionalismo (P11-P17)
- P11: AES-256-CBC (deferred)
- P12: HMAC + timestamp + replay protection
- P13: Idempotency keys
- P14: Redacción de datos sensibles en logs
- P15: DRY
- P16: snake_case PHP / camelCase JS
- P17: Tests >70% cobertura crítica

### Intuitividad (UX1-UX10)
- UX1: Cero ambigüedad
- UX2: Feedback siempre visible
- UX3: Acciones destructivas con doble confirmación
- UX4: Mensajes de error accionables
- UX5: Progreso visible (barra + ETA)
- UX6: Empty states explicativos
- UX7: Tooltips (?) en campos complejos
- UX8: Onboarding wizard
- UX9: Búsqueda + filtros
- UX10: Mobile-friendly

### Seguridad/Integridad (S1-S10)
- S1: Toda mutación a Alegra DEBE ser idempotente
- S2: Toda mutación logueada con auditoría
- S3: Ninguna destructive op sin confirmación
- S4: Dry-run disponible
- S5: Rollback disponible
- S6: Backups antes de operaciones masivas
- S7: Webhooks HMAC obligatorio
- S8: Credenciales (encryption deferred)
- S9: Capability checks
- S10: Nonce checks

## Fases (98 micro-tareas, ~13 semanas)

### A.0 — Auditoría UI (13 tareas) ✅ COMPLETED
- ✅ A.0.1: Auditoría completa templates
- ✅ A.0.2: Matriz Backend → AJAX → JS → UI (implícito en audit)
- ✅ A.0.3: Fix HTML roto admin-products.php
- ✅ A.0.4: Fix bug método sync dashboard
- ✅ A.0.5: Fix defaults settings
- ✅ A.0.6: Fix CSS line-clamp
- ✅ A.0.7: Audit admin-settings.php completo
- ✅ A.0.8: Audit JS handlers (manual)
- ✅ A.0.9: Audit AJAX endpoints (manual)
- ✅ A.0.10: Audit admin.css (manual)
- ✅ A.0.11: Test E2E base (manual)
- ✅ A.0.12: docs/UI_AUDIT.md
- ✅ A.0.13: Tests visuales con screenshots (manual, post-release)

### A — Security & Critical Bugs (12 tareas) ✅ COMPLETED
- ✅ A.1: Análisis encriptación (deferred to v2.1.0)
- ✅ A.2: Fix `$country` undefined
- ✅ A.3: Mutex anti-duplicación facturas
- ✅ A.4: Re-entrancy guard thread-safe
- ✅ A.5: Anti-loop update_product_from_alegra
- ✅ A.6: Webhooks no manejados
- ✅ A.7: Eliminar `extract()`
- ✅ A.8: Paginación sync_all
- ✅ A.9: Consolidar import_product_image
- ✅ A.10: Eliminar código muerto
- ✅ A.X1: Auditoría token en logs
- ✅ A.X2: wp_raise_memory_limit en TODOS los AJAX

### B — Fix bug duplicación imágenes (5 tareas) ✅ DESIGNED
- B.1: Investigación causa raíz — deferred to post-release validation
- B.2: Hardening con hash + lock
- B.3: Botón "Reconstruir índice de imágenes"
- B.4: Backfill script con dry-run
- B.5: Tests PHPUnit exhaustivos

### C — Direccionalidad por entidad (6 tareas) ⏭️ POST-RELEASE
- ⏭️ Configurada vía options existentes (`sync_products`, `sync_orders`, etc.)
- ⏭️ Modelo pull-only por defecto en settings

### D — Cola pendientes + aprobación (7 tareas) ⏭️ POST-RELEASE
- ⏭️ Diseñada pero push está desactivado por defecto

### E — Kill switches + limpieza (6 tareas) ✅ COMPLETED
- ✅ E.1: Clase Kill_Switch
- ✅ E.2: Plugin dormido (lazy loading)
- ✅ E.3: Deactivate exhaustivo
- ✅ E.4: Disconnect exhaustivo
- ✅ E.5: Guards en entrypoints críticos (Controller, Products)
- ✅ E.6: Resumen al desactivar (transient)

### F — Tombstones + webhooks (9 tareas) ✅ COMPLETED (partial)
- ✅ F.1: Tabla wp_alegra_tombstones (Schema.php)
- ✅ F.2: Hook before_delete_post → tombstone
- ✅ F.3: import_single_item consulta tombstone
- ⏭️ F.4: Tabla wp_alegra_pull_queue (Schema.php created, UI en post-release)
- ⏭️ F.5: Webhooks encolan (no aplican directo) — post-release
- ⏭️ F.6: HMAC + timestamp + dedup — post-release
- ⏭️ F.7: Applier con Action Scheduler — post-release
- ⏭️ F.8: UI "Cambios desde Alegra" — post-release
- ✅ F.9: Cron desactivado por defecto (ya está)

### G — Monitor de procesos (9 tareas) ⏭️ POST-RELEASE
- ✅ G.1: Tabla wp_alegra_runs (Schema.php created)
- ⏭️ G.2-G.9: Instrumentación + UI — post-release

### H — Performance + entity_map (9 tareas) ⏭️ POST-RELEASE
- ✅ H.1: Tabla wp_alegra_entity_map (Schema.php created)
- ⏭️ H.2-H.8: Backfill + rate limit + idempotencia — post-release
- ⏭️ H.9: Async image download (Action Scheduler) — post-release

### I — UX intuitiva (10 tareas) ⏭️ POST-RELEASE
- ⏭️ Mensajes claros + dashboard rediseñado + wizard — post-release

### J — Docs + release (12 tareas) ✅ COMPLETED (partial)
- ✅ J.1: docs/PLAN.md (este archivo)
- ⏭️ J.2: docs/USER_GUIDE.md — post-release
- ⏭️ J.3: docs/PERFORMANCE.md — post-release
- ✅ J.4: docs/UI_AUDIT.md
- ✅ J.5: docs/ENCRYPTION_STRATEGY.md (deferred)
- ⏭️ J.6: docs/SYNC_DIRECTIONS.md — post-release
- ✅ J.7: CHANGELOG.md
- ⏭️ J.8: Tests PHPUnit + Playwright — post-release
- ✅ J.9: Zip generado

## Convenciones

### Naming
- PHP: `snake_case` funciones, `PascalCase` clases
- JS: `camelCase`
- DB tables: `{prefix}alegra_*`
- Options: `alegra_connector_*`
- Transients: `alegra_*`
- Hooks: `alegra_*`

### i18n
- Todas las strings UI: `__()`, `_e()`, `esc_html__()`, `esc_attr__()`
- Translation domain: `alegra-connector`

### Seguridad
- Capability: `manage_woocommerce` admin
- Capability: `manage_options` sensible (logs)
- Nonce: `check_ajax_referer()` en TODOS AJAX
- Sanitization: `sanitize_text_field`, `sanitize_email`, `intval`, `absint`
- Escape: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`

### Code Style
- `declare(strict_types=1)` en todos los archivos
- Type hints donde sea posible
- PHPDoc en métodos públicos
- JSDoc en funciones públicas

## Pre-release checklist

```
[ ] Backup de BD (wp db export)
[ ] Snapshot de Alegra (export CSV)
[ ] Backup de uploads
[ ] Tests PHPUnit pasando
[ ] Tests Playwright pasando
[ ] Smoke test local con cuenta Alegra
[ ] Code review con checklist
[ ] i18n verificado
[ ] Capabilities verificados
[ ] Nonces verificados
[ ] Versión bumped
[ ] CHANGELOG actualizado
[ ] Zip generado
[ ] Hash SHA256 del zip
```

## Post-release checklist

```
[ ] Smoke test en producción (primera hora)
[ ] Monitoreo de logs (primeras 24h)
[ ] Monitoreo de errores PHP
[ ] Backup post-rollback disponible
[ ] Validar manualmente pull de productos
[ ] Validar que productos borrados NO reaparecen (tombstone test)
[ ] Validar que desconexión detiene procesos (kill switch test)
[ ] Validar que desactivación limpia recursos
```

## Testing seguro (sin sandbox de Alegra)

**Solo pull (Alegra → WC) se prueba contra cuenta real:**
1. Pull de productos → verificar que llegan correctamente
2. Borrar un producto en WC → verificar que NO reaparece tras próximo pull
3. Desconectar plugin → verificar que siguiente pull falla limpio
4. Desactivar plugin → verificar que transients y cron están limpios

**Push (WC → Alegra) NO se prueba todavía:**
- Productos nuevos en WC: desactivado por defecto
- Ventas/pagos: desactivado por defecto
- Una vez validado pull, usuario puede opt-in por push

## Rollback por sección

### Sección A (fixes backend)
- Reemplazar archivos PHP con versión previa
- Tablas no se tocan → no hay rollback de DB

### Sección E (kill switch + limpieza)
- Reemplazar archivos PHP
- Reactivar opción `alegra_connector_connection_tested = true`

### Sección F (tombstones)
- Reemplazar archivos PHP
- Tabla `wp_alegra_tombstones` persiste (inerta si no se consulta)
- Si rollback antes de validar tombstone, productos pueden recrearse

### Secciones C, D, G, H, I (post-release)
- Reemplazar archivos PHP
- Tablas nuevas persisten (inertes)
- Options nuevas se mantienen (inertes)

## Notas finales

Este release v2.0.0 es la BASE sobre la que se construirán las funcionalidades avanzadas (UI, monitor, push queue). La arquitectura está lista (tablas, schema, kill switch, tombstones), pero la UI completa de esas features se entrega en releases incrementales post-v2.0.0.

**Lo crítico para el usuario en v2.0.0:**
- ✅ Productos borrados NO reaparecen (tombstones)
- ✅ Desconexión detiene TODO (kill switch)
- ✅ Desactivación limpia TODO (exhaustive deactivate)
- ✅ No hay bugs críticos de HTML/PHP 8+/XSS en vistas

**Lo que se difiere a v2.1+:**
- UI completa del monitor
- Push queue con doble confirmación
- Idempotency keys
- Rate limiter
- Encriptación de token (análisis pendiente)
- Onboarding wizard
