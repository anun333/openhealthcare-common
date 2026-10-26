<?php

/**
 * Generates a module's FOOTPRINT.md: what it adds to an OpenEMR install, what it reads and writes,
 * and what it hooks, extracted from the source so the sheet cannot drift from the code. One tool
 * for every openhealthcare module: copies in each module drifted apart (one had no permission
 * checks section, five carried another module's closing notes).
 *
 *     php tools/footprint.php [--check]                  in a module (its tools/footprint.php loads this)
 *     vendor/bin/footprint [--root=<module>] [--check]   the same, from anywhere
 *
 * --check exits 1 if FOOTPRINT.md is stale. What the sheet can't show (its closing notes) is the
 * module's own, from composer.json: extra.openhealthcare.footprint-not-covered, a list of sentences.
 *
 * Code the module runs from openhealthcare-common is read as the module's: the OpenEmr methods it
 * calls, and what those call through self::.
 *
 * SQL is found with PHP's own tokenizer, in string literals only, and only in
 * strings that begin with a SQL verb, so `$from`, `<select>` and prose such as
 * "from %d to %d" are never read as queries. Each extraction runs a self-test
 * before it is trusted: a probe that finds nothing looks the same as a module
 * that does nothing.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Composer installs the package under the web root; the tools are for the command line only.
    http_response_code(404);
    exit;
}

$root = getcwd() ?: '.';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    }
}
$root = rtrim((string) realpath($root), '/');
if ($root === '' || !is_file("$root/composer.json") || !is_file("$root/info.txt")) {
    fwrite(STDERR, "footprint: run it in a module's folder, or pass --root=<module folder> (no composer.json and info.txt there)\n");
    exit(2);
}
$out = $root . '/FOOTPRINT.md';

/**
 * Every string literal in a PHP source, with interpolations replaced by `?`.
 * Literals joined by `.` come back as one string, the operands between them
 * replaced by `?`, so `"SELECT …" . Cols::SQL . " FROM t"` is one query.
 *
 * @return list<string>
 */
/** Calls to OpenEMR classes that write, and what they write. */
const CORE_CLASS_WRITES = [
    '->recordDisclosure(' => "`extended_log`, through `EventAuditLogger::recordDisclosure()`",
    '->updateRecordedDisclosure(' => "`extended_log`, through `EventAuditLogger::updateRecordedDisclosure()`",
    '->deleteDisclosure(' => "`extended_log`, through `EventAuditLogger::deleteDisclosure()`",
    '->createDocument(' => "`documents` and `categories_to_documents`, and the file in the site's documents folder, through `Document::createDocument()`",
    '->saveQuestionnaireResource(' => "`questionnaire_repository`, and a `uuid_registry` row for a new questionnaire, through `QuestionnaireService::saveQuestionnaireResource()`",
];

