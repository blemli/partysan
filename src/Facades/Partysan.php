<?php

namespace Blemli\Partysan\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Blemli\Partysan\Partysan
 */
class Partysan extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Blemli\Partysan\Partysan::class;
    }
}
