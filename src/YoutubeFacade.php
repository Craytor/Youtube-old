<?php

namespace Craytor\Youtube;

use Illuminate\Support\Facades\Facade;

class YoutubeFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'youtube';
    }
}
