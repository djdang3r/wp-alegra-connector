<?php
/**
 * make-pot.php — dependency-free gettext template (.pot) extractor.
 *
 * Scans the plugin's PHP and JS sources for the WordPress i18n functions and
 * writes languages/alegra-connector.pot with msgid/msgctxt/msgid_plural and
 * file:line references.
 *
 * Why hand-rolled: the release host has neither WP-CLI nor gettext's xgettext,
 * so `wp i18n make-pot` / `msgcat` cannot run at build time. This script only
 * needs PHP, which the build already requires for the smoke/exec gates.
 *
 * Usage: php scripts/make-pot.php [output.pot]
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$out  = $argv[1] ?? ($root . '/languages/alegra-connector.pot');

$text_domain = 'alegra-connector';
$version      = '2.4.2';
$package      = 'Alegra Connector';

$php_functions = [
    '__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e',
    '_x', '_ex', '_n', '_nx', 'esc_attr_x', 'esc_html_x',
];

$skip_dirs = [
    '/.git', '/releases', '/node_modules', '/temp_pkg', '/.omo', '/scripts',
];

$entries = [];

/**
 * Normalize a PHP/JS string literal into its runtime value.
 */
function unquote(string $raw, string $quote): string
{
    $inner = substr($raw, 1, -1);
    if ($quote === "'") {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }
    return stripcslashes($inner);
}

/**
 * Add/merge an extracted entry.
 */
function add_entry(array &$entries, string $msgid, ?string $plural, ?string $context, string $ref): void
{
    if ($msgid === '') {
        return;
    }
    $key = ($context ?? '') . "\x04" . $msgid;
    if (!isset($entries[$key])) {
        $entries[$key] = [
            'msgid'   => $msgid,
            'plural'  => $plural,
            'context' => $context,
            'refs'    => [],
        ];
    }
    if ($plural !== null && $entries[$key]['plural'] === null) {
        $entries[$key]['plural'] = $plural;
    }
    $entries[$key]['refs'][$ref] = true;
}

/**
 * Extract from a PHP file using the tokenizer (robust against multiline args,
 * concatenation is intentionally NOT followed — WP requires literal strings).
 */
function extract_php(string $path, string $rel, array $functions, array &$entries): void
{
    $code = file_get_contents($path);
    if ($code === false) {
        return;
    }
    $tokens = @token_get_all($code);
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = $token[1];
        if (!in_array($name, $functions, true)) {
            continue;
        }
        // Next significant token must be "(".
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        $line = $token[2];
        // Collect the first up-to-three string literal arguments.
        $args = [];
        $depth = 0;
        for ($k = $j; $k < $count; $k++) {
            $t = $tokens[$k];
            if ($t === '(') {
                $depth++;
                continue;
            }
            if ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $args[] = unquote($t[1], $t[1][0]);
            } elseif ($t[0] === T_DNUMBER || $t[0] === T_LNUMBER) {
                $args[] = $t[1];
            }
        }
        if ($args === []) {
            continue;
        }

        $msgid = $args[0];
        $plural = null;
        $context = null;
        if (in_array($name, ['_n', '_nx'], true)) {
            $plural = $args[1] ?? null;
        }
        if (in_array($name, ['_x', '_ex', '_nx', 'esc_attr_x', 'esc_html_x'], true)) {
            $context = $args[1] ?? null;
            if (in_array($name, ['_nx'], true)) {
                $context = $args[2] ?? null;
            }
        }
        add_entry($entries, $msgid, $plural, $context, $rel . ':' . $line);
    }
}

/**
 * Extract from a JS file with a regex (covers the same WP functions).
 */
