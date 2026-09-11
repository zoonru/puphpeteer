<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Future;

/** A retained CDP object belongs to exactly one execution context.
 * @internal
 */
final class RemoteObject
{
    private bool $disposed = false;

    public function __construct(
        public readonly Session $session,
        public readonly int $contextId,
        public readonly string $objectId,
    ) {}

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /** @return Future<void> */
    public function dispose(): Future
    {
        if ($this->disposed) {
            return Future::complete()->map(static function (): void {});
        }
        $this->disposed = true;
        return $this->session->send('Runtime.releaseObject', ['objectId' => $this->objectId])->map(static function (): void {});
    }
}
