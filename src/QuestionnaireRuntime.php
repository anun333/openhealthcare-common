<?php

/**
 * The version of OpenEMR's own questionnaire runtime a site serves, read from the runtime file,
 * which declares it (`version: '0.4.0'`). OpenEMR's release number doesn't tell: 8.5.0 shipped
 * runtime 0.2.0 and gained 0.4.0 in a backport (#14826), so a module asks the runtime.
 *
 * 0.4.0 (master 2ee0a6a503, rel-850 0d8b8709b7; there was no 0.3.0 on either) is the first with
 * enableWhenExpression and repeats per instance, and the first whose pages save a submitted
 * response as completed, or amended when it was completed before. Before it, every response was
 * saved in-progress.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common;

final class QuestionnaireRuntime
{
    /** Where the runtime lives, under OpenEMR's project directory. */
    public const PATH = 'interface/forms/questionnaire_assessments/native/openemr_questionnaire.js';

    /** The first version with enableWhenExpression, repeats per instance and completed responses. */
    public const COMPLETES = '0.4.0';

    /** @var array<string, ?string> by project directory */
    private static array $versions = [];

    /** The runtime's version, or null when the site has no native runtime or its file names none. */
    public static function version(?string $projectDir = null): ?string
    {
        $path = rtrim($projectDir ?? OpenEmr::projectDir(), '/') . '/' . self::PATH;
        if (!array_key_exists($path, self::$versions)) {
            // The declaration sits in the runtime's class, thousands of lines in: read the whole file (about 300 KB).
            $source = is_file($path) ? file_get_contents($path) : false;
            self::$versions[$path] = is_string($source) && preg_match("/\\bversion:\\s*'(\\d+\\.\\d+\\.\\d+)'/", $source, $m) === 1 ? $m[1] : null;
        }
        return self::$versions[$path];
    }

    /** Whether the site's runtime is this version or later; false when there is none. */
    public static function atLeast(string $minimum, ?string $projectDir = null): bool
    {
        $version = self::version($projectDir);
        return $version !== null && version_compare($version, $minimum, '>=');
    }
}
