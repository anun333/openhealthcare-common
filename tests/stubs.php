<?php

// OpenEMR's translation function, which the host provides; the tests run without OpenEMR.
if (!function_exists('xl')) {
    function xl(string $text): string
    {
        return $text;
    }
}
