<?php

if (!function_exists('getArrayFromSettingsFile')) {
    function getArrayFromSettingsFile(string $filepath): array
    {
        $pattern = '/^([a-zA-Z_\-]+)\s*(=|:|(?<=\s))\s*(.*)$/';
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $outputArray = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '' || str_starts_with($l, '#') || str_starts_with($l, '//')) {
                continue;
            }

            if (preg_match($pattern, $l, $matches)) {
                $key = strtolower($matches[1]);
                $rawValue = trim($matches[3]);

                $value = '';
                $inQuote = null; // Tracks if we are inside ' or "
                $len = strlen($rawValue);

                for ($i = 0; $i < $len; $i++) {
                    $char = $rawValue[$i];
                    $nextChar = $rawValue[$i + 1] ?? '';

                    if ($char === '\\' && $inQuote !== null) {
                        $value .= $char . $nextChar;
                        $i++; // Skip the escaped character
                        continue;
                    }

                    if (($char === '"' || $char === "'") && ($inQuote === null || $inQuote === $char)) {
                        $inQuote = ($inQuote === null) ? $char : null;
                    }

                    if ($inQuote === null) {
                        if ($char === '#' || ($char === '/' && $nextChar === '/')) {
                            break; // Stop parsing right here; the rest is a comment
                        }
                    }

                    $value .= $char;
                }
                $value = trim($value);

                // Evaluate the quoting state of the cleaned value
                $isDoubleQuoted = str_starts_with($value, '"') && str_ends_with($value, '"');
                $isSingleQuoted = str_starts_with($value, "'") && str_ends_with($value, "'");

                if ($isDoubleQuoted || $isSingleQuoted) {
                    $value = substr($value, 1, -1);
                    if ($isDoubleQuoted) {
                        $value = stripcslashes($value);
                    }
                }

                $outputArray[$key] = $value;
            }
        }
        return $outputArray;
    }
}
