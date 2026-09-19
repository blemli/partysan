<?php

use Blemli\Partysan\Rename\AppName;

it('derives every spelling from a multi-word name', function (string $input) {
    $name = new AppName($input);

    expect($name->title())->toBe('My App')
        ->and($name->studly())->toBe('MyApp')
        ->and($name->kebab())->toBe('my-app')
        ->and($name->snake())->toBe('my_app')
        ->and($name->flat())->toBe('myapp')
        ->and($name->upperSnake())->toBe('MY_APP')
        ->and($name->words())->toBe('my app');
})->with(['My App', 'my-app', 'MyApp', 'my_app']);

it('collapses duplicate spellings of a single-word name', function () {
    $name = new AppName('Partysan');

    expect(array_keys($name->replacementsTo(new AppName('New App'))))
        ->toBe(['Partysan', 'PARTYSAN', 'partysan']);
});

it('maps each old spelling to the same spelling of the new name', function () {
    $old = new AppName('My App');
    $new = new AppName('Fancy Tool');

    expect($old->replacementsTo($new))->toBe([
        'My App' => 'Fancy Tool',
        'MyApp' => 'FancyTool',
        'MY_APP' => 'FANCY_TOOL',
        'my-app' => 'fancy-tool',
        'my_app' => 'fancy_tool',
        'myapp' => 'fancytool',
        'my app' => 'fancy tool',
    ]);
});

it('replaces whole words only', function () {
    $old = new AppName('art');
    $new = new AppName('craft');

    $text = 'php artisan art:run — Art by ART_KEY in art-studio and art.example and MyArt';
    $result = $old->replaceIn($text, $new, $count);

    expect($result)->toBe('php artisan craft:run — Craft by CRAFT_KEY in craft-studio and craft.example and MyArt')
        ->and($count)->toBe(5);
});

it('keeps the studly spelling attached to a following class name', function () {
    $old = new AppName('My App');
    $new = new AppName('New App');

    expect($old->replaceIn('MyAppServiceProvider extends MyApple', $new))
        ->toBe('NewAppServiceProvider extends MyApple');
});

it('flags any casing when scanning', function () {
    $name = new AppName('My App');

    expect($name->matches('see MYAPP here'))->toBeTrue()
        ->and($name->matches('Myapp'))->toBeTrue()
        ->and($name->matches('my-apps'))->toBeFalse()
        ->and($name->matches('nothing'))->toBeFalse();
});
