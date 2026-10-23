<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenHealthcare\Common\OpenEmr;
use PHPUnit\Framework\TestCase;

/**
 * Without OpenEMR loaded, OpenEmr takes its 7.0 path everywhere: no OEGlobalsBag, no session
 * factory, no access-denied helper. The 8.x paths run in each module's end-to-end checks.
 */
final class OpenEmrTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['OE_SITE_DIR'], $GLOBALS['webroot'], $GLOBALS['fileroot'], $GLOBALS['openemr_version'], $GLOBALS['kernel'], $_SESSION['authUser']);
    }

    public function testSettingsComeFromTheGlobalsOn70(): void
    {
        $GLOBALS['OE_SITE_DIR'] = '/var/www/openemr/sites/default';
        $GLOBALS['webroot'] = '/openemr';
        $GLOBALS['fileroot'] = '/var/www/openemr';
        $GLOBALS['openemr_version'] = '7.0.2';
        $this->assertSame(['/var/www/openemr/sites/default', '/openemr', '/var/www/openemr', '7.0.2'],
            [OpenEmr::siteDir(), OpenEmr::webRoot(), OpenEmr::projectDir(), OpenEmr::version()]);
        $GLOBALS['webroot'] = ['not', 'a', 'string'];
        $this->assertSame('', OpenEmr::webRoot(), 'a setting that is not a scalar reads as empty');
    }

    public function testTheSessionComesFromSessionOn70(): void
    {
        $_SESSION['authUser'] = 'admin';
        $this->assertSame('admin', OpenEmr::session('authUser'));
        $this->assertNull(OpenEmr::session('nothing'));
    }

    public function testWithoutAKernelNobodyListens(): void
    {
        OpenEmr::dispatch(new \stdClass(), 'openhealthcare.test');
        $this->expectException(\RuntimeException::class);
        OpenEmr::eventDispatcher();
    }

    public function testDenyIsAPlain403PageWithoutOpenEmrsHelper(): void
    {
        // deny() ends the request, so it runs in a PHP process of its own.
        $script = sprintf(
            'require %s; require %s; OpenHealthcare\\Common\\OpenEmr::deny("no access", "Sliding <Fee>", "Sliding Fee");',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export(__DIR__ . '/stubs.php', true),
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_log=/dev/null -r ' . escapeshellarg($script) . ' 2>&1');
        $this->assertIsString($out);
        $this->assertStringContainsString('<title>Sliding &lt;Fee&gt;</title>', $out, 'the title is escaped');
        $this->assertStringContainsString('<h1>Not authorized</h1>', $out);
    }
}
