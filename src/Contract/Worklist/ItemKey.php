<?php

/**
 * Which work item this is, as the Worklist stores assignments, notes and dismissals against it:
 * the contributing tab's id, the patient and a reference to the module's own record. A contributed
 * tab's reference is a plain positive number (the module's row id, or a number it builds), so the
 * same work keeps the same key from one load to the next and a new piece of work gets a new one.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Worklist;

final class ItemKey
{
    /** What a contributed tab's id may be. */
    public const SOURCE_PATTERN = '/^[a-z][a-z0-9]{2,15}$/D';

    /** What a contributed item's reference may be. */
    public const REF_PATTERN = '/^[1-9]\d{0,17}$/D';

    public function __construct(
        public readonly string $source,
        public readonly int $pid,
        public readonly string $ref,
    ) {
        if (preg_match(self::SOURCE_PATTERN, $source) !== 1 || $pid < 1 || preg_match(self::REF_PATTERN, $ref) !== 1) {
            throw new \InvalidArgumentException("Not a work item key: \"$source:$pid:$ref\"");
        }
    }

    public function __toString(): string
    {
        return "{$this->source}:{$this->pid}:{$this->ref}";
    }
}
