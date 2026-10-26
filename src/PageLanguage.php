<?php

/**
 * The language a module's page is in, for `<html lang>` (WCAG 2.1 SC 3.1.1) and `dir`: the
 * signed-in user's OpenEMR language, so a screen reader reads a translated page in that
 * language rather than with English pronunciation.
 *
 * OpenEMR keeps the user's choice as a `lang_languages.lang_id` in the session's
 * `language_choice` (library/auth.inc.php on 7.0 and master; translation.inc.php treats an empty
 * choice as 1, English). Its `lang_code` isn't always a language tag: the codes shipped in
 * contrib/util/language_translations/currentLanguage_utf8.sql include `se` for Swedish, `el` for
 * Latin American Spanish, `zt` for Traditional Chinese, `pa` for Angolan Portuguese and `dd` for a
 * dummy language. Those are mapped to BCP 47 here, but only when the row's description is the one
 * OpenEMR ships with that code, so a site's own row that uses `el` for Greek stays `el`.
 *
 * Without a session (a command-line tool, the tests), with no matching row, or when the database
 * can't be asked, the page is English.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common;

use OpenEMR\Common\Database\QueryUtils;

final class PageLanguage
{
    /**
     * OpenEMR's shipped codes that aren't the language's BCP 47 tag, with the description OpenEMR
     * ships them with. Every other code OpenEMR ships is already the ISO 639-1 code.
     */
    private const CORE = [
        'se' => ['sv', 'Swedish'],
        'el' => ['es-419', 'Spanish (Latin American)'],
        'du' => ['nl', 'Dutch'],
        'fq' => ['fr-CA', 'French (Canadian)'],
        'zh' => ['zh-Hans', 'Chinese (Simplified)'],
        'zt' => ['zh-Hant', 'Chinese (Traditional)'],
        'gr' => ['el', 'Greek'],
        'ei' => ['en-IN', 'English (Indian)'],
        'pe' => ['pt-PT', 'Portuguese (European)'],
        'pt' => ['pt-BR', 'Portuguese (Brazilian)'],
        'pa' => ['pt-AO', 'Portuguese (Angolan)'],
        'po' => ['pl', 'Polish'],
        'li' => ['lt', 'Lithuanian'],
        'fp' => ['fil', 'Filipino'],
        'au' => ['en-AU', 'English (Australian)'],
        'dd' => ['en', 'dummy'],
    ];

    /** The BCP 47 tag for the signed-in user's language, `en` when there is none. */
    public static function code(): string
    {
        $row = self::row();
        return $row === null ? 'en' : self::bcp47($row['code'], $row['description']);
    }

    /** `rtl` when the signed-in user's language is written right to left (Arabic, Hebrew, Persian, Urdu), else `ltr`. */
    public static function dir(): string
    {
        $row = self::row();
        return $row !== null && $row['rtl'] ? 'rtl' : 'ltr';
    }

    /**
     * A `lang_languages.lang_code` as a BCP 47 tag. A code OpenEMR ships under another meaning is
     * mapped only when the description matches what OpenEMR ships it with; any other two- or
     * three-letter code is taken as the language's own code; anything else is English.
     */
    public static function bcp47(string $code, string $description = ''): string
    {
        $code = strtolower(trim($code));
        $known = self::CORE[$code] ?? null;
        if ($known !== null && strcasecmp(trim($description), $known[1]) === 0) {
            return $known[0];
        }
        return preg_match('/^[a-z]{2,3}$/', $code) === 1 ? $code : 'en';
    }

    /** @return ?array{code: string, description: string, rtl: bool} */
    private static function row(): ?array
    {
        $choice = OpenEmr::session('language_choice');
        if (!is_numeric($choice) || (int)$choice < 1) {
            return null;
        }
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT `lang_code`, `lang_description`, `lang_is_rtl` FROM `lang_languages` WHERE `lang_id` = ?',
                [(int)$choice]
            );
        } catch (\Throwable) {
            return null;
        }
        $row = $rows[0] ?? null;
        if (!is_array($row) || !is_string($row['lang_code'] ?? null)) {
            return null;
        }
        return [
            'code' => $row['lang_code'],
            'description' => is_string($row['lang_description'] ?? null) ? $row['lang_description'] : '',
            'rtl' => (int)($row['lang_is_rtl'] ?? 0) === 1,
        ];
    }
}
