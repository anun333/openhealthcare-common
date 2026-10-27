<?php

/**
 * How a module adds its tab to the Staff Worklist. The Worklist asks with its
 * staff_worklist.collect_sources event; the module's listener hands its WorkSource to contribute()
 * with the tab's label and owner role, both already translated, after deciding whether this user
 * gets the tab.
 *
 * The event object belongs to the Worklist and implements nothing from this library, so a site
 * where an older copy of the library loaded first still gets a working Worklist. What a listener
 * may rely on, by version:
 * - Worklist 0.8.0 and later: a public readonly `now` (\DateTimeImmutable) and
 *   addSource(its own WorkSourceInterface, string $label, string $ownerRole);
 * - Worklist 0.9.0 and later, also contribute(WorkSource $source, string $label, string $ownerRole).
 * contribute() here uses the event's contribute() when it has one, and otherwise wraps the source
 * in the Worklist's own types for addSource(), so one contributor works with both.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Worklist;

final class Worklist
{
    /** The event the Worklist sends when it gathers its tabs. */
    public const COLLECT_SOURCES = 'staff_worklist.collect_sources';

    /** The Worklist's own types, which its addSource() takes (0.8.0 on). */
    private const WORKLIST_SOURCE = 'OpenHealthcare\\StaffWorklist\\Source\\WorkSourceInterface';
    private const WORKLIST_ITEM = 'OpenHealthcare\\StaffWorklist\\Model\\WorkItem';
    private const WORKLIST_KEY = 'OpenHealthcare\\StaffWorklist\\Model\\ItemKey';
    private const WORKLIST_PATIENT = 'OpenHealthcare\\StaffWorklist\\Model\\PatientRef';

    /**
     * Adds the tab. False when the event isn't the Worklist's, or is one this library can't hand a
     * source to; the tab is then left out for this load and nothing fails. The Worklist's own
     * refusals (an id that's taken or one of its built-in tabs, an id added twice) still throw.
     */
    public static function contribute(object $event, WorkSource $source, string $label, string $ownerRole): bool
    {
        if (method_exists($event, 'contribute')) {
            $event->contribute($source, $label, $ownerRole);
            return true;
        }
        if (method_exists($event, 'addSource') && self::worklistTypesLoaded()) {
            $event->addSource(self::asWorklistSource($source), $label, $ownerRole);
            return true;
        }
        return false;
    }

    /** The time the Worklist is collecting for, so every tab measures waits from the same moment. */
    public static function now(object $event): \DateTimeImmutable
    {
        $now = property_exists($event, 'now') ? $event->now : null;
        return $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable();
    }

    /** Whether the Worklist's own types can be loaded (it's installed and on), which asWorklistSource() needs. */
    public static function worklistTypesLoaded(): bool
    {
        return interface_exists(self::WORKLIST_SOURCE)
            && class_exists(self::WORKLIST_ITEM) && class_exists(self::WORKLIST_KEY) && class_exists(self::WORKLIST_PATIENT);
    }

    /**
     * The source as the Worklist's own WorkSourceInterface, turning each item into the Worklist's
     * WorkItem, ItemKey and PatientRef as it's collected. Call it only when worklistTypesLoaded().
     */
    public static function asWorklistSource(WorkSource $source): object
    {
        if (!self::worklistTypesLoaded()) {
            throw new \LogicException('The Staff Worklist\'s own types are not loaded');
        }
        return new class ($source) implements \OpenHealthcare\StaffWorklist\Source\WorkSourceInterface {
            public function __construct(private readonly WorkSource $source)
            {
            }

            public function id(): string
            {
                return $this->source->id();
            }

            public function collect(?int $pid = null): array
            {
                return array_map(Worklist::toWorklistItem(...), $this->source->collect($pid));
            }
        };
    }

    /** One item as the Worklist's own WorkItem. */
    public static function toWorklistItem(WorkItem $item): object
    {
        $patient = $item->patient;
        return new \OpenHealthcare\StaffWorklist\Model\WorkItem(
            new \OpenHealthcare\StaffWorklist\Model\ItemKey($item->key->source, $item->key->pid, $item->key->ref),
            new \OpenHealthcare\StaffWorklist\Model\PatientRef(
                $patient->pid,
                $patient->name,
                $patient->dob,
                $patient->pubpid,
                $patient->deceased,
                $patient->portalAccess,
            ),
            $item->title,
            $item->detail,
            $item->priority,
            $item->since,
            $item->ownerId,
            $item->actionLabel,
            $item->actionUrl,
            $item->actionMode,
            $item->dismissible,
            $item->siteId,
            $item->sensitivity,
            $item->overdue,
        );
    }
}
