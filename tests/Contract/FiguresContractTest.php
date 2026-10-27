<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests\Contract;

use OpenHealthcare\Common\Contract\Figures\Figure;
use OpenHealthcare\Common\Contract\Figures\FiguresRequest;
use PHPUnit\Framework\TestCase;

final class FiguresContractTest extends TestCase
{
    private static function figure(string $id = 'slidingfee.discounts_posted', int|float $value = 1234.5, string $unit = Figure::UNIT_MONEY): Figure
    {
        return new Figure($id, 'Sliding fee discounts posted', $value, $unit, 'Sliding Fee Discount', 'Visits dated in the period', '/interface/modules/custom_modules/oe-module-sliding-fee/public/evaluation.php');
    }

    public function testAFigureNamesItsModuleAndUnit(): void
    {
        $figure = self::figure();
        $this->assertSame(['slidingfee.discounts_posted', 1234.5, 'money'], [$figure->id, $figure->value, $figure->unit]);
        foreach (['discounts', 'sf.discounts', 'slidingfee.', 'SlidingFee.discounts', 'slidingfee.discounts-posted'] as $bad) {
            try {
                self::figure($bad);
                $this->fail("$bad was accepted as an id");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[1.0, 'dollars'], [NAN, Figure::UNIT_COUNT], [INF, Figure::UNIT_MONEY]] as [$value, $unit]) {
            try {
                self::figure(value: $value, unit: $unit);
                $this->fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testARequestKeepsOnlyWhatWasWanted(): void
    {
        $request = new FiguresRequest(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-09-30'), ['slidingfee.discounts_posted'], 4);
        $this->assertTrue($request->wants('slidingfee.discounts_posted'));
        $this->assertFalse($request->wants('slidingfee.visits_discounted'));
        $request->answer(self::figure());
        $request->answer(self::figure('slidingfee.visits_discounted', 12, Figure::UNIT_COUNT));
        $this->assertSame(['slidingfee.discounts_posted'], array_keys($request->figures()));
        $this->assertSame(4, $request->facilityId);

        $all = new FiguresRequest(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'));
        $this->assertTrue($all->wants('caremgmt.months_billed'), 'asking for nothing in particular asks for everything');
    }

    public function testASecondAnswerForOneFigureIsAMistake(): void
    {
        $request = new FiguresRequest(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $request->answer(self::figure());
        $this->expectException(\LogicException::class);
        $request->answer(self::figure());
    }

    public function testAPeriodRunsForwardAndWantedIdsAreIds(): void
    {
        foreach ([['2026-02-01', '2026-01-31', []], ['2026-01-01', '2026-01-31', ['not an id']], ['2026-01-01', '2026-01-31', [5]]] as [$from, $to, $wanted]) {
            try {
                new FiguresRequest(new \DateTimeImmutable($from), new \DateTimeImmutable($to), $wanted);
                $this->fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOutsideOpenEmrNobodyAnswers(): void
    {
        $this->assertSame([], FiguresRequest::ask(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
    }
}
