<?php

declare(strict_types=1);

$target = $argv[1] ?? '.';
$found = false;
$rules = [
    'Display URL helper inside {{ }} (double-encodes) — use {!! esc_url(...) !!}' => ['esc_url', 'esc_url_raw'],
    'Redundant esc_html/esc_attr inside {{ }} — drop to bare {{ $var }}' => ['esc_html', 'esc_attr'],
    'esc_js inside {{ }} — review JS context; do not double-encode it through Blade' => ['esc_js'],
    'WP/WC HTML inside {{ }} (prints tags as text) — use {!! ... !!}' => ['wp_kses_post', 'wc_price', 'get_price_html', 'get_the_term_list', 'the_content'],
];

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

echo "Auditing *.blade.php under: $target\n\n";

try {
    if (!is_dir($target)) {
        throw new RuntimeException("directory not found: $target");
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $path = $file->getPathname();
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("could not read: $path");
        }

        // Preserve byte offsets and newlines so diagnostics retain their source lines.
        $source = preg_replace_callback(
            '/{{--.*?--}}|(?<!@)@verbatim\b.*?@endverbatim\b/s',
            static fn (array $match): string => preg_replace('/[^\r\n]/', ' ', $match[0]),
            $source,
        );
        if ($source === null || preg_match_all('/(?<!@)\{\{(.*?)\}\}/s', $source, $echoes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException("could not scan $path: ".preg_last_error_msg());
        }

        foreach ($echoes as $echo) {
            $calls = [];
            $tokens = token_get_all('<?php '.$echo[1][0]);
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $next++;
                }
                if (($tokens[$next] ?? null) === '(') {
                    $calls[] = strtolower(ltrim($token[1], '\\'));
                }
            }

            foreach ($rules as $label => $helpers) {
                if (array_intersect($helpers, $calls) === []) {
                    continue;
                }

                $line = substr_count($source, "\n", 0, $echo[0][1]) + 1;
                $expression = preg_replace('/\s+/', ' ', $echo[0][0]);
                if ($expression === null) {
                    throw new RuntimeException("could not scan $path: ".preg_last_error_msg());
                }
                echo "✗ $label\n    $path:$line:$expression\n\n";
                $found = true;
            }
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Audit failed: {$error->getMessage()}\n");
    exit(2);
}

if ($found) {
    fwrite(STDERR, "Found escaping anti-patterns. See the sage-blade-escaping SKILL.md.\n");
    exit(1);
}

echo "✓ No known double-encoding anti-patterns found. Manually review any raw {!! !!} echoes.\n";
