<?php

use Blemli\Partysan\Console\CodePreview;
use Blemli\Partysan\Console\SnippetHighlighter;
use Blemli\Partysan\Console\SnippetMultiSelectPrompt;
use Blemli\Partysan\Rename\AppName;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

it('colours strings, variables, keywords and comments and emphasises the old name', function () {
    $highlighter = new SnippetHighlighter((new AppName('My App'))->scanPattern());

    $out = $highlighter->highlight("return config('my_app.disk'); // MyApp");

    expect($out)
        ->toContain("\e[35mreturn\e[39m")
        ->toContain("\e[32m'\e[39m\e[1m\e[33mmy_app\e[39m\e[22m\e[32m.disk'\e[39m")
        ->toContain("\e[90m// \e[39m\e[1m\e[33mMyApp\e[39m\e[22m");
});

it('does not colour keywords outside php files', function () {
    $out = (new SnippetHighlighter)->highlight('return of the jedi', php: false);

    expect($out)->toBe('return of the jedi');
});

it('renders a preview box for the highlighted option', function () {
    Prompt::fake([Key::DOWN, Key::SPACE, Key::ENTER]);
    // fallbackWhen() only ever turns the flag on; the console kernel turned it on for unit tests.
    $flag = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $flag->setValue(null, false);

    $prompt = new SnippetMultiSelectPrompt(
        label: 'Pick files',
        options: ['a.php' => 'a.php  (1)', 'b.php' => 'b.php  (2)'],
        snippet: fn ($key) => $key === 'b.php' ? ['   12  second file line'] : ['    1  first file line'],
    );

    expect($prompt->prompt())->toBe(['b.php']);

    Prompt::assertOutputContains('first file line');
    Prompt::assertOutputContains('second file line');
    Prompt::assertOutputContains('preview');

    $flag->setValue(null, true);
});

it('previews matches with one greyed context line on each side and marks gaps', function () {
    $source = ['<?php', '', 'namespace App;', '', 'class Foo', '{', '    public function disk()', '    {', "        return config('my_app.disk');", '    }', '', '    public function name()', '    {', "        return 'my-app';", '    }', '}'];
    $preview = new CodePreview(new SnippetHighlighter((new AppName('My App'))->scanPattern()), width: 80);

    $lines = array_map(fn ($l) => preg_replace('/\e\[[0-9;]*m/', '', $l), $preview->lines([9, 14], $source, true));

    expect($lines)->toBe([
        '    8  {',
        "    9  return config('my_app.disk');",
        '   10  }',
        '    ⋯',
        '   13  {',
        "   14  return 'my-app';",
        '   15  }',
    ]);

    $raw = $preview->lines([9], $source, true);
    expect($raw[0])->toContain("\e[2m{\e[22m")            // context is dim
        ->and($raw[1])->toContain("\e[1m\e[33mmy_app");    // match is emphasised
});

it('merges overlapping context and caps the preview with a count', function () {
    $source = array_map(fn ($i) => "line {$i} my-app", range(1, 40));
    $preview = new CodePreview(new SnippetHighlighter, width: 80, budget: 6);

    $lines = array_map(fn ($l) => preg_replace('/\e\[[0-9;]*m/', '', $l), $preview->lines([2, 3, 20, 30], $source, false));

    expect($lines)->toBe([
        '    1  line 1 my-app',
        '    2  line 2 my-app',
        '    3  line 3 my-app',
        '    4  line 4 my-app',
        '       … and 2 more',
    ]);
});
