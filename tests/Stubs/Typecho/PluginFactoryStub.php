<?php

declare(strict_types=1);

namespace Typecho;

final class PluginFactoryStub
{
    private string $handle;

    public function __construct(string $handle)
    {
        $this->handle = $handle;
    }

    /** @param mixed $callback */
    public function __set(string $component, $callback): void
    {
        Plugin::$registrations[] = [$this->handle, $component];
    }
}
