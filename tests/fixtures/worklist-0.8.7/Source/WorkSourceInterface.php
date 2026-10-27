<?php

/**
 * A kind of outstanding work the worklist can find in OpenEMR data.
 *
 * Sources only read. Each returns the items that are outstanding right now;
 * the worklist never stores items, only staff state about them.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenHealthcare\StaffWorklist\Model\WorkItem;

interface WorkSourceInterface
{
    /** The ItemKey source this produces, e.g. ItemKey::SOURCE_RESULT. */
    public function id(): string;

    /**
     * @param int|null $pid limit to one patient (used to re-check an item before acting on it)
     * @return WorkItem[]
     */
    public function collect(?int $pid = null): array;
}
