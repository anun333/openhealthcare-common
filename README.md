# openhealthcare-common

Code shared by the openhealthcare modules for [OpenEMR](https://www.open-emr.org): Clinic Forms, UDS Report, Staff Worklist, Sliding Fee Discount, Care Management, Migration, Layout Export and Units Check. Each module had its own copy of this code, and the copies had started to differ. This package holds one.

It's a plain Composer library, not an OpenEMR module: there is nothing to enable in Module Manager.

## What's in it

- **`OpenHealthcare\Common\OpenEmr`:** the OpenEMR calls that changed between releases, made once so a module runs on 7.0.x as well as 8.x. Each method uses the newer interface when the running OpenEMR has it, and the 7.0 way otherwise.

  | Method | 8.x | 7.0 |
  |---|---|---|
  | `setting()`, `siteDir()`, `webRoot()`, `projectDir()`, `version()` | `OEGlobalsBag` (7.0.4, 8.0 on) | `$GLOBALS` |
  | `eventDispatcher()`, `dispatch()` | the kernel from `OEGlobalsBag` | `$GLOBALS['kernel']` |
  | `session()` | `SessionWrapperFactory` | `$_SESSION` |
  | `csrfToken()`, `requireCsrf()` | `CsrfUtils` with a session | without one; 403 either way |
  | `deny()` | `AccessDeniedHelper` (8.1 on) | a plain 403 page |
  | `audit()` | `EventAuditLogger::getInstance()` | `::instance()` |
  | `isAdministrator()` | `AclMain::aclCheckCore('admin', 'super')` | the same |
  | `pageLang()`, `pageDir()` (1.4.0) | the session's `language_choice`, through `lang_languages` | the same, from `$_SESSION` |

- **`OpenEmr::pageLang()` and `pageDir()`** (1.4.0, through `OpenHealthcare\Common\PageLanguage`): the page's language as a BCP 47 tag, and its direction, from the signed-in user's OpenEMR language, so a screen reader reads a translated page in that language (WCAG 2.1 SC 3.1.1). OpenEMR's own language codes aren't all tags (`se` is Swedish, `el` Latin American Spanish, `zt` Traditional Chinese), so they're mapped: `sv`, `es-419`, `zh-Hant`. Without a session, from the command line or without a matching row it's `en` and `ltr`. In a page:

  ```php
  <html lang="<?php echo attr(OpenEmr::pageLang()); ?>" dir="<?php echo attr(OpenEmr::pageDir()); ?>">
  ```

- **The footprint tool** (`tools/footprint.php`, `vendor/bin/footprint`): writes each module's FOOTPRINT.md from its source. See [The footprint tool](#the-footprint-tool).

- **`OpenHealthcare\Common\TranslatedStrings::scan($moduleDir)`:** every string a module passes to `xl()`, `xlt()`, `xla()`, `xlj()` or `xlx()` that has an apostrophe or quote mark, which OpenEMR shows as a backtick. `scan($moduleDir, twigFilters: true)` (1.4.0) also checks strings passed to Twig's `xl`, `xlt`, `xla` and `xlj` filters (`{{ 'Assign'|xlt }}`), which `twigFilterLiterals()` lists. A module's test:

  ```php
  $result = TranslatedStrings::scan(dirname(__DIR__));
  self::assertGreaterThan(10, $result['checked'], 'the scan found the translated strings');
  self::assertSame([], $result['found']);
  ```

- **`OpenHealthcare\Common\GuideLabels::check($moduleDir)`:** every label the module's GUIDE.md quotes in bold (menu entries, tabs, buttons, headings) must be a string the module translates, so a renamed button fails the module's test instead of leaving the guide pointing at nothing. A label translated by a Twig filter counts too (1.4.0), so a dashboard card's button needs no entry below. A label that comes from data rather than an `xl()` call is named with its file in composer.json, under `extra.openhealthcare.guide-labels-elsewhere` (`{"Housing Status (UDS)": "sql/install.sql"}`), and the file must still contain it. A module's test:

  ```php
  $result = GuideLabels::check(dirname(__DIR__));
  self::assertGreaterThan(20, $result['labels'], 'the guide quotes its labels');
  self::assertSame([], $result['missing']);
  ```

- **`OpenHealthcare\Common\QuestionnaireRuntime::version()`** and **`atLeast($version)`:** the version of OpenEMR's own questionnaire runtime, read from the file the site serves. OpenEMR's release number doesn't say: 8.5.0 shipped runtime 0.2.0 and gained 0.4.0 in a backport. `QuestionnaireRuntime::COMPLETES` (0.4.0) is the first with `enableWhenExpression`, repeats per instance and responses saved as completed.

- **`OpenHealthcare\Common\QuestionnaireStatus`:** which saved questionnaire responses count as answered. Runtime 0.4.0 saves a submitted response as completed (amended when edited after); every earlier one saved everything in-progress. Until a site's first completed or amended response, every response counts; after it, a response saved earlier counts whatever its status, and a later one only when completed or amended; entered-in-error never counts. `condition('qr')` gives the SQL and its binds, `counts($status, $createTime, $since)` the same rule for a row already read.

- **The shared contracts** (1.5.0, `OpenHealthcare\Common\Contract\…`): what one module hands another, so neither loads the other's classes or reads its tables. See [Contracts between modules](#contracts-between-modules).
  - **`Contract\Worklist`:** a tab in the Staff Worklist. `WorkSource` (`id()`, `collect(?int $pid)`), `WorkItem` (the row: title, detail, priority, since, owner, action, site, sensitivity, overdue), `ItemKey` (tab id, patient, a plain positive reference) and `PatientRef` (with `SQL_COLUMNS` and `fromRow()`), and `Worklist::contribute()`, which adds the tab to any Worklist from 0.8.0.
  - **`Contract\Figures`:** totals one module asks of another for a period. `FiguresRequest::ask($from, $to, $wanted, $facilityId)` sends `openhealthcare.figures.request` and returns `Figure`s by id (`slidingfee.discounts_posted`: label, value, unit `count`, `money` or `percent`, module, note, the page it comes from). Totals only, never a patient.
  - **`Contract\Forms\DocumentFill`:** Clinic Forms' fill event (`clinic_forms.document.fill`, from Clinic Forms 0.33.0): `asks($event, $document, $table)`, `fill()`, `language()` and `pid()`, for a module that fills a table in one of its documents.

## What isn't, on purpose

- **A guard for command-line tools.** A module installed by Composer has no `vendor/` of its own, so a tool can't load this package before it refuses a web request. Each tool starts with these lines instead:

  ```php
  if (PHP_SAPI !== 'cli') {
      // Composer installs the module under the web root; its tools are for the command line only.
      http_response_code(404);
      exit;
  }
  ```

- **A module bootstrap's version guard.** `openemr.bootstrap.php` runs on every request, the login page included, before a module's dependencies are known to be there. A bootstrap that needs `OEGlobalsBag` returns early without it, so on OpenEMR 7.0.3 and older the module stays switched off instead of stopping every page.
- **The events themselves.** The Worklist's collect event and Clinic Forms' fill event stay classes of their modules and implement nothing from here, so their pages keep working on a site where an older copy of the library loads first. The contracts read an event by what it has (`method_exists()`, its properties), as the modules already did.

## Using it in a module

The module requires `anun333/openhealthcare-common: ^1.0`. Its bootstrap registers the copy in its own `vendor/` before asking whether the class exists. Composer remembers a class it once failed to find, so asking first would hide a copy registered afterwards.

```php
// In openemr.bootstrap.php, after registering the module's own namespace:
$common = __DIR__ . '/vendor/anun333/openhealthcare-common/src';
if (is_dir($common)) {
    // A module copied in, with `composer install --no-dev` run in its folder. This leaves OpenEMR's own
    // mapping alone when Composer installed the module there.
    $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\', $common);
    // From 1.5.0, a module that uses the contracts also registers them under their own prefix (below).
    if (is_dir($common . '/Contract')) {
        $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\Contract\\', $common . '/Contract');
    }
}
if (!class_exists(\OpenHealthcare\Common\OpenEmr::class)) {
    return; // the module stays switched off rather than stop OpenEMR's pages
}
```

`dev/new_module.py` in the openhealthcare repository starts a module this way.

Keep permission checks literal in the module's own code, such as `AclMain::aclCheckCore('admin', 'super')` at the top of a page, so a reviewer reads them where the page is. `isAdministrator()` is here for code with no page of its own. A module's `tools/footprint.php` reads the methods of this package that the module calls, as part of the module, and lists their SQL and calls under `vendor/anun333/openhealthcare-common/`.

## Contracts between modules

Before 1.5.0 a module adding a Worklist tab built it from the Worklist's own classes (`OpenHealthcare\StaffWorklist\Source\WorkSourceInterface` and `Model\WorkItem`, `ItemKey`, `PatientRef`), so its source class couldn't even load without the Worklist, and couldn't be tested on its own. The contracts give those types a second name here. **During the change both names work:**

| | Worklist 0.8.0 to 0.8.7 | Worklist 0.9.0 and later |
|---|---|---|
| **A tab on the Worklist's own types** (`addSource()`), as Care Management 0.3.x | works, as before | works: `addSource()` stays |
| **A tab on the contract** (`Worklist::contribute()`), as Behavioral Health 0.2.6, CCBHC Report 0.1.6 and Revenue Cycle 0.1.7 | works: `contribute()` wraps the source in the Worklist's own types for `addSource()` | works: the event's own `contribute()` takes it |

A contributor's listener:

```php
$dispatcher->addListener(Worklist::COLLECT_SOURCES, function (object $event): void {
    if (!interface_exists(WorkSource::class) || !AclMain::aclCheckCore('patients', 'med')) {
        return; // no contract loaded, or a user this module doesn't give the tab
    }
    Worklist::contribute($event, new MySource(Worklist::now($event)), xl('My tab'), xl('Clinician'));
});
```

`contribute()` returns false and adds nothing when the event can take neither (not the Worklist's, or the Worklist isn't loaded); the Worklist's own refusals (an id that's taken or one of its built-in tabs) still throw. A source class that implements `WorkSource` must not load where the contract is missing, which is why the listener asks `interface_exists()` before it creates one.

