<?php

namespace App\Domain\AiAssistant;

final readonly class AiQueryAssistanceResult
{
    /**
     * @param  array<string, mixed>  $deterministicFacts
     */
    public function __construct(
        public AiQueryIntent $intent,
        public string $explanation,
        public array $deterministicFacts,
    ) {}
}

