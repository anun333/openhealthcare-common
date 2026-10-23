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

- **`OpenHealthcare\Common\TranslatedStrings::scan($moduleDir)`:** every string a module passes to `xl()`, `xlt()`, `xla()`, `xlj()` or `xlx()` that has an apostrophe or quote mark, which OpenEMR shows as a backtick. A module's test:

  ```php
  $result = TranslatedStrings::scan(dirname(__DIR__));
  self::assertGreaterThan(10, $result['checked'], 'the scan found the translated strings');
  self::assertSame([], $result['found']);
  ```

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
- **The footprint tool** comes in 1.1.0, once its eight copies are one.
- **The event contracts** (the Staff Worklist's tab, Clinic Forms' fill) stay in their modules for now. Moving them means a class with two names during the change, which needs a plan of its own.

## Using it in a module

The module requires `anun333/openhealthcare-common: ^1.0`. Its bootstrap registers the copy in its own `vendor/` before asking whether the class exists. Composer remembers a class it once failed to find, so asking first would hide a copy registered afterwards.

```php
// In openemr.bootstrap.php, after registering the module's own namespace:
$common = __DIR__ . '/vendor/anun333/openhealthcare-common/src';
if (is_dir($common)) {
    // A module copied in, with `composer install --no-dev` run in its folder. This leaves OpenEMR's own
    // mapping alone when Composer installed the module there.
    $classLoader->registerNamespaceIfNotExists('OpenHealthcare\\Common\\', $common);
}
if (!class_exists(\OpenHealthcare\Common\OpenEmr::class)) {
    return; // the module stays switched off rather than stop OpenEMR's pages
}
```

`dev/new_module.py` in the openhealthcare repository starts a module this way.

Keep permission checks literal in the module's own code, such as `AclMain::aclCheckCore('admin', 'super')` at the top of a page, so a reviewer reads them where the page is. `isAdministrator()` is here for code with no page of its own. A module's `tools/footprint.php` reads the methods of this package that the module calls, as part of the module, and lists their SQL and calls under `vendor/anun333/openhealthcare-common/`.

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

## Install

Modules require it in their `composer.json`, and `composer require anun333/oe-module-…` from the OpenEMR directory brings it along. A module copied into `custom_modules` without Composer needs this package too. Each module's README says how, once it uses the package.

PHP 8.2 or later; OpenEMR's own classes come from the OpenEMR it runs in.

## Development

```
composer install
vendor/bin/phpunit
```

The tests run without OpenEMR, so they cover the 7.0 paths. The 8.x paths run in each module's end-to-end checks against OpenEMR 8.4.1 and master.

## License

GPL-3.0-or-later, as OpenEMR. See [LICENSE](LICENSE).
