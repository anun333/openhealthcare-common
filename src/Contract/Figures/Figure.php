<?php

/**
 * One figure a module gives another for a period: what it counts, the number, and where it comes
 * from. A figure is a total, never a list of patients, so a module can show another's figures
 * without reading its tables or holding its records.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Figures;

final class Figure
{
    /** How many: visits, patients, months. */
    public const UNIT_COUNT = 'count';
    /** An amount in the site's currency, to the cent. */
    public const UNIT_MONEY = 'money';
    /** A share from 0 to 100. */
    public const UNIT_PERCENT = 'percent';

    public const UNITS = [self::UNIT_COUNT, self::UNIT_MONEY, self::UNIT_PERCENT];

    /**
     * A figure's id: the answering module's short name, a dot, and the figure's name, such as
     * slidingfee.discounts_posted. The module's part keeps two modules' names apart.
     */
    public const ID_PATTERN = '/^[a-z][a-z0-9]{2,31}\.[a-z][a-z0-9_]{1,63}$/D';

    /**
     * @param string      $id     see ID_PATTERN
     * @param string      $label  what it counts, already translated
     * @param int|float   $value  in the unit given
     * @param string      $unit   one of UNITS
     * @param string      $module the answering module's name as staff know it, already translated
     * @param string|null $note   what the figure includes or leaves out, already translated
     * @param string|null $url    a path under the webroot to the page the figure comes from
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int|float $value,
        public readonly string $unit,
        public readonly string $module,
        public readonly ?string $note = null,
        public readonly ?string $url = null,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException("Not a figure id: \"$id\"");
        }
        if (!in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException("Not a figure unit: \"$unit\"");
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException("Figure $id is not a number");
        }
    }
}
