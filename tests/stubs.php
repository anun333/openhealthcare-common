<?php

namespace {
    // OpenEMR's translation function, which the host provides; the tests run without OpenEMR.
    if (!function_exists('xl')) {
        function xl(string $text): string
        {
            return $text;
        }
    }
}

namespace OpenEMR\Common\Database {
    // The one QueryUtils call the library's own classes make outside OpenEMR: a test sets the rows.
    if (!class_exists(QueryUtils::class)) {
        final class QueryUtils
        {
            /** @var list<array<string, mixed>> */
            public static array $rows = [];
            public static string $lastSql = '';

            /**
             * @param array<mixed> $binds
             * @return list<array<string, mixed>>
             */
            public static function fetchRecords(string $sql, array $binds = []): array
            {
                self::$lastSql = $sql;
                return self::$rows;
            }
        }
    }
}
