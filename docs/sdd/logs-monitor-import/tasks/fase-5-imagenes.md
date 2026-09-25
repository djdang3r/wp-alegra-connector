# Fase 5 — D5: Imágenes (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `logs-monitor-import` |
| Documentos base | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Tareas que expande | **T5.1–T5.3** (skeleton `tasks.md:524-594`) |
| Decisión de diseño | **D5** (`design.md:690-772`) |
| Versión objetivo | **2.5.0** |
| Estado | T5.1a, T5.1b, T5.2a, T5.2b `ACTIVO` · T5.3a, T5.3b `BLOQUEADO(Fase 0.1 / G1)` para el cierre de la rama; la implementación de la opción es idéntica en A y B |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`, stubs `scripts/lib/wp-stubs.php`) |

> **Convención de IDs de test (corrección).** Los tests nuevos del harness son `T28.{fase}{n}`:
> Fase 5 usa `T28.51`–`T28.59`. `T28.59` es el test `blocked reflejado` **movido desde Fase 3**
> (era `T28.312`): `blocked` lo produce T5.2a y un test de Fase 3 no puede depender de una fase
> posterior. Ver T5.2b §Verificación.

> **Regla de oro (heredada del skeleton).** Después de que un test pase, **revertir el fix**, correr
> `bash scripts/exec-test.sh` y confirmar que **ese** test falla. Volver a aplicar el fix. Sin
> `prove-it-catches` el test no se acepta.
>
> **Todos los `file:line` fueron re-verificados en HEAD.** Las citas mal del skeleton/design se
> corrigen y se listan abajo.

---

## Correcciones de cita (verificadas en HEAD para Fase 5)

| # | Cita original (skeleton/design) | Realidad verificada | Impacto |
|---|---|---|---|
| C1 | `design.md:731` / `tasks.md:552` citan `download_and_attach_image:2166-2258` | El método va de **`:2166` a `:2264`** (el `finally` cierra en `:2259-2263`; `}` en `:2264`). El `.jpg` está en `:2231` (confirmado). | T5.1a usa el rango real; el BEFORE/AFTER apunta a `:2230-2233`. |
| C2 | `tasks.md:551` cita `import_product_image:2370-2403` | Confirmado: `Products::import_product_image` va de `:2370` a `:2403`. El `.jpg` está en `:2393` (confirmado). **Ojo: existe OTRO `import_product_image` en `Admin_Dashboard.php:108` (muerto).** | T5.1a toca el de `Products`; T5.1b borra el de `Admin_Dashboard`. No confundir. |
| C3 | `tasks.md:536-541` (T5.1) y `design.md:719-721` dicen que el método muerto elimina "la tercera allowlist" | Falso (ya corregido en `tasks.md:37`): `Admin_Dashboard.php:113` **llama** a `Sync\Products::is_allowed_image_url()`, no duplica allowlist. El tercer `.jpg` sí existe: `:132`. | T5.1b borra el método muerto y con él el tercer `.jpg`; **no** hay tercera allowlist. |
| C4 | `tasks.md:50` cita `is_allowed_image_url:2136-2158`; `spec.md:38` cita `:2136-2157` | El método va de **`:2136` a `:2158`** (cierra en `:2158`). | T5.3a usa `:2136-2158`. |
| C5 | `spec.md:38` cita `allowed_image_hosts:2125-2131` | El método va de **`:2125` a `:2130`** (`:2131` está en blanco). | T5.3a usa `:2125-2130`. |
| C6 | `tasks.md:557` (T5.2) cita `import_product_images:2018`, `download_and_attach_image:2166-2258`, `import_product_image:2370-2403`, `import_from_alegra:1248` | `import_product_images` `:2018-2113` ✔; `import_from_alegra` `:1248-1399` ✔; los otros dos, ver C1/C2. | Ajustar rangos. |
| C7 | `tasks.md:558-559` lista los puntos que "hoy sólo loguean": `:2170-2176`, `:2221-2228`, `:2236-2244`, `:2374-2380`, `:2386-2390`, `:2400-2402` | Confirmados exactos. | Sin cambio. |
| C8 | Harness: `wp_check_filetype_and_ext` y `wp_get_image_mime` **no** están stubeados; `download_url`/`media_handle_sideload` siempre devuelven `WP_Error` (`scripts/lib/wp-stubs.php:817-818`) | El camino mime **no** es testeable en runtime; `extension_from_mime` (pura) **sí**. El camino de fallo de imágenes **sí** es testeable (toda descarga falla). | T5.1a se verifica por source-scan + test puro. T5.2a se verifica con fallos. |

**Citas confirmadas exactas (no requieren corrección):** `Products.php:2018-2113` (`import_product_images`), `:2041` (llamada a `import_product_image` en modo `favorite`), `:2068`/`:2090` (llamadas a `download_and_attach_image`), `:2125-2130` (`allowed_image_hosts`), `:2128` (filtro), `:2136-2158` (`is_allowed_image_url`), `:2147-2155` (match de subdominio; `str_ends_with('.alegra.com')` en `:2152`), `:2166-2264` (`download_and_attach_image`), `:2170-2176` (blocked), `:2220` (`download_url`), `:2221-2228` (download fail), `:2230-2233` (`.jpg`), `:2235-2244` (sideload fail), `:2246-2258` (éxito), `:2370-2403` (`Products::import_product_image`), `:2374-2380` (blocked), `:2386-2390` (download fail), `:2392-2395` (`.jpg`), `:2397-2402` (sideload/éxito), `:1396` (`$result['total_pages']` antes del `return $result`); `Admin_Dashboard.php:108-144` (método muerto `import_product_image`), `:113` (llamada a `is_allowed_image_url`), `:132` (`.jpg`), `:393-579` (`register_settings`), `:1877-1936` (sanitizers), `:4109-4112` (respuesta de `ajax_import_from_api`), `:652+` (`get_script_strings`); `templates/admin-settings.php:252` (`#tab-advanced`), `:302-357` (filas de Avanzado), `:13` (form `options.php`); `alegra-connector.php:409-446` (`$defaults`), `:452-470` (`$non_autoload`), `:472-476` (siembra); `uninstall.php:48-130` (opciones).

