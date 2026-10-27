<?php

/**
 * Stable identifier for a work item: "<source>:<pid>:<ref>", where ref is the
 * id of the underlying record (order, transaction or appointment). A screening
 * item's ref may end in one digit after a dot, telling apart the items one
 * questionnaire response can give: "12" for a total at the cut-off, "12.2" for an
 * unfinished PHQ-9 (the digit is the instrument's), so dismissing an unfinished screen
 * never hides the result it later becomes. A screen from OpenEMR's own PHQ-9 or GAD-7
 * encounter form has its form's name before the id ("phq9-7", "gad7-7.3"), since those
 * ids are another table's.
 *
 * Claims follow-up, prior authorization and sliding fee items do the same from 0.8.6: the
 * digit after the dot names the condition (ClaimRules, AuthorizationRules and
 * ReassessmentRules give each one its digit), and a claim's key also names the claim
 * version after a "v" ("345.1v2"). So dismissing a denial never hides the "claim file not
 * sent" that follows it, or the denial of the claim billed again; "ends soon" never hides
 * "units used up"; "runs out soon" never hides "ran out". Keys saved before 0.8.6 have no
 * dot; LegacyKeys carries their state over once.
 *
 * The pid is part of the key so an action request can be re-checked against
 * the source for that one patient instead of trusting what the browser sent.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Model;

final class ItemKey
{
    public const SOURCE_RESULT = 'result';
    public const SOURCE_ORDER = 'order';
    public const SOURCE_REFERRAL = 'referral';
    public const SOURCE_CAREGAP = 'caregap';
    public const SOURCE_SCREEN = 'screen';
    public const SOURCE_CLAIM = 'claim';
    public const SOURCE_SLIDING = 'sliding';
    public const SOURCE_AUTH = 'auth';
    public const SOURCE_SOCIAL = 'social';

    /** The worklist's own sources; another module's are added through Event\CollectSourcesEvent. */
    public const BUILT_IN = ['result', 'order', 'referral', 'caregap', 'screen', 'claim', 'sliding', 'auth', 'social'];
    /** What another module's source id may be. */
    public const CONTRIBUTED_SOURCE = '/^[a-z][a-z0-9]{2,15}$/D';

    /** @var array<string, true> sources other modules added for this request */
    private static array $contributed = [];

    private const PATTERN = '/^([a-z][a-z0-9]{2,15}):([1-9]\d{0,17}):((?:(phq9|gad7|sdoh|qr)-)?[1-9]\d{0,17}(?:\.[1-9](v[1-9]\d{0,8})?)?)$/D';

    /** Sources whose ref may end in a condition digit after a dot: screening, and from 0.8.6 these three. */
    private const CONDITION_SOURCES = [self::SOURCE_SCREEN, self::SOURCE_CLAIM, self::SOURCE_AUTH, self::SOURCE_SLIDING];

    /** Accepts another module's source in keys, for this request (WorklistService does it as the module adds it). */
    public static function allow(string $source): void
    {
        if (preg_match(self::CONTRIBUTED_SOURCE, $source) === 1 && !in_array($source, self::BUILT_IN, true)) {
            self::$contributed[$source] = true;
        }
    }

    public function __construct(
        public readonly string $source,
        public readonly int $pid,
        public readonly string $ref,
    ) {
        if (!self::valid("$source:$pid:$ref")) {
            throw new \InvalidArgumentException('Invalid work item key');
        }
    }

    public static function parse(string $key): ?self
    {
        if (!self::valid($key) || !preg_match(self::PATTERN, $key, $m)) {
            return null;
        }
        return new self($m[1], (int)$m[2], $m[3]);
    }

    /**
     * The pattern; a dotted ref only on a screening, claims, prior authorization or sliding fee item, and
     * a claim version ("v2") only on a claims item; a "phq9-"/"gad7-" prefix only on a screening item; a
     * "sdoh-" or "qr-" prefix, which a social needs item always has, only there.
     */
    private static function valid(string $key): bool
    {
        if (preg_match(self::PATTERN, $key, $m) !== 1) {
            return false;
        }
        $prefix = $m[4] ?? '';
        $dotted = str_contains($m[3], '.');
        if (($m[5] ?? '') !== '' && $m[1] !== self::SOURCE_CLAIM) {
            return false;
        }
        if ($dotted && !in_array($m[1], self::CONDITION_SOURCES, true)) {
            return false;
        }
        return match ($m[1]) {
            self::SOURCE_SCREEN => in_array($prefix, ['', 'phq9', 'gad7'], true),
            self::SOURCE_SOCIAL => in_array($prefix, ['sdoh', 'qr'], true),
            // Another module's source: only once it's been added, and with a plain ref.
            default => (in_array($m[1], self::BUILT_IN, true) || isset(self::$contributed[$m[1]])) && $prefix === '',
        };
    }

    /** The ref without its condition digit or claim version: the record the item is about. */
    public function record(): string
    {
        return explode('.', $this->ref, 2)[0];
    }

    public function __toString(): string
    {
        return "{$this->source}:{$this->pid}:{$this->ref}";
    }
}