function stringLiterals(string $php): array
{
    $items = [];
    $current = null;
    foreach (token_get_all($php) as $tok) {
        if ($current !== null) {
            if ($tok === '"' || (is_array($tok) && $tok[0] === T_END_HEREDOC)) {
                $items[] = ['lit', $current];
                $current = null;
            } elseif (is_array($tok) && $tok[0] === T_ENCAPSED_AND_WHITESPACE) {
                $current .= $tok[1];
            } else {
                $current .= '?';
            }
            continue;
        }
        if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($tok === '"' || (is_array($tok) && $tok[0] === T_START_HEREDOC)) {
            $current = '';
        } elseif (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $items[] = ['lit', substr($tok[1], 1, -1)];
        } else {
            $items[] = ['tok', is_array($tok) ? $tok[1] : $tok];
        }
    }
    $strings = [];
    $n = count($items);
    for ($i = 0; $i < $n; $i++) {
        if ($items[$i][0] !== 'lit') {
            continue;
        }
        $s = $items[$i][1];
        $j = $i + 1;
        while ($j < $n && $items[$j] === ['tok', '.']) {
            $j++;
            if ($j < $n && $items[$j][0] === 'lit') {
                $s .= $items[$j][1];
                $j++;
                continue;
            }
            // A non-literal operand: skip to the next `.` or the end of the expression.
            $s .= '?';
            $depth = 0;
            for (; $j < $n; $j++) {
                [$kind, $v] = $items[$j];
                if ($kind !== 'tok') {
                    continue;
                }
                if (in_array($v, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($v, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && in_array($v, ['.', ';', ',', '?', ':', '=>'], true)) {
                    break;
                }
            }
        }
        $strings[] = $s;
        $i = $j - 1;
    }
    return $strings;
}

/** @return array{reads: list<string>, writes: list<string>}|null */
function sqlTables(string $s): ?array
{
    $s = trim($s);
    // Upper case only, as every query in this module is written, so a sentence
    // that starts "Select a site from the list" is not read as one.
    if (!preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/', $s, $m)) {
        // A fragment such as a shared column list can carry a subquery. Only an
        // upper-case SELECT … FROM counts here, so prose and `<select>` don't.
        if (!preg_match('/\bSELECT\b[\s\S]*?\bFROM\s+`?[A-Za-z_]/', $s)) {
            return null;
        }
        $m = [1 => 'SELECT'];
    }
    $id = '`?([A-Za-z_][A-Za-z0-9_]*)`?';
    $verb = strtoupper($m[1]);
    $writes = [];
    if ($verb === 'INSERT' || $verb === 'REPLACE') {
        preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+' . $id . '/i', $s, $w);
        $writes = isset($w[1]) ? [$w[1]] : [];
    } elseif ($verb === 'UPDATE') {
        preg_match('/^UPDATE\s+' . $id . '/i', $s, $w);
        $writes = isset($w[1]) ? [$w[1]] : [];
    } elseif ($verb === 'DELETE') {
        preg_match('/^DELETE\s+FROM\s+' . $id . '/i', $s, $w);
        $writes = isset($w[1]) ? [$w[1]] : [];
    }
    preg_match_all('/\b(?:FROM|JOIN)\s+' . $id . '/i', $s, $r);
    $reads = array_values(array_diff(array_unique($r[1]), $verb === 'DELETE' ? $writes : []));
    return ['reads' => $reads, 'writes' => $writes];
}

function selfTest(): void
{
    $sample = <<<'PHP'
    <?php
    // SELECT x FROM not_a_query_in_a_comment
    $from = 1; $a = "<select>"; $b = sprintf('from %d to %d', 1, 2);
    $q = "SELECT a FROM t1 JOIN t2 ON t1.id = t2.id WHERE x IN ($in)";
    $w = 'INSERT INTO `t3` (a) VALUES (?) ON DUPLICATE KEY UPDATE a = VALUES(a)';
    $d = 'DELETE FROM `t4` WHERE id = ?';
    $c = "SELECT a, " . Cols::SQL . "
          FROM t5 JOIN t6 ON t5.id = t6.id WHERE " . implode(' AND ', $w) . " ORDER BY a";
    const COLS = "x.a, (x.b = 1 AND EXISTS (SELECT 1 FROM t7 WHERE t7.id = x.id)) AS c";
    $prose = 'Select a site from the list';
    PHP;
    $found = array_values(array_filter(array_map('sqlTables', stringLiterals($sample))));
    $reads = array_merge(...array_column($found, 'reads'));
    $writes = array_merge(...array_column($found, 'writes'));
    sort($reads);
    sort($writes);
    if ($reads !== ['t1', 't2', 't5', 't6', 't7'] || $writes !== ['t3', 't4']) {
        fwrite(STDERR, "SQL extractor self-test failed: reads=" . json_encode($reads) . " writes=" . json_encode($writes) . "\n");
        exit(2);
    }
}

/** @return list<string> */
function phpFiles(string $root): array
{
    $files = array_values(array_filter([$root . '/openemr.bootstrap.php', $root . '/ModuleManagerListener.php'], 'is_file'));
    foreach (array_filter(['src', 'public', 'background', 'bin'], fn($d) => is_dir($root . '/' . $d)) as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

/**
 * The openhealthcare-common methods the module calls through its import of
 * OpenHealthcare\Common\OpenEmr, and what those call through self::, as one source under the
 * library's path; none when the module doesn't require the library at run time. They run as the
 * module's code, so their SQL, checks and calls are the module's.
 *
 * @param array<string, string> $module each module file's path => its source
 * @return array<string, string>
 */
function libraryMethods(string $root, array $composer, array $module): array
{
    if (!isset($composer['require']['anun333/openhealthcare-common'])) {
        return [];
    }
    $rel = 'vendor/anun333/openhealthcare-common/src/OpenEmr.php';
    $lib = is_file("$root/$rel") ? (string) file_get_contents("$root/$rel") : '';
    if ($lib === '') {
        fail("$rel is missing; run composer install");
    }
    preg_match_all('/^    (?:(?:public|private|protected) )?static function (\w+)\(.*?^    \}$/ms', $lib, $m, PREG_SET_ORDER);
    $bodies = array_column($m, 0, 1);
    if (count($bodies) !== substr_count($lib, 'static function ')) {
        fail("$rel: not every method was read; the library's layout changed");
    }
    // Calls through each file's import of the class, under its alias or its own name.
    $want = [];
    foreach ($module as $php) {
        if (preg_match('/^use OpenHealthcare\\\\Common\\\\OpenEmr(?: as (\w+))?;/m', $php, $u) === 1) {
            preg_match_all('/\b' . ($u[1] ?? 'OpenEmr') . '::(\w+)\(/', $php, $c);
            array_push($want, ...$c[1]);
        }
    }
    $take = [];
    while (($name = array_shift($want)) !== null) {
        if (isset($take[$name])) {
            continue;
        }
        if (!isset($bodies[$name])) {
            fail("OpenEmr::$name() is called but $rel has no such method");
        }
        $take[$name] = $bodies[$name];
        preg_match_all('/\bself::(\w+)\(/', $bodies[$name], $s);
        array_push($want, ...$s[1]);
    }
    if ($take === []) {
        return [];
    }
    ksort($take);
    return [$rel => "<?php\n" . implode("\n", $take)];
}

/** @return array<string, string> each file's path from the module's root => its source, then the library methods it calls */
function sources(string $root, array $composer): array
{
    $out = [];
    foreach (phpFiles($root) as $file) {
        $out[substr($file, strlen($root) + 1)] = (string) file_get_contents($file);
    }
    return $out + libraryMethods($root, $composer, $out);
}

function fail(string $why): never
{
    fwrite(STDERR, "footprint: $why\n");
    exit(2);
}

selfTest();

// --- package identity -------------------------------------------------------
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
if (!is_array($composer) || !is_string($composer['name'] ?? null)) {
    fail('composer.json has no name');
}
$name = $composer['name'];
$parts = explode('/', $name);
$installDir = 'interface/modules/custom_modules/' . end($parts);
// version.php when the module has a database version to track, else info.txt ("Clinic Forms v0.1.0"),
// which the Module Manager reads.
if (is_file($root . '/version.php')) {
    $v_major = $v_minor = $v_patch = $v_tag = '';
    include $root . '/version.php';
    $version = "$v_major.$v_minor.$v_patch" . ($v_tag !== '' ? "-$v_tag" : '');
} elseif (preg_match('/\bv(\d+\.\d+\.\d+(?:-[\w.]+)?)\s*$/', (string) file_get_contents($root . '/info.txt'), $vm)) {
    $version = $vm[1];
} else {
    fail('no version in version.php or info.txt');
}
$notCovered = $composer['extra']['openhealthcare']['footprint-not-covered'] ?? null;
if (!is_array($notCovered) || $notCovered === [] || array_filter($notCovered, fn($n) => !is_string($n) || trim($n) === '') !== []) {
    fail("composer.json has no extra.openhealthcare.footprint-not-covered: a list of what this sheet can't show for the module");
}

// --- install-time SQL -------------------------------------------------------
// Statement by statement, and every statement must be classified: the sheet
// claims to list everything install.sql does, so an unhandled statement kind is
// a failure, not something to leave out.
$hasInstall = is_file($root . '/sql/install.sql');
$install = $hasInstall ? (string) file_get_contents($root . '/sql/install.sql') : '';
// Every statement must start inside an #If...#EndIf guard, or the "re-running
// changes nothing" line below would be a claim rather than a fact.
$depth = 0;
$unguarded = [];
$inStatement = false;
foreach (explode("\n", $install) as $n => $line) {
    $t = trim($line);
    if (str_starts_with($t, '#If')) {
        $depth++;
    } elseif (str_starts_with($t, '#EndIf')) {
        $depth--;
    } elseif ($t !== '' && !str_starts_with($t, '--') && !str_starts_with($t, '#')) {
        if (!$inStatement && $depth === 0) {
            $unguarded[] = $n + 1;
        }
        $inStatement = !str_ends_with($t, ';');
    }
}
$body = preg_replace('/^\s*(--|#)[^\n]*$/m', '', $install) ?? '';
$statements = array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $body) ?: []), fn($x) => $x !== ''));
if ($hasInstall && $statements === []) {
    fail('no statements parsed from sql/install.sql');
}
/**
 * The openhealthcare module that owns a table, by its prefix, or null: a table another of our
 * modules created isn't OpenEMR's, and its sheet says whose it is. No core table uses these
 * prefixes (checked against master's database.sql, 2026-10-25).
 */
