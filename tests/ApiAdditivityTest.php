<?php

/**
 * 1.x only adds: every class, constant, method and property of each released 1.x is still here
 * and works as it did (tools/api-signature.php names what counts as a break). The checks run the
 * tool in a process of its own, so the surface is read from src exactly as a site would load it.
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiAdditivityTest extends TestCase
{
    private const TOOL = __DIR__ . '/../tools/api-signature.php';

    /** @return array{int, string} exit code and what it printed */
    private static function check(string $against): array
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::TOOL) . ' ' . escapeshellarg("--against=$against") . ' ' . escapeshellarg(__DIR__ . '/../src') . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    /** @return iterable<string, array{string}> */
    public static function released(): iterable
    {
        foreach (glob(__DIR__ . '/fixtures/api-*.json') ?: [] as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    public function testEveryReleasedSurfaceIsKept(): void
    {
        $this->assertNotEmpty(iterator_to_array(self::released()), 'no released surface to check against');
    }

    #[DataProvider('released')]
    public function testNothingReleasedIsBroken(string $surface): void
    {
        [$code, $out] = self::check($surface);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/: 0 breaks$/', $out);
    }

    public function testTheCheckFindsABreak(): void
    {
        // The control: a surface with a method, a constant's value, an optional parameter and an
        // interface's methods the current src doesn't match must be named, or a pass means nothing.
        $old = json_decode((string)file_get_contents(__DIR__ . '/fixtures/api-1.4.0.json'), true, 512, JSON_THROW_ON_ERROR);
        $old['OpenHealthcare\\Common\\OpenEmr']['methods']['gone'] = ['static' => true, 'return' => 'void', 'params' => []];
        $old['OpenHealthcare\\Common\\QuestionnaireRuntime']['constants']['PATH'] = 'elsewhere.js';
        $old['OpenHealthcare\\Common\\OpenEmr']['methods']['deny']['params'][2]['default'] = "'other module'";
        $old['OpenHealthcare\\Common\\Contract\\Worklist\\WorkSource'] = [
            'kind' => 'interface', 'final' => false, 'abstract' => false, 'constants' => [], 'properties' => [],
            'methods' => ['id' => ['static' => false, 'return' => 'string', 'params' => []]],
        ];
        $file = tempnam(sys_get_temp_dir(), 'ohc-api');
        file_put_contents($file, json_encode($old));
        try {
            [$code, $out] = self::check($file);
        } finally {
            unlink($file);
        }
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('OpenHealthcare\\Common\\OpenEmr::gone() is gone', $out);
        $this->assertStringContainsString('OpenHealthcare\\Common\\QuestionnaireRuntime::PATH changed value', $out);
        $this->assertStringContainsString("OpenHealthcare\\Common\\OpenEmr::deny() changed \$module's default", $out);
        $this->assertStringContainsString('OpenHealthcare\\Common\\Contract\\Worklist\\WorkSource::collect() was added to an interface', $out);
    }
}
