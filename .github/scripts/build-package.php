<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/*
 * Builds the installable package of a Quince module.
 *
 * Usage: php .github/scripts/build-package.php <module_name> <version> <output_dir>
 *
 * Run from the repository root, after `composer install --no-dev`. The script:
 *  1. checks that <version> matches `$this->version` in the module class,
 *  2. copies the module into <output_dir>/<module_name>/, leaving out repository files,
 *  3. renders config.xml and one config_<iso>.xml per translated language,
 *  4. puts an index.php stub into every directory of the package,
 *  5. denies web access to vendor/ with an .htaccess file.
 *
 * Nothing here needs PrestaShop: the values come from the module class and its
 * translation files, the same sources PrestaShop itself would read.
 */

[$script, $module, $version, $outDir] = array_pad($argv, 4, null);

if ($module === null || $version === null || $outDir === null) {
    fwrite(STDERR, "Usage: php build-package.php <module_name> <version> <output_dir>\n");
    exit(1);
}

$root = getcwd();
$mainFile = $root . '/' . $module . '.php';

if (!is_file($mainFile)) {
    fail("Main file {$module}.php not found in " . $root);
}

$source = (string) file_get_contents($mainFile);

// ---------------------------------------------------------------- 1. version

$classVersion = match1('/\$this->version\s*=\s*\'([^\']+)\'/', $source);

if ($classVersion !== $version) {
    fail("Tag version {$version} does not match \$this->version {$classVersion} in {$module}.php");
}

// ---------------------------------------------------------------- 2. copy

$target = rtrim($outDir, '/\\') . '/' . $module;

if (is_dir($target)) {
    removeTree($target);
}

$skip = ['.git', '.github', '.gitignore', '.gitattributes', 'tests', 'node_modules', 'phpunit.xml', 'build', 'composer.lock'];
copyTree($root, $target, $skip);

// ---------------------------------------------------------------- 3. config*.xml

$meta = [
    'name' => $module,
    'displayName' => transSource($source, 'displayName'),
    'version' => $classVersion,
    'description' => transSource($source, 'description'),
    'author' => match1('/\$this->author\s*=\s*\'([^\']+)\'/', $source),
    'tab' => match1('/\$this->tab\s*=\s*\'([^\']+)\'/', $source),
    'is_configurable' => preg_match('/function\s+getContent\s*\(/', $source) === 1 ? '1' : '0',
    'need_instance' => match1('/\$this->need_instance\s*=\s*(\d)/', $source),
];

file_put_contents($target . '/config.xml', configXml($meta));

foreach (glob($root . '/translations/*', GLOB_ONLYDIR) ?: [] as $langDir) {
    $locale = basename($langDir);
    $iso = strtolower(substr($locale, 0, 2));

    if ($iso === 'en') {
        continue;
    }

    $translated = $meta;
    $translated['displayName'] = translate($langDir, $meta['displayName']);
    $translated['description'] = translate($langDir, $meta['description']);

    file_put_contents($target . '/config_' . $iso . '.xml', configXml($translated));
}

// ---------------------------------------------------------------- 4. index.php stubs

$stub = <<<'PHP'
<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
// Wczytany przez inny skrypt (np. API Platform skanuje src/ApiPlatform/Resources
// przy budowie kontenera panelu) nie może przekierować ani zakończyć procesu.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Location: ../');
exit;

PHP;

$dirs = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$stubs = 0;

foreach (array_merge([$target], array_map('strval', iterator_to_array($dirs, false))) as $path) {
    if (is_dir($path) && !is_file($path . '/index.php')) {
        file_put_contents($path . '/index.php', $stub);
        ++$stubs;
    }
}

// ---------------------------------------------------------------- 5. vendor/.htaccess

// vendor/ is git-ignored, so its .htaccess never reaches the repository. Without it
// Apache serves vendor/composer/installed.json and the rest to anyone who asks.
$htaccess = <<<'HTACCESS'
# Apache 2.2
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Apache 2.4
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

HTACCESS;

if (is_dir($target . '/vendor')) {
    file_put_contents($target . '/vendor/.htaccess', $htaccess);
}

echo "Built {$target} ({$classVersion}), " . $stubs . " index.php stubs\n";

// ---------------------------------------------------------------- helpers

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function match1(string $pattern, string $subject): string
{
    if (preg_match($pattern, $subject, $m) !== 1) {
        fail("Pattern not found in module class: {$pattern}");
    }

    return $m[1];
}

/** English source of `$this-><property> = $this->trans('...')`. */
function transSource(string $source, string $property): string
{
    $pattern = '/\$this->' . $property . '\s*=\s*\$this->trans\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s';

    return stripslashes(match1($pattern, $source));
}

/** Translation of an English string from the module's XLF files, or the string itself. */
function translate(string $langDir, string $english): string
{
    foreach (glob($langDir . '/*.xlf') ?: [] as $file) {
        $xml = @simplexml_load_file($file);

        if ($xml === false) {
            continue;
        }

        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        foreach ($xml->xpath('//x:trans-unit') ?: [] as $unit) {
            if ((string) $unit->source === $english && trim((string) $unit->target) !== '') {
                return (string) $unit->target;
            }
        }
    }

    return $english;
}

/** @param array<string,string> $meta */
function configXml(array $meta): string
{
    $cdata = static fn (string $v): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $v) . ']]>';

    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . "<module>\n"
        . '    <name>' . $meta['name'] . "</name>\n"
        . '    <displayName>' . $cdata($meta['displayName']) . "</displayName>\n"
        . '    <version>' . $cdata($meta['version']) . "</version>\n"
        . '    <description>' . $cdata($meta['description']) . "</description>\n"
        . '    <author>' . $cdata($meta['author']) . "</author>\n"
        . '    <tab>' . $cdata($meta['tab']) . "</tab>\n"
        . '    <is_configurable>' . $meta['is_configurable'] . "</is_configurable>\n"
        . '    <need_instance>' . $meta['need_instance'] . "</need_instance>\n"
        . "    <limited_countries></limited_countries>\n"
        . "</module>\n";
}

/** @param string[] $skip names left out at the top level */
function copyTree(string $from, string $to, array $skip): void
{
    mkdir($to, 0755, true);

    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }

        // config*.xml are rendered above, never copied from a working tree.
        if (preg_match('/^config(_[a-z]{2})?\.xml$/', $entry) === 1) {
            continue;
        }

        $src = $from . '/' . $entry;
        $dst = $to . '/' . $entry;

        if (is_dir($src)) {
            copyTree($src, $dst, []);
        } else {
            copy($src, $dst);
        }
    }
}

function removeTree(string $path): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
    }

    rmdir($path);
}