function otherModule(string $table): ?string
{
    $prefixes = [
        'behavioral_health_' => 'Behavioral Health', 'care_mgmt_' => 'Care Management', 'ccbhc_' => 'CCBHC Report',
        'migration_' => 'Migration', 'revenue_cycle_' => 'Revenue Cycle', 'sliding_fee_' => 'Sliding Fee',
        'staff_worklist_' => 'Staff Worklist', 'title_x_report_' => 'Title X Report', 'uds_' => 'UDS Report',
    ];
    foreach ($prefixes as $prefix => $module) {
        if (str_starts_with($table, $prefix)) {
            return "the $module module (another openhealthcare module)";
        }
    }
    return null;
}

$ownTables = [];
$alters = [];
$inserts = [];
$updates = [];
$id = '`?([A-Za-z_][A-Za-z0-9_]*)`?';
foreach ($statements as $st) {
    if (preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?' . $id . '/i', $st, $m)) {
        $ownTables[] = $m[1];
    } elseif (preg_match('/^ALTER TABLE\s+' . $id . '\s+ADD\s+(?:COLUMN\s+)?' . $id . '\s+([^\n,]+)/i', $st, $m)) {
        $alters[$m[1]][] = ['column' => $m[2], 'type' => trim($m[3])];
    } elseif (preg_match('/^INSERT INTO\s+' . $id . '/i', $st, $m)) {
        $inserts[$m[1]][] = $st;
    } elseif (preg_match('/^UPDATE\s+' . $id . '\s+SET\s+[\s\S]*?\bWHERE\b([\s\S]*)$/i', $st, $m)) {
        $updates[$m[1]][] = $m[2];
    } else {
        fail('unclassified statement in sql/install.sql: ' . substr(preg_replace('/\s+/', ' ', $st) ?? '', 0, 80));
    }
}
// An UPDATE whose WHERE names a value this install SQL inserted changes the module's own rows (moving
// its field in a layout, say); any other is listed as changing rows the module didn't add.
preg_match_all("/'([^']*)'/", implode("\n", array_merge([], ...array_values($inserts))), $lit);
$insertedValues = array_flip(array_filter($lit[1], fn($v) => strlen($v) > 3));
$ownUpdates = [];
$otherUpdates = [];
foreach ($updates as $table => $wheres) {
    foreach ($wheres as $where) {
        preg_match_all("/'([^']*)'/", $where, $w);
        $mine = array_values(array_filter($w[1], fn($v) => isset($insertedValues[$v])));
        if ($mine !== []) {
            $ownUpdates[$table] = array_merge($ownUpdates[$table] ?? [], $mine);
        } else {
            $otherUpdates[$table][] = str_replace('`', '', trim(preg_replace('/\s+/', ' ', $where) ?? ''));
        }
    }
}
if ($hasInstall && $ownTables === []) {
    fail('no CREATE TABLE found in sql/install.sql');
}
if (!$hasInstall && is_dir($root . '/sql')) {
    fail('sql/ exists but has no install.sql; the sheet would say the module changes no table');
}
$coreAlters = array_diff_key($alters, array_flip($ownTables));
$installWrites = array_values(array_diff(array_keys($inserts), $ownTables));
$newLists = [];
$listRows = 0;
foreach ($inserts['list_options'] ?? [] as $st) {
    preg_match_all("/\(\s*'([^']*)'\s*,\s*'([^']*)'/", $st, $t, PREG_SET_ORDER);
    foreach ($t as $row) {
        $listRows++;
        if ($row[1] === 'lists') {
            $newLists[] = $row[2];
        }
    }
}
if (isset($inserts['list_options']) && $listRows === 0) {
    fail('list_options is written but no value tuples were parsed');
}
$layoutFields = [];
foreach ($inserts['layout_options'] ?? [] as $st) {
    preg_match_all("/VALUES\s*\(\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'/i", $st, $t, PREG_SET_ORDER);
    foreach ($t as $row) {
        $layoutFields[] = ['form' => $row[1], 'field' => $row[2], 'group' => $row[3], 'title' => $row[4]];
    }
}
if (isset($inserts['layout_options']) && $layoutFields === []) {
    fail('layout_options is written but no fields were parsed');
}
$services = [];
foreach ($inserts['background_services'] ?? [] as $st) {
    if (preg_match('/\(([^)]*)\)\s*VALUES\s*\((.*)\)\s*$/is', $st, $m)) {
        $cols = array_map(fn($c) => trim($c, " `\n"), explode(',', $m[1]));
        preg_match_all("/'([^']*)'|([^,\s]+(?:\(\))?)/", $m[2], $v);
        $vals = array_map(fn($a, $b) => $a !== '' ? $a : $b, $v[1], $v[2]);
        if (count($cols) === count($vals)) {
            $services[] = array_combine($cols, $vals);
        }
    }
}
if (isset($inserts['background_services']) && $services === []) {
    fail('background_services is written but no service row was parsed');
}
preg_match_all('/^#(If\w+)/m', $install, $g);
$guards = array_values(array_unique($g[1]));
$sqlFiles = array_map('basename', glob($root . '/sql/*.sql') ?: []);
$uninstall = array_values(array_filter($sqlFiles, fn($f) => preg_match('/uninstall|cleanup|remove/i', $f)));

