<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

/** Handle to a function in the QuickJS runtime. */
final class JsFunctionHandle extends RemoteObject
{
    public function __invoke(mixed ...$arguments): mixed
    {
        return $this->client()->call($this->remoteId(), '', $arguments, 'invoke')->await();
    }
}
