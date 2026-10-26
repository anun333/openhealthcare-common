<?php

/**
 * A module's GUIDE.md quotes the labels staff click in bold: menu entries, tabs, buttons and
 * headings. This checks each one is a string the module translates, so a renamed button fails the
 * module's test instead of leaving the guide pointing at something that isn't there.
 *
 * A label passed to Twig's translation filters (`{{ 'Assign'|xlt }}`) counts as translated from 1.4.0;
 * before, a dashboard card's button had to be named below as coming from its template.
 *
 * A label that comes from the module's data rather than an xl() call (a list of priorities in a
 * JSON file, say) is named in the module's composer.json, under
 * `extra.openhealthcare.guide-labels-elsewhere`, with the file it comes from:
 * `{"High": "data/priorities.json"}`. The file must still contain the label, so that can't go
 * stale either.
 *
 * Trailing colons, ellipses and full stops are ignored on both sides: "Assign…" is the label
 * "Assign". A module's test asserts a floor on `labels` and `strings` as well as an empty
 * `missing`, so a wrong root or an empty guide fails instead of passing on nothing.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common;

final class GuideLabels
{
    /** **A label**, on one line. */
    private const BOLD = '/\*\*([^*\n]+?)\*\*/';

    /**
     * @param string       $root  the module's directory
     * @param string       $guide the guide, relative to $root
     * @param list<string> $dirs  where the module's translated strings are, as TranslatedStrings reads them
     * @return array{labels: int, strings: int, missing: list<string>}
     */
    public static function check(string $root, string $guide = 'GUIDE.md', array $dirs = ['src', 'public', 'templates']): array
    {
        $root = rtrim($root, '/');
        $text = is_file("$root/$guide") ? file_get_contents("$root/$guide") : false;
        if (!is_string($text)) {
            throw new \RuntimeException("No guide at $root/$guide");
        }
        preg_match_all(self::BOLD, $text, $m);
        $labels = array_values(array_unique(array_filter(array_map(self::normal(...), $m[1]), fn(string $label): bool => $label !== '')));

        $strings = [];
        foreach ([...TranslatedStrings::literals($root, $dirs), ...TranslatedStrings::twigFilterLiterals($root, $dirs)] as $literal) {
            $strings[self::normal($literal['text'])] = true;
        }
        $elsewhere = self::elsewhere($root);

        $missing = [];
        foreach ($labels as $label) {
            if (isset($strings[$label])) {
                continue;
            }
            $file = $elsewhere[$label] ?? null;
            if ($file === null) {
                $missing[] = $label;
            } elseif (!str_contains(is_file("$root/$file") ? (string)file_get_contents("$root/$file") : '', $label)) {
                $missing[] = "$label (named as coming from $file, which doesn't contain it)";
            }
        }
        return ['labels' => count($labels), 'strings' => count($strings), 'missing' => $missing];
    }

    private static function normal(string $text): string
    {
        return rtrim(trim($text), " :.\u{2026}");
    }

    /** @return array<string, string> label => the file it comes from */
    private static function elsewhere(string $root): array
    {
        $composer = is_file("$root/composer.json") ? json_decode((string)file_get_contents("$root/composer.json"), true) : null;
        $named = is_array($composer) ? ($composer['extra']['openhealthcare']['guide-labels-elsewhere'] ?? []) : [];
        $elsewhere = [];
        foreach (is_array($named) ? $named : [] as $label => $file) {
            if (is_string($label) && is_string($file)) {
                $elsewhere[self::normal($label)] = $file;
            }
        }
        return $elsewhere;
    }
}
