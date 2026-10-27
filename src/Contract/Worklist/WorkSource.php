<?php

/**
 * A tab another module adds to the Staff Worklist: its id and the work it finds. The module builds
 * WorkItems from its own data and hands the source over with Worklist::contribute() when the
 * Worklist asks (its staff_worklist.collect_sources event), without loading the Worklist's classes.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Worklist;

interface WorkSource
{
    /** The tab's id: 3 to 16 lower-case letters or digits, starting with a letter (ItemKey::SOURCE_PATTERN). */
    public function id(): string;

    /**
     * The open work, for every patient or for one.
     *
     * @return list<WorkItem>
     */
    public function collect(?int $pid = null): array;
}
