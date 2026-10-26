<?php

declare(strict_types=1);

namespace OpenHealthcare\Common\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\Common\PageLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Without OpenEMR loaded the session is $_SESSION (the 7.0 path); the 8.x session objects are read
 * through OpenEmr::session(), which OpenEmrBagTest covers on each shape.
 */
final class PageLanguageTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['language_choice']);
        QueryUtils::$rows = [];
        QueryUtils::$lastSql = '';
        QueryUtils::$lastBinds = [];
        QueryUtils::$throw = null;
    }

    public function testWithoutASessionThePageIsEnglishAndTheDatabaseIsNotAsked(): void
    {
        $this->assertSame('en', OpenEmr::pageLang());
        $this->assertSame('ltr', OpenEmr::pageDir());
        $this->assertSame('', QueryUtils::$lastSql);
    }

    public function testASpanishUserGetsSpanish(): void
    {
        $_SESSION['language_choice'] = '3';
        QueryUtils::$rows = [['lang_code' => 'es', 'lang_description' => 'Spanish (Spain)', 'lang_is_rtl' => 0]];
        $this->assertSame('es', OpenEmr::pageLang());
        $this->assertStringContainsString('FROM `lang_languages` WHERE `lang_id` = ?', QueryUtils::$lastSql);
        $this->assertSame([3], QueryUtils::$lastBinds);
        $this->assertSame('ltr', OpenEmr::pageDir());
    }

    public function testARightToLeftLanguageSetsTheDirection(): void
    {
        $_SESSION['language_choice'] = 22;
        QueryUtils::$rows = [['lang_code' => 'ar', 'lang_description' => 'Arabic', 'lang_is_rtl' => '1']];
        $this->assertSame('ar', OpenEmr::pageLang());
        $this->assertSame('rtl', OpenEmr::pageDir());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function codes(): array
    {
        return [
            'OpenEMR\'s Latin American Spanish' => ['el', 'Spanish (Latin American)', 'es-419'],
            'a site\'s own row using el for Greek' => ['el', 'Greek', 'el'],
            'OpenEMR\'s Greek' => ['gr', 'Greek', 'el'],
            'Swedish' => ['se', 'Swedish', 'sv'],
            'Traditional Chinese' => ['zt', 'Chinese (Traditional)', 'zh-Hant'],
            'Brazilian Portuguese' => ['pt', 'Portuguese (Brazilian)', 'pt-BR'],
            'Angolan Portuguese, not Punjabi' => ['pa', 'Portuguese (Angolan)', 'pt-AO'],
            'the dummy language' => ['dd', 'dummy', 'en'],
            'an ISO code as it is' => ['vi', 'Vietnamese', 'vi'],
            'upper case and spaces' => [' FR ', 'French (Standard)', 'fr'],
            'an unknown code' => ['x1', 'Klingon', 'en'],
            'an empty code' => ['', '', 'en'],
        ];
    }

    #[DataProvider('codes')]
    public function testOpenEmrsCodesBecomeLanguageTags(string $code, string $description, string $tag): void
    {
        $this->assertSame($tag, PageLanguage::bcp47($code, $description));
    }

    public function testNoRowOrNoTableMeansEnglish(): void
    {
        $_SESSION['language_choice'] = 99;
        $this->assertSame('en', OpenEmr::pageLang(), 'no lang_languages row for the choice');

        QueryUtils::$throw = new \RuntimeException('Table lang_languages does not exist');
        $this->assertSame('en', OpenEmr::pageLang(), 'a failed query');
        $this->assertSame('ltr', OpenEmr::pageDir());
    }

    public function testFromTheCommandLineThePageIsEnglish(): void
    {
        // A command-line tool has no session at all: a PHP process of its own, as the tool would be.
        $script = sprintf(
            'require %s; require %s; echo OpenHealthcare\\Common\\OpenEmr::pageLang(), "|", OpenHealthcare\\Common\\OpenEmr::pageDir();',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export(__DIR__ . '/stubs.php', true),
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        $this->assertSame('en|ltr', $out);
    }
}