---

## Fase 5 — Objetivo

- La extensión del archivo adjunto se deriva del **mime real** (PNG/WebP/GIF dejan de guardarse `.jpg`).
- Los fallos de imagen se **cuentan y se muestran** al comerciante (host bloqueado / descarga / sideload / diferidas), no sólo se loguean.
- La allowlist de hosts de imagen es **configurable** por el comerciante, sin hardcodear el CDN en el plugin distribuido, y **nunca** se amplía a `*` ni a `http`.

**DoD de la fase:** REQ-IMG-01/02/03/04, REQ-LOG-03, NFR-07 verdes; no queda ningún `.jpg`
hardcodeado en los puntos de importación; los fallos se ven en pantalla; la allowlist no admite
comodín.

---

### T5.1a — `extension_from_mime()` + `wp_check_filetype_and_ext` en los dos puntos vivos

**Objetivo**: que el nombre del archivo adjunto conserve la extensión real del contenido descargado,
en vez de forzar `.jpg`.

**Descripción técnica**: hoy los dos puntos vivos de importación arman el `file_array` con
`'.jpg'` hardcodeado (`Products.php:2231` en `download_and_attach_image`, `:2393` en
`import_product_image`). Eso rompe PNG/WebP y la validación del sideload (REQ-IMG-02). El helper
canónico de WP es `wp_check_filetype_and_ext($file, $filename)`: valida el mime real y devuelve la
extensión; si no puede, se cae al mapa por mime (`wp_get_image_mime`/`mime_content_type`) y, último
recurso, `jpg` (preserva el comportamiento actual). Decisión D5 (`design.md:692-721`); cubre
REQ-IMG-02.

**Desarrollo técnico**

Archivo: `includes/Sync/Products.php`.

1) **Helper nuevo** (junto a `allowed_image_hosts`, antes de `:2125`):
```php
/**
 * Extensión de archivo derivada del mime real. Fallback 'jpg' (comportamiento previo).
 */
private static function extension_from_mime(string $mime): string
{
    $map = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/avif'    => 'avif',
        'image/bmp'     => 'bmp',
        'image/tiff'    => 'tiff',
        'image/svg+xml' => 'svg',
    ];
    return $map[strtolower($mime)] ?? 'jpg';
}
```

2) **Punto vivo 1** — `download_and_attach_image`.

**ANTES** (`Products.php:2230-2233`):
```php
$file_array = [
    'name' => 'alegra-' . $product_id . '-' . substr($url_hash, 0, 8) . '.jpg',
    'tmp_name' => $tmp,
];
```
**DESPUÉS:**
```php
$fallback = 'alegra-' . $product_id . '-' . substr($url_hash, 0, 8);
$check = wp_check_filetype_and_ext($tmp, $fallback . '.jpg');
$ext = !empty($check['ext'])
    ? (string) $check['ext']
    : self::extension_from_mime((string) (wp_get_image_mime($tmp) ?: mime_content_type($tmp)));
$file_array = ['name' => $fallback . '.' . $ext, 'tmp_name' => $tmp];
```

3) **Punto vivo 2** — `import_product_image`.

**ANTES** (`Products.php:2392-2395`):
```php
$file_array = [
    'name' => 'alegra-' . $product_id . '.jpg',
    'tmp_name' => $tmp,
];
```
**DESPUÉS:**
```php
$fallback = 'alegra-' . $product_id;
$check = wp_check_filetype_and_ext($tmp, $fallback . '.jpg');
$ext = !empty($check['ext'])
    ? (string) $check['ext']
    : self::extension_from_mime((string) (wp_get_image_mime($tmp) ?: mime_content_type($tmp)));
$file_array = ['name' => $fallback . '.' . $ext, 'tmp_name' => $tmp];
```

**Resultado esperado**
- Un PNG descargado se sideloadea como `alegra-<id>-<hash>.png` (no `.jpg`).
- El fuente de `Products.php` **no** contiene `"'.jpg'"` ni `'.jpg'` en el armado de `file_array`.
- `extension_from_mime('image/png') === 'png'`; `extension_from_mime('image/jpeg') === 'jpg'`;
  `extension_from_mime('application/octet-stream') === 'jpg'` (fallback).

**Dependencias**: ninguna.

**Trazabilidad**: REQ-IMG-02.

