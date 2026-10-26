<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\QuestionnaireStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuestionnaireStatusTest extends TestCase
{
    private const SINCE = '2026-11-02 09:15:00';

    protected function tearDown(): void
    {
        QueryUtils::$rows = [];
    }

    public function testTheMomentIsTheFirstCompletedSaveByItsLastUpdate(): void
    {
        QueryUtils::$rows = [['since' => self::SINCE]];
        $this->assertSame(self::SINCE, QuestionnaireStatus::since());
        $this->assertStringContainsString("MIN(COALESCE(`last_updated`, `create_time`))", QueryUtils::$lastSql);
        $this->assertStringContainsString("`status` IN ('completed', 'amended')", QueryUtils::$lastSql);

        QueryUtils::$rows = [['since' => null]];
        $this->assertNull(QuestionnaireStatus::since(), 'no completed response yet');
    }

    /**
     * @return array<string, array{?string, ?string, ?string, bool}>
     */
    public static function rows(): array
    {
        return [
            'old runtime: in-progress counts' => ['in-progress', '2026-10-01 10:00:00', null, true],
            'old runtime: no status counts' => [null, '2026-10-01 10:00:00', null, true],
            'old runtime: entered-in-error does not' => ['entered-in-error', '2026-10-01 10:00:00', null, false],
            'saved before the switch, in-progress' => ['in-progress', '2026-11-01 16:00:00', self::SINCE, true],
            'saved after the switch, in-progress: a draft' => ['in-progress', '2026-11-03 08:00:00', self::SINCE, false],
            'saved after the switch, completed' => ['completed', '2026-11-03 08:00:00', self::SINCE, true],
            'saved after the switch, amended' => ['amended', '2026-11-04 08:00:00', self::SINCE, true],
            'at the switch itself, completed' => ['completed', self::SINCE, self::SINCE, true],
            'after the switch, stopped' => ['stopped', '2026-11-03 08:00:00', self::SINCE, false],
            'before the switch, entered-in-error' => ['entered-in-error', '2026-11-01 16:00:00', self::SINCE, false],
            'no create time after the switch' => ['in-progress', null, self::SINCE, false],
        ];
    }

    #[DataProvider('rows')]
    public function testARowCountsByTheRule(?string $status, ?string $created, ?string $since, bool $counts): void
    {
        $this->assertSame($counts, QuestionnaireStatus::counts($status, $created, $since));
    }

    public function testTheSqlConditionIsTheSameRule(): void
    {
        [$sql, $binds] = QuestionnaireStatus::conditionSince('qr', null);
        $this->assertSame("COALESCE(`qr`.`status`, '') <> 'entered-in-error'", $sql);
        $this->assertSame([], $binds);

        [$sql, $binds] = QuestionnaireStatus::conditionSince('qr', self::SINCE);
        $this->assertSame(
            "(COALESCE(`qr`.`status`, '') IN ('completed', 'amended') OR (COALESCE(`qr`.`status`, '') <> 'entered-in-error' AND `qr`.`create_time` < ?))",
            $sql
        );
        $this->assertSame([self::SINCE], $binds);

        QueryUtils::$rows = [['since' => self::SINCE]];
        $this->assertSame([self::SINCE], QuestionnaireStatus::condition('qr')[1], 'condition() asks since() itself');
    }

    public function testAnAliasMustBeAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QuestionnaireStatus::conditionSince('qr; DROP TABLE x', null);
    }
}
