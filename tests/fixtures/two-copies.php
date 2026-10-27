<?php

/**
 * Two copies of the library on one site, as modules copied in by hand bring them: an old one (no contracts) registered
 * first for the whole namespace, then this one's contracts under their own prefix, as a module's bootstrap registers
 * them from 1.5.0. Prints which file each class came from, as JSON. Run by TwoCopiesTest in a process of its own:
 *
 *     php two-copies.php <old src> <new src> with-contract|without-contract
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/composer/ClassLoader.php';

[, $old, $new, $mode] = $argv;
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('OpenHealthcare\\Common\\', $old);
if ($mode === 'with-contract') {
    $loader->addPsr4('OpenHealthcare\\Common\\Contract\\', $new . '/Contract');
}
$loader->register();

$from = static function (string $class): ?string {
    if (!class_exists($class) && !interface_exists($class)) {
        return null;
    }
    return (string)(new \ReflectionClass($class))->getFileName();
};
echo json_encode([
    'OpenEmr' => $from('OpenHealthcare\\Common\\OpenEmr'),
    'WorkSource' => $from('OpenHealthcare\\Common\\Contract\\Worklist\\WorkSource'),
    'FiguresRequest' => $from('OpenHealthcare\\Common\\Contract\\Figures\\FiguresRequest'),
], JSON_UNESCAPED_SLASHES);
