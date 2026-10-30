<?php

declare(strict_types=1);

it('rebuilds a cold summary once for 10 simultaneous readers', function () {
    tradableAsset();
    $startAt = startInstant();

    $outputs = runTogether(array_fill(0, 10, ['summary_reader.php', $startAt]));

    $rebuilds = collect($outputs)->filter(fn (string $output) => json_decode($output, true)['queries'] > 0);

    expect($rebuilds)->toHaveCount(1);
});
