<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenHealthcare\Common\QuestionnaireRuntime;
use PHPUnit\Framework\TestCase;

final class QuestionnaireRuntimeTest extends TestCase
{
    /** A project directory holding a runtime file with this source, or none when null. */
    private static function project(?string $source): string
    {
        $dir = sys_get_temp_dir() . '/ohc-runtime-' . bin2hex(random_bytes(6));
        if ($source !== null) {
            mkdir(dirname("$dir/" . QuestionnaireRuntime::PATH), 0777, true);
            file_put_contents("$dir/" . QuestionnaireRuntime::PATH, $source);
        } else {
            mkdir($dir);
        }
        return $dir;
    }

    public function testTheVersionIsReadFromTheRuntimesOwnDeclaration(): void
    {
        // As 2ee0a6a503 declares it, thousands of lines into the file.
        $master = self::project(str_repeat("// ...\n", 6000) . "    static info = {\n        version: '0.4.0',\n    };\n");
        $this->assertSame('0.4.0', QuestionnaireRuntime::version($master));
        $this->assertTrue(QuestionnaireRuntime::atLeast(QuestionnaireRuntime::COMPLETES, $master));

        // rel-850 before the backport.
        $before = self::project("        version: '0.2.0',\n");
        $this->assertSame('0.2.0', QuestionnaireRuntime::version($before));
        $this->assertFalse(QuestionnaireRuntime::atLeast(QuestionnaireRuntime::COMPLETES, $before));
    }

    public function testNoRuntimeOrNoDeclarationIsNoVersion(): void
    {
        $none = self::project(null);
        $this->assertNull(QuestionnaireRuntime::version($none), 'a release before the native runtime');
        $this->assertFalse(QuestionnaireRuntime::atLeast('0.1.0', $none));
        $this->assertNull(QuestionnaireRuntime::version(self::project("const fhirVersion = '4.0.1';\n")), 'only a version: key counts');
    }
}