**Verificación**
1. Test puro (runtime, no requiere stubs de WP):
```php
TestRunner::test('T28.51 extension_from_mime maps the documented mimes', function (): void {
    $f = fn (string $m): string => alegra_call_private_static(
        \Alegra\Connector\Sync\Products::class, 'extension_from_mime', $m
    );
    TestRunner::assertSame('png',  $f('image/png'), 'png');
    TestRunner::assertSame('jpg',  $f('image/jpeg'), 'jpeg');
    TestRunner::assertSame('webp', $f('image/webp'), 'webp');
    TestRunner::assertSame('gif',  $f('image/gif'), 'gif');
    TestRunner::assertSame('avif', $f('image/avif'), 'avif');
    TestRunner::assertSame('svg',  $f('image/svg+xml'), 'svg');
    TestRunner::assertSame('jpg',  $f('application/octet-stream'), 'unknown falls back to jpg');
});
```
2. Source-scan (los dos puntos vivos):
```php
TestRunner::test('T28.52 no hardcoded .jpg remains in the live import points', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'includes/Sync/Products.php');
    TestRunner::assertStringNotContains("'name' => 'alegra-' . \$product_id . '-' . substr(\$url_hash, 0, 8) . '.jpg'", $src, 'point 1 must not hardcode .jpg');
    TestRunner::assertStringNotContains("'name' => 'alegra-' . \$product_id . '.jpg'", $src, 'point 2 must not hardcode .jpg');
    TestRunner::assertStringContains('wp_check_filetype_and_ext', $src, 'the mime check must be used');
});
```
> El harness **no** stubea `wp_check_filetype_and_ext`/`wp_get_image_mime`, pero como `download_url`
> siempre devuelve `WP_Error` (`scripts/lib/wp-stubs.php:817`), el camino mime nunca se ejecuta en los
> tests. **Si en el futuro se stubea `download_url` con éxito, hay que stubbear también esas dos
> funciones** o el test fatalea. Nota para el worker.

**Prove-it-catches**: reponer `'.jpg'` en `:2231` → el source-scan falla. Cambiar el mapa de
`extension_from_mime` (p. ej. `'image/png' => 'jpg'`) → el test puro falla.

**Riesgo**: que `wp_get_image_mime()` devuelva `false` para un archivo válido y `mime_content_type()`
no exista en algún host. Guard: `wp_check_filetype_and_ext` es la primera opción y `extension_from_mime`
cae a `jpg`; `mime_content_type` viene con WP (no es una dependencia externa).

**Estimación**: S/M (2 h).

---

### T5.1b — Borrar el método muerto `Admin_Dashboard::import_product_image` (y el tercer `.jpg`)

**Objetivo**: eliminar código muerto que conserva un tercer `.jpg` hardcodeado y confunde el
mantenimiento.

