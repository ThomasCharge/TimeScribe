<?php

declare(strict_types=1);

/**
 * Sync Laravel-style PHP language files.
 *
 * Defaults:
 *   - Source locale: lang/en
 *   - Mode: append missing keys to the top of every other locale file
 *   - Missing values: source English value
 *
 * Usage:
 *   php scripts/sync-lang.php --dry-run
 *   php scripts/sync-lang.php
 *
 *   php scripts/sync-lang.php --translate --manage
 *   php scripts/sync-lang.php --sort
 *
 * Notes:
 *   - Existing translated values are preserved.
 *   - Missing entries are added at the top.
 *   - --sort adds missing entries, then sorts alphabetically by key.
 *   - --translate uses local LibreTranslate.
 *   - If translation fails, the source value is prefixed with [ENG].
 *   - Placeholder-only strings like ":time" are not tagged.
 *   - Laravel placeholders like ":time" are protected during translation.
 *   - This rewrites PHP arrays, so comments inside returned arrays are not preserved.
 * 
 * LibreTranslate setup:
 *
 *   brew install python pipx
 *   pipx ensurepath
 *   pipx install libretranslate
 *
 *   ARGOSPM="$HOME/.local/pipx/venvs/libretranslate/bin/argospm"
 *   "$ARGOSPM" update
 *   "$ARGOSPM" install translate-en_da translate-en_de translate-en_fr translate-en_it translate-en_pl translate-en_pt translate-en_zh
 */

// =====================
// CONFIG
// =====================

const DEFAULT_SOURCE_LOCALE = 'en';
const DEFAULT_LANG_DIR = __DIR__.'/../lang';

const DEFAULT_LIBRETRANSLATE_URL = 'http://127.0.0.1:5000';
const DEFAULT_LIBRETRANSLATE_COMMAND = 'libretranslate --host 127.0.0.1 --port 5000';

const DEFAULT_FALLBACK_MARKER = '[ENG] ';

/**
 * Files that should not be touched by default.
 *
 * region.php has useful comments/grouping, and this script rewrites PHP arrays,
 * so touching it would remove those comments.
 */
const DEFAULT_SKIP_FILES = [
    'region.php',
];

/**
 * Dry-run does not call LibreTranslate by default.
 * Set true if you want dry-run to test real translations.
 */
const ALLOW_TRANSLATION_DURING_DRY_RUN = false;

/**
 * Folder name => LibreTranslate language code.
 * Add aliases here if your folder names differ.
 */
const LOCALE_MAP = [
    'ar' => 'ar',
    'az' => 'az',
    'bg' => 'bg',
    'bn' => 'bn',
    'ca' => 'ca',
    'cs' => 'cs',
    'da' => 'da',
    'de' => 'de',
    'el' => 'el',
    'en' => 'en',
    'en_GB' => 'en',
    'en_US' => 'en',
    'eo' => 'eo',
    'es' => 'es',
    'et' => 'et',
    'fa' => 'fa',
    'fi' => 'fi',
    'fr' => 'fr',
    'ga' => 'ga',
    'he' => 'he',
    'hi' => 'hi',
    'hu' => 'hu',
    'id' => 'id',
    'it' => 'it',
    'ja' => 'ja',
    'ko' => 'ko',
    'lt' => 'lt',
    'lv' => 'lv',
    'ms' => 'ms',
    'nb' => 'nb',
    'nl' => 'nl',
    'pl' => 'pl',
    'pt' => 'pt',
    'pt_BR' => 'pt',
    'pt_PT' => 'pt',
    'ro' => 'ro',
    'ru' => 'ru',
    'sk' => 'sk',
    'sl' => 'sl',
    'sv' => 'sv',
    'th' => 'th',
    'tl' => 'tl',
    'tr' => 'tr',
    'uk' => 'uk',
    'ur' => 'ur',
    'zh' => 'zh',
    'zh_CN' => 'zh',
    'zh_TW' => 'zh',
];

// =====================
// START
// =====================

