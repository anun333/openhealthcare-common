<?php

/**
 * The patient a work item is about, as the Worklist shows it. SQL_COLUMNS reads what fromRow()
 * needs from patient_data under the alias pd, so a source can join it into its own query.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Worklist;

final class PatientRef
{
    /**
     * The patient_data columns fromRow() reads, for a query with patient_data AS pd. Portal access is
     * a portal account (username set) on a patient allowed the portal.
     */
    public const SQL_COLUMNS = "pd.fname, pd.lname, pd.DOB, pd.pubpid, pd.deceased_date,
        (pd.allow_patient_portal = 'YES' AND EXISTS (
            SELECT 1 FROM patient_access_onsite AS pao
             WHERE pao.pid = pd.pid AND COALESCE(pao.portal_username, '') <> '')) AS portal_access";

    public function __construct(
        public readonly int $pid,
        public readonly string $name,
        public readonly string $dob,
        public readonly string $pubpid,
        public readonly bool $deceased = false,
        public readonly bool $portalAccess = false,
    ) {
    }

    /**
     * From a row read with SQL_COLUMNS.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(int $pid, array $row, ?\DateTimeImmutable $now = null): self
    {
        $name = trim(self::text($row['lname'] ?? '') . ', ' . self::text($row['fname'] ?? ''), ', ');
        return new self(
            $pid,
            $name,
            self::text($row['DOB'] ?? ''),
            self::text($row['pubpid'] ?? ''),
            self::isDeceased($row['deceased_date'] ?? null, $now ?? new \DateTimeImmutable()),
            (int)($row['portal_access'] ?? 0) === 1,
        );
    }

    /** Whether a deceased date is set and not in the future. A zero date isn't one. */
    public static function isDeceased(mixed $deceasedDate, \DateTimeImmutable $now): bool
    {
        if (!is_string($deceasedDate) || $deceasedDate === '' || str_starts_with($deceasedDate, '0000-00-00')) {
            return false;
        }
        try {
            return new \DateTimeImmutable($deceasedDate) <= $now;
        } catch (\Exception) {
            return false;
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
