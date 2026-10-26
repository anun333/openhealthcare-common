<?php

/**
 * OpenEMR's xl() turns an apostrophe or a quote mark in a translated string into a backtick
 * (library/translation.inc.php, unless a site turns that off), so "claim's" shows as "claim`s".
 * This finds every string literal a module passes to xl(), xlt(), xla(), xlj() or xlx() that has
 * one, for the module's own test to assert there are none.
 *
 * A module's test asserts a floor on `checked` as well as an empty `found`, so a scan that read
 * no files (a wrong root) fails instead of passing on nothing.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common;

final class TranslatedStrings
{
    /** xl(), xlt(), xla(), xlj() and xlx() with a single- or double-quoted literal. */
    private const CALL = "/\\bxl[tajx]?\\(\\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/";

    /**
     * @param string       $root the module's directory
     * @param list<string> $dirs the folders under it to read, PHP and Twig files only
     * @return array{checked: int, found: list<string>} found: "path: text" for each string with an apostrophe or quote mark
     */
    public static function scan(string $root, array $dirs = ['src', 'public', 'templates']): array
    {
        $literals = self::literals($root, $dirs);
        $found = [];
        foreach ($literals as $literal) {
            if (str_contains($literal['text'], "'") || str_contains($literal['text'], '"')) {
                $found[] = $literal['path'] . ': ' . $literal['text'];
            }
        }
        return ['checked' => count($literals), 'found' => $found];
    }

    /**
     * Every string literal the module passes to xl(), xlt(), xla(), xlj() or xlx(), in file order.
     *
     * @param string       $root the module's directory
     * @param list<string> $dirs the folders under it to read, PHP and Twig files only
     * @return list<array{path: string, text: string}> path relative to $root
     */
    public static function literals(string $root, array $dirs = ['src', 'public', 'templates']): array
    {
        $root = rtrim($root, '/');
        $literals = [];
        foreach ($dirs as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            $paths = [];
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && preg_match('/\.(php|twig)$/', $file->getFilename())) {
                    $paths[] = $file->getPathname();
                }
            }
            sort($paths);
            foreach ($paths as $path) {
                preg_match_all(self::CALL, (string)file_get_contents($path), $m);
                foreach ($m[1] as $literal) {
                    $literals[] = ['path' => substr($path, strlen($root) + 1), 'text' => stripcslashes(substr($literal, 1, -1))];
                }
            }
        }
        return $literals;
    }
}
