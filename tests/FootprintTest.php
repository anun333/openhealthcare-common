<?php

/**
 * The footprint tool, run as the modules run it, on a sample module (tests/fixtures/sample-module):
 * what the sheet says, --check, and a module that doesn't say what its sheet can't show.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use PHPUnit\Framework\TestCase;

final class FootprintTest extends TestCase
{
    private string $module;

    protected function setUp(): void
    {
        $this->module = sys_get_temp_dir() . '/footprint-test-' . bin2hex(random_bytes(4));
        self::copy(__DIR__ . '/fixtures/sample-module', $this->module);
    }

    protected function tearDown(): void
    {
        self::remove($this->module);
    }

    public function testTheSheetSaysWhatTheSampleModuleDoes(): void
    {
        [$code, $out] = $this->footprint();
        self::assertSame(0, $code, $out);
        $sheet = (string)file_get_contents($this->module . '/FOOTPRINT.md');
        foreach ([
            '`example/oe-module-sample` 1.2.3',
            '- **Tables it creates and owns:** `sample_thing`.',
            '`list_options`: 2 rows defining 1 new lists: `sample_list`.',
            "- **Rows it updates in `layout_options`:** only rows it added itself (`sample_field`).",
            "- **Rows it updates in `layout_options` that it didn't add:** where `form_id = 'DEM' AND field_id = 'core_field'`.",
            '| `patient_data` | `public/index.php` | — | OpenEMR core |',
            '| `sample_thing` | — | `public/index.php` | this module |',
            '| `sliding_fee_assessment` | `public/index.php` | — | the Sliding Fee module (another openhealthcare module) |',
            '| `staff_worklist_log` | — | `public/index.php` | the Staff Worklist module (another openhealthcare module) |',
            "**In its own SQL it writes to no OpenEMR core table.** **It writes to another openhealthcare module's tables:** `staff_worklist_log`.",
            '| `patients / med (write or addonly)` | `public/index.php` |',
            "- `patient-record-select`, through OpenEMR's `EventAuditLogger`, from `public/index.php`.",
            '- `public/index.php`: `file_put_contents()`',
            "## Programs it runs\n\n- `src/Bootstrap.php`: `proc_open()`\n",
            'Menu entry "Sample", shown to users with `patients / med`.',
            "## Not covered here\n\n- Sample note one.\n- Sample note two.\n",
        ] as $line) {
            self::assertStringContainsString($line, $sheet);
        }
    }

    public function testCheckPassesUntilTheModuleChanges(): void
    {
        $this->footprint();
        self::assertSame(0, $this->footprint('--check')[0]);
        file_put_contents($this->module . '/public/index.php', "\n\$more = QueryUtils::fetchRecords('SELECT `id` FROM `users`');\n", FILE_APPEND);
        [$code, $out] = $this->footprint('--check');
        self::assertSame(1, $code);
        self::assertStringContainsString('FOOTPRINT.md is stale', $out);
    }

    public function testAModuleMustSayWhatItsSheetCannotShow(): void
    {
        $composer = json_decode((string)file_get_contents($this->module . '/composer.json'), true);
        unset($composer['extra']);
        file_put_contents($this->module . '/composer.json', json_encode($composer));
        [$code, $out] = $this->footprint();
        self::assertSame(2, $code);
        self::assertStringContainsString('extra.openhealthcare.footprint-not-covered', $out);
        self::assertFileDoesNotExist($this->module . '/FOOTPRINT.md');
    }

    public function testItRefusesAFolderThatIsNotAModule(): void
    {
        [$code, $out] = $this->footprint('', sys_get_temp_dir());
        self::assertSame(2, $code);
        self::assertStringContainsString("run it in a module's folder", $out);
    }

    /** @return array{0: int, 1: string} */
    private function footprint(string $arg = '', ?string $root = null): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/footprint.php')
            . ' ' . escapeshellarg('--root=' . ($root ?? $this->module)) . ($arg === '' ? '' : ' ' . escapeshellarg($arg)) . ' 2>&1';
        exec($command, $lines, $code);
        return [$code, implode("\n", $lines)];
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $target = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
            $f->isDir() ? mkdir($target) : copy($f->getPathname(), $target);
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
