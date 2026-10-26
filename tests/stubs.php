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
            /** @var array<mixed> */
            public static array $lastBinds = [];
            /** A test sets this to make the query fail, as a site without the table would. */
            public static ?\Throwable $throw = null;

            /**
             * @param array<mixed> $binds
             * @return list<array<string, mixed>>
             */
            public static function fetchRecords(string $sql, array $binds = []): array
            {
                self::$lastSql = $sql;
                self::$lastBinds = $binds;
                if (self::$throw !== null) {
                    throw self::$throw;
                }
                return self::$rows;
            }
        }
    }
}
