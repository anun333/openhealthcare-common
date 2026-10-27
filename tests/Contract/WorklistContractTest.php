<?php

/**
 * The Worklist contract on its own, and with the Worklist's own classes as 0.8.7 shipped them
 * (tests/fixtures/worklist-0.8.7, copied from that tag): a contributor built on this library adds
 * its tab to a Worklist from before the contract, and to one with contribute(). The Worklist's
 * classes load only in the tests that run in a process of their own, so the others see a site
 * without them.
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests\Contract;

use OpenHealthcare\Common\Contract\Worklist\ItemKey;
use OpenHealthcare\Common\Contract\Worklist\PatientRef;
use OpenHealthcare\Common\Contract\Worklist\WorkItem;
use OpenHealthcare\Common\Contract\Worklist\Worklist;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class WorklistContractTest extends TestCase
{
    private const LEGACY = __DIR__ . '/../fixtures/worklist-0.8.7';

    private static function item(int $pid = 7, string $ref = '42', string $mode = 'chart'): WorkItem
    {
        return new WorkItem(
            key: new ItemKey('bhplan', $pid, $ref),
            patient: new PatientRef($pid, 'Doe, Jan', '1980-02-03', 'P7', false, true),
            title: 'Treatment plan review due',
            detail: 'Last reviewed 2026-07-01',
            priority: WorkItem::PRIORITY_HIGH,
            since: new \DateTimeImmutable('2026-10-01 00:00:00'),
            ownerId: 3,
            actionLabel: 'Open plan',
            actionUrl: '/interface/patient_file/summary/demographics.php',
            actionMode: $mode,
            dismissible: true,
            siteId: 4,
            sensitivity: 'normal',
            overdue: true,
        );
    }

    private static function source(WorkItem ...$items): WorkSource
    {
        return new class ($items) implements WorkSource {
            public ?int $askedFor = -1;

            /** @param list<WorkItem> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function id(): string
            {
                return 'bhplan';
            }

            public function collect(?int $pid = null): array
            {
                $this->askedFor = $pid;
                return $pid === null ? $this->items : array_values(array_filter($this->items, static fn(WorkItem $i): bool => $i->patient->pid === $pid));
            }
        };
    }

    private static function requireLegacy(): void
    {
        foreach (['Source/WorkSourceInterface', 'Model/ItemKey', 'Model/PatientRef', 'Model/WorkItem', 'Event/CollectSourcesEvent'] as $file) {
            require_once self::LEGACY . "/$file.php";
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function badKeys(): iterable
    {
        yield 'source too short' => ['bh', 7, '1'];
        yield 'source with a capital' => ['BHplan', 7, '1'];
        yield 'source too long' => ['abcdefghijklmnopq', 7, '1'];
        yield 'patient zero' => ['bhplan', 0, '1'];
        yield 'ref zero' => ['bhplan', 7, '0'];
        yield 'ref with a dot' => ['bhplan', 7, '1.2'];
        yield 'ref with a prefix' => ['bhplan', 7, 'qr-1'];
        yield 'ref too long' => ['bhplan', 7, '1234567890123456789'];
        yield 'ref with a newline' => ['bhplan', 7, "1\n"];
    }

    #[DataProvider('badKeys')]
    public function testAKeyRefusesWhatTheWorklistWouldRefuse(string $source, int $pid, string $ref): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ItemKey($source, $pid, $ref);
    }

    public function testAKeyPrintsAsTheWorklistStoresIt(): void
    {
        $this->assertSame('bhplan:7:42', (string)new ItemKey('bhplan', 7, '42'));
        $this->assertSame('rcharge:2147483647:123456789012345678', (string)new ItemKey('rcharge', 2147483647, '123456789012345678'));
    }

    public function testThePatientComesFromTheSharedColumns(): void
    {
        $now = new \DateTimeImmutable('2026-10-26 12:00:00');
        $row = ['fname' => 'Jan', 'lname' => 'Doe', 'DOB' => '1980-02-03', 'pubpid' => 'P7', 'deceased_date' => '2026-10-01', 'portal_access' => '1'];
        $patient = PatientRef::fromRow(7, $row, $now);
        $this->assertSame(['Doe, Jan', '1980-02-03', 'P7', true, true], [$patient->name, $patient->dob, $patient->pubpid, $patient->deceased, $patient->portalAccess]);
        $this->assertSame('Doe', PatientRef::fromRow(7, ['lname' => 'Doe'], $now)->name);
        $this->assertFalse(PatientRef::isDeceased('0000-00-00 00:00:00', $now), 'a zero date is no date');
        $this->assertFalse(PatientRef::isDeceased('2026-11-01', $now), 'a date still to come');
        $this->assertFalse(PatientRef::isDeceased(null, $now));
        $this->assertFalse(PatientRef::isDeceased('not a date', $now));
        $this->assertStringContainsString('pd.deceased_date', PatientRef::SQL_COLUMNS);
        $this->assertStringContainsString('AS portal_access', PatientRef::SQL_COLUMNS);
    }

    public function testAnItemRefusesAPriorityOrModeTheWorklistDoesNotShow(): void
    {
        $this->assertSame('chart', self::item()->actionMode);
        foreach ([['priority', 0], ['mode', 'popup']] as [$what, $value]) {
            try {
                $what === 'priority'
                    ? new WorkItem(new ItemKey('bhplan', 7, '1'), new PatientRef(7, 'Doe', '', ''), 't', 'd', $value, new \DateTimeImmutable(), null, 'a', '/x', 'chart', true)
                    : self::item(mode: $value);
                $this->fail("$what $value was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnItemsKeyAndPatientMustBeTheSamePatient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkItem(new ItemKey('bhplan', 7, '1'), new PatientRef(8, 'Doe', '', ''), 't', 'd', 3, new \DateTimeImmutable(), null, 'a', '/x', 'chart', true);
    }

    public function testAWorklistWithContributeGetsTheSourceItself(): void
    {
        $event = new class (new \DateTimeImmutable('2026-10-26 08:00:00')) {
            /** @var list<array{WorkSource, string, string}> */
            public array $got = [];

            public function __construct(public readonly \DateTimeImmutable $now)
            {
            }

            public function contribute(WorkSource $source, string $label, string $ownerRole): void
            {
                $this->got[] = [$source, $label, $ownerRole];
            }

            public function addSource(object $source, string $label, string $ownerRole): void
            {
                throw new \LogicException('contribute() should have been used');
            }
        };
        $source = self::source(self::item());
        $this->assertTrue(Worklist::contribute($event, $source, 'Treatment plan reviews', 'Clinician'));
        $this->assertSame([[$source, 'Treatment plan reviews', 'Clinician']], $event->got);
        $this->assertSame('2026-10-26 08:00:00', Worklist::now($event)->format('Y-m-d H:i:s'));
    }

    public function testSomethingElseIsLeftAloneAndNothingFails(): void
    {
        $this->assertFalse(Worklist::contribute(new \stdClass(), self::source(), 'Tab', 'Clinician'));
        $this->assertInstanceOf(\DateTimeImmutable::class, Worklist::now(new \stdClass()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutTheWorklistsOwnTypesAnOldEventIsLeftAlone(): void
    {
        // An event with addSource() but none of the Worklist's types loaded: nothing to wrap the source in.
        $event = new class {
            public int $calls = 0;

            public function addSource(object $source, string $label, string $ownerRole): void
            {
                $this->calls++;
            }
        };
        $this->assertFalse(Worklist::worklistTypesLoaded());
        $this->assertFalse(Worklist::contribute($event, self::source(self::item()), 'Tab', 'Clinician'));
        $this->assertSame(0, $event->calls);
        $this->expectException(\LogicException::class);
        Worklist::asWorklistSource(self::source());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAWorklistFromBeforeTheContractTakesTheSourceThroughAddSource(): void
    {
        self::requireLegacy();
        $now = new \DateTimeImmutable('2026-10-26 08:00:00');
        $event = new \OpenHealthcare\StaffWorklist\Event\CollectSourcesEvent($now);
        $source = self::source(self::item(7, '42'), self::item(9, '43', 'dialog'));

        $this->assertTrue(Worklist::worklistTypesLoaded());
        $this->assertTrue(Worklist::contribute($event, $source, 'Treatment plan reviews', 'Clinician'));
        $added = $event->added();
        $this->assertSame(['bhplan'], array_keys($added));
        $this->assertSame(['Treatment plan reviews', 'Clinician'], [$added['bhplan']['label'], $added['bhplan']['ownerRole']]);
        $legacy = $added['bhplan']['source'];
        $this->assertInstanceOf(\OpenHealthcare\StaffWorklist\Source\WorkSourceInterface::class, $legacy);
        $this->assertSame('bhplan', $legacy->id());

        // The Worklist allows a contributed source's keys once it's added (WorklistService), then collects.
        \OpenHealthcare\StaffWorklist\Model\ItemKey::allow('bhplan');
        $items = $legacy->collect();
        $this->assertCount(2, $items);
        $first = $items[0];
        $this->assertInstanceOf(\OpenHealthcare\StaffWorklist\Model\WorkItem::class, $first);
        $this->assertSame('bhplan:7:42', (string)$first->key);
        $this->assertSame(
            ['Doe, Jan', '1980-02-03', 'P7', false, true],
            [$first->patient->name, $first->patient->dob, $first->patient->pubpid, $first->patient->deceased, $first->patient->portalAccess],
        );
        $this->assertSame(
            ['Treatment plan review due', 'Last reviewed 2026-07-01', 2, '2026-10-01', 3, 'Open plan', '/interface/patient_file/summary/demographics.php', 'chart', true, 4, 'normal', true],
            [$first->title, $first->detail, $first->priority, $first->since->format('Y-m-d'), $first->ownerId, $first->actionLabel, $first->actionUrl, $first->actionMode, $first->dismissible, $first->siteId, $first->sensitivity, $first->overdue],
        );
        $this->assertSame('dialog', $items[1]->actionMode);

        // The Worklist re-checks one patient's item against its source: the patient is passed through.
        $one = $legacy->collect(9);
        $this->assertSame(['bhplan:9:43'], array_map(static fn(object $i): string => (string)$i->key, $one));
        // And its key parses back, as an action on the item would send it.
        $this->assertNotNull(\OpenHealthcare\StaffWorklist\Model\ItemKey::parse('bhplan:9:43'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheOldWorklistsOwnRefusalsStillReachTheContributor(): void
    {
        self::requireLegacy();
        $event = new \OpenHealthcare\StaffWorklist\Event\CollectSourcesEvent(new \DateTimeImmutable());
        Worklist::contribute($event, self::source(), 'Tab', 'Clinician');
        $this->expectException(\InvalidArgumentException::class);
        Worklist::contribute($event, self::source(), 'Tab again', 'Clinician');
    }
}
