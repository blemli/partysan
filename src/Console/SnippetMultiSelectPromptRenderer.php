<?php

namespace Blemli\Partysan\Console;

use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Themes\Default\MultiSelectPromptRenderer;

class SnippetMultiSelectPromptRenderer extends MultiSelectPromptRenderer
{
    public function __invoke(MultiSelectPrompt $prompt): string
    {
        if (! $prompt instanceof SnippetMultiSelectPrompt || in_array($prompt->state, ['submit', 'cancel', 'error'], true)) {
            return parent::__invoke($prompt);
        }

        // Both boxes span the terminal so the preview lines up with the list.
        $width = $prompt->terminal()->cols() - 6;
        $full = fn (string $body) => implode(PHP_EOL, array_map(fn (string $line) => $this->pad($line, $width - 2), explode(PHP_EOL, $body)));

        $this->box(
            $this->cyan($this->truncate($prompt->label, $width)),
            $full($this->renderOptions($prompt)),
            info: $this->getInfoText($prompt),
        );

        $lines = $prompt->snippetLines();

        if ($lines !== []) {
            $this->box($this->dim('preview'), $full(implode(PHP_EOL, $lines)));
        }

        return (string) $this->when(
            $prompt->hint,
            fn () => $this->hint($prompt->hint),
            fn () => $this->newLine(),
        );
    }

    public function reservedLines(): int
    {
        return parent::reservedLines() + SnippetMultiSelectPrompt::SNIPPET_LINES + 2;
    }
}
