<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests\Contract;

use OpenHealthcare\Common\Contract\Forms\DocumentFill;
use PHPUnit\Framework\TestCase;

final class DocumentFillContractTest extends TestCase
{
    /** An event shaped as Clinic Forms' DocumentFillEvent is from 0.33.0. */
    private static function event(string $documentId = 'sliding-fee-notice', string $language = 'es', ?int $pid = null): object
    {
        return new class ($documentId, $language, ['discounts'], $pid) {
            /** @var array<string, array{rows: list<list<string>>, note: ?string}> */
            public array $tables = [];

            /** @param list<string> $tableIds */
            public function __construct(
                public readonly string $documentId,
                public readonly string $language,
                public readonly array $tableIds,
                public readonly ?int $pid = null,
            ) {
            }

            /** @param list<list<string>> $rows */
            public function fillTable(string $id, array $rows, ?string $note = null): void
            {
                if (!in_array($id, $this->tableIds, true)) {
                    throw new \InvalidArgumentException("no table $id");
                }
                $this->tables[$id] = ['rows' => $rows, 'note' => $note];
            }
        };
    }

    public function testItAnswersOnlyForItsDocumentAndTable(): void
    {
        $event = self::event();
        $this->assertTrue(DocumentFill::asks($event, 'sliding-fee-notice', 'discounts'));
        $this->assertFalse(DocumentFill::asks($event, 'sliding-fee-notice', 'income'));
        $this->assertFalse(DocumentFill::asks($event, 'privacy-notice', 'discounts'));
        $this->assertFalse(DocumentFill::asks(new \stdClass(), 'sliding-fee-notice', 'discounts'));
    }

    public function testItReadsTheEventAndFillsTheTable(): void
    {
        $event = self::event(pid: 12);
        $this->assertSame(['es', 12], [DocumentFill::language($event), DocumentFill::pid($event)]);
        $this->assertSame(['en', null], [DocumentFill::language(self::event(language: '')), DocumentFill::pid(self::event())]);
        DocumentFill::fill($event, 'discounts', [['100%', '$20']], 'From the schedule in force');
        $this->assertSame(['discounts' => ['rows' => [['100%', '$20']], 'note' => 'From the schedule in force']], $event->tables);
    }

    public function testTheEventsNameIsClinicFormsOwn(): void
    {
        $this->assertSame('clinic_forms.document.fill', DocumentFill::EVENT_NAME);
    }
}
