<?php


namespace app\components\parser\news;

use app\components\helper\SevpoiskParser;
use app\components\parser\ParserInterface;
use Exception;


class SevpoiskAlushtaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const FEED_SRC = "/alushta/";

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {

        return SevpoiskParser::parse(self::FEED_SRC, self::class);

    }
}

