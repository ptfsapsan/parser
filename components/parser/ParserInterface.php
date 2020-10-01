<?php

namespace app\components\parser;

use Throwable;

/**
 * Интерфейс, представляющий парсер
 */
interface ParserInterface
{

    /**
     * @return array
     * @throws Throwable
     */
    public static function run(): array;

}