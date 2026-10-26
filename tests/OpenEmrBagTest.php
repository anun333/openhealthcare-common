<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenHealthcare\Common\OpenEmr;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * OpenEmr against the two shapes of OEGlobalsBag, each in its own process, as the bag is defined
 * once per process: 7.0.4 to 8.0.x's, with get(), set() and has() only, and 8.1.0's, with the
 * getters. 1.0.0 to 1.3.0 called the getters wherever the class existed, which stopped every page
 * on 8.0.0 (the official image, 2026-10-25).
 */
final class OpenEmrBagTest extends TestCase
{
    private const BAG = <<<'PHP'
        namespace OpenEMR\Core;

        class OEGlobalsBag
        {
            private static ?self $instance = null;
            /** @var array<string, mixed> */
            public array $values = [];

            public static function getInstance(): self
            {
                return self::$instance ??= new static();
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }
        }
        PHP;

    #[RunInSeparateProcess]
    public function testOn80TheValuesComeFromTheBagByName(): void
    {
        eval(self::BAG);
        $bag = \OpenEMR\Core\OEGlobalsBag::getInstance();
        $bag->set('webroot', '/openemr');
        $bag->set('fileroot', '/var/www/openemr');
        $bag->set('srcdir', '/var/www/openemr/library');
        $bag->set('OE_SITE_DIR', '/var/www/openemr/sites/default');
        $kernel = new \stdClass();
        $bag->set('kernel', $kernel);

        self::assertSame('/openemr', OpenEmr::webRoot());
        self::assertSame('/var/www/openemr', OpenEmr::projectDir());
        self::assertSame('/var/www/openemr/library', OpenEmr::srcDir());
        self::assertSame('/var/www/openemr/sites/default', OpenEmr::siteDir());
        self::assertSame($kernel, OpenEmr::kernel());
    }

    #[RunInSeparateProcess]
    public function testOn81TheGettersAreUsedAndNoKernelMeansNone(): void
    {
        eval(self::BAG);
        eval(<<<'PHP'
            namespace OpenEMR\Core;

            final class Bag81 extends OEGlobalsBag
            {
                public function getWebRoot(): string
                {
                    return '/from-the-kernel';
                }

                public function getProjectDir(): string
                {
                    return '/kernel/project';
                }

                public function getSrcDir(): string
                {
                    return '/kernel/project/library';
                }

                public function hasKernel(): bool
                {
                    return false;
                }

                public function getKernel(): object
                {
                    throw new \RuntimeException('no kernel: getKernel() must not be called');
                }
            }
            PHP);
        // getInstance() is `new static()` on first call: make the 8.1 shape the instance.
        $reflection = new \ReflectionProperty(\OpenEMR\Core\OEGlobalsBag::class, 'instance');
        $reflection->setValue(null, new \OpenEMR\Core\Bag81());
        \OpenEMR\Core\OEGlobalsBag::getInstance()->set('webroot', '/stored');

        self::assertSame('/from-the-kernel', OpenEmr::webRoot(), 'the getter, not the stored value');
        self::assertSame('/kernel/project', OpenEmr::projectDir());
        self::assertSame('/kernel/project/library', OpenEmr::srcDir());
        self::assertNull(OpenEmr::kernel(), 'hasKernel() false: no kernel, and getKernel() is not called');
    }
}
