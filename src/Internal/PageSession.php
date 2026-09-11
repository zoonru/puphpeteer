<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;

/** Tracks the default execution world and frame lifecycle for a single CDP target. */
final class PageSession
{
    public string $frameId = '';
    public ?int $contextId = null;
    /** @var array<string, array{parent: ?string, loader: string, lifecycle: array<string, true>, started: bool}> */
    public array $frames = [];
    /** @var DeferredFuture<null> */
    private DeferredFuture $changed;
    /** @var array<string, int> */
    private array $listeners = [];
    private ?\Throwable $closed = null;

    public function __construct(public readonly Session $session)
    {
        $this->changed = new DeferredFuture();
        $this->observe('Page.frameAttached', function (array $event): void {
            $id = (string) $event['frameId'];
            $this->frames[$id] = ['parent' => (string) $event['parentFrameId'], 'loader' => '', 'lifecycle' => [], 'started' => false];
        });
        $this->observe('Page.frameNavigated', function (array $event): void {
            /** @var array<string, mixed> $frame */
            $frame = $event['frame'];
            $this->recordFrame($frame);
        });
        $this->observe('Page.frameStartedLoading', function (array $event): void {
            $id = (string) $event['frameId'];
            if (isset($this->frames[$id])) {
                $this->frames[$id]['started'] = true;
            }
        });
        $this->observe('Page.frameStoppedLoading', function (array $event): void {
            $id = (string) $event['frameId'];
            if (isset($this->frames[$id])) {
                $this->frames[$id]['lifecycle']['DOMContentLoaded'] = true;
                $this->frames[$id]['lifecycle']['load'] = true;
            }
        });
        $this->observe('Page.lifecycleEvent', function (array $event): void {
            $id = (string) $event['frameId'];
            if (!isset($this->frames[$id])) {
                return;
            }
            $name = (string) $event['name'];
            if ($name === 'init') {
                $this->frames[$id]['lifecycle'] = [];
                $this->frames[$id]['loader'] = (string) $event['loaderId'];
                $this->frames[$id]['started'] = true;
            }
            $this->frames[$id]['lifecycle'][$name] = true;
        });
        $this->observe('Page.frameDetached', function (array $event): void {
            if (($event['reason'] ?? '') === 'swap') {
                return;
            }
            unset($this->frames[(string) $event['frameId']]);
            if ($event['frameId'] === $this->frameId) {
                $this->closed = new \RuntimeException('Navigating frame was detached');
            }
        });
        $this->observe('Runtime.executionContextCreated', function (array $event): void {
            /** @var array<string, mixed> $context */
            $context = $event['context'];
            /** @var array<string, mixed> $aux */
            $aux = $context['auxData'] ?? [];
            if (($aux['isDefault'] ?? false) && ($aux['frameId'] ?? null) === $this->frameId) {
                $this->contextId = (int) $context['id'];
            }
        });
        $this->observe('Runtime.executionContextDestroyed', function (array $event): void {
            if (($event['executionContextId'] ?? null) === $this->contextId) {
                $this->contextId = null;
            }
        });
        $this->observe('Runtime.executionContextsCleared', function (): void {
            $this->contextId = null;
        });
        $this->observe('__session_closed', function (): void {
            $this->closed = new TargetClosedException('Navigating frame was detached');
            foreach ($this->listeners as $event => $id) {
                $this->session->off($event, $id);
            }
            $this->listeners = [];
        });
    }

    /** @param array<string, mixed> $tree */
    public function recordTree(array $tree): void
    {
        /** @var array<string, mixed> $frame */
        $frame = $tree['frame'];
        $this->recordFrame($frame);
        /** @var list<array<string, mixed>> $children */
        $children = $tree['childFrames'] ?? [];
        foreach ($children as $child) {
            $this->recordTree($child);
        }
    }

    /** @param array<string, mixed> $frame */
    private function recordFrame(array $frame): void
    {
        $id = (string) $frame['id'];
        $parent = isset($frame['parentId']) ? (string) $frame['parentId'] : null;
        if ($parent === null) {
            $this->frameId = $id;
        }
        $loader = (string) ($frame['loaderId'] ?? '');
        $previous = $this->frames[$id] ?? null;
        $this->frames[$id] = [
            'parent' => $parent,
            'loader' => $loader,
            'lifecycle' => $previous !== null && $previous['loader'] === $loader ? $previous['lifecycle'] : [],
            'started' => $previous['started'] ?? false,
        ];
    }

    /** @param \Closure(array<string, mixed>): void $callback */
    private function observe(string $event, \Closure $callback): void
    {
        $this->listeners[$event] = $this->session->observe($event, function (array $params) use ($callback): void {
            $callback($params);
            $this->notify();
        });
    }

    public function notify(): void
    {
        $changed = $this->changed;
        $this->changed = new DeferredFuture();
        $changed->complete(null);
    }

    /** @return Future<null> */
    public function change(): Future
    {
        return $this->changed->getFuture();
    }

    public function assertOpen(): void
    {
        if ($this->closed !== null) {
            throw $this->closed;
        }
        if ($this->session->isClosed()) {
            throw new TargetClosedException('Target closed');
        }
    }

    public function executionContext(?Cancellation $cancellation = null): int
    {
        while (true) {
            $this->assertOpen();
            if ($this->contextId !== null) {
                return $this->contextId;
            }
            $this->change()->await($cancellation);
        }
    }

    /** @param list<string> $expected */
    public function lifecycleComplete(array $expected): bool
    {
        foreach ($this->frames as $id => $frame) {
            if ($id !== $this->frameId && !$frame['started']) {
                continue;
            }
            foreach ($expected as $name) {
                if (!isset($frame['lifecycle'][$name])) {
                    return false;
                }
            }
        }
        return isset($this->frames[$this->frameId]);
    }
}
