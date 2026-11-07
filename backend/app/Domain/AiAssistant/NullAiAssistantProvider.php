<?php

namespace App\Domain\AiAssistant;

final class NullAiAssistantProvider implements AiAssistantProviderInterface
{
    public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal
    {
        throw new AiBoundaryException('AI assistant provider is not configured.');
    }

    public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array
    {
        throw new AiBoundaryException('AI assistant provider is not configured.');
    }
}

