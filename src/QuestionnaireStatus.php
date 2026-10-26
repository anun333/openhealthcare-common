<?php

/**
 * Which saved questionnaire responses count as answered. Before runtime 0.4.0 OpenEMR saved every
 * response in-progress, finished or not, so a module counted them all. From 0.4.0 the clinic's
 * pages save a submitted response as completed (amended when it is edited after), and only a draft,
 * which today only an API client writes, stays in-progress.
 *
 * The rule, per site:
 * - until its first completed or amended response, every response counts: it is on the old runtime;
 * - after that, a response saved before that moment counts whatever its status, and one saved later
 *   counts only when it is completed or amended;
 * - entered-in-error never counts.
 *
 * The moment is the earliest last_updated (create_time when the row was never updated) among
 * completed and amended rows, so an old response edited under the new runtime doesn't move it back.
 * No setting is needed and no upgrade date is guessed.
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

final class QuestionnaireStatus
{
    /** R4's statuses for a response someone finished. */
    public const FINISHED = ['completed', 'amended'];

    /** When the site saved its first completed or amended response, or null when it has none. */
    public static function since(): ?string
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT MIN(COALESCE(`last_updated`, `create_time`)) AS `since` FROM `questionnaire_response` WHERE `status` IN ('completed', 'amended')"
        );
        $since = $rows[0]['since'] ?? null;
        return is_string($since) && $since !== '' ? $since : null;
    }

    /**
     * A WHERE condition, with its binds, for the responses that count on the questionnaire_response
     * table under this alias.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function condition(string $alias = 'questionnaire_response'): array
    {
        return self::conditionSince($alias, self::since());
    }

    /**
     * condition(), given the moment from since().
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function conditionSince(string $alias, ?string $since): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException("Not a table alias: $alias");
        }
        $status = "COALESCE(`$alias`.`status`, '')";
        if ($since === null) {
            return ["$status <> 'entered-in-error'", []];
        }
        return ["($status IN ('completed', 'amended') OR ($status <> 'entered-in-error' AND `$alias`.`create_time` < ?))", [$since]];
    }

    /** The same rule for a row already read: its status and create_time, and the moment from since(). */
    public static function counts(?string $status, ?string $createTime, ?string $since): bool
    {
        if ($status === 'entered-in-error') {
            return false;
        }
        if ($since === null || in_array($status, self::FINISHED, true)) {
            return true;
        }
        // Both are MySQL datetimes, Y-m-d H:i:s, so they compare as strings.
        return $createTime !== null && $createTime < $since;
    }
}