$sources = sources($root, $composer);

// --- runtime SQL ------------------------------------------------------------
$reads = [];
$writes = [];
$queries = 0;
foreach ($sources as $rel => $phpSource) {
    foreach (stringLiterals($phpSource) as $s) {
        $t = sqlTables($s);
        if ($t === null) {
            continue;
        }
        $queries++;
        foreach ($t['reads'] as $x) {
            $reads[$x][$rel] = true;
        }
        foreach ($t['writes'] as $x) {
            $writes[$x][$rel] = true;
        }
    }
}
if ($queries === 0) {
    fail('no SQL found in the module\'s PHP; the extractor or the layout changed');
}
ksort($reads);
ksort($writes);
$coreWrites = array_values(array_filter(array_diff(array_keys($writes), $ownTables), fn(string $t): bool => otherModule($t) === null));
$moduleWrites = array_values(array_filter(array_diff(array_keys($writes), $ownTables), fn(string $t): bool => otherModule($t) !== null));

// --- hooks ------------------------------------------------------------------
$bootstrap = (string) file_get_contents($root . '/src/Bootstrap.php');
preg_match_all('/addListener\(\s*([A-Za-z_\\\\]+::[A-Z_]+)/', $bootstrap, $m);
$hooks = $m[1];
if ($hooks === [] && str_contains($bootstrap, 'addListener')) {
    fail('addListener present but no event parsed');
}

preg_match("/acl_req\s*=\s*\['([^']+)'\s*,\s*'([^']+)'\]/", $bootstrap, $menuAcl);
preg_match("/->label\s*=\s*xlt?\('([^']+)'\)/", $bootstrap, $menuLabel);

