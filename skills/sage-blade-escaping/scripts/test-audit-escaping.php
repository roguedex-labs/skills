<?php

declare(strict_types=1);

$root = sys_get_temp_dir().'/sage-escaping-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;

function checkAudit(string $directory, int $expected, ?int $line = null, ?string $cwd = null): void
{
    global $checks;

    $command = ['bash', __DIR__.'/audit-escaping.sh'];
    if ($directory !== '') {
        $command[] = $directory;
    }

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the audit command.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== $expected || ($line !== null && !str_contains($stdout, "sample.blade.php:$line:"))) {
        throw new RuntimeException("Unexpected audit result for $directory: expected $expected, got $status.\n$stdout$stderr");
    }
    if ($expected === 2 && !str_contains($stderr, $directory)) {
        throw new RuntimeException("Scan error did not identify its target: $stdout$stderr");
    }

    $checks++;
}

try {
    $cases = [
        'safe-output' => [<<<'BLADE'
<a href="{!! esc_url($url) !!}" title="{{ $title }}">{{ $label }}</a>
{!! wp_kses_post($html) !!}
BLADE, 0],
        'multiline-url' => ["<div>\n{{\n    ".'esc_url($url)' ."\n}}\n</div>", 1, 2],
        'tabbed-url' => ['{{'."\t".'esc_url'."\t".'($url) }}', 1, 1],
        'nested-url' => ['{{ $enabled ? trim(esc_url($url)) : \'#\' }}', 1, 1],
        'comment-separated-call' => ['{{ \esc_url /* display URL */ ($url) }}', 1, 1],
        'helper-text-not-a-call' => [<<<'BLADE'
{{ 'esc_url($url)' }}
{{ /* esc_url($url) */ $title }}
BLADE, 0],
        'raw-url-helper' => ['{{ $enabled ? esc_url_raw($url) : \'#\' }}', 1, 1],
        'attribute-helper' => ['{{ $label ?? esc_attr($fallback) }}', 1, 1],
        'javascript-helper' => ['{{'."\n".'esc_js($message) }}', 1, 1],
        'html-helper' => ['{{ $enabled ? $product->get_price_html() : wc_price(0) }}', 1, 1],
        'blade-comment' => ["{{--\n".'{{ esc_url($url) }}' ."\n--}}", 0],
        'verbatim' => ["@verbatim\n".'{{ esc_url($url) }}' ."\n@endverbatim", 0],
        'escaped-echo' => ['@{{ esc_url($url) }}', 0],
        'active-after-ignored-regions' => [<<<'BLADE'
{{-- {{ esc_url($url) }} --}}
@verbatim
{{ esc_attr($label) }}
@endverbatim
{{ $enabled ? esc_url($url) : '#' }}
BLADE, 1, 5],
        'html-comment-still-executes' => ['<!-- {{ esc_url($url) }} -->', 1, 1],
    ];

    foreach ($cases as $name => $case) {
        $directory = "$root/$name";
        mkdir($directory);
        file_put_contents("$directory/sample.blade.php", $case[0]);
        checkAudit($directory, $case[1], $case[2] ?? null);
    }

    $directory = "$root/not-blade";
    mkdir($directory);
    file_put_contents("$directory/sample.php", '{{ esc_url($url) }}');
    checkAudit($directory, 0);
    checkAudit('', 0, cwd: $directory);
    checkAudit("$root/missing", 2);
    checkAudit(__DIR__.'/../examples', 0);

    $directory = "$root/unreadable-file";
    mkdir($directory);
    $file = "$directory/sample.blade.php";
    file_put_contents($file, '{{ esc_url($url) }}');
    chmod($file, 0000);
    clearstatcache();
    if (is_readable($file)) {
        throw new RuntimeException('Run these checks as an unprivileged user to exercise unreadable input.');
    }
    checkAudit($directory, 2);
    chmod($file, 0600);

    $directory = "$root/unreadable-directory";
    mkdir($directory);
    mkdir("$directory/private");
    file_put_contents("$directory/private/sample.blade.php", '{{ esc_url($url) }}');
    chmod("$directory/private", 0000);
    checkAudit($directory, 2);

    echo "Passed $checks audit command regression checks.\n";
} finally {
    // Restore directory permissions before traversing failed permission fixtures.
    foreach (glob("$root/*/private") as $directory) {
        chmod($directory, 0700);
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
