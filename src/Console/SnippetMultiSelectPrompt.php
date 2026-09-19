<?php

namespace Blemli\Partysan\Console;

use Closure;
use Laravel\Prompts\MultiSelectPrompt;

/**
 * A multiselect that shows a preview (e.g. the matching lines of a file)
 * for the highlighted option underneath the list.
 */
class SnippetMultiSelectPrompt extends MultiSelectPrompt
{
    public const SNIPPET_LINES = 12;

    /**
     * @param  array<int|string, string>  $options
     * @param  array<int|string>  $default
     * @param  Closure(int|string|null): list<string>  $snippet  already coloured lines for the highlighted key
     */
    public function __construct(
        string $label,
        array $options,
        public readonly Closure $snippet,
        array $default = [],
        int $scroll = 5,
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
    ) {
        static::$themes['default'][self::class] = SnippetMultiSelectPromptRenderer::class;

        parent::__construct($label, $options, $default, $scroll, $required, $validate, $hint);
    }

    /** @return list<string> */
    public function snippetLines(): array
    {
        return array_slice(($this->snippet)($this->highlightedValue()), 0, self::SNIPPET_LINES);
    }

    /**
     * Without a TTY (tests, --no-interaction) behave like the plain multiselect.
     */
    public function fallback(): mixed
    {
        return (new MultiSelectPrompt($this->label, $this->options, $this->default, $this->scroll, $this->required, $this->validate, $this->hint))->prompt();
    }

    public static function shouldFallback(): bool
    {
        return static::$shouldFallback;
    }
}
