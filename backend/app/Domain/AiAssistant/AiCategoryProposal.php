<?php

namespace App\Domain\AiAssistant;

final readonly class AiCategoryProposal
{
    /**
     * @param  array<string, mixed>  $extraFields
     */
    public function __construct(
        public string $categorySlug,
        public int $confidencePercent,
        public string $rationale,
        public array $extraFields = [],
    ) {}
}

