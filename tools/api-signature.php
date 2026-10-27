<?php

/**
 * The library's public surface, read by reflection, and the check that a release only adds to it:
 * 1.x promises that a module written against an earlier 1.x still works, since the first copy of
 * the library a site loads serves every module.
 *
 *     php tools/api-signature.php [src]                       prints the surface as JSON
 *     php tools/api-signature.php --against=<old.json> [src]  exits 1, naming each break
 *
 * A break is a class, interface, constant (or its value), public method or public property that
 * went away or changed; a required parameter added to a method, or an optional one made required;
 * a parameter's or return's type changed; a class made final or abstract; a method added to an
 * interface (every class implementing it would stop). tests/fixtures/api-<version>.json holds each
 * released 1.x surface, made with the first form from that tag's src, and ApiAdditivityTest checks
 * against them.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Tools;

const PREFIX = 'OpenHealthcare\\Common\\';

/**
 * Every class and interface under $src, loaded from there.
 *
 * @return array<string, array<string, mixed>>
 */
function surface(string $src): array
{
    $src = rtrim($src, '/');
    spl_autoload_register(static function (string $class) use ($src): void {
        if (str_starts_with($class, PREFIX)) {
            $file = $src . '/' . str_replace('\\', '/', substr($class, strlen(PREFIX))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }, true, true);
    $out = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
    $names = [];
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $names[] = PREFIX . str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
        }
    }
    sort($names);
    foreach ($names as $name) {
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
            continue;
        }
        $out[$name] = describe(new \ReflectionClass($name));
    }
    return $out;
}

/** @return array<string, mixed> */
function describe(\ReflectionClass $class): array
{
    $constants = [];
    foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
        $constants[$constant->getName()] = $constant->getValue();
    }
    $methods = [];
    foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class->getName() && !$class->isInterface()) {
            continue;
        }
        $params = [];
        foreach ($method->getParameters() as $param) {
            $params[] = [
                'name' => $param->getName(),
                'type' => typeName($param->getType(), $class),
                'optional' => $param->isOptional(),
                'default' => $param->isDefaultValueAvailable() ? var_export($param->getDefaultValue(), true) : null,
                'variadic' => $param->isVariadic(),
            ];
        }
        $methods[$method->getName()] = [
            'static' => $method->isStatic(),
            'return' => typeName($method->getReturnType(), $class),
            'params' => $params,
        ];
    }
    ksort($methods);
    $properties = [];
    foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
        $properties[$property->getName()] = [
            'type' => typeName($property->getType(), $class),
            'static' => $property->isStatic(),
            'readonly' => $property->isReadOnly(),
        ];
    }
    ksort($properties);
    return [
        'kind' => $class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : 'class'),
        'final' => $class->isFinal(),
        'abstract' => $class->isAbstract() && !$class->isInterface(),
        'constants' => $constants,
        'methods' => $methods,
        'properties' => $properties,
    ];
}

/** A type as text, with `self` as the class it names: PHP 8.5's reflection prints the class, earlier ones `self`. */
function typeName(?\ReflectionType $type, \ReflectionClass $class): string
{
    return (string)preg_replace('/(?<![\\\\\w])self\b/', $class->getName(), (string)$type);
}

/**
 * What $new breaks of $old.
 *
 * @param array<string, array<string, mixed>> $old
 * @param array<string, array<string, mixed>> $new
 * @return list<string>
 */
function breaks(array $old, array $new): array
{
    $breaks = [];
    foreach ($old as $name => $was) {
        $now = $new[$name] ?? null;
        if ($now === null) {
            $breaks[] = "$name is gone";
            continue;
        }
        if ($now['kind'] !== $was['kind']) {
            $breaks[] = "$name was a {$was['kind']} and is now a {$now['kind']}";
            continue;
        }
        if ($now['final'] && !$was['final']) {
            $breaks[] = "$name is now final";
        }
        if ($now['abstract'] && !$was['abstract']) {
            $breaks[] = "$name is now abstract";
        }
        foreach ($was['constants'] as $constant => $value) {
            if (!array_key_exists($constant, $now['constants'])) {
                $breaks[] = "$name::$constant is gone";
            } elseif ($now['constants'][$constant] !== $value) {
                $breaks[] = "$name::$constant changed value";
            }
        }
        foreach ($was['properties'] as $property => $shape) {
            if (!isset($now['properties'][$property])) {
                $breaks[] = "$name::\$$property is gone";
            } elseif ($now['properties'][$property] !== $shape) {
                $breaks[] = "$name::\$$property changed type, static or readonly";
            }
        }
        foreach ($was['methods'] as $method => $shape) {
            $breaks = [...$breaks, ...methodBreaks("$name::$method()", $shape, $now['methods'][$method] ?? null)];
        }
        if ($was['kind'] === 'interface') {
            foreach (array_diff_key($now['methods'], $was['methods']) as $method => $_) {
                $breaks[] = "$name::$method() was added to an interface";
            }
        }
    }
    return $breaks;
}

/**
 * @param array<string, mixed>      $was
 * @param array<string, mixed>|null $now
 * @return list<string>
 */
function methodBreaks(string $label, array $was, ?array $now): array
{
    if ($now === null) {
        return ["$label is gone"];
    }
    $breaks = [];
    if ($now['static'] !== $was['static']) {
        $breaks[] = "$label changed between static and not";
    }
    if ($now['return'] !== $was['return']) {
        $breaks[] = "$label returns {$now['return']} instead of {$was['return']}";
    }
    foreach ($was['params'] as $i => $param) {
        $is = $now['params'][$i] ?? null;
        if ($is === null) {
            $breaks[] = "$label lost parameter \${$param['name']}";
            continue;
        }
        // Callers may name arguments, so a renamed parameter breaks them too.
        if ($is['name'] !== $param['name']) {
            $breaks[] = "$label renamed parameter \${$param['name']} to \${$is['name']}";
        }
        if ($is['type'] !== $param['type']) {
            $breaks[] = "$label changed \${$param['name']}'s type from {$param['type']} to {$is['type']}";
        }
        if ($param['optional'] && !$is['optional']) {
            $breaks[] = "$label made \${$param['name']} required";
        } elseif ($param['optional'] && $is['default'] !== $param['default']) {
            $breaks[] = "$label changed \${$param['name']}'s default";
        }
        if ($is['variadic'] !== $param['variadic']) {
            $breaks[] = "$label changed whether \${$param['name']} is variadic";
        }
    }
    foreach (array_slice($now['params'], count($was['params'])) as $param) {
        if (!$param['optional']) {
            $breaks[] = "$label added required parameter \${$param['name']}";
        }
    }
    return $breaks;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $against = null;
    $src = dirname(__DIR__) . '/src';
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--against=')) {
            $against = substr($arg, strlen('--against='));
        } else {
            $src = $arg;
        }
    }
    $surface = surface($src);
    if ($against === null) {
        echo json_encode($surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    $old = json_decode((string)file_get_contents($against), true, 512, JSON_THROW_ON_ERROR);
    $found = breaks($old, $surface);
    foreach ($found as $break) {
        echo $break, "\n";
    }
    fwrite(STDERR, sprintf("%d classes checked against %s: %d breaks\n", count($old), basename($against), count($found)));
    exit($found === [] ? 0 : 1);
}
