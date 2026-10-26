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
        // bad.php's 'Say \"yes\" to continue' is single-quoted, so PHP keeps its backslashes (1.4.0;
        // 1.3.3 read it as if double-quoted).
        $this->assertSame(["public/bad.php: The family's income", 'public/bad.php: Say \\"yes\\" to continue'], $result['found']);
    }

    public function testOnlyTheFoldersAskedForAreRead(): void
    {
        $this->assertSame(['checked' => 4, 'found' => []], TranslatedStrings::scan(self::ROOT, ['src']));
        $this->assertSame(['checked' => 0, 'found' => []], TranslatedStrings::scan(self::ROOT, ['no-such-folder']), 'a missing folder reads nothing, which a floor on checked catches');
    }

    public function testASingleQuotedPhpLiteralUnescapesOnlyItsQuoteAndBackslash(): void
    {
        $dir = sys_get_temp_dir() . '/ohc-strings-' . bin2hex(random_bytes(6));
        mkdir("$dir/src", 0777, true);
        file_put_contents(
            "$dir/src/page.php",
            "<?php echo xlt('Line\\none'); echo xlt('It\\'s'); echo xlt('Back\\\\slash'); echo xlt(\"Tab\\there\");\n"
        );
        $texts = array_column(TranslatedStrings::literals($dir, ['src']), 'text');
        $this->assertSame(['Line\\none', "It's", 'Back\\slash', "Tab\there"], $texts);
    }

    public function testTwigFilterLiteralsAreReadAndScannedWhenAsked(): void
    {
        $dir = sys_get_temp_dir() . '/ohc-strings-' . bin2hex(random_bytes(6));
        mkdir("$dir/templates", 0777, true);
        file_put_contents(
            "$dir/templates/card.html.twig",
            "{{ \"Claim's status\"|xla }} {{ 'Open'|xlt }} {{ 'x' | xl }} {{ 'Kept'|xlListLabel }} {{ 'Plain'|text }}\n"
        );
        $this->assertSame(["Claim's status", 'Open', 'x'], array_column(TranslatedStrings::twigFilterLiterals($dir, ['templates']), 'text'));
        $this->assertSame(['checked' => 0, 'found' => []], TranslatedStrings::scan($dir, ['templates']), 'scan() reads xl calls only, as before 1.4.0');
        $this->assertSame(['checked' => 3, 'found' => ["templates/card.html.twig: Claim's status"]], TranslatedStrings::scan($dir, ['templates'], true));
    }

    public function testThisPackageWritesNoSuchStringItself(): void
    {
        $result = TranslatedStrings::scan(dirname(__DIR__));
        $this->assertGreaterThan(0, $result['checked'], 'OpenEmr::deny translates its heading');
        $this->assertSame([], $result['found']);
    }
}
