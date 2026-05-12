<?php

namespace Tonydev\LaraGlot;

class LaraGlot
{
      public static function plugin(): LaraGlotPlugin
      {
            return new LaraGlotPlugin();
      }
}