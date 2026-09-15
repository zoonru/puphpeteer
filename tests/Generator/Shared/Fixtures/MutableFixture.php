<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Generator\Shared;

use Nesk\Puphpeteer\RemoteObject;

class MutableFixture extends RemoteObject
{
    public int $value {
        get { return $this->getRemote('value'); }
        set { $this->setRemote('value', $value); }
    }

    public function size(): int
    {
        return $this->invokeStaticRemote(__FUNCTION__, func_get_args());
    }
}