// --- permission checks, audit events, settings ------------------------------
// Read from the source as written. This lists the checks the code makes; it
// does not show that they behave correctly for a given user.
// The level is a string ('write') or a list of levels any of which will do (['write', 'addonly']).
$aclPattern = "/aclCheckCore\(\s*'([^']+)'\s*,\s*(?:'([^']*)'|(\\$\w+))(?:\s*,\s*(?:'[^']*'|\\$\w+(?:\s*\?\?\s*'[^']*')?))?(?:\s*,\s*(?:'([^']+)'|\[([^\]]+)\]))?/";
if (!preg_match($aclPattern, "aclCheckCore('patients', 'med', '', 'write')", $t) || ($t[4] ?? '') !== 'write'
    || !preg_match($aclPattern, "aclCheckCore('patients', 'docs', '', ['write', 'addonly'])", $t) || str_replace(["'", ' '], '', $t[5] ?? '') !== 'write,addonly'
    || !preg_match($aclPattern, "aclCheckCore('sensitivities', \$s, \$username ?? '')", $t) || ($t[3] ?? '') !== '$s') {
    fail('permission-check extractor self-test failed');
}
$checks = [];
$events = [];
$classWrites = [];
$settings = [];
foreach ($sources as $rel => $src) {
    $n = preg_match_all($aclPattern, $src, $m, PREG_SET_ORDER);
    if ($n === 0 && str_contains($src, 'aclCheckCore(')) {
        fail("aclCheckCore in $rel but no call parsed");
    }
    foreach ($m as $c) {
        $what = $c[1] . ' / ' . (($c[2] ?? '') !== '' ? $c[2] : 'the item\'s level');
        $level = ($c[4] ?? '') !== '' ? $c[4] : (($c[5] ?? '') !== '' ? implode(' or ', array_map(fn($l) => trim($l, " '\""), explode(',', $c[5]))) : 'view');
        $checks[$what . ' (' . $level . ')'][$rel] = true;
    }
    // Audit events are written through OpenEMR's EventAuditLogger, directly or through the
    // module's Compat adapter (OpenEmr::audit) or openhealthcare-common's, which pass their caller's event name on.
    $isAdapter = str_ends_with($rel, 'Compat/OpenEmr.php') || $rel === 'vendor/anun333/openhealthcare-common/src/OpenEmr.php';
    if (preg_match_all("/(?:newEvent|OpenEmr::audit)\(\s*'([^']+)'/", $src, $m) === 0 && !$isAdapter
        && (str_contains($src, 'newEvent(') || str_contains($src, 'OpenEmr::audit('))) {
        fail("audit event in $rel but no event name parsed");
    }
    foreach ($m[1] as $e) {
        $events[$e][$rel] = true;
    }
    // Rows and files written through OpenEMR's own classes rather than the module's SQL.
    foreach (CORE_CLASS_WRITES as $call => $what) {
        if (str_contains($src, $call)) {
            $classWrites[$what][$rel] = true;
        }
    }
    // Directly, or through an adapter's setting() (self:: inside openhealthcare-common's methods).
    preg_match_all("/(?:OEGlobalsBag::getInstance\(\)->get(?:Boolean|String|Int)?|(?:self|OpenEmr)::setting)\('([A-Za-z0-9_]+)'\)/", $src, $m);
    foreach ($m[1] as $k) {
        $settings[$k][$rel] = true;
    }
    // Through the module's Compat adapter: its named helpers read these settings.
    preg_match_all("/OpenEmr::(?:setting\('([A-Za-z0-9_]+)'\)|(siteDir|webRoot|projectDir)\()/", $src, $m, PREG_SET_ORDER);
    foreach ($m as $c) {
        $k = $c[1] !== '' ? $c[1] : ['siteDir' => 'OE_SITE_DIR', 'webRoot' => 'webroot', 'projectDir' => 'fileroot'][$c[2]];
        $settings[$k][$rel] = true;
    }
}
if ($checks === []) {
    fail('no permission checks found; the extractor or the layout changed');
}
ksort($checks);
ksort($settings);

// --- OpenEMR functions it calls ---------------------------------------------
// A global function call that PHP doesn't provide and the module doesn't
// define is OpenEMR's (the generator runs without OpenEMR loaded).
$defined = [];
$called = [];
foreach ($sources as $rel => $phpSource) {
    $toks = array_values(array_filter(token_get_all($phpSource),
        fn($t) => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
    // A method is not a global function: a class's private text() must not hide calls to
    // OpenEMR's text(). Track the brace depth at which each class body opens.
    $depth = 0;
    $classDepths = [];
    $classPending = false;
    foreach ($toks as $k => $t) {
        $id = is_array($t) ? $t[0] : $t;
        $prevTok = $toks[$k - 1] ?? null;
        if (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && !(is_array($prevTok) && $prevTok[0] === T_DOUBLE_COLON)) {
            $classPending = true;
        } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            if ($classPending && $id === '{') {
                $classDepths[] = $depth;
                $classPending = false;
            }
        } elseif ($id === '}') {
            if ($classDepths !== [] && end($classDepths) === $depth) {
                array_pop($classDepths);
            }
            $depth--;
        }
        if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($toks[$k + 1] ?? null) !== '(') {
            continue;
        }
        $prev = $toks[$k - 1] ?? null;
        $prevId = is_array($prev) ? $prev[0] : $prev;
        if ($prevId === T_FUNCTION) {
            if ($classDepths === []) {
                $defined[ltrim($t[1], '\\')] = true;
            }
        } elseif (!in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FN], true)) {
            $called[ltrim($t[1], '\\')][$rel] = true;
        }
    }
}
$coreFunctions = array_filter($called, fn($f) => !isset($defined[$f]) && !function_exists($f)
    && !in_array(strtolower($f), ['isset', 'empty', 'unset', 'list', 'array', 'exit', 'die', 'eval', 'match'], true), ARRAY_FILTER_USE_KEY);
if (isset($called['text']) === false && is_file($root . '/public/index.php') && str_contains((string) file_get_contents($root . '/public/index.php'), 'text(')) {
    fail('core-function detector self-test failed: text() is called in public/index.php but not found');
}
if (!isset($coreFunctions['xl']) && str_contains((string) file_get_contents($root . '/src/Bootstrap.php'), 'xlt(')) {
    fail('core-function detector self-test failed: xlt() is called but not found');
}
ksort($coreFunctions);

