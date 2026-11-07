<?php

namespace App\Domain\AiAssistant;

interface AiAssistantProviderInterface
{
    public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal;

    /**
     * @return array{intent: string, explanation: string, extra?: array<string, mixed>}
     */
    public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array;
}