$options = getopt('', [
    'sort',
    'translate',
    'manage',
    'dry-run',
    'include-skipped',
    'source::',
    'lang-dir::',
    'missing-value::',
    'libretranslate-url::',
    'libretranslate-command::',
    'fallback-marker::',
    'help',
]);

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$sort = array_key_exists('sort', $options);
$translate = array_key_exists('translate', $options);
$manageLibreTranslate = array_key_exists('manage', $options);
$dryRun = array_key_exists('dry-run', $options);
$includeSkipped = array_key_exists('include-skipped', $options);

$sourceLocale = $options['source'] ?? DEFAULT_SOURCE_LOCALE;
$langDir = rtrim($options['lang-dir'] ?? DEFAULT_LANG_DIR, DIRECTORY_SEPARATOR);
$missingValueMode = $options['missing-value'] ?? 'source';
$libreTranslateUrl = rtrim($options['libretranslate-url'] ?? DEFAULT_LIBRETRANSLATE_URL, '/');
$libreTranslateCommand = $options['libretranslate-command'] ?? DEFAULT_LIBRETRANSLATE_COMMAND;
$fallbackMarker = $options['fallback-marker'] ?? DEFAULT_FALLBACK_MARKER;

if (! in_array($missingValueMode, ['source', 'key', 'blank'], true)) {
    fail('Invalid --missing-value. Use source, key, or blank.');
}

if (! is_dir($langDir)) {
    fail("Language directory not found: {$langDir}");
}

$sourceDir = $langDir.DIRECTORY_SEPARATOR.$sourceLocale;

if (! is_dir($sourceDir)) {
    fail("Source language directory not found: {$sourceDir}");
}

$sourceFiles = glob($sourceDir.DIRECTORY_SEPARATOR.'*.php') ?: [];

if ($sourceFiles === []) {
    fail("No PHP language files found in source directory: {$sourceDir}");
}

$targetDirs = getTargetLocaleDirs($langDir, $sourceLocale);

if ($targetDirs === []) {
    fail("No target language directories found in: {$langDir}");
}

$syncDirs = $sort
    ? array_merge([$sourceDir], $targetDirs)
    : $targetDirs;

$shouldTranslate = $translate && (! $dryRun || ALLOW_TRANSLATION_DURING_DRY_RUN);
$managedProcess = null;

if ($translate && $dryRun && ! ALLOW_TRANSLATION_DURING_DRY_RUN) {
    echo "Dry run: translation calls are disabled. Missing translations will show as {$fallbackMarker}fallbacks.".PHP_EOL.PHP_EOL;
}

if ($shouldTranslate && ! isLibreTranslateAvailable($libreTranslateUrl)) {
    if ($manageLibreTranslate) {
        echo "LibreTranslate not reachable at {$libreTranslateUrl}.".PHP_EOL;
        echo "Starting managed instance...".PHP_EOL;
        echo "Log: ".getLibreTranslateLogPath().PHP_EOL.PHP_EOL;

        $managedProcess = startLibreTranslate($libreTranslateCommand);

        if (! waitForLibreTranslate($libreTranslateUrl, 180)) {
            stopManagedProcess($managedProcess);
            $managedProcess = null;

            echo PHP_EOL;
            echo "Could not start LibreTranslate.".PHP_EOL;
            echo "Check log: ".getLibreTranslateLogPath().PHP_EOL;
            echo "Missing translations will use {$fallbackMarker}fallbacks.".PHP_EOL.PHP_EOL;

            $shouldTranslate = false;
        } else {
            echo PHP_EOL."LibreTranslate is running.".PHP_EOL.PHP_EOL;
        }
    } else {
        echo "LibreTranslate is not reachable at {$libreTranslateUrl}.".PHP_EOL;
        echo "Start it manually with: libretranslate".PHP_EOL;
        echo "Or run this script with: --translate --manage".PHP_EOL;
        echo "Missing translations will use {$fallbackMarker}fallbacks.".PHP_EOL.PHP_EOL;

        $shouldTranslate = false;
    }
}

