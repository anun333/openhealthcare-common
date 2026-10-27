<?php

/**
 * Asks other modules for figures over a period, through OpenEMR's event dispatcher, so a dashboard
 * (Revenue Cycle's) can show another module's totals without reading its tables. The asking module
 * calls ask(); each answering module listens for EVENT_NAME and answers the figures it offers:
 *
 *   $dispatcher->addListener(FiguresRequest::EVENT_NAME, function (object $event): void {
 *       if (!$event instanceof FiguresRequest || !$event->wants('slidingfee.discounts_posted')) {
 *           return;
 *       }
 *       $event->answer(new Figure('slidingfee.discounts_posted', xl('Sliding fee discounts posted'), 1234.5, Figure::UNIT_MONEY, xl('Sliding Fee Discount')));
 *   });
 *
 * An answering module checks its own access rule for this user before answering, as its own page
 * would, and catches its own errors: one module's failure shouldn't take the asking page down.
 * A figure nobody answers is simply missing; the asking page says it isn't available.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Figures;

use OpenHealthcare\Common\OpenEmr;

final class FiguresRequest
{
    public const EVENT_NAME = 'openhealthcare.figures.request';

    /** @var array<string, Figure> */
    private array $figures = [];

    /**
     * @param \DateTimeImmutable $from       the period's first day
     * @param \DateTimeImmutable $to         its last day, included
     * @param list<string>       $wanted     the figure ids asked for; none asks for every figure offered
     * @param int|null           $facilityId facility.id to count for, or null for every facility
     */
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly array $wanted = [],
        public readonly ?int $facilityId = null,
    ) {
        if ($to < $from) {
            throw new \InvalidArgumentException('A period must end on or after its first day');
        }
        foreach ($wanted as $id) {
            if (!is_string($id) || preg_match(Figure::ID_PATTERN, $id) !== 1) {
                throw new \InvalidArgumentException('Not a figure id: ' . var_export($id, true));
            }
        }
    }

    /**
     * Sends the request and returns what came back, by id. Outside OpenEMR (a command-line tool,
     * the tests) nobody is listening, so it's empty.
     *
     * @param list<string> $wanted
     * @return array<string, Figure>
     */
    public static function ask(\DateTimeImmutable $from, \DateTimeImmutable $to, array $wanted = [], ?int $facilityId = null): array
    {
        $request = new self($from, $to, $wanted, $facilityId);
        OpenEmr::dispatch($request, self::EVENT_NAME);
        return $request->figures();
    }

    /** Whether the asker wants this figure. */
    public function wants(string $id): bool
    {
        return $this->wanted === [] || in_array($id, $this->wanted, true);
    }

    /**
     * Gives a figure. One the asker didn't want is dropped; a second answer for the same id is a
     * mistake in one of the modules and throws.
     */
    public function answer(Figure $figure): void
    {
        if (!$this->wants($figure->id)) {
            return;
        }
        if (isset($this->figures[$figure->id])) {
            throw new \LogicException("Figure {$figure->id} was answered twice");
        }
        $this->figures[$figure->id] = $figure;
    }

    /** @return array<string, Figure> */
    public function figures(): array
    {
        return $this->figures;
    }
}
