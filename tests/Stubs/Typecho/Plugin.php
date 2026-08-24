<?php

declare(strict_types=1);

namespace Typecho;

final class Plugin
{
    /** @var array<int,array{0:string,1:string}> */
    public static array $registrations = [];

    public static function factory(string $handle): PluginFactoryStub
    {
        return new PluginFactoryStub($handle);
    }
}
