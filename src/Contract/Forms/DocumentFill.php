<?php

/**
 * Clinic Forms' fill event, for a module that fills a table in one of its documents (Sliding Fee
 * fills the sliding fee notice's discounts). Clinic Forms sends EVENT_NAME before it prints or
 * downloads a document; the event is its own class, which implements nothing from this library, so
 * the helpers here read it by what it has rather than by its type:
 *
 *   $dispatcher->addListener(DocumentFill::EVENT_NAME, function (object $event): void {
 *       if (DocumentFill::asks($event, 'sliding-fee-notice', 'discounts')) {
 *           DocumentFill::fill($event, 'discounts', $rows, $note);
 *       }
 *   });
 *
 * What the event has, from Clinic Forms 0.33.0: public readonly documentId (string), language
 * (string, such as en or es), tableIds (list of strings) and pid (?int: null for a blank print), and
 * fillTable(string $id, list<list<string>> $rows, ?string $note): each row's cells in the table's
 * column order, with a note printed under the table. A table nobody fills prints blank.
 *
 * @package   OpenHealthcare\Common
 * @link      https://github.com/anun333/openhealthcare-common
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\Common\Contract\Forms;

final class DocumentFill
{
    public const EVENT_NAME = 'clinic_forms.document.fill';

    /** Whether the event is Clinic Forms asking for this table of this document. */
    public static function asks(object $event, string $documentId, string $tableId): bool
    {
        return method_exists($event, 'fillTable')
            && ($event->documentId ?? null) === $documentId
            && in_array($tableId, (array)($event->tableIds ?? []), true);
    }

    /** The document's language, en when the event doesn't say. */
    public static function language(object $event): string
    {
        $language = $event->language ?? null;
        return is_string($language) && $language !== '' ? $language : 'en';
    }

    /** The patient the document is filled for, or null for a blank print. */
    public static function pid(object $event): ?int
    {
        $pid = $event->pid ?? null;
        return is_int($pid) && $pid > 0 ? $pid : null;
    }

    /**
     * Fills the table. Clinic Forms refuses a table the document doesn't have, so check asks() first.
     *
     * @param list<list<string>> $rows
     */
    public static function fill(object $event, string $tableId, array $rows, ?string $note = null): void
    {
        $event->fillTable($tableId, $rows, $note);
    }
}
