<?php

namespace Blemli\Partysan\Console;

use Laravel\Prompts\Concerns\Colors;

/**
 * Light syntax colouring for one-line code snippets shown in the terminal,
 * with every match of a given pattern in bold yellow on top.
 */
class SnippetHighlighter
{
    use Colors;

    /** @var list<string> */
    protected const KEYWORDS = [
        'abstract', 'as', 'break', 'case', 'catch', 'class', 'const', 'continue', 'declare', 'default', 'do', 'echo',
        'else', 'elseif', 'enum', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'if', 'implements',
        'instanceof', 'interface', 'match', 'namespace', 'new', 'null', 'private', 'protected', 'public', 'readonly',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'use', 'while', 'yield', 'true', 'false', 'self', 'parent',
    ];

    public function __construct(protected ?string $emphasis = null) {}

    public function highlight(string $line, bool $php = true): string
    {
        $tokens = preg_split(
            '/(\/\/.*$|#(?!\[).*$|\/\*.*?\*\/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\$[A-Za-z_][A-Za-z0-9_]*|\b\d+(?:\.\d+)?\b|\b[A-Za-z_][A-Za-z0-9_]*\b)/u',
            $line,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [$line];

        $out = '';

        foreach ($tokens as $token) {
            $color = match (true) {
                str_starts_with($token, '//'), str_starts_with($token, '#'), str_starts_with($token, '/*') => 'gray',
                $token[0] === '"' || $token[0] === "'" => 'green',
                $token[0] === '$' => 'cyan',
                $php && in_array(strtolower($token), self::KEYWORDS, true) => 'magenta',
                preg_match('/^\d/', $token) === 1 => 'yellow',
                default => null,
            };

            $out .= $this->emphasise($token, $color);
        }

        return $out;
    }

    protected function emphasise(string $text, ?string $color): string
    {
        $paint = fn (string $s) => $color === null || $s === '' ? $s : $this->{$color}($s);

        if ($this->emphasis === null) {
            return $paint($text);
        }

        $parts = preg_split('/('.trim($this->emphasis, '/iu').')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';

        foreach ($parts as $index => $part) {
            $out .= $index % 2 === 1 ? $this->bold($this->yellow($part)) : $paint($part);
        }

        return $out;
    }
}
