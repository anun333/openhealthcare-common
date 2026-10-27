<?php

/**
 * Where modules copied in by hand carry different copies of the library, the first copy registered serves the
 * namespace. A module from 1.5.0 also registers its copy's contracts under OpenHealthcare\Common\Contract\, which
 * Composer looks under before the shorter prefix, so a tab or a figure works even where an older copy came first.
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class TwoCopiesTest extends TestCase
{
    private string $old = '';

    protected function setUp(): void
    {
        // The old copy: OpenEmr and nothing of the contracts, as 1.4.0 and earlier have.
        $this->old = sys_get_temp_dir() . '/ohc-old-copy-' . bin2hex(random_bytes(6));
        mkdir($this->old);
        file_put_contents("{$this->old}/OpenEmr.php", "<?php\nnamespace OpenHealthcare\\Common;\nfinal class OpenEmr {}\n");
    }

    protected function tearDown(): void
    {
        unlink("{$this->old}/OpenEmr.php");
        rmdir($this->old);
    }

    /** @return array<string, ?string> */
    private function classesFrom(string $mode): array
    {
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/../fixtures/two-copies.php', $this->old, realpath(__DIR__ . '/../../src'), $mode])) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        return json_decode(implode('', $out), true, 4, JSON_THROW_ON_ERROR);
    }

    public function testTheContractsComeFromTheCopyThatHasThem(): void
    {
        $got = $this->classesFrom('with-contract');
        $this->assertSame("{$this->old}/OpenEmr.php", $got['OpenEmr'], 'the rest still comes from the copy registered first');
        $this->assertStringEndsWith('/src/Contract/Worklist/WorkSource.php', (string)$got['WorkSource']);
        $this->assertStringEndsWith('/src/Contract/Figures/FiguresRequest.php', (string)$got['FiguresRequest']);
    }

    public function testWithoutTheirOwnPrefixTheContractsAreMissing(): void
    {
        // The control: an old copy registered first hides them, which is why a module registers the prefix.
        $got = $this->classesFrom('without-contract');
        $this->assertSame("{$this->old}/OpenEmr.php", $got['OpenEmr']);
        $this->assertNull($got['WorkSource']);
        $this->assertNull($got['FiguresRequest']);
    }
}