**Descripción técnica**: `Admin_Dashboard::import_product_image` (`:108-144`) tiene **0 llamadores**
(grep de `import_product_image` → sólo la definición en `Admin_Dashboard`; el import real vive en
`Products::import_product_image`, `:2370`). Su `.jpg` está en `:132`. El design (hallazgo #3) y la
corrección #2 de `tasks.md` (`:37`) confirman: se **borra** el método en vez de arreglarlo. Cubre
REQ-IMG-02.

**Desarrollo técnico**

Archivo: `admin/Admin/Admin_Dashboard.php`.

**ANTES** (borrar **todo** el bloque `:105-144`, incluyendo el docblock `:105-107`):
```php
    /**
     * Download and attach image from Alegra to WooCommerce product
     */
    private function import_product_image(int $product_id, string $image_url): void
    {
        if (empty($image_url)) return;

        // SSRF guard (AC-68): only fetch https URLs from Alegra's CDN.
        if (!Sync\Products::is_allowed_image_url($image_url)) {
            $this->log('warning', 'Blocked image download from a non-allowlisted host', [
                'product_id' => $product_id,
                'host' => (string) (wp_parse_url($image_url)['host'] ?? ''),
            ]);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url, 15);
        if (is_wp_error($tmp)) {
            $this->log('warning', 'Image download failed for product ' . $product_id, ['error' => $tmp->get_error_message()]);
            return;
        }

        $file_array = [
            'name' => 'alegra-' . $product_id . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $product_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, $attachment_id);
        } else {
            $this->log('warning', 'Image sideload failed for product ' . $product_id, ['error' => $attachment_id->get_error_message()]);
        }

        @unlink($tmp);
    }
```
**DESPUÉS:** el bloque se elimina por completo. No quedan referencias a `Sync\Products` en
`Admin_Dashboard` por este método. **No** borrar `get_product_by_alegra_id` (`:97`) ni `log()` (`:87`):
se usan en otros lados.

**Resultado esperado**
- `Admin_Dashboard.php` no contiene `function import_product_image`.
- `Admin_Dashboard.php` no contiene `'alegra-' . $product_id . '.jpg'`.
- El plugin sigue cargando (`bash scripts/smoke-test.sh` verde) — el método era muerto.

**Dependencias**: ninguna. (Independiente de T5.1a; se puede hacer antes o después.)

**Trazabilidad**: REQ-IMG-02 (elimina el tercer `.jpg`).

**Verificación** (source-scan, `scripts/exec-test.php`):
```php
TestRunner::test('T28.53 the dead Admin_Dashboard::import_product_image is gone', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains('function import_product_image', $src, 'the dead method must be deleted');
    TestRunner::assertStringNotContains("'alegra-' . \$product_id . '.jpg'", $src, 'the third hardcoded .jpg must be gone');
});
```
Y `bash scripts/smoke-test.sh` verde (la clase carga).

**Prove-it-catches**: reponer el método → el source-scan falla.

**Riesgo**: borrar un método con consumidores externos (imposible: es `private`). Guard: es `private`
y el grep da 0 llamadores.

**Estimación**: S (0.5 h).

---

### T5.2a — Incrementos del acumulador de stats de imagen (el acumulador es de T3.2.b)

**Objetivo**: contar por request las imágenes ok / bloqueadas / fallo de descarga / fallo de sideload,
para poder reportarlas.

**Descripción técnica**: hoy cada rama de fallo sólo llama `$this->logger->warning(...)`
(`Products.php:2170-2176`, `:2221-2228`, `:2236-2244`, `:2374-2380`, `:2386-2390`, `:2400-2402`) y no
deja rastro en el resultado (REQ-IMG-03, REQ-LOG-03). Decisión D5 (`design.md:723-742`); cubre
REQ-IMG-03, REQ-LOG-03, REQ-IMG-01.

> **Dueño único del acumulador (cierra el conflicto T3.2.b vs T5.2a).** La propiedad
> `private static array $image_stats`, `reset_image_stats()` e `image_stats()` las **declara T3.2.b
> (Fase 3)** — ver `fase-3-chunked.md` §T3.2.b. **T5.2a NO las redeclara**: sólo agrega los
> incrementos `blocked`/`download`/`sideload`/`ok` en las ramas existentes. El `deferred++` también
> es de T3.2.b (su guard de deadline). Dependencia dura: si T3.2.b no está, T5.2a no arranca.

**Desarrollo técnico**

Archivo: `includes/Sync/Products.php`. Agregar **sólo** la línea de incremento antes de cada
`return`/rama de éxito (el acumulador ya lo declaró T3.2.b):

| Método | Línea de la rama | Incremento |
|---|---|---|
| `download_and_attach_image` | `:2170-2176` (host no permitido) | `self::$image_stats['blocked']++;` antes de `return 0;` |
| `download_and_attach_image` | `:2221-2228` (`download_url` WP_Error) | `self::$image_stats['download']++;` antes de `return 0;` |
| `download_and_attach_image` | `:2235-2244` (sideload WP_Error) | `self::$image_stats['sideload']++;` antes de `return 0;` |
| `download_and_attach_image` | `:2246-2258` (éxito) | `self::$image_stats['ok']++;` antes del `return (int) $attachment_id;` |
| `import_product_image` | `:2374-2380` (host no permitido) | `self::$image_stats['blocked']++;` |
| `import_product_image` | `:2386-2390` (`download_url` WP_Error) | `self::$image_stats['download']++;` |
| `import_product_image` | `:2400-2402` (sideload WP_Error) | `self::$image_stats['sideload']++;` |
| `import_product_image` | `:2398-2399` (éxito) | `self::$image_stats['ok']++;` |

> **`deferred`**: NO se incrementa acá; es del guard de deadline que **T3.2.b (Fase 3)** agrega antes
> de cada `download_and_attach_image` (`:2068`, `:2090`). T5.2a no inventa un deadline propio.
> **No** contar `:2168` (url vacía) ni `:2180` (url normalizada vacía): no son fallos de import.
> Los caminos de **dedup** (`:2199-2204`, `:2209-2214`) no incrementan: la imagen ya existía
> (idempotencia), no es un fallo ni una descarga nueva.

**Resultado esperado**
- Tras un import con 3 descargas fallidas: `image_stats()['download'] === 3` y `['failed'] === 3`.
- Un host no permitido: `['blocked']++` y el log conserva "Blocked image download from a
  non-allowlisted host".
- Una imagen adjuntada con éxito: `['ok']++`.
- `image_stats()['failed']` = `blocked + download + sideload` (nunca incluye `ok`/`deferred`).

**Dependencias**: **T3.2.b** (dueño de `$image_stats`/`reset_image_stats()`/`image_stats()`; el
`deferred++` y el guard de deadline). Los 4 incrementos `blocked`/`download`/`sideload`/`ok` no
dependen de nada más.

**Trazabilidad**: REQ-IMG-03, REQ-LOG-03, REQ-IMG-01.

**Verificación** (runtime; el stub hace fallar **toda** descarga, `wp-stubs.php:817`):
```php
TestRunner::test('T28.54 image_stats counts blocked and download failures', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    $p = make_products();
    \Alegra\Connector\Sync\Products::reset_image_stats();

    // host no permitido → blocked
    alegra_call_private($p, 'download_and_attach_image', 1000, 'https://evil.example/x.png');
    TestRunner::assertSame(1, \Alegra\Connector\Sync\Products::image_stats()['blocked'], 'blocked host counted');

    // host permitido → download_url del stub devuelve WP_Error → download
    alegra_call_private($p, 'download_and_attach_image', 1000, 'https://cdn3.alegra.com/x.png');
    $s = \Alegra\Connector\Sync\Products::image_stats();
    TestRunner::assertSame(1, $s['download'], 'download failure counted');
    TestRunner::assertSame(1, $s['failed'], 'failed = blocked+download+sideload');
});
```
> `download_and_attach_image` es `private`; usar `alegra_call_private` (`test-framework.php:289`).
> `is_allowed_image_url` (`:2136`) es static public, así que `cdn3.alegra.com` pasa (subdominio de
> `alegra.com`, `:2152`) y `evil.example` no.

**Prove-it-catches**: quitar el `self::$image_stats['download']++;` de `:2221-2228` → el test de
`download` falla. Quitar el `blocked++` de `:2170-2176` → el test de `blocked` falla.

**Riesgo**: contar `ok` en el camino de dedup (inflaría). Guard: los incrementos van **después** de
las ramas de dedup. Contar `deferred` como fallo. Guard: `failed` sólo suma `blocked+download+sideload`.

**Estimación**: S/M (2 h).

---

### T5.2b — Flujo de stats al resultado del import y a la UI

**Objetivo**: que el resumen del import (chunked y manual) muestre cuántas imágenes fallaron, con
desglose, y que un import sin fallos no alarma.

**Descripción técnica**: el acumulador de T5.2a vive en `Products`. Hay que resetearlo al inicio de
cada corrida, adjuntarlo al resultado de `import_from_alegra`, mergearlo por página en
`ajax_sync_page`, incluirlo en `ajax_import_from_api` y mostrarlo en `admin.js`. Decisión D5
(`design.md:734-742`); cubre REQ-IMG-03 y REQ-IMG-01.

**Desarrollo técnico**

Archivo: `includes/Sync/Products.php`.

1) En `import_from_alegra` (`:1248-1399`): **reset** al inicio (después de `$result = [...]`, `:1256`)
```php
self::reset_image_stats();
```
y **adjuntar** antes del `return $result;` (`:1396-1398`). **ANTES** (`:1395-1398`):
```php
$this->logger->info('Products import from Alegra completed', $result);
$result['total_pages'] = $current_page;

return $result;
```
**DESPUÉS:**
```php
$this->logger->info('Products import from Alegra completed', $result);
$result['total_pages'] = $current_page;
$result['images'] = self::image_stats();

return $result;
```

