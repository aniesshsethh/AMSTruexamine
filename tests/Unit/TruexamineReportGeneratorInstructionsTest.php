<?php

use App\Ai\Agents\TruexamineReportGenerator;

test('truexamine agent instructions forbid false day mismatches when sources agree', function () {
    $instructions = (string) TruexamineReportGenerator::make()->instructions();

    expect($instructions)
        ->toContain('MUST NOT report a day mismatch')
        ->toContain('normalize')
        ->toContain('UAN, CV, and BGV all show the same end date');
});

test('truexamine agent instructions require key finding themes and verifier defaults', function () {
    $instructions = (string) TruexamineReportGenerator::make()->instructions();

    expect($instructions)
        ->toContain('Missing employment in any source')
        ->toContain('verifier_name')
        ->toContain('Not Available')
        ->toContain('education_period_start')
        ->toContain('YYYY-01-01')
        ->toContain('Shown only in UAN record.')
        ->toContain('Qualification aligns in both records.')
        ->toContain('NON-NEGOTIABLE')
        ->toContain('that combination is invalid');
});
