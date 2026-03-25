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
        ->toContain('Undeclared or missing employment')
        ->toContain('verifier_name')
        ->toContain('Not Available');
});
