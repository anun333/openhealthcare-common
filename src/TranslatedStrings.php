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
 * Twig templates translate with filters as well (`'Assign'|xlt`, OpenEMR's xl, xlt, xla and xlj);
 * twigFilterLiterals() reads those, GuideLabels counts them, and scan() checks them when asked
 * (1.4.0). A single-quoted PHP literal keeps its backslashes but for `\'` and `\\`, as PHP does;
 * 1.0.0 to 1.3.3 unescaped it like a double-quoted one, so `'\n'` read as a new line.
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

    /** A string literal passed to Twig's xl, xlt, xla or xlj filter, and not to a longer filter name (xlListLabel). */
    private const TWIG_FILTER = "/('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\\s*\\|\\s*xl[taj]?(?![A-Za-z0-9_])/";

    /**
     * @param string       $root        the module's directory
     * @param list<string> $dirs        the folders under it to read, PHP and Twig files only
     * @param bool         $twigFilters also check strings passed to Twig's translation filters (1.4.0)
     * @return array{checked: int, found: list<string>} found: "path: text" for each string with an apostrophe or quote mark
     */
    public static function scan(string $root, array $dirs = ['src', 'public', 'templates'], bool $twigFilters = false): array
    {
        $literals = self::literals($root, $dirs);
        if ($twigFilters) {
            $literals = [...$literals, ...self::twigFilterLiterals($root, $dirs)];
        }
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
        return self::find($root, $dirs, '/\.(php|twig)$/', self::CALL);
    }

    /**
     * Every string literal a Twig template passes to OpenEMR's xl, xlt, xla or xlj filter
     * (`'Assign'|xlt`), in file order (1.4.0).
     *
     * @param string       $root the module's directory
     * @param list<string> $dirs the folders under it to read; Twig files only
     * @return list<array{path: string, text: string}> path relative to $root
     */
    public static function twigFilterLiterals(string $root, array $dirs = ['src', 'public', 'templates']): array
    {
        return self::find($root, $dirs, '/\.twig$/', self::TWIG_FILTER);
    }

    /**
     * @param list<string> $dirs
     * @return list<array{path: string, text: string}>
     */
    private static function find(string $root, array $dirs, string $files, string $pattern): array
    {
        $root = rtrim($root, '/');
        $literals = [];
        foreach ($dirs as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            $paths = [];
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && preg_match($files, $file->getFilename())) {
                    $paths[] = $file->getPathname();
                }
            }
            sort($paths);
            foreach ($paths as $path) {
                preg_match_all($pattern, (string)file_get_contents($path), $m);
                foreach ($m[1] as $literal) {
                    $literals[] = ['path' => substr($path, strlen($root) + 1), 'text' => self::unescape($literal, str_ends_with($path, '.php'))];
                }
            }
        }
        return $literals;
    }

    /**
     * A literal's text, quotes removed. A single-quoted PHP string unescapes only `\'` and `\\`;
     * a double-quoted one, and any Twig string (Twig's lexer uses stripcslashes for both quotes),
     * unescapes C-style.
     */
    private static function unescape(string $literal, bool $php): string
    {
        $text = substr($literal, 1, -1);
        if ($php && $literal[0] === "'") {
            return strtr($text, ['\\\\' => '\\', "\\'" => "'"]);
        }
        return stripcslashes($text);
    }
}
