# Aceptación manual — Alegra Connector 2.6.0 (`sync-reliability`)

> **Qué es esto.** La validación **humana** de los **5 problemas** reportados por
> el comerciante, corrida en una **réplica** de la tienda desde el **WP admin**.
> No la reemplaza el harness (`bash scripts/exec-test.sh`): depende de UI y
> tiempos reales. El resultado se firma al final (tabla de cierre).
>
> - **Release:** 2.6.0 · **ZIP:** `releases/alegra-connector-v2.6.0.zip`
> - **Verificación técnica del release:** `docs/RELEASE_2.6.0_VERIFICATION.md`
> - **Riesgo** (Alto/Medio) según la tabla de la verificación de release.

---

## Preparación

1. Instalar **2.6.0** en una **réplica** de la tienda (no en producción).
2. `Alegra Connector → Configuración`:
   - Conexión **testeada** (`Conectar` responde OK).
   - Método de sincronización = **Periódica** (o **Periódica + Tiempo Real**).
   - `push_orders_enabled` = **false** (default) para el Problema 2.
3. Tener un catálogo de **> 90 ítems** en Alegra (≥ 3 páginas de 30).
4. Tener un producto con **stock 10** en WC y **10** en Alegra.

---

## Problema 1 — "El dashboard dice 'Consumidor Final — No encontrado' aunque el contacto existe" (falso negativo del CF)

**Riesgo: Alto.**

1. `Alegra Connector → Configuración → Conexión` → completar credenciales → **Conectar**.
2. Abrir el dashboard (`Alegra Connector`): la fila del CF debe decir **Disponible**
   (o **No verificado** con "Verificar ahora" si el chequeo no pudo completarse).
   **Nunca** "No encontrado" con el CF existente.
3. Pulsar **"Verificar ahora"**: la fila se actualiza **sin recargar** y muestra `message`.
4. En Alegra, **borrar** el CF (cuenta de prueba); en WC, facturar un pedido que lo use.
5. **Resultado esperado:** la factura se **auto-sana** (nota
   *"Consumidor Final re-resuelto y factura creada (auto-sanado)"*) o deja un
   mensaje accionable; **no** falla en silencio; el log tiene la causa.

**Capturas a adjuntar:** fila del dashboard (Disponible / No verificado), botón
"Verificar ahora" actualizando la fila, log/nota del auto-sanado.

---

## Problema 2 — "Vendí y el stock volvió a subir solo → sobreventa" (la re-inflación, el titular)

**Riesgo: Crítico.**

1. Producto con stock **10** en WC y **10** en Alegra; `push_orders_enabled=false` (default).
2. Vender **3** unidades en WC (stock WC = 7). Confirmar que el ajuste WC→Alegra se
   emite (log).
3. `Alegra Connector → Productos` → **"Sincronizar inventario"**.
4. **Resultado esperado:** el stock de WC **no** sube (queda en 7 o baja); el stock
   de Alegra refleja 7. El log muestra el ajuste, **no** una re-inflación.
5. **Grep de un solo escritor:**
   ```bash
   grep -rnE 'set_manage_stock|set_stock_quantity|set_stock_status' includes/ public/ admin/
   ```
   ⇒ matches **solo** en `includes/Sync/Inventory_Writer.php`.

**Capturas a adjuntar:** stock WC antes/después, stock Alegra = 7, log del ajuste,
salida del grep.

---

## Problema 3 — "El stock no se actualiza / las variaciones quedan viejas"

**Riesgo: Medio.**

1. Cambiar el stock de un **producto simple** en Alegra; esperar el webhook/import
   (o correr el import). **Resultado esperado:** WC refleja el nuevo stock.
2. Cambiar el stock de una **variación** en Alegra; disparar el webhook/import
   update del padre. **Resultado esperado:** la variación hija se actualiza (no
   solo el padre).
3. Con `alegra_connector_import_preserve_fields` incluyendo `inventory`, correr el
   poll: el stock **no** se toca. Sin preservar, **sí** se escribe.
4. Producto con `backorders=yes` y stock 0 ⇒ badge **"onbackorder"**; con
   `backorders=no` y stock 0 ⇒ **"outofstock"** (idéntico a HEAD con el umbral por
   defecto). Con `woocommerce_notify_no_stock_amount=2` y `backorders=no`, un
   producto con qty 1 queda **"outofstock"** (cambio intencional, **R19**).

**Capturas a adjuntar:** stock de variación actualizado, `preserve_fields` sin
tocar stock, badges onbackorder/outofstock.

---

## Problema 4 — "El sitio se ralentiza / el poll nunca termina" (el lock y la cascada)

**Riesgo: Alto.**

1. Con el catálogo grande, bajar en `Configuración → Avanzado` el **tope de lote**
   del poll (p. ej. 2) y el **presupuesto** (p. ej. 20 s); guardar.
2. `Productos` → **"Sincronizar inventario"**.
3. **Resultado esperado:** la respuesta dice *"Inventario sincronizado
   parcialmente… (cursor N)"*; el log tiene
   `Inventory poll truncated; resuming next run`; **no** hay fatal 500 ni worker
   colgado minutos.
4. Con `push_products_enabled=true`, confirmar que el poll **no** dispara un POST
   por ítem (sin cascada).
5. Provocar un fatal a mitad (o matar el request): el próximo poll corre **sin**
   esperar 300 s (lock liberado por el shutdown handler).

**Capturas a adjuntar:** AJAX del poll truncado, log de truncación, sin 500.

---

## Problema 5 — "Reanudar vs reimportar / nunca completa"

**Riesgo: Medio.**

1. Tras una corrida truncada, `Productos` muestra el progreso/cursor; una **segunda
   corrida continúa** desde el cursor (no reinicia en la página 1).
2. Repetir hasta que el catálogo complete: la última corrida deja `completed=true`
   y borra el cursor.
3. `Configuración → Avanzado`: ver la card **"Sincronización con cron real
   (recomendado)"** con `DISABLE_WP_CRON`, la URL real del sitio y la línea WP-CLI.

**Capturas a adjuntar:** cursor visible, segunda corrida desde el cursor, card del
cron real.

---

## Sin regresión (distribuido)

Facturación, clientes, pagos y webhooks siguen funcionando; DIAN intacto;
`inventory_source=woocommerce` sigue **sin** escribir stock.

---

## Cierre (firmar)

| # | Problema | Resultado observado | OK | Capturas |
|---|---|---|---|---|
| 1 | CF falso negativo | Disponible / No verificado honesto; auto-sanado | ☐ | ☐ |
| 2 | Re-inflación (titular) | WC no sube; Alegra = 7; un solo escritor | ☐ | ☐ |
| 3 | Stock / variaciones | Simple y variación actualizan; `preserve` respetado; badges | ☐ | ☐ |
| 4 | Lentitud / poll | `truncated` + cursor; lock libre; sin cascada | ☐ | ☐ |
| 5 | Reanudar / completar | Cursor reanudable; completa; card cron real | ☐ | ☐ |
| 6 | Sin regresión | Factura/clientes/pagos/webhooks/DIAN intactos | ☐ | ☐ |

- **Probado por:** ______________________  **Fecha:** __________
- **Versión:** 2.6.0 · **Commit/tag:** `v2.6.0`
- **Réplica:** ______________________ (catálogo ≥ 3 páginas)