try {
    runSync(
        sourceFiles: $sourceFiles,
        targetDirs: $syncDirs,
        sourceLocale: $sourceLocale,
        sort: $sort,
        missingValueMode: $missingValueMode,
        translate: $translate,
        shouldTranslate: $shouldTranslate,
        libreTranslateUrl: $libreTranslateUrl,
        fallbackMarker: $fallbackMarker,
        dryRun: $dryRun,
        includeSkipped: $includeSkipped
    );
} finally {
    if ($managedProcess !== null) {
        echo PHP_EOL."Stopping managed LibreTranslate instance...".PHP_EOL;
        stopManagedProcess($managedProcess);
    }
}

// =====================
// MAIN
// =====================

function runSync(
    array $sourceFiles,
    array $targetDirs,
    string $sourceLocale,
    bool $sort,
    string $missingValueMode,
    bool $translate,
    bool $shouldTranslate,
    string $libreTranslateUrl,
    string $fallbackMarker,
    bool $dryRun,
    bool $includeSkipped
): void {
    $totals = [
        'filesChecked' => 0,
        'filesChanged' => 0,
        'missingKeys' => 0,
        'translated' => 0,
        'fallbacks' => 0,
        'skippedTranslations' => 0,
    ];

    foreach ($targetDirs as $targetDir) {
        $targetLocale = basename($targetDir);

        foreach ($sourceFiles as $sourceFile) {
            $fileName = basename($sourceFile);
            $targetFile = $targetDir.DIRECTORY_SEPARATOR.$fileName;
            if (! $includeSkipped && in_array($fileName, DEFAULT_SKIP_FILES, true)) {
                continue;
            }

            $totals['filesChecked']++;

            $sourceArray = loadLangFile($sourceFile);
            $targetArray = is_file($targetFile)
                ? loadLangFile($targetFile)
                : [];

            $stats = [
                'missingKeys' => [],
                'translated' => 0,
                'fallbacks' => 0,
                'skippedTranslations' => 0,
            ];

            $context = [
                'missingValueMode' => $missingValueMode,
                'translate' => $translate,
                'shouldTranslate' => $shouldTranslate,
                'libreTranslateUrl' => $libreTranslateUrl,
                'sourceLocale' => $sourceLocale,
                'targetLocale' => $targetLocale,
                'fallbackMarker' => $fallbackMarker,
            ];

            $syncedArray = syncArray($sourceArray, $targetArray, $context, $stats);

            if ($sort) {
                $syncedArray = sortArrayByKeyRecursive($syncedArray);
            }

            if (! $sort && count($stats['missingKeys']) === 0) {
                continue;
            }

            $newContent = buildLangFileContent($syncedArray);
            $oldContent = is_file($targetFile)
                ? file_get_contents($targetFile)
                : false;

            if ($oldContent === $newContent) {
                continue;
            }

            $totals['filesChanged']++;
            $totals['missingKeys'] += count($stats['missingKeys']);
            $totals['translated'] += $stats['translated'];
            $totals['fallbacks'] += $stats['fallbacks'];
            $totals['skippedTranslations'] += $stats['skippedTranslations'];

            reportChange(
                targetLocale: $targetLocale,
                fileName: $fileName,
                stats: $stats,
                sort: $sort,
                dryRun: $dryRun,
                fileExists: is_file($targetFile)
            );

            if (! $dryRun) {
                ensureDirectoryExists(dirname($targetFile));
                file_put_contents($targetFile, $newContent);
            }
        }
    }

    echo PHP_EOL;

    echo $dryRun ? 'Dry run complete. ' : 'Done. ';
    echo "{$totals['filesChanged']} of {$totals['filesChecked']} file(s) changed. ";
    echo "{$totals['missingKeys']} missing key(s). ";
    echo "{$totals['translated']} translated. ";
    echo "{$totals['fallbacks']} fallback(s). ";
    echo "{$totals['skippedTranslations']} skipped.".PHP_EOL;
}

// =====================
// SYNC
// =====================

