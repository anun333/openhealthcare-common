<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenHealthcare\Common\GuideLabels;
use PHPUnit\Framework\TestCase;

final class GuideLabelsTest extends TestCase
{
    /**
     * A module folder with this guide, page and composer.json extra.
     *
     * @param array<string, string> $elsewhere
     */
    private static function module(string $guide, string $page, array $elsewhere = [], ?string $data = null): string
    {
        $dir = sys_get_temp_dir() . '/ohc-guide-' . bin2hex(random_bytes(6));
        mkdir("$dir/public", 0777, true);
        mkdir("$dir/data");
        file_put_contents("$dir/GUIDE.md", $guide);
        file_put_contents("$dir/public/index.php", $page);
        file_put_contents("$dir/composer.json", json_encode(['extra' => ['openhealthcare' => ['guide-labels-elsewhere' => $elsewhere]]]));
        if ($data !== null) {
            file_put_contents("$dir/data/priorities.json", $data);
        }
        return $dir;
    }

    public function testEveryBoldLabelIsATranslatedString(): void
    {
        $dir = self::module(
            "# Guide\n\nOpen **Worklist**, pick **Mine**, then **Assign…** and **Open chart**:\n",
            "<?php echo xlt('Worklist'); echo xla('Mine'); echo text(xl(\"Assign\")); echo xlt('Open chart:');\n"
        );
        $result = GuideLabels::check($dir);
        $this->assertSame([], $result['missing']);
        $this->assertSame(4, $result['labels']);
        $this->assertSame(4, $result['strings']);
    }

    public function testARenamedButtonIsMissing(): void
    {
        $dir = self::module("Press **Snooze**, then **Dismiss**.\n", "<?php echo xlt('Snooze for a week'); echo xlt('Dismiss');\n");
        $this->assertSame(['Snooze'], GuideLabels::check($dir)['missing']);
    }

    public function testALabelFromDataIsNamedWithItsFile(): void
    {
        $guide = "Items marked **High** come first.\n";
        $page = "<?php echo xlt('Worklist');\n";
        $this->assertSame(['High'], GuideLabels::check(self::module($guide, $page))['missing'], 'not translated and not named');
        $this->assertSame([], GuideLabels::check(self::module($guide, $page, ['High' => 'data/priorities.json'], '["High","Low"]'))['missing']);
        // Named, but the file no longer has it: that goes stale too, so it fails.
        $stale = GuideLabels::check(self::module($guide, $page, ['High' => 'data/priorities.json'], '["Urgent","Low"]'));
        $this->assertSame(["High (named as coming from data/priorities.json, which doesn't contain it)"], $stale['missing']);
    }

    public function testNoGuideIsAnError(): void
    {
        $dir = self::module('x', '<?php');
        unlink("$dir/GUIDE.md");
        $this->expectException(\RuntimeException::class);
        GuideLabels::check($dir);
    }
}
