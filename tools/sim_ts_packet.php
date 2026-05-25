<?php
const TS_PACKET_SIZE = 188;

function packetSize(int $remaining, bool $pcr): int
{
    $bodySpace = TS_PACKET_SIZE - 4;
    $pcrAfContent = '';
    if ($pcr) {
        $pcrAfContent = str_repeat('P', 7);
        $bodySpace -= 1 + strlen($pcrAfContent);
    }

    $adaptationField = '';
    if ($remaining > $bodySpace) {
        $adaptationField = $pcrAfContent !== '' ? chr(strlen($pcrAfContent)) . $pcrAfContent : '';
        $chunkSize = $bodySpace;
    } elseif ($pcrAfContent !== '' || $remaining <= $bodySpace - 2) {
        if ($pcrAfContent !== '') {
            $stuffing = $bodySpace - $remaining;
            $afContent = $pcrAfContent . str_repeat('S', $stuffing);
        } else {
            $stuffing = $bodySpace - 2 - $remaining;
            $afContent = "\x00" . str_repeat('S', $stuffing);
        }
        $adaptationField = chr(strlen($afContent)) . $afContent;
        $chunkSize = $remaining;
    } else {
        $chunkSize = $remaining;
    }

    $size = 4 + strlen($adaptationField) + $chunkSize;
    if ($size < TS_PACKET_SIZE) {
        $size = TS_PACKET_SIZE;
    }
    return $size;
}

foreach ([1, 50, 100, 176, 177, 182, 183, 184, 200] as $r) {
    foreach ([false, true] as $pcr) {
        $s = packetSize($r, $pcr);
        if ($s !== 188) {
            echo "BAD remaining=$r pcr=" . ($pcr ? '1' : '0') . " size=$s\n";
        }
    }
}
echo "done\n";