function syncArray(array $source, array $target, array $context, array &$stats, string $prefix = ''): array
{
    $missing = [];

    foreach ($source as $key => $sourceValue) {
        $keyPath = $prefix === ''
            ? (string) $key
            : $prefix.'.'.$key;

        if (! array_key_exists($key, $target)) {
            $missing[$key] = buildMissingValue($sourceValue, $key, $context, $stats, $keyPath);
            collectMissingKeys($sourceValue, $keyPath, $stats['missingKeys']);
            continue;
        }

        if (is_array($sourceValue) && is_array($target[$key])) {
            $target[$key] = syncArray($sourceValue, $target[$key], $context, $stats, $keyPath);
        }
    }

    return $missing + $target;
}

function buildMissingValue(mixed $sourceValue, string|int $key, array $context, array &$stats, string $keyPath): mixed
{
    if (is_array($sourceValue)) {
        $result = [];

        foreach ($sourceValue as $childKey => $childValue) {
            $result[$childKey] = buildMissingValue(
                sourceValue: $childValue,
                key: $childKey,
                context: $context,
                stats: $stats,
                keyPath: $keyPath.'.'.$childKey
            );
        }

        return $result;
    }

    if (! is_string($sourceValue)) {
        return $sourceValue;
    }

    if ($context['translate']) {
        $translated = translateWithLibreTranslateIfPossible($sourceValue, $context, $stats);

        if ($translated !== null && $translated !== '') {
            return $translated;
        }

        $stats['fallbacks']++;

        return buildFallbackText($sourceValue, $context['fallbackMarker']);
    }

    if ($context['missingValueMode'] === 'blank') {
        return '';
    }

    if ($context['missingValueMode'] === 'key') {
        return (string) $key;
    }

    return $sourceValue;
}

function collectMissingKeys(mixed $sourceValue, string $keyPath, array &$missingKeys): void
{
    if (! is_array($sourceValue)) {
        $missingKeys[] = $keyPath;
        return;
    }

    if ($sourceValue === []) {
        $missingKeys[] = $keyPath;
        return;
    }

    foreach ($sourceValue as $childKey => $childValue) {
        collectMissingKeys($childValue, $keyPath.'.'.$childKey, $missingKeys);
    }
}

// =====================
// LIBRETRANSLATE
// =====================

function translateWithLibreTranslateIfPossible(string $text, array $context, array &$stats): ?string
{
    if (shouldSkipTranslation($text)) {
        $stats['skippedTranslations']++;
        return $text;
    }

    if (! $context['shouldTranslate']) {
        return null;
    }

    $sourceLang = mapLocale($context['sourceLocale']);
    $targetLang = mapLocale($context['targetLocale']);

    if ($sourceLang === null || $targetLang === null) {
        $stats['skippedTranslations']++;
        return null;
    }

    if ($sourceLang === $targetLang) {
        $stats['skippedTranslations']++;
        return $text;
    }

    $protected = protectLaravelPlaceholders($text);

    $response = httpPostForm(
        $context['libreTranslateUrl'].'/translate',
        [
            'q' => $protected['text'],
            'source' => $sourceLang,
            'target' => $targetLang,
            'format' => 'html',
        ]
    );

    if ($response['status'] < 200 || $response['status'] >= 300) {
        fwrite(
            STDERR,
            "LibreTranslate failed for {$context['targetLocale']} with HTTP {$response['status']}: "
            .trim($response['body']).PHP_EOL
        );

        return null;
    }

    $decoded = json_decode($response['body'], true);

    if (! is_array($decoded) || ! isset($decoded['translatedText'])) {
        fwrite(STDERR, "LibreTranslate returned an unexpected response for {$context['targetLocale']}.".PHP_EOL);
        return null;
    }

    $translated = (string) $decoded['translatedText'];
    $translated = html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $translated = restoreLaravelPlaceholders($translated, $protected['placeholders']);

    if ($translated === '') {
        return null;
    }

    $stats['translated']++;

    return $translated;
}

function isLibreTranslateAvailable(string $baseUrl): bool
{
    $response = httpGet(rtrim($baseUrl, '/').'/languages');

    return $response['status'] >= 200 && $response['status'] < 300;
}