Archivo: `admin/Admin/Admin_Dashboard.php`.

2) Helper de merge (junto a `sanitize_reconcile_batch`, `:1903`):
```php
/**
 * Suma dos desgloses de imagen (por página) preservando las claves. D5.
 * @param array<string,int> $a @param array<string,int> $b
 * @return array<string,int>
 */
private static function merge_image_stats(array $a, array $b): array
{
    $out = [];
    foreach (['ok', 'blocked', 'download', 'sideload', 'deferred'] as $k) {
        $out[$k] = (int) ($a[$k] ?? 0) + (int) ($b[$k] ?? 0);
    }
    $out['failed'] = $out['blocked'] + $out['download'] + $out['sideload'];
    return $out;
}
```

3) `ajax_sync_page` (`:2073-2225`): resetear por página **antes** del loop de productos (`:2119`),
```php
\Alegra\Connector\Sync\Products::reset_image_stats();
```
y mergear en el estado **después** del loop (después de `:2144`, dentro del `if ($type === 'products')`):
```php
$state['images'] = self::merge_image_stats(
    is_array($state['images'] ?? null) ? $state['images'] : [],
    \Alegra\Connector\Sync\Products::image_stats()
);
```
La línea del **payload** (`'images' => …`, `:2216-2221`) la escribe **T3.4 (Fase 3)** leyendo
**`$state['images']`**, que es la **fuente canónica**; T5.2b **no** la re-lista. Path canónico: el
ítem crudo viene de `GET /items` (`mode => 'advanced'`, `Products.php:1329-1333`) como
`$item['images'][].url` (consumido por `Products::import_product_images()`, `Products.php:2018`); el
acumulado del run vive en `$state['images']` y el JS lo consume como
`d.images.{ok,blocked,download,sideload,deferred,failed}`.

4) `ajax_import_from_api` (`:4073-4116`): incluir las imágenes en la respuesta. **ANTES**
(`:4109-4112`):
```php
wp_send_json_success([
    'message' => sprintf(__('Importación completada: %d nuevos, %d actualizados.', 'alegra-connector'), $imported, $updated),
    'data' => $result,
]);
```
**DESPUÉS:**
```php
wp_send_json_success([
    'message' => sprintf(__('Importación completada: %d nuevos, %d actualizados.', 'alegra-connector'), $imported, $updated),
    'data' => $result,
    'images' => $result['images'] ?? [],
]);
```

Archivo: `admin/assets/js/admin.js`.

5) En la rama `d.done` del chunked (`:245-267`), después de armar el resumen y **antes** del
`setTimeout`:
```js
var img = d.images || {};
if ((img.failed || 0) > 0) {
    showNotice(fmt(S.imagesFailed, img.failed, img.blocked || 0, img.download || 0, img.sideload || 0, img.deferred || 0), 'warning');
}
```
String en `Admin_Dashboard::get_script_strings()` (`:652+`). **Dueño único: T5.2b** (cierra I3):
T3.3.e (Fase 3) la **consume** pero **no** la redeclara; la declaración vive acá una sola vez. Tabla
canónica completa en `fase-6-ui-logs-monitor.md` §"Tabla canónica de strings i18n".
```php
'imagesFailed' => __('%1$s imágenes no se pudieron importar (%2$s host no permitido, %3$s fallo de descarga, %4$s fallo al adjuntar, %5$s diferidas).', 'alegra-connector'),
```

**Resultado esperado**
- El resultado de un import incluye `images` con `ok/blocked/download/sideload/deferred/failed`.
- Con `failed > 0`, el modal y un `showNotice` warning muestran el desglose.
- Sin fallos (`failed === 0`), **ninguna** alarma.
- El chunked acumula el desglose entre páginas (no se resetea a 0 al final).

**Dependencias**: T5.2a, T3.4 (payload de página con `images`), T2.2 (ruta manual instrumentada).

