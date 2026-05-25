<?php
$f = $argv[1] ?? 'hls/a/b/segment_1.ts';
if (!is_file($f)) {
    fwrite(STDERR, "missing: $f\n");
    exit(1);
}
$d = file_get_contents($f);
$len = strlen($d);
echo "file_len=$len mod188=" . ($len % 188) . "\n";

$wrong = 0;
$off = 0;
while ($off < $len) {
    if ($d[$off] !== "\x47") {
        $off++;
        continue;
    }
    $next = $off + 188;
    if ($next < $len && $d[$next] !== "\x47") {
        for ($j = $off + 1; $j < min($off + 300, $len); $j++) {
            if ($d[$j] === "\x47") {
                $next = $j;
                break;
            }
        }
    }
    $psz = $next - $off;
    if ($psz !== 188 && $wrong < 15) {
        echo "packet at $off size=$psz\n";
    }
    if ($psz !== 188) {
        $wrong++;
    }
    $off = $next;
}
echo "non188_packets=$wrong\n";