function waitForLibreTranslate(string $baseUrl, int $timeoutSeconds): bool
{
    $start = time();
    $lastMessageAt = 0;

    while (time() - $start < $timeoutSeconds) {
        if (isLibreTranslateAvailable($baseUrl)) {
            return true;
        }

        if (time() - $lastMessageAt >= 5) {
            $elapsed = time() - $start;
            echo "Waiting for LibreTranslate... {$elapsed}s".PHP_EOL;
            $lastMessageAt = time();
        }

        usleep(500000);
    }

    return false;
}

function startLibreTranslate(string $command): mixed
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', getLibreTranslateLogPath(), 'a'],
        2 => ['file', getLibreTranslateLogPath(), 'a'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);

    if (! is_resource($process)) {
        fail('Could not start LibreTranslate process.');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    return $process;
}

function stopManagedProcess(mixed $process): void
{
    if (! is_resource($process)) {
        return;
    }

    proc_terminate($process);
    proc_close($process);
}

function getLibreTranslateLogPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-lang-libretranslate.log';
}

function mapLocale(string $locale): ?string
{
    if (array_key_exists($locale, LOCALE_MAP)) {
        return LOCALE_MAP[$locale];
    }

    $normalised = str_replace('-', '_', $locale);

    if (array_key_exists($normalised, LOCALE_MAP)) {
        return LOCALE_MAP[$normalised];
    }

    $base = explode('_', $normalised)[0];

    return LOCALE_MAP[$base] ?? null;
}

function shouldSkipTranslation(string $text): bool
{
    $trimmed = trim($text);

    return $trimmed === '' || isPlaceholderOnlyText($trimmed);
}

function isPlaceholderOnlyText(string $text): bool
{
    return preg_match('/^:[A-Za-z_][A-Za-z0-9_]*$/', $text) === 1;
}

function buildFallbackText(string $text, string $fallbackMarker): string
{
    if (shouldSkipTranslation($text)) {
        return $text;
    }

    if (str_starts_with($text, $fallbackMarker)) {
        return $text;
    }

    return $fallbackMarker.$text;
}

function protectLaravelPlaceholders(string $text): array
{
    $placeholders = [];
    $index = 0;

    $protectedText = preg_replace_callback(
        '/(?<![A-Za-z0-9_]):[A-Za-z_][A-Za-z0-9_]*/',
        static function (array $matches) use (&$placeholders, &$index): string {
            $id = (string) $index;
            $placeholders[$id] = $matches[0];
            $index++;

            return '<span translate="no" data-ph="'.$id.'"></span>';
        },
        $text
    );

    return [
        'text' => $protectedText ?? $text,
        'placeholders' => $placeholders,
    ];
}

function restoreLaravelPlaceholders(string $text, array $placeholders): string
{
    foreach ($placeholders as $id => $placeholder) {
        $pattern = '/<span\s+translate=["\']no["\']\s+data-ph=["\']'.preg_quote((string) $id, '/').'["\']\s*><\/span>/';
        $text = preg_replace($pattern, $placeholder, $text) ?? $text;
    }

    return $text;
}

// =====================
// HTTP, no curl
// =====================

function httpGet(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    return [
        'status' => parseHttpStatus($http_response_header ?? []),
        'body' => $body === false ? '' : $body,
    ];
}

function httpPostForm(string $url, array $params): array
{
    $body = http_build_query($params);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: '.strlen($body),
            ]),
            'content' => $body,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);

    return [
        'status' => parseHttpStatus($http_response_header ?? []),
        'body' => $responseBody === false ? '' : $responseBody,
    ];
}

function parseHttpStatus(array $headers): int
{
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

// =====================
// FILES / ARRAYS
// =====================

function sortArrayByKeyRecursive(array $array): array
{
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = sortArrayByKeyRecursive($value);
        }
    }

    if (! isListArray($array)) {
        uksort($array, static function (string|int $left, string|int $right): int {
            return strnatcasecmp((string) $left, (string) $right);
        });
    }

    return $array;
}