// --- outbound network -------------------------------------------------------
$netPattern = '/\b(curl_init|fsockopen|stream_socket_client|GuzzleHttp|HttpClient|socket_connect)\b|file_get_contents\(\s*[\'"]https?:/';
if (!preg_match($netPattern, 'curl_init(') || !preg_match($netPattern, "file_get_contents('https://x')")) {
    fail('network detector self-test failed');
}
$net = [];
foreach ($sources as $rel => $phpSource) {
    if (preg_match_all($netPattern, $phpSource, $m)) {
        $net[$rel] = array_unique($m[0]);
    }
}

// --- files it writes ---------------------------------------------------------
// Calls that create, replace or delete files, as written in the source. The sheet says where
// they are; the README says what they write.
$filePattern = '/\b(file_put_contents|unlink|rename|mkdir|move_uploaded_file|tempnam)\s*\(/';
if (!preg_match($filePattern, 'file_put_contents($f, $x)') || preg_match($filePattern, '$this->unlinkLater')) {
    fail('file-write detector self-test failed');
}
$fileWrites = [];
foreach (phpFiles($root) as $file) {
    if (preg_match_all($filePattern, (string) file_get_contents($file), $m)) {
        $fileWrites[substr($file, strlen($root) + 1)] = array_values(array_unique($m[1]));
    }
}
ksort($fileWrites);

// --- programs it runs ---------------------------------------------------------
// Calls that start another program on the server, as written in the source. The sheet says where they
// are; the README says which program, and why.
$runPattern = '/(?<![\w>:$])(?<!function )(proc_open|exec|shell_exec|system|passthru|popen|pcntl_exec)\s*\(/';
if (!preg_match($runPattern, 'proc_open($command') || !preg_match($runPattern, 'shell_exec(\'x\')')
    || !preg_match($runPattern, '\\exec($c)') || preg_match($runPattern, '$this->exec(') || preg_match($runPattern, 'curl_exec($h)')
    || preg_match($runPattern, 'Process::exec(') || preg_match($runPattern, 'private function exec(string $f)')) {
    fail('program detector self-test failed');
}
$runs = [];
foreach (phpFiles($root) as $file) {
    // the code alone: a comment that names one of these calls runs nothing
    $code = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents($file))));
    if (preg_match_all($runPattern, $code, $m)) {
        $runs[substr($file, strlen($root) + 1)] = array_values(array_unique($m[1]));
    }
}
ksort($runs);

// --- composition ------------------------------------------------------------
$kinds = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    // Documentation and licence text change nothing a reviewer must check, and
    // counting them would make every doc edit fail --check. build/ is local output
    // (git-ignored, absent from a fresh clone), so counting it made CI disagree.
    if (preg_match('#^(\.git|\.github|vendor|\.phpunit\.cache|build|LICENSES)/#', $rel) || str_ends_with($rel, '.md')
        || in_array($rel, ['composer.lock', 'LICENSE', '.gitignore', '.phpunit.result.cache'], true)) {
        continue;
    }
    $kind = match (true) {
        str_starts_with($rel, 'src/') => 'PHP logic (`src/`)',
        str_starts_with($rel, 'public/') => 'pages (`public/`)',
        str_starts_with($rel, 'background/') => 'background job (`background/`)',
        in_array($rel, ['openemr.bootstrap.php', 'ModuleManagerListener.php', 'info.txt', 'version.php', 'composer.json'], true) => 'module wiring',
        str_starts_with($rel, 'tests/') || $rel === 'phpunit.xml.dist' => 'tests (`tests/`, `phpunit.xml.dist`)',
        str_starts_with($rel, 'sql/') => 'install SQL (`sql/`)',
        str_starts_with($rel, 'tools/') => 'tools (`tools/`)',
        str_starts_with($rel, 'data/cases/') => 'expected results (`data/cases/`, for the tests)',
        str_starts_with($rel, 'data/') => 'data (`data/`, read at runtime)',
        default => 'other',
    };
    $kinds[$kind]['files'] = ($kinds[$kind]['files'] ?? 0) + 1;
    $kinds[$kind]['lines'] = ($kinds[$kind]['lines'] ?? 0) + count(file($f->getPathname()) ?: []);
}
uasort($kinds, fn($a, $b) => $b['lines'] <=> $a['lines']);