**Trazabilidad**: REQ-IMG-03, REQ-IMG-01.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T28.55 import_from_alegra exposes image stats and the page payload merges them', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    // el mock de /items devuelve 1 ítem con imagen; download_url siempre falla en el stub
    alegra_mock_seed_item('itm-img', [
        'name' => 'Con imagen', 'type' => 'simple',
        'images' => [['url' => 'https://cdn3.alegra.com/a.png', 'favorite' => true]],
    ]);
    $result = make_products()->import_from_alegra(1, 30, 0);
    TestRunner::assertArrayHasKey('images', $result, 'the result must carry image stats');
    TestRunner::assertTrue(($result['images']['download'] ?? 0) >= 1, 'the failed download must be counted');

    // payload de página
    alegra_test_reset();
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    set_transient('alegra_batch_state', [
        'type' => 'products', 'page' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 1,
        'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'filters' => [],
        'run_id' => 0, 'start' => 0, 'offset' => 0, 'policy' => 'respect', 'images' => [],
    ], 600);
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertArrayHasKey('images', $resp->payload, 'the page payload must include images');
});

TestRunner::test('T28.59 the page payload reflects blocked images (moved from Fase 3 T28.312)', function (): void {
    alegra_test_reset();
    update_option('alegra_connector_sync_images', true);
    // host NO permitido: is_allowed_image_url() lo bloquea antes de descargar (T5.2a)
    alegra_mock_seed_item('itm-blocked', [
        'name' => 'Con imagen bloqueada', 'type' => 'simple',
        'images' => [['url' => 'https://evil.example/x.png', 'favorite' => true]],
    ]);
    $admin = new \Alegra\Connector\Admin\Admin_Dashboard(make_api(), make_logger());
    set_transient('alegra_batch_state', [
        'type' => 'products', 'page' => 0, 'per_page' => 30, 'total_pages' => 1, 'total_items' => 1,
        'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'filters' => [],
        'run_id' => 0, 'start' => 0, 'offset' => 0, 'policy' => 'respect', 'images' => [],
    ], 600);
    $resp = alegra_capture_json(fn () => $admin->ajax_sync_page());
    TestRunner::assertTrue(($resp->payload['images']['blocked'] ?? 0) > 0, 'the blocked host must be reflected in the payload');
    TestRunner::assertTrue(($resp->payload['images']['failed'] ?? 0) > 0, 'blocked counts as failed');
});
```
> El seed real del harness es `alegra_mock_seed_item(string $id, array $data = [])`
> (`scripts/lib/alegra-mock.php:70`). Ajustar los campos al shape que espera el mock de `/items`.
>
> **`T28.59` — movido desde Fase 3 (`T28.312`) para cerrar la dependencia cross-fase.** El test
> original vivía en la verificación de T3.4 (Fase 3) pero asserta `images.blocked > 0`, y `blocked`
> sólo se incrementa en **T5.2a** (Fase 5). Un test de Fase 3 no puede depender de una fase
> posterior: se movió a T5.2b, donde T5.2a ya está aplicada y el payload de T3.4 ya existe. Cubre
> REQ-IMG-03.

**Prove-it-catches**: quitar `$result['images'] = self::image_stats();` → el primer
`assertArrayHasKey('images', $result)` falla. Quitar `'images'` de la respuesta de `ajax_sync_page` →
el segundo falla. Quitar el `self::$image_stats['blocked']++;` de T5.2a → `T28.59` falla
(`blocked === 0`).

**Riesgo**: que el merge pierda páginas (si se resetea el estado). Guard: `merge_image_stats` suma
sobre `$state['images']`; sólo se resetea el acumulador estático por página. Que un import sin fallos
alarme. Guard: el aviso está detrás de `if ((img.failed || 0) > 0)`.

**Estimación**: S/M (2 h).

---

### T5.3a — Allowlist configurable: opción + sanitizer + merge + registro · BLOQUEADO(Fase 0.1 / G1)

**Objetivo**: permitir que el comerciante agregue hosts de imagen desde la UI (sin hardcodear el CDN
en el plugin) y que la allowlist nunca se amplíe a comodín ni a `http`.

**Descripción técnica**: `allowed_image_hosts()` (`Products.php:2125-2130`) devuelve `['alegra.com']`
y aplica el filtro `alegra_connector_allowed_image_hosts` (`:2128`); `is_allowed_image_url()`
(`:2136-2158`) exige `https` (`:2142`) y hace match exacto o de subdominio (`:2152`). **`cdn3.alegra.com`
ya está permitido** por el `str_ends_with($host, '.alegra.com')` de `:2152` → la verificación G1
(`T0.1`) probablemente cierre como **Rama A**. El fix es la opción `allowed_image_hosts_extra`
sanitizada (sin `*`, `/`, `:`), que se mergea con la base. Decisión D5 (`design.md:744-772`); cubre
REQ-IMG-04 y NFR-07. **Condicional a G1**: ver "Rama A / Rama B".

**Desarrollo técnico**

Archivo: `includes/Sync/Products.php`.

**ANTES** (`:2125-2130`):
```php
public static function allowed_image_hosts(): array
{
    $hosts = ['alegra.com'];
    $filtered = apply_filters('alegra_connector_allowed_image_hosts', $hosts);
    return is_array($filtered) ? $filtered : $hosts;
}
```
**DESPUÉS:**
```php
public static function allowed_image_hosts(): array
{
    $base = ['alegra.com'];
    $extra = (array) get_option('alegra_connector_allowed_image_hosts_extra', []);
    $hosts = array_values(array_unique(array_merge($base, $extra)));
    $filtered = apply_filters('alegra_connector_allowed_image_hosts', $hosts);
    return is_array($filtered) ? $filtered : $hosts;
}
```
> Sin la opción, `get_option(..., [])` devuelve `[]` y el resultado es exactamente `['alegra.com']`
> (sin regresión, `design.md:913`).

Archivo: `admin/Admin/Admin_Dashboard.php`.

1) **Sanitizer** (junto a `sanitize_webhook_selected_events`, `:1920`):
```php
/**
 * Sanitiza la lista de hosts de imagen extra. Un host por línea o por elemento.
 * Acepta solo dominios válidos; rechaza `*`, `/`, `:` (NFR-07). D5.
 * @param mixed $value
 * @return array<int,string>
 */
