<?php

/**
 * The patient identifiers and flags shown on a worklist row.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Model;

final class PatientRef
{
    /**
     * Columns every source selects for the patient, from `patient_data AS pd`.
     * portal_access mirrors the portal login rule (allow_patient_portal = YES plus
     * portal credentials); whether the portal itself is enabled is a global setting
     * checked by the caller.
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
     * @param array<string, mixed> $row with the SQL_COLUMNS fields
     */
    public static function fromRow(int $pid, array $row, ?\DateTimeImmutable $now = null): self
    {
        $name = trim(($row['lname'] ?? '') . ', ' . ($row['fname'] ?? ''), ', ');
        return new self(
            $pid,
            $name,
            (string)($row['DOB'] ?? ''),
            (string)($row['pubpid'] ?? ''),
            self::isDeceased($row['deceased_date'] ?? null, $now ?? new \DateTimeImmutable()),
            (int)($row['portal_access'] ?? 0) === 1,
        );
    }

    /**
     * A date of death on or before now. Blank and zero dates (legacy rows) mean not deceased.
     */
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
}
