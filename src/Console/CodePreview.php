<?php

namespace Blemli\Partysan\Console;

/**
 * Builds the coloured preview lines for a file: every matching line with one
 * greyed line of context before and after, gaps marked with ⋯, capped to a budget.
 */
class CodePreview
{
    public function __construct(
        protected SnippetHighlighter $highlighter,
        protected int $width = 100,
        protected int $context = 1,
        protected int $budget = SnippetMultiSelectPrompt::SNIPPET_LINES,
    ) {}

    /**
     * @param  list<int>  $matches  1-based line numbers
     * @param  list<string>  $source  the file's lines
     * @return list<string>
     */
    public function lines(array $matches, array $source, bool $php): array
    {
        $shown = [];
        $previous = 0;
        $covered = 0;

        foreach ($matches as $number) {
            $from = max(1, $number - $this->context);
            $to = min(count($source), $number + $this->context);

            $chunk = [];

            if ($previous > 0 && $from > $previous + 1) {
                $chunk[] = $this->highlighter->gray('    ⋯');
            }

            for ($n = max($from, $previous + 1); $n <= $to; $n++) {
                $text = mb_strimwidth(trim($source[$n - 1] ?? ''), 0, $this->width, '…');
                $isMatch = in_array($n, $matches, true);

                $chunk[] = $this->highlighter->gray(sprintf('%5d', $n)).'  '
                    .($isMatch ? $this->highlighter->highlight($text, $php) : $this->highlighter->dim($text));
            }

            // Keep one line free for the "… and N more" note.
            if (count($shown) + count($chunk) > $this->budget - ($covered + 1 < count($matches) ? 1 : 0)) {
                break;
            }

            array_push($shown, ...$chunk);
            $previous = max($previous, $to);
            $covered++;
        }

        if ($covered < count($matches)) {
            $shown[] = $this->highlighter->gray(sprintf('%5s  … and %d more', '', count($matches) - $covered));
        }

        return $shown;
    }
}
