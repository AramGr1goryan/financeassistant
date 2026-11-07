<?php

namespace App\Domain\AiAssistant;

final readonly class AiCategoryPromptContext
{
    /**
     * @param  list<array{slug: string, name: string}>  $allowedCategories
     */
    public function __construct(
        public string $transactionType,
        public ?string $merchant,
        public ?string $description,
        public array $allowedCategories,
    ) {}
}

