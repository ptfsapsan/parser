<?php


namespace app\components\parser\news;

use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;


class Example implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;


    public static function run(): array
    {
        $posts = [];

        /*Create Post*/
        $post = new NewsPost(
            self::class,
            "Заголовок новости",
            "Описание новости",
            "2020-09-20 20:23:03",
            "https://life.ru/p/1347758",
            "https://static.life.ru/S_publications/2020/8/25/769661646456.8036-600x.png"
        );

        /*Add items to post*/
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_HEADER,
                "Заголовок",
                null,
                null,
                6,
                null
            ));
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_LINK,
                "Ссылка на instagram",
                null,
                "https://instagram.com",
                null,
                null
            ));
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_TEXT,
                "Текст новости",
                null,
                null,
                null,
                null
            ));
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_VIDEO,
                null,
                null,
                null,
                null,
                "YQRmaQ14-LA"
            ));
        $posts[] = $post;

        return $posts;
    }


}