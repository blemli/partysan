<?php

namespace Blemli\Partysan\Rename;

use Illuminate\Support\Str;

/**
 * All the spellings an app name shows up as inside a project.
 */
final class AppName
{
    public function __construct(public readonly string $input) {}

    public function title(): string
    {
        return Str::headline($this->input);
    }

    public function studly(): string
    {
        return Str::studly($this->title());
    }

    public function kebab(): string
    {
        return Str::slug($this->title());
    }

    public function snake(): string
    {
        return str_replace('-', '_', $this->kebab());
    }

    public function flat(): string
    {
        return str_replace('-', '', $this->kebab());
    }

    public function upperSnake(): string
    {
        return strtoupper($this->snake());
    }

    public function words(): string
    {
        return strtolower($this->title());
    }

    /**
     * Variant name => spelling. Order matters: when two variants collapse to the
     * same spelling (single-word names), the first one listed wins when mapping
     * to the new name.
     *
     * @return array<string, string>
     */
    public function variants(): array
    {
        return [
            'title' => $this->title(),
            'studly' => $this->studly(),
            'upperSnake' => $this->upperSnake(),
            'kebab' => $this->kebab(),
            'snake' => $this->snake(),
            'flat' => $this->flat(),
            'words' => $this->words(),
        ];
    }

    /**
     * Old spelling => new spelling, deduplicated.
     *
     * @return array<string, string>
     */
    public function replacementsTo(AppName $new): array
    {
        $map = [];
        $newVariants = $new->variants();

        foreach ($this->variants() as $variant => $spelling) {
            if ($spelling === '' || array_key_exists($spelling, $map)) {
                continue;
            }

            $map[$spelling] = $newVariants[$variant];
        }

        return $map;
    }

    /**
     * Case-sensitive pattern matching every known spelling as a whole word.
     * Capitalised spellings may be followed by another capital (MyAppServiceProvider).
     */
    public function replacePattern(): string
    {
        $capitalised = [];
        $plain = [];

        foreach (array_unique($this->variants()) as $spelling) {
            if ($spelling === '') {
                continue;
            }

            if (preg_match('/^[A-Z][a-z]/', $spelling)) {
                $capitalised[] = $spelling;
            } else {
                $plain[] = $spelling;
            }
        }

        $branches = [];

        if ($capitalised !== []) {
            $branches[] = '(?:'.$this->alternation($capitalised).')(?![a-z0-9])';
        }

        if ($plain !== []) {
            $branches[] = '(?:'.$this->alternation($plain).')(?![A-Za-z0-9])';
        }

        return '/(?<![A-Za-z0-9])(?:'.implode('|', $branches).')/u';
    }

    /**
     * Case-insensitive pattern used to flag anything that still looks like the name.
     */
    public function scanPattern(): string
    {
        $spellings = array_values(array_unique(array_filter($this->variants())));

        return '/(?<![A-Za-z0-9])(?:'.$this->alternation($spellings).')(?![A-Za-z0-9])/iu';
    }

    /**
     * Replace every known spelling of this name with the matching spelling of $new.
     *
     * @param-out int $count
     */
    public function replaceIn(string $text, AppName $new, ?int &$count = null): string
    {
        $map = $this->replacementsTo($new);
        $count = 0;

        $result = preg_replace_callback(
            $this->replacePattern(),
            fn (array $match) => $map[$match[0]] ?? $match[0],
            $text,
            -1,
            $count,
        );

        return $result ?? $text;
    }

    public function matches(string $text): bool
    {
        return preg_match($this->scanPattern(), $text) === 1;
    }

    /**
     * @param  list<string>  $spellings
     */
    private function alternation(array $spellings): string
    {
        usort($spellings, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return implode('|', array_map(fn (string $s) => preg_quote($s, '/'), $spellings));
    }
}