function loadLangFile(string $path): array
{
    $value = require $path;

    if (! is_array($value)) {
        fail("Language file did not return an array: {$path}");
    }

    return $value;
}

function buildLangFileContent(array $array): string
{
    return "<?php\n\n"
        ."declare(strict_types=1);\n\n"
        .'return '.exportPhpArray($array).";\n";
}

function exportPhpArray(array $array, int $level = 1): string
{
    if ($array === []) {
        return '[]';
    }

    $indent = str_repeat('    ', $level);
    $closingIndent = str_repeat('    ', $level - 1);
    $isList = isListArray($array);

    $lines = ['['];

    foreach ($array as $key => $value) {
        $exportedValue = is_array($value)
            ? exportPhpArray($value, $level + 1)
            : var_export($value, true);

        if ($isList) {
            $lines[] = "{$indent}{$exportedValue},";
            continue;
        }

        $exportedKey = var_export($key, true);
        $lines[] = "{$indent}{$exportedKey} => {$exportedValue},";
    }

    $lines[] = "{$closingIndent}]";

    return implode("\n", $lines);
}

function isListArray(array $array): bool
{
    if ($array === []) {
        return true;
    }

    return array_keys($array) === range(0, count($array) - 1);
}

function getTargetLocaleDirs(string $langDir, string $sourceLocale): array
{
    $dirs = glob($langDir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];

    return array_values(array_filter(
        $dirs,
        static fn (string $dir): bool => basename($dir) !== $sourceLocale
    ));
}

function ensureDirectoryExists(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        fail("Could not create directory: {$dir}");
    }
}

// =====================
// OUTPUT
// =====================

function reportChange(
    string $targetLocale,
    string $fileName,
    array $stats,
    bool $sort,
    bool $dryRun,
    bool $fileExists
): void {
    $prefix = $dryRun ? '[dry-run] ' : '';
    $action = $fileExists ? 'update' : 'create';

    echo "{$prefix}{$action} {$targetLocale}/{$fileName}";

    if (count($stats['missingKeys']) > 0) {
        echo ' - add '.count($stats['missingKeys']).' missing key(s)';
    }

    if ($stats['translated'] > 0) {
        echo ' - '.$stats['translated'].' translated';
    }

    if ($stats['fallbacks'] > 0) {
        echo ' - '.$stats['fallbacks'].' fallback(s)';
    }

    if ($stats['skippedTranslations'] > 0) {
        echo ' - '.$stats['skippedTranslations'].' skipped';
    }

    if ($sort) {
        echo ' - sort';
    }

    echo PHP_EOL;

    foreach (array_slice($stats['missingKeys'], 0, 20) as $missingKey) {
        echo "    + {$missingKey}".PHP_EOL;
    }

    if (count($stats['missingKeys']) > 20) {
        echo '    ... and '.(count($stats['missingKeys']) - 20).' more'.PHP_EOL;
    }
}

function printUsage(): void
{
    echo <<<'TEXT'
Usage:
  php scripts/sync-lang.php --dry-run
  php scripts/sync-lang.php

  php scripts/sync-lang.php --translate --manage
  php scripts/sync-lang.php --sort

Options:
  --sort
      Add missing entries, then sort alphabetically by key.

  --translate
      Translate missing strings with local LibreTranslate.
      If translation fails, source text is prefixed with [ENG].

  --manage
      Start and stop LibreTranslate for this run.

  --dry-run
      Show what would change without writing files.
      Translation is not called during dry-run unless ALLOW_TRANSLATION_DURING_DRY_RUN is true.

  --source=en
      Source locale folder under lang/.

  --lang-dir=/path/to/lang
      Language directory. Defaults to ./lang relative to project root.

  --missing-value=source|key|blank
      Used when --translate is not used.

  --libretranslate-url=http://127.0.0.1:5000
      Local LibreTranslate base URL.

  --libretranslate-command="libretranslate --host 127.0.0.1 --port 5000"
      Command used by --manage.

  --fallback-marker="[ENG] "
      Prefix used when translation fails.

TEXT;
}

function fail(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
