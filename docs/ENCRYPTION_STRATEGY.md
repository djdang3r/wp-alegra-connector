# Estrategia de Encriptación del Token de Alegra

**Estado:** Diferido a v2.1.0+ (no implementado en v2.0.0)

## Contexto

El plugin almacena el token de API de Alegra en `wp_options` sin encriptar:
- `alegra_connector_token` (línea 222 de `Admin_Dashboard.php`)
- `alegra_connector_webhook_secret` (línea 1597 de `Admin_Dashboard.php`)

Existe una clase `includes/Encryption.php` que implementa AES-256-CBC pero **NO SE USA** actualmente.

## Por qué se difiere

Reportado por el usuario: **"Daba problemas al conectar con alegra"** cuando se intentó encriptar previamente.

Causas probables:
1. **WordPress salts diferentes** entre entornos (local/staging/producción) hacen que el mismo valor encriptado no se pueda desencriptar en otro entorno
2. **Base64 + IV aleatorio** funcionan pero son frágiles si hay corrupción parcial
3. **`hash_hmac` con `LOGGED_IN_KEY` etc.** no garantiza unicidad en multisite

## Mitigaciones inmediatas (v2.0.0)

Mientras tanto, se aplican estas mitigaciones:

1. **No se loguea el token en ningún log** (verificado en A.X1)
2. **No se muestra el token en la UI** (campo `type="password"` con toggle)
3. **Documentación** de que el token está en plaintext (este archivo)
4. **No se almacena en logs** (verificado en `Logger.php`)

## Diseño propuesto (para v2.1.0)

### Algoritmo: `sodium_crypto_secretbox` (AEAD)

```php
// Encrypt
$key = sodium_crypto_generichash(
    wp_salt('auth') . NONCE_SALT . 'alegra_v2',
    '',
    SODIUM_CRYPTO_SECRETBOX_KEYBYTES // 32 bytes
);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24 bytes
$ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
$encrypted = 'v3:' . base64_encode($nonce . $ciphertext);

// Decrypt
if (strpos($encrypted, 'v3:') === 0) {
    $data = base64_decode(substr($encrypted, 3), true);
    $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = sodium_crypto_generichash(wp_salt('auth') . NONCE_SALT . 'alegra_v2', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
}
```

### Ventajas sobre AES-256-CBC
- AEAD integrado (autenticación + encriptación en una operación)
- No requiere padding manual
- Menos código = menos bugs
- Mejor performance (nativo en PHP 7.2+)

### Plan de migración gradual

1. **v2.1.0:** Agregar soporte de lectura (si tiene prefijo `v3:` desencripta, si no plaintext)
2. **v2.2.0:** Agregar escritura (al guardar, encriptar)
3. **v2.3.0:** Script de migración one-time para tokens existentes
4. **v3.0.0:** Eliminar soporte plaintext

### Fallback transparente

Si desencriptación falla:
- Mostrar mensaje claro al usuario: "Re-autenticación requerida"
- NO romper la conexión permanentemente
- Ofrecer botón "Re-conectar" en Configuración

### Tests requeridos antes de implementar

1. **Roundtrip test:** Encriptar y desencriptar debe ser idéntico byte-a-byte
2. **Cross-environment test:** Encriptar en local, desencriptar en staging con mismas salts
3. **Corruption test:** Modificar 1 byte y verificar que falla limpiamente
4. **Migration test:** Token encriptado con v1 debe poder migrarse a v3 sin perder conexión

## Decisión de implementación

**No implementar en v2.0.0.** Esperar a que el usuario reporte:
- Problemas específicos con el plaintext (ej: dump de BD expuesto)
- Necesidad de cumplir con PCI DSS / SOC2 / etc.

Por ahora, mitigaciones inmediatas son suficientes.