// --- render -----------------------------------------------------------------
$md = [];
$md[] = '# Footprint';
$md[] = '';
$md[] = "Generated by openhealthcare-common's footprint tool from the source of `$name` $version. Do not edit; run `php tools/footprint.php` after changing the module, and `--check` in CI.";
$md[] = '';
$md[] = '## Where it installs';
$md[] = '';
$md[] = "`composer require $name` places it in `$installDir/` (the `openemr/oe-module-installer-plugin` rule: `custom_modules/` plus the part of the package name after the slash). It changes no OpenEMR core file. Enable it under Modules > Manage Modules.";
$md[] = '';
$md[] = '## What installing it changes in the database';
$md[] = '';
if (!$hasInstall) {
    $md[] = 'Nothing. The module ships no install SQL (there is no `sql/` directory), so installing, enabling, disabling or removing it creates, changes and leaves behind no table or row.';
    $md[] = '';
} else {
    $md[] = count($statements) . ' statements in `sql/install.sql`, every one classified below. ' . ($unguarded === []
        ? 'Each sits inside an `#If…` guard (' . implode(', ', array_map(fn($x) => "`#$x`", $guards)) . '), so re-running the install changes nothing.'
        : '**Not guarded, so a re-run would repeat them:** statements starting on line ' . implode(', ', $unguarded) . '.');
    $md[] = '';
    if ($coreAlters === []) {
        $md[] = '- **Core schema changes:** none.';
    } else {
        foreach ($coreAlters as $t => $cols) {
            $md[] = "- **Core schema change — adds " . count($cols) . " columns to OpenEMR's `$t` table:** " . implode(', ', array_map(fn($c) => "`{$c['column']}` ({$c['type']})", $cols)) . '.';
        }
    }
    $md[] = '- **Tables it creates and owns:** ' . implode(', ', array_map(fn($t) => "`$t`", $ownTables)) . '.';
    if ($installWrites === []) {
        $md[] = '- **Rows added to core tables:** none.';
    } else {
        $md[] = '- **Rows added to core tables:** ' . implode(', ', array_map(fn($t) => "`$t`", $installWrites)) . '.';
        if ($newLists !== []) {
            $md[] = "  - `list_options`: $listRows rows defining " . count($newLists) . ' new lists: ' . implode(', ', array_map(fn($l) => "`$l`", $newLists)) . '.';
        }
        foreach ($services as $sv) {
            $md[] = "  - `background_services`: one row, `{$sv['name']}` (\"{$sv['title']}\"), which runs `{$sv['function']}()` from `{$sv['require_once']}` every {$sv['execute_interval']} minutes. It is installed " . ($sv['active'] === '0' ? 'inactive; the module switches it on when enabled and off when disabled (`ModuleManagerListener.php`).' : 'active.');
        }
        if ($layoutFields !== []) {
            $forms = array_unique(array_column($layoutFields, 'form'));
            $md[] = '  - `layout_options`: ' . count($layoutFields) . ' fields added to the ' . implode(', ', array_map(fn($f) => "`$f`", $forms)) . ' layout' . ($forms === ['DEM'] ? ' (patient Demographics), so every user of that form sees them' : '') . ': ' . implode(', ', array_map(fn($f) => "{$f['title']} (`{$f['field']}`, group {$f['group']})", $layoutFields)) . '.';
        }
    }
    foreach ($ownUpdates as $table => $values) {
        $md[] = "- **Rows it updates in `$table`:** only rows it added itself (" . implode(', ', array_map(fn($v) => "`$v`", array_values(array_unique($values)))) . ').';
    }
    foreach ($otherUpdates as $table => $wheres) {
        $md[] = "- **Rows it updates in `$table` that it didn't add:** where " . implode('; where ', array_map(fn($w) => "`$w`", $wheres)) . '.';
    }
    $md[] = '';
    $md[] = $uninstall === []
        ? '- **No uninstall script** (`sql/` holds only ' . implode(', ', array_map(fn($f) => "`$f`", $sqlFiles)) . '): disabling or removing the module leaves the tables and rows above in place, along with anything staff recorded in them.'
        : '- **Uninstall script:** ' . implode(', ', array_map(fn($f) => "`$f`", $uninstall)) . '.';
    $md[] = '';
}
$md[] = '## What it reads and writes at runtime';
$md[] = '';
$md[] = "$queries SQL statements found in the module's PHP (" . implode(', ', array_map(fn($f) => '`' . $f . '`', array_merge(array_map(fn($d) => "$d/", array_values(array_filter(['src', 'public', 'background', 'bin'], fn($d) => is_dir($root . '/' . $d)))), array_values(array_filter(['ModuleManagerListener.php', 'openemr.bootstrap.php'], fn($f) => is_file($root . '/' . $f)))))) . ")" . (isset($sources['vendor/anun333/openhealthcare-common/src/OpenEmr.php']) ? ' and in the openhealthcare-common methods it calls (`vendor/anun333/openhealthcare-common/src/OpenEmr.php`)' : '') . ". Data OpenEMR's own functions read on its behalf is not in this table; see the next section.";
$md[] = '';
$md[] = '| table | read by | written by | owner |';
$md[] = '|---|---|---|---|';
foreach (array_unique(array_merge(array_keys($reads), array_keys($writes))) as $t) {
    $r = isset($reads[$t]) ? implode('<br>', array_map(fn($f) => "`$f`", array_keys($reads[$t]))) : '—';
    $w = isset($writes[$t]) ? implode('<br>', array_map(fn($f) => "`$f`", array_keys($writes[$t]))) : '—';
    $owner = in_array($t, $ownTables, true) ? 'this module' : ($t === 'information_schema' ? 'the database server (column lookups)' : (otherModule($t) ?? 'OpenEMR core'));
    $md[] = "| `$t` | $r | $w | $owner |";
}
$md[] = '';
// Core classes known to write, found above, so the summary can't understate writes either.
$classWriteLines = array_map(
    fn(string $what, array $files): string => $what . ' (in ' . implode(', ', array_map(fn($f) => "`$f`", array_keys($files))) . ')',
    array_keys($classWrites),
    $classWrites,
);
// Core functions known to write, so the summary line can't understate writes.
$writerFunctions = ['setUserSetting' => 'user_settings', 'sqlInsert' => 'a core table', 'sqlStatement' => 'a core table'];
$viaFunctions = array_intersect_key($writerFunctions, $coreFunctions);
$md[] = ($writes === []
    ? '**It writes nothing in its own SQL: every statement above reads.**'
    : ($coreWrites === [] && $moduleWrites === []
    ? '**In its own SQL it writes only to its own tables.**'
    : ($coreWrites === [] ? '**In its own SQL it writes to no OpenEMR core table.**'
    : '**In its own SQL it writes to OpenEMR core tables:** ' . implode(', ', array_map(fn($t) => "`$t`", $coreWrites)) . '.')
    . ($moduleWrites === [] ? '' : ' **It writes to another openhealthcare module\'s tables:** ' . implode(', ', array_map(fn($t) => "`$t`", $moduleWrites)) . '.')))
    . ($viaFunctions === [] ? '' : ' Through OpenEMR functions it also writes ' . implode(', ', array_map(fn($f, $t) => "`$t` (`$f()`)", array_keys($viaFunctions), $viaFunctions)) . '.')
    . ($classWriteLines === [] ? '' : ' Through OpenEMR\'s own classes it writes ' . implode('; ', $classWriteLines) . '.');