public static function sanitize_image_hosts($value): array
{
    if (is_string($value)) {
        $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $line) {
        $host = strtolower(trim((string) $line));
        if ($host === '') {
            continue;
        }
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host); // quitar esquema
        $host = rtrim($host, '/');
        if (preg_match('#[/*:]#', $host)) {
            continue; // rechaza comodín, path y puerto
        }
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            continue; // exige dominio con TLD
        }
        $out[] = $host;
    }
    return array_values(array_unique($out));
}
```

2) **Registro de la opción** en `register_settings` (`:393-579`), después de
`alegra_connector_import_max_pages` (`:541-544`):
```php
register_setting('alegra_connector_settings', 'alegra_connector_allowed_image_hosts_extra', [
    'sanitize_callback' => [self::class, 'sanitize_image_hosts'],
    'default' => [],
]);
```

Archivo: `alegra-connector.php`.

3) `$defaults` (`:409-446`), agregar:
```php
'alegra_connector_allowed_image_hosts_extra' => [],
```
4) `$non_autoload` (`:452-470`), agregar:
```php
'alegra_connector_allowed_image_hosts_extra',
```

Archivo: `uninstall.php`.

5) Después de `delete_option('alegra_connector_products_import_cursor');` (`:102`), agregar:
```php
delete_option('alegra_connector_allowed_image_hosts_extra');
```
> T7.4 es el dueño de las 4 opciones nuevas del cambio; T5.3a agrega la suya para no dejarla colgada.

**Rama A / Rama B (G1, `T0.1`)**
- **Rama A** (el host real cae en `*.alegra.com`, lo más probable dado `:2152`): no se agrega ningún
  host por código; la opción queda vacía. Se documenta como falsa alarma en `CHANGELOG.md`.
- **Rama B** (host fuera): el comerciante carga el host observado por la **UI** (T5.3b); **nunca** `*`;
  `https` obligatorio (`is_allowed_image_url` ya lo exige). Se documenta en `CHANGELOG.md`/nota de
  release **sin** poner el host en el código. Prueba de regresión: `is_allowed_image_url($host_observado)`
  true; `http://…` y `*` false.
- La implementación de la opción es **la misma** en A y B; lo que cambia es si el comerciante la usa.
  **No cerrar la tarea sin el resultado de T0.1 registrado en `phase0-results.md`.**

**Resultado esperado**
- Sin la opción: `allowed_image_hosts() === ['alegra.com']` (sin regresión).
- `sanitize_image_hosts("https://cdn.example.com\n*\nfoo:8080")` → `['cdn.example.com']`.
- Con `allowed_image_hosts_extra = ['cdn.example.com']`:
  `is_allowed_image_url('https://cdn.example.com/x.png') === true`;
  `is_allowed_image_url('http://cdn.example.com/x.png') === false`;
  `is_allowed_image_url('https://evil.com/x.png') === false`.
- `sanitize_image_hosts('*') === []`, `sanitize_image_hosts('foo/bar') === []`,
  `sanitize_image_hosts('foo:8080') === []`.

**Dependencias**: **T0.1 (G1)** para el cierre de la rama.

**Trazabilidad**: REQ-IMG-04, NFR-07.

**Verificación** (runtime + source-scan):
```php
TestRunner::test('T28.56 the allowlist merges the extra hosts and rejects wildcards', function (): void {
    alegra_test_reset();
    TestRunner::assertSame(['alegra.com'], \Alegra\Connector\Sync\Products::allowed_image_hosts(), 'no regression without the option');

    $san = \Alegra\Connector\Admin\Admin_Dashboard::sanitize_image_hosts("https://cdn.example.com\n*\nfoo:8080\nfoo/bar\n");
    TestRunner::assertSame(['cdn.example.com'], $san, 'only the valid domain survives');

    update_option('alegra_connector_allowed_image_hosts_extra', ['cdn.example.com'], false);
    TestRunner::assertTrue(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://cdn.example.com/x.png'), 'extra host allowed over https');
    TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url('http://cdn.example.com/x.png'), 'http rejected');
    TestRunner::assertFalse(\Alegra\Connector\Sync\Products::is_allowed_image_url('https://evil.com/x.png'), 'unknown host rejected');
});
TestRunner::test('T28.57 the extra-host option is registered and cleaned up', function (): void {
    $admin = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringContains("'alegra_connector_allowed_image_hosts_extra'", $admin, 'must be registered');
    $uninstall = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'uninstall.php');
    TestRunner::assertStringContains("delete_option('alegra_connector_allowed_image_hosts_extra')", $uninstall, 'must be cleaned on uninstall');
    $main = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'alegra-connector.php');
    TestRunner::assertStringContains("'alegra_connector_allowed_image_hosts_extra' => []", $main, 'must be in $defaults');
});
```

**Prove-it-catches**: quitar el `preg_match('#[/*:]#', $host)` → el test de `*`/`foo:8080` falla.
Quitar el merge de la opción en `allowed_image_hosts()` → el test del host extra falla.

