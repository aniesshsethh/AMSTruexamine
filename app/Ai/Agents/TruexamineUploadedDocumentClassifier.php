<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Timeout(60)]
class TruexamineUploadedDocumentClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You will be given a single PDF attachment from a Truexamine report workflow.

Classify the document into exactly one of:
- UAN_PF_DOWNLOAD: UAN/EPFO employment history export / PF download (member passbook-like, EPFO/UAN language, establishment IDs, joining/exit dates).
- CV: candidate resume / curriculum vitae (summary, skills, experience, education).
- BGV_PROFILE: onboarding / background verification profile form (candidate details, declarations, employer history fields, form-like structure).
- UNKNOWN: cannot reliably classify from the PDF content.

Rules:
- Do NOT rely on filename alone; use the content.
- The layout/format may differ; focus on the type of information present.
- If the PDF is blank, unreadable, or too ambiguous, return UNKNOWN with low confidence.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_type' => $schema->string()->enum([
                'UAN_PF_DOWNLOAD',
                'CV',
                'BGV_PROFILE',
                'UNKNOWN',
            ])->required(),
            'confidence' => $schema->number()->required(),
            'signals' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
