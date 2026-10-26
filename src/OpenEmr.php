<?php

/**
 * The OpenEMR calls our modules make that changed between releases, made once here so a module runs
 * on 7.0.x as well as 8.x. Each uses the newer interface when the running OpenEMR has it and the
 * 7.0 way otherwise:
 *
 *   settings                OEGlobalsBag (7.0.4, 8.0+)          or $GLOBALS
 *   web root, project and    OEGlobalsBag's getters (8.1+)        or the value OpenEMR set under its
 *   library folders, kernel                                      name (in the bag on 7.0.4 to 8.0.x,
 *                                                                in $GLOBALS before)
 *   the session             SessionWrapperFactory (8.0+)        or $_SESSION
 *   CSRF tokens             CsrfUtils with a session argument    or without one (7.0)
 *   access denied           AccessDeniedHelper (8.1+)            or a plain 403 page
 *   the audit log           EventAuditLogger::getInstance()      or ::instance() (7.0's Singleton trait)
 *
 * Measured against the v7_0_3 and v8_0_0 tags and the official 7.0.2, 7.0.3 and 8.x images
 * (2026-10-16 and 2026-10-22). Corrected 2026-10-25 (1.3.1): 7.0.4 and 8.0.0 to 8.0.0.3 have the
 * bag but not getWebRoot(), getProjectDir(), getSrcDir() or getKernel(), which came with 8.1.0, so
 * 1.0.0 to 1.3.0 stopped every page there; the official 8.0.0 image showed it. It replaces the copies in Clinic Forms, Layout Export and Units Check,
 * which were the same in every method but the module named in the 403 page's log line.
 *
 * OpenEMR's classes are the host's: nothing here requires them through Composer, and each is
 * looked up by name where a release might not have it.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OpenEmr
{
    private const GLOBALS_BAG = 'OpenEMR\\Core\\OEGlobalsBag';
    private const SESSION_FACTORY = 'OpenEMR\\Common\\Session\\SessionWrapperFactory';
    private const ACCESS_DENIED = 'OpenEMR\\Common\\Acl\\AccessDeniedHelper';

    /** A string setting, such as OE_SITE_DIR. */
    public static function setting(string $name): string
    {
        $value = class_exists(self::GLOBALS_BAG) ? self::GLOBALS_BAG::getInstance()->get($name) : ($GLOBALS[$name] ?? '');
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * What OEGlobalsBag's getter returns when the running OpenEMR has it (8.1.0 and later), else the
     * value OpenEMR set under its name: in the bag on 7.0.4 to 8.0.x, in $GLOBALS before.
     */
    private static function fromBag(string $getter, string $name): mixed
    {
        if (!class_exists(self::GLOBALS_BAG)) {
            return $GLOBALS[$name] ?? null;
        }
        $bag = self::GLOBALS_BAG::getInstance();
        return method_exists($bag, $getter) ? $bag->$getter() : $bag->get($name);
    }

    public static function siteDir(): string
    {
        return self::setting('OE_SITE_DIR');
    }

    public static function webRoot(): string
    {
        $value = self::fromBag('getWebRoot', 'webroot');
        return is_string($value) ? $value : '';
    }

    public static function projectDir(): string
    {
        $value = self::fromBag('getProjectDir', 'fileroot');
        return is_string($value) ? $value : '';
    }

    /** OpenEMR's library folder, for the legacy includes (options.inc.php, forms.inc.php). */
    public static function srcDir(): string
    {
        $value = self::fromBag('getSrcDir', 'srcdir');
        return is_string($value) ? $value : '';
    }

    /** OpenEMR's kernel, or null outside a request (a command-line tool, the tests). */
    public static function kernel(): ?object
    {
        if (class_exists(self::GLOBALS_BAG)) {
            $bag = self::GLOBALS_BAG::getInstance();
            // 8.1.0's getKernel() throws without one; ask first.
            if (method_exists($bag, 'hasKernel') && !$bag->hasKernel()) {
                return null;
            }
        }
        $kernel = self::fromBag('getKernel', 'kernel');
        return is_object($kernel) ? $kernel : null;
    }

    /** OpenEMR's release, such as 8.4.1: the setting where it is set, else the database's version row. */
    public static function version(): string
    {
        $v = self::setting('openemr_version');
        if ($v !== '') {
            return $v;
        }
        $row = QueryUtils::fetchRecords('SELECT `v_major`, `v_minor`, `v_patch` FROM `version` LIMIT 1')[0] ?? null;
        return $row === null ? '' : $row['v_major'] . '.' . $row['v_minor'] . '.' . $row['v_patch'];
    }

    /** The event dispatcher, for subscribing to OpenEMR's events (the menu, other modules'). */
    public static function eventDispatcher(): EventDispatcherInterface
    {
        $kernel = self::kernel();
        $dispatcher = $kernel !== null && method_exists($kernel, 'getEventDispatcher') ? $kernel->getEventDispatcher() : null;
        if (!$dispatcher instanceof EventDispatcherInterface) {
            throw new \RuntimeException('OpenEMR kernel not available');
        }
        return $dispatcher;
    }

    /**
     * Sends an event to OpenEMR's dispatcher, for other modules to answer. Without a kernel (a
     * command-line tool, the tests) nobody is listening, so nothing happens.
     */
    public static function dispatch(object $event, string $name): void
    {
        try {
            $dispatcher = self::eventDispatcher();
        } catch (\RuntimeException) {
            return;
        }
        $dispatcher->dispatch($event, $name);
    }

    /** A value from the logged-in user's session: pid, authUser, authProvider, authUserID. */
    public static function session(string $key): mixed
    {
        $session = self::sessionObject();
        if ($session !== null) {
            return $session->get($key);
        }
        return $_SESSION[$key] ?? null;
    }

    public static function csrfToken(): string
    {
        $session = self::sessionObject();
        return $session !== null && self::takesSession('collectCsrfToken')
            ? (string)CsrfUtils::collectCsrfToken(session: $session)
            : (string)CsrfUtils::collectCsrfToken();
    }

    /** Ends the request (OpenEMR's own CSRF failure page) unless the posted token is valid. */
    public static function requireCsrf(mixed $token): void
    {
        $token = is_string($token) ? $token : '';
        $session = self::sessionObject();
        $valid = $session !== null && self::takesSession('verifyCsrfToken')
            ? CsrfUtils::verifyCsrfToken($token, session: $session)
            : CsrfUtils::verifyCsrfToken($token);
        if (!$valid) {
            // 8.x answers 403; 7.0 prints the same refusal with 200. Make it 403 everywhere.
            if (!headers_sent()) {
                http_response_code(403);
            }
            CsrfUtils::csrfNotVerified();
            exit;
        }
    }

    /**
     * Ends the request with OpenEMR's access-denied page, or a plain 403 on releases without one.
     *
     * @param string $module the module's name, for the log line on releases without the page
     */
    public static function deny(string $logMessage, string $title, string $module = 'openhealthcare module'): never
    {
        if (class_exists(self::ACCESS_DENIED)) {
            self::ACCESS_DENIED::denyWithTemplate($logMessage, $title);
            exit;
        }
        error_log($module . ': ' . $logMessage);
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title></head><body><h1>'
            . htmlspecialchars(xl('Not authorized'), ENT_QUOTES) . '</h1></body></html>';
        exit;
    }

    public static function isAdministrator(): bool
    {
        return (bool)AclMain::aclCheckCore('admin', 'super');
    }

    /** Writes an event to OpenEMR's audit log as the logged-in user. */
    public static function audit(string $event, string $comment, int $pid = 0): void
    {
        $logger = method_exists(EventAuditLogger::class, 'getInstance') ? EventAuditLogger::getInstance() : EventAuditLogger::instance();
        $logger->newEvent($event, (string)self::session('authUser'), (string)self::session('authProvider'), 1, $comment, $pid);
    }

    private static function sessionObject(): ?object
    {
        if (!class_exists(self::SESSION_FACTORY)) {
            return null;
        }
        return self::SESSION_FACTORY::getInstance()->getActiveSession();
    }

    private static function takesSession(string $method): bool
    {
        foreach ((new \ReflectionMethod(CsrfUtils::class, $method))->getParameters() as $parameter) {
            if ($parameter->getName() === 'session') {
                return true;
            }
        }
        return false;
    }
}