**Two copies of the library.** With Composer there's one copy, and a module requiring `^1.5.0` gets one with the contracts. Modules copied in by hand each carry a copy, and the first one registered serves the namespace, so an older copy registered first (a Clinic Forms from before 1.5.0, say) would hide the contracts. So a module that uses them also registers its copy's `Contract` folder under `OpenHealthcare\Common\Contract\` (the bootstrap above): Composer looks under the longer prefix first, and the contracts come from the first copy that has them while everything else still comes from the first copy registered (`tests/Contract/TwoCopiesTest.php`). A copy before 1.5.0 has no `Contract` folder, so it can't take the prefix.

**The steps:**

1. **1.5.0** (2026-10-26): the contract types, `Worklist::contribute()` and its wrapping for older Worklists, figures, and the fill event's description. Nothing changes for a module that doesn't use them.
2. **Worklist 0.9.0:** the collect event gains `contribute()`; `addSource()` stays. Its own items are still its own `Model` classes inside; only what other modules hand it changed.
3. **The contributors move**, each in its next release: Behavioral Health, CCBHC Report and Revenue Cycle now; Care Management in its next (its builder has the list of changes); a module built from now on starts on the contract.
4. **`addSource()` with the Worklist's own types goes** no sooner than Worklist 1.0.0, once every contributor has shipped on the contract, and only with a release note one version ahead. Until then a module that hasn't moved keeps its tab.

**Figures** and **the fill event** have no second name to retire: figures are new, and the fill event stays Clinic Forms' own class, which the contract describes and Clinic Forms' tests hold it to (`tests/Paper/DocumentFillContractTest.php`). A module answering figures checks its own access rule before it answers, as its own page would, and catches its own errors so the asking page still opens. Sliding Fee 0.6.0 answers the first two.

The plan, with what each module changes, is in the openhealthcare repository's `docs/products/shared-contracts-2026-10.md`.

## The footprint tool

Each module's FOOTPRINT.md says what installing it changes in the database, what it reads and writes, what it hooks into, which permission checks and audit events it has, and which settings and files it touches. It's generated from the source, so it can't drift from the code. In a module:

```
php tools/footprint.php            # write FOOTPRINT.md
php tools/footprint.php --check    # exit 1 if it's stale (CI runs this)
```

The module's `tools/footprint.php` only loads `vendor/anun333/openhealthcare-common/tools/footprint.php`; `vendor/bin/footprint [--root=<module>] [--check]` runs the same tool. What the sheet can't show for a module (whether its permission checks behave for a restricted user, whether its rules are right) is written by the module in its composer.json:

```json
"extra": {
    "openhealthcare": {
        "footprint-not-covered": ["Whether ...", "Whether ..."]
    }
}
```

A table another openhealthcare module created is named by its module in the sheet's owner column (1.3.0), not as OpenEMR's, and a write to one is said apart from writes to core tables.

A change to the tool's output changes every module's sheet, so a release that changes it regenerates them all in the same pass.

## Modules that use it

Each installs with `composer require anun333/<module>` from the OpenEMR directory.

| Module | What it does | Uses this package |
|---|---|---|
| [Clinic Forms](https://github.com/anun333/oe-module-clinic-forms) | Printable clinic paperwork, filled with the clinic's details, and the same documents as OpenEMR questionnaires | at run time |
| [Layout Export](https://github.com/anun333/oe-module-layout-export) | Layout forms and lists exported to files, compared with a site or another file | at run time |
| [Units Check](https://github.com/anun333/oe-module-units-check) | A site's units in UCUM terms, and UCUM codes added to its unit lists | at run time |
| [Behavioral Health](https://github.com/anun333/oe-module-behavioral-health) | 42 CFR Part 2 consents and disclosures, in OpenEMR's own Disclosures record | at run time |
| [UDS Report](https://github.com/anun333/oe-module-uds-report) | HRSA's Uniform Data System tables, with the record problems to fix first | in its tests |
| [Staff Worklist](https://github.com/anun333/oe-module-staff-worklist) | One queue of outstanding staff work across patients | in its tests |
| [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) | A sliding fee program to HRSA's and Title X's rules | in its tests |
| [Care Management](https://github.com/anun333/oe-module-care-management) | Care management enrollment, time and the codes each month meets | in its tests |
| [Migration](https://github.com/anun333/oe-module-migration) | Records from another EHR, from the FHIR export certified EHRs offer | in its tests |

"In its tests" means the translated-strings scan only; the module's own compatibility code still runs.

## Versions

Every module installs into OpenEMR's one `vendor/` folder, so two modules needing different major versions of this package can't be installed together. So:

- **1.x only adds.** A 1.x release never removes or changes what a module already uses. Modules require `^1.0`.
- **A breaking change is 2.0.0,** and the modules move to it together.
- **Checked by reflection:** `tools/api-signature.php` reads the public surface, and `ApiAdditivityTest` holds `src` to every released one in `tests/fixtures/api-<version>.json` (a class, constant, method, parameter, property or interface method gone or changed fails). A release adds its own surface file.
- **Planned:** PHP 8.3 as the floor in **1.6.0** (2027-01-01, with a guarded patient-id accessor). The shared contracts, planned as 1.6.0, came forward to 1.5.0 on 2026-10-26.

## Install

Modules require it in their `composer.json`, and `composer require anun333/oe-module-…` from the OpenEMR directory brings it along. A module copied into `custom_modules` without Composer needs this package too. Each module's README says how, once it uses the package.

**After adding, upgrading or removing a module copied in by hand, restart the container (or Apache).** OpenEMR's Docker images build Composer's autoloader with an APCu cache, which keeps each class's file, and each class it didn't find, across requests. So the copy of this package that serves a class is settled by the first request after a restart, a class once not found stays not found until the cache is cleared, and removing the folder a cached class came from stops the pages that use it. Seen on the 8.4.1 image, 2026-10-26; an OpenEMR built from source without `--apcu-autoloader` has no such cache.

PHP 8.2 or later; OpenEMR's own classes come from the OpenEMR it runs in. Whatever the version, run a PHP with the fix for php2shell (2026-10-21: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later); the modules' READMEs say why the official OpenEMR 7.0.x images can't get it.

## Development

```
composer install
vendor/bin/phpunit
```

The tests run without OpenEMR, so they cover the 7.0 paths. The 8.x paths run in each module's end-to-end checks against OpenEMR 8.4.1 and master.

Releasing: add the new version's public surface, `php tools/api-signature.php > tests/fixtures/api-X.Y.Z.json`, so later releases are checked against it too.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
