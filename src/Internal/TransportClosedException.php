<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Internal;

/** @internal A peer closed the browser WebSocket. */
final class TransportClosedException extends \RuntimeException {}