function extract_js(string $path, string $rel, array $functions, array &$entries): void
{
    $code = file_get_contents($path);
    if ($code === false) {
        return;
    }
    $names = implode('|', array_map('preg_quote', $functions));
    $pattern = '/\b(?:' . $names . ')\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1'
        . '(?:\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3)?'
        . '(?:\s*,\s*([\'"])((?:\\\\.|(?!\5).)*)\5)?/s';

    if (!preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
        return;
    }

    foreach ($matches[0] as $idx => $match) {
        $offset = $match[1];
        $line = substr_count(substr($code, 0, $offset), "\n") + 1;
        $quote = $matches[1][$idx][0];
        $msgid = unquote($quote . $matches[2][$idx][0] . $quote, $quote);
        $second = isset($matches[4][$idx]) && $matches[4][$idx][0] !== ''
            ? unquote($matches[3][$idx][0] . $matches[4][$idx][0] . $matches[3][$idx][0], $matches[3][$idx][0])
            : null;
        $third  = isset($matches[6][$idx]) && $matches[6][$idx][0] !== ''
            ? unquote($matches[5][$idx][0] . $matches[6][$idx][0] . $matches[5][$idx][0], $matches[5][$idx][0])
            : null;
        $fn = '';
        if (preg_match('/\b(' . $names . ')\s*\(/', $match[0], $fm)) {
            $fn = $fm[1];
        }
        $plural = in_array($fn, ['_n', '_nx'], true) ? $second : null;
        $context = in_array($fn, ['_x', '_ex', '_nx', 'esc_attr_x', 'esc_html_x'], true)
            ? ($fn === '_nx' ? $third : $second)
            : null;
        add_entry($entries, $msgid, $plural, $context, $rel . ':' . $line);
    }
}

/**
 * Recursively collect source files.
 */
function collect_files(string $dir, array $skip_dirs, string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        // Normalize Windows separators so the .pot references are canonical
        // (forward slashes) regardless of the build host.
        $rel = ltrim(str_replace('\\', '/', str_replace($root, '', $path)), '/');
        foreach ($skip_dirs as $skip) {
            if (strpos('/' . $rel, $skip . '/') !== false) {
                continue 2;
            }
        }
        $ext = strtolower($file->getExtension());
        if ($ext === 'php' || $ext === 'js') {
            $files[$rel] = $path;
        }
    }
    ksort($files);
    return $files;
}

$files = collect_files($root, $skip_dirs, $root);
foreach ($files as $rel => $path) {
    if (substr($rel, -4) === '.php') {
        extract_php($path, $rel, $php_functions, $entries);
    } else {
        extract_js($path, $rel, $php_functions, $entries);
    }
}

// Deterministic order by msgid (then context).
uksort($entries, static function (string $a, string $b): int {
    return strcmp($a, $b);
});

$escape = static function (string $value): string {
    return str_replace(
        ['\\', '"', "\n", "\r", "\t"],
        ['\\\\', '\\"', '\\n', '\\r', '\\t'],
        $value
    );
};

$date = gmdate('Y-m-d H:iO');
$header = <<<POT
# Copyright (C) 2026 {$package}
# This file is distributed under the GPL v2 or later.
msgid ""
msgstr ""
"Project-Id-Version: {$package} {$version}\\n"
"Report-Msgid-Bugs-To: https://github.com/djdang3r/wp-alegra-connector/issues\\n"
"POT-Creation-Date: {$date}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: {$text_domain}\\n"
"X-Generator: scripts/make-pot.php\\n"

POT;

$body = '';
$msgid_count = 0;
foreach ($entries as $entry) {
    $refs = array_keys($entry['refs']);
    sort($refs);
    foreach ($refs as $ref) {
        $body .= '#: ' . $ref . "\n";
    }
    if ($entry['context'] !== null) {
        $body .= 'msgctxt "' . $escape((string) $entry['context']) . "\"\n";
    }
    $body .= 'msgid "' . $escape((string) $entry['msgid']) . "\"\n";
    if ($entry['plural'] !== null) {
        $body .= 'msgid_plural "' . $escape((string) $entry['plural']) . "\"\n";
        $body .= "msgstr[0] \"\"\n";
        $body .= "msgstr[1] \"\"\n";
    } else {
        $body .= "msgstr \"\"\n";
    }
    $body .= "\n";
    $msgid_count++;
}

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0755, true);
}
file_put_contents($out, $header . $body);

echo "Wrote {$out} ({$msgid_count} msgid entries)\n";
