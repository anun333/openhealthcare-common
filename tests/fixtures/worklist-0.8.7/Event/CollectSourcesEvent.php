<?php

/**
 * Asked as the worklist is put together: another module may add a tab of its own, a work source
 * with its label and the role that owns its rows (Care Management adds "Care management, not
 * billed"). It goes through OpenEMR's event dispatcher, so the worklist knows nothing of the module
 * that answers. The module decides who sees its tab: it adds the source only for users it admits.
 *
 *   $dispatcher->addListener(CollectSourcesEvent::EVENT_NAME, function (CollectSourcesEvent $e): void {
 *       $e->addSource(new MySource($e->now), xl('My tab'), xl('Front desk'));
 *   });
 *
 * A source's id is the first part of its rows' keys ("caremgmt:9301:12202609"): three to sixteen
 * lower-case letters or digits, starting with a letter, and none of the worklist's own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Event;

use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Source\WorkSourceInterface;

final class CollectSourcesEvent
{
    public const EVENT_NAME = 'staff_worklist.collect_sources';

    /** @var array<string, array{source: WorkSourceInterface, label: string, ownerRole: string}> */
    private array $added = [];

    public function __construct(public readonly \DateTimeImmutable $now)
    {
    }

    /**
     * @param string $label     the tab's name, already translated
     * @param string $ownerRole who owns a row nobody has been assigned, already translated
     */
    public function addSource(WorkSourceInterface $source, string $label, string $ownerRole): void
    {
        $id = $source->id();
        if (preg_match(ItemKey::CONTRIBUTED_SOURCE, $id) !== 1 || in_array($id, ItemKey::BUILT_IN, true) || isset($this->added[$id])) {
            throw new \InvalidArgumentException("A worklist source id must be 3 to 16 lower-case letters or digits, starting with a letter, and not one already in use: \"$id\"");
        }
        $this->added[$id] = ['source' => $source, 'label' => $label, 'ownerRole' => $ownerRole];
    }

    /** @return array<string, array{source: WorkSourceInterface, label: string, ownerRole: string}> */
    public function added(): array
    {
        return $this->added;
    }
}
