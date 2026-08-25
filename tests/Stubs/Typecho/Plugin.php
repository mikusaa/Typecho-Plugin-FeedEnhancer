<?php

declare(strict_types=1);

namespace Typecho;

final class Plugin
{
    /** @var array<int,array{0:string,1:string}> */
    public static array $registrations = [];

    /** @var array<string,array<string,mixed>> */
    public static array $callbacks = [];

    public static function factory(string $handle): PluginFactoryStub
    {
        return new PluginFactoryStub($handle);
    }
}
