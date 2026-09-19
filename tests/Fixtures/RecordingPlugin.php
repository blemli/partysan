<?php

namespace Blemli\Partysan\Tests\Fixtures;

use Blemli\Partysan\Rename\BasePlugin;
use Blemli\Partysan\Rename\RenameContext;

class RecordingPlugin extends BasePlugin
{
    /** @var list<string> */
    public static array $runs = [];

    public function __construct(
        protected string $key = 'recording',
        protected int $order = 15,
        protected bool $applies = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function applies(RenameContext $context): bool
    {
        return $this->applies;
    }

    public function run(RenameContext $context): void
    {
        static::$runs[] = $this->key.':'.$context->old->kebab().'->'.$context->new->kebab();
        $context->record("{$this->key} ran");
    }

    public function checklist(RenameContext $context): array
    {
        return ["{$this->key}: remember the thing"];
    }
}
