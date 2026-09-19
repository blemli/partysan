<?php

namespace Blemli\Partysan\Rename;

abstract class BasePlugin implements RenamePlugin
{
    public function order(): int
    {
        return 500;
    }

    public function applies(RenameContext $context): bool
    {
        return true;
    }

    public function checklist(RenameContext $context): array
    {
        return [];
    }
}