$md[] = '';
$md[] = '## OpenEMR functions it calls';
$md[] = '';
$md[] = 'Global functions the module calls that PHP doesn\'t provide and the module doesn\'t define. Whatever these read or write, OpenEMR reads or writes on the module\'s behalf.';
$md[] = '';
foreach ($coreFunctions as $f => $files) {
    $md[] = "- `$f()`: " . implode(', ', array_map(fn($x) => "`$x`", array_keys($files)));
}
$md[] = '';
$md[] = '## What it hooks';
$md[] = '';
$md[] = $hooks === [] ? '- No event listeners.' : implode("\n", array_map(fn($h) => "- `$h`", $hooks));
if ($menuAcl !== []) {
    $md[] = '- Menu entry' . ($menuLabel !== [] ? " \"{$menuLabel[1]}\"" : '') . ", shown to users with `{$menuAcl[1]} / {$menuAcl[2]}`.";
}
$md[] = '';
$md[] = '## Permission checks in the code';
$md[] = '';
$md[] = 'As written in the source; whether they behave correctly for a given user is not something this sheet can show.';
$md[] = '';
$md[] = '| check | where |';
$md[] = '|---|---|';
foreach ($checks as $c => $files) {
    $md[] = "| `$c` | " . implode('<br>', array_map(fn($f) => "`$f`", array_keys($files))) . ' |';
}
$md[] = '';
$md[] = '## Audit log';
$md[] = '';
$md[] = $events === []
    ? '- Writes no audit events.'
    : implode("\n", array_map(fn($e, $f) => "- `$e`, through OpenEMR's `EventAuditLogger`, from " . implode(', ', array_map(fn($x) => "`$x`", array_keys($f))) . '.', array_keys($events), $events));
$md[] = '';
$md[] = '## Settings it reads';
$md[] = '';
$md[] = $settings === []
    ? '- None.'
    : implode("\n", array_map(fn($k, $f) => "- `$k` (" . implode(', ', array_map(fn($x) => "`$x`", array_keys($f))) . ')', array_keys($settings), $settings));
$md[] = '';
$md[] = '## Files it writes';
$md[] = '';
$md[] = $fileWrites === []
    ? '- None.'
    : implode("\n", array_map(fn($f, $c) => "- `$f`: " . implode(', ', array_map(fn($x) => "`$x()`", $c)), array_keys($fileWrites), $fileWrites));
$md[] = '';
$md[] = '## Programs it runs';
$md[] = '';
$md[] = $runs === []
    ? '- None: no `proc_open()`, `exec()`, `shell_exec()`, `system()`, `passthru()` or `popen()` in the module\'s PHP.'
    : implode("\n", array_map(fn($f, $c) => "- `$f`: " . implode(', ', array_map(fn($x) => "`$x()`", $c)), array_keys($runs), $runs));
$md[] = '';
$md[] = '## Outbound network';
$md[] = '';
$md[] = $net === []
    ? '- None: no cURL, sockets, HTTP clients or remote `file_get_contents` in the module\'s PHP.'
    : implode("\n", array_map(fn($f, $c) => "- `$f`: " . implode(', ', $c), array_keys($net), $net));
$md[] = '';
$md[] = '## Size, by what a reviewer does with it';
$md[] = '';
$md[] = '| part | files | lines |';
$md[] = '|---|---|---|';
foreach ($kinds as $k => $v) {
    $md[] = "| $k | {$v['files']} | {$v['lines']} |";
}
$md[] = '';
$md[] = '## Not covered here';
$md[] = '';
foreach ($notCovered as $note) {
    $md[] = '- ' . $note;
}
$md[] = '';
$text = implode("\n", $md);

if (in_array('--check', $argv, true)) {
    $current = is_file($out) ? (string) file_get_contents($out) : '';
    if ($current !== $text) {
        fwrite(STDERR, "FOOTPRINT.md is stale; run php tools/footprint.php\n");
        exit(1);
    }
    echo "FOOTPRINT.md is current\n";
    exit(0);
}
file_put_contents($out, $text);
echo "wrote FOOTPRINT.md ($queries queries, " . count($reads) . " tables read, " . count($writes) . " written)\n";
