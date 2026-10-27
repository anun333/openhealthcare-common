<?php

/**
 * One piece of outstanding staff work, as produced by a source.
 *
 * Items are recomputed from OpenEMR data on every load; nothing here is stored.
 * When the underlying condition clears (result signed, reply recorded) the
 * source stops producing the item and it leaves the worklist on its own.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Model;

final class WorkItem
{
    public const PRIORITY_CRITICAL = 1;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_NORMAL = 3;

    /**
     * @param ItemKey            $key
     * @param string             $title        what needs doing, already translated
     * @param string             $detail       supporting facts, already translated
     * @param \DateTimeImmutable $since        when the work started waiting
     * @param int|null           $ownerId      users.id responsible when nobody is assigned
     * @param string             $actionUrl    path under the webroot where the work is done
     * @param string             $actionMode   'dialog' opens over the worklist; 'window' opens a popup (for core
     *                                         pages that close themselves); 'chart' loads the patient first
     * @param bool               $dismissible  false when only completing the work in OpenEMR may clear it
     * @param int|null           $siteId       facility.id the work belongs to; null when it can't be determined
     * @param string|null        $sensitivity  form_encounter.sensitivity of the encounter the work came from
     * @param bool               $overdue      past a hard limit the clinic must act within (critical results)
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
    }

    public function source(): string
    {
        return $this->key->source;
    }

    /** Site id for grouping and filtering, with unknown sites as SiteAccess::NO_SITE. */
    public function siteKey(): int
    {
        return $this->siteId ?? SiteAccess::NO_SITE;
    }
}
