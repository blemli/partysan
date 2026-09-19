<?php

namespace Blemli\Partysan\Rename;

interface RenamePlugin
{
    /**
     * Short identifier used with --only and --without.
     */
    public function key(): string;

    /**
     * Lower runs first. Built-ins: env 10, composer 20, readme 25, namespace 30, kamal 40,
     * compose 45, 1password 50, forge 60, ide 80, github 800, scan 900, caches 950, directory 1000, herd 1010 (after the move; use
     * $context->currentPath()).
     */
    public function order(): int;

    /**
     * Whether this plugin has anything to do in the given project.
     */
    public function applies(RenameContext $context): bool;

    public function run(RenameContext $context): void;

    /**
     * Manual follow-ups to print after the rename.
     *
     * @return list<string>
     */
    public function checklist(RenameContext $context): array;
}