**Riesgo**: reintroducir SSRF ampliando la allowlist (R7). Guard: sanitizer rechaza `*`, `/`, `:`;
`https` obligatorio (`:2142`); sólo hosts verificados por el comerciante.

**Estimación**: S/M (2 h).

---

### T5.3b — UI de la allowlist (textarea en Avanzado) · BLOQUEADO(Fase 0.1 / G1)

**Objetivo**: que el comerciante pueda cargar hosts de imagen extra desde Ajustes → Avanzado, sin
tocar código.

**Descripción técnica**: la opción `alegra_connector_allowed_image_hosts_extra` (T5.3a) se edita como
textarea (un host por línea) en el tab Avanzado (`templates/admin-settings.php:252`), dentro del form
`options.php` (`:13`) que ya persiste las opciones del grupo `alegra_connector_settings`. Cubre
REQ-IMG-04.

**Desarrollo técnico**

Archivo: `templates/admin-settings.php`, en el tab Avanzado, después de la fila "Paginas maximas por
importacion" (`:312-315`):
```php
<!-- Extra image hosts (D5) -->
<tr><th><?php esc_html_e('Hosts de imágenes extra:','alegra-connector');?></th><td>
<textarea name="alegra_connector_allowed_image_hosts_extra" rows="4" class="large-text code" placeholder="cdn.ejemplo.com"><?php
    echo esc_textarea(implode("\n", (array) get_option('alegra_connector_allowed_image_hosts_extra', [])));
?></textarea>
<p class="description"><?php esc_html_e('Un host por línea, sin https:// (ej: cdn.ejemplo.com). Se permiten además de los hosts de Alegra. No se admite "*" ni http.','alegra-connector');?></p></td></tr>
```
> El form postea un string; `sanitize_image_hosts` (T5.3a) lo parte por líneas y lo guarda como array.
> Con el textarea vacío, `$_POST` trae `''` → el sanitizer devuelve `[]`.

**Resultado esperado**
- Ajustes → Avanzado muestra "Hosts de imágenes extra" con un host por línea.
- Guardar con `cdn.ejemplo.com` → la opción queda `['cdn.ejemplo.com']` y el import lo acepta.
- Guardar con `*` → se descarta (la opción queda `[]`).

**Dependencias**: T5.3a, T0.1 (G1) para el cierre de la rama.

**Trazabilidad**: REQ-IMG-04.

**Verificación** (source-scan + manual):
```php
TestRunner::test('T28.58 the extra-host textarea is rendered in the advanced tab', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains('name="alegra_connector_allowed_image_hosts_extra"', $tpl, 'the textarea must post the option');
    TestRunner::assertStringContains("get_option('alegra_connector_allowed_image_hosts_extra', [])", $tpl, 'the textarea must render the stored hosts');
});
```
Manual: Ajustes → Avanzado → cargar `cdn.ejemplo.com` → Guardar → verificar con
`wp option get alegra_connector_allowed_image_hosts_extra`.

**Prove-it-catches**: quitar el `name="alegra_connector_allowed_image_hosts_extra"` → el source-scan
falla.

**Riesgo**: que el textarea postee y el sanitizer no esté registrado (WP descartaría el valor). Guard:
T5.3a registra la opción antes; el orden de implementación es T5.3a → T5.3b.

**Estimación**: S (1 h).

---

## DoD Fase 5

- REQ-IMG-01/02/03/04, REQ-LOG-03, NFR-07 verdes.
- No queda ningún `.jpg` hardcodeado en los puntos de importación (`Products.php:2231`, `:2393`) ni el
  método muerto de `Admin_Dashboard` (`:108-144`).
- Los fallos de imagen se ven en pantalla con desglose; un import sin fallos no alarma.
- La allowlist no admite `*`, `/`, `:` ni `http`; sin la opción devuelve exactamente `['alegra.com']`.
- `bash scripts/exec-test.sh` verde con los tests `T28.51`–`T28.59` y sus `prove-it-catches` aplicados.

## Índice de tests nuevos (Fase 5)

| Test | Tipo | Archivo |
|---|---|---|
| `T28.51 extension_from_mime maps the documented mimes` | runtime (puro) | `scripts/exec-test.php` |
| `T28.52 no hardcoded .jpg remains in the live import points` | source-scan | `scripts/exec-test.php` |
| `T28.53 the dead Admin_Dashboard::import_product_image is gone` | source-scan | `scripts/exec-test.php` |
| `T28.54 image_stats counts blocked and download failures` | runtime | `scripts/exec-test.php` |
| `T28.55 import_from_alegra exposes image stats and the page payload merges them` | runtime | `scripts/exec-test.php` |
| `T28.56 the allowlist merges the extra hosts and rejects wildcards` | runtime | `scripts/exec-test.php` |
| `T28.57 the extra-host option is registered and cleaned up` | source-scan | `scripts/exec-test.php` |
| `T28.58 the extra-host textarea is rendered in the advanced tab` | source-scan | `scripts/exec-test.php` |
| `T28.59 the page payload reflects blocked images (moved from Fase 3 T28.312)` | runtime | `scripts/exec-test.php` |

## Trazabilidad tarea → requerimiento

| Requerimiento | Tareas |
|---|---|
| REQ-IMG-01 | T5.2a, T5.2b |
| REQ-IMG-02 | T5.1a, T5.1b |
| REQ-IMG-03 | T5.2a, T5.2b |
| REQ-IMG-04 | T5.3a, T5.3b |
| REQ-LOG-03 | T5.2a |
| NFR-07 | T5.3a |
