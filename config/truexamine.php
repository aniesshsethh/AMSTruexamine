<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI model for Truexamine report generation
    |--------------------------------------------------------------------------
    |
    | Use a vision-capable model that supports PDF inputs (e.g. gpt-4o).
    |
    */

    'openai_model' => env('TRUEXAMINE_OPENAI_MODEL', 'gpt-4o'),

];
