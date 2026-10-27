<?php

/**
 * One piece of open work in a contributed tab. Items are worked out from the module's own data on
 * every load and nothing here is stored: when the work is done, the source stops producing the
 * item and it leaves the Worklist on its own. The Worklist stores only what staff add to it
 * (assignee, note, snooze, dismissal), against the item's key.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Worklist;

final class WorkItem
{
    public const PRIORITY_CRITICAL = 1;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_NORMAL = 3;

    /** How the item's action opens: over the Worklist, as a popup (for core pages that close themselves), or in the chart. */
    public const ACTION_MODES = ['dialog', 'window', 'chart'];

    /**
     * @param string      $title       what needs doing, already translated
     * @param string      $detail      the supporting facts, already translated
     * @param \DateTimeImmutable $since when the work started waiting
     * @param int|null    $ownerId     users.id responsible while nobody is assigned
     * @param string      $actionUrl   a path under the webroot where the work is done
     * @param string      $actionMode  one of ACTION_MODES
     * @param bool        $dismissible false when only finishing the work in OpenEMR may clear it
     * @param int|null    $siteId      facility.id the work belongs to, or null when it can't be told
     * @param string|null $sensitivity form_encounter.sensitivity of the visit the work came from
     * @param bool        $overdue     past a hard limit the clinic must act within
     */
    public function __construct(
        public readonly ItemKey $key,
        public readonly PatientRef $patient,
        public readonly string $title,
        public readonly string $detail,
        public readonly int $priority,
        public readonly \DateTimeImmutable $since,
        public readonly ?int $ownerId,
        public readonly string $actionLabel,
        public readonly string $actionUrl,
        public readonly string $actionMode,
        public readonly bool $dismissible,
        public readonly ?int $siteId = null,
        public readonly ?string $sensitivity = null,
        public readonly bool $overdue = false,
    ) {
        if (!in_array($priority, [self::PRIORITY_CRITICAL, self::PRIORITY_HIGH, self::PRIORITY_NORMAL], true)) {
            throw new \InvalidArgumentException("Not a work item priority: $priority");
        }
        if (!in_array($actionMode, self::ACTION_MODES, true)) {
            throw new \InvalidArgumentException("Not a work item action mode: \"$actionMode\"");
        }
        if ($key->pid !== $patient->pid) {
            throw new \InvalidArgumentException("The item's key is for patient {$key->pid} but its patient is {$patient->pid}");
        }
    }
}
