<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenHealthcare\Common\TranslatedStrings;
use PHPUnit\Framework\TestCase;

final class TranslatedStringsTest extends TestCase
{
    private const ROOT = __DIR__ . '/fixtures/strings';

    public function testEveryStringWithAnApostropheOrQuoteMarkIsFoundAndNoOther(): void
    {
        $result = TranslatedStrings::scan(self::ROOT);
        $this->assertSame(7, $result['checked'], 'four in src, three in public; the .txt file is not read');
        $this->assertSame(["public/bad.php: The family's income", 'public/bad.php: Say "yes" to continue'], $result['found']);
    }

    public function testOnlyTheFoldersAskedForAreRead(): void
    {
        $this->assertSame(['checked' => 4, 'found' => []], TranslatedStrings::scan(self::ROOT, ['src']));
        $this->assertSame(['checked' => 0, 'found' => []], TranslatedStrings::scan(self::ROOT, ['no-such-folder']), 'a missing folder reads nothing, which a floor on checked catches');
    }

    public function testThisPackageWritesNoSuchStringItself(): void
    {
        $result = TranslatedStrings::scan(dirname(__DIR__));
        $this->assertGreaterThan(0, $result['checked'], 'OpenEmr::deny translates its heading');
        $this->assertSame([], $result['found']);
    }
}
