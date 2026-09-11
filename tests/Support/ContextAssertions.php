<?php

declare(strict_types=1);

use Nesk\Puphpeteer\BrowserContext;
use Nesk\Puphpeteer\Internal\Connection;
use Nesk\Puphpeteer\Page;

/** Protocol observations stand in for public discovery APIs outside the selected scope. */
trait ContextAssertions
{
    private function browserConnection(): Connection
    {
        return self::property($this->browser, 'connection');
    }

    private function contextId(BrowserContext $context): string
    {
        return self::property($context, 'id');
    }

    private function contextIds(): array
    {
        $ids = $this->browserConnection()->send('Target.getBrowserContexts')->await()['browserContextIds'];
        sort($ids);
        return $ids;
    }

    private function targetId(Page $page): string
    {
        return self::property($page, 'targetId');
    }

    private function targets(?string $contextId = null): array
    {
        $targets = $this->browserConnection()->send('Target.getTargets')->await()['targetInfos'];
        return array_values(array_filter($targets, static fn (array $target): bool =>
            $target['type'] === 'page' && ($contextId === null || ($target['browserContextId'] ?? '') === $contextId)));
    }

    private function targetInfo(Page $page): array
    {
        return $this->browserConnection()->send('Target.getTargetInfo', ['targetId' => $this->targetId($page)])->await()['targetInfo'];
    }
}
