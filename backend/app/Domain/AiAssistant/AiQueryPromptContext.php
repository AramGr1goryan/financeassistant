<?php

namespace App\Domain\AiAssistant;

final readonly class AiQueryPromptContext
{
    /**
     * @param  list<string>  $allowedIntents
     * @param  array<string, mixed>  $deterministicFacts
     */
    public function __construct(
        public string $question,
        public string $currency,
        public array $allowedIntents,
        public array $deterministicFacts,
    ) {}
}

