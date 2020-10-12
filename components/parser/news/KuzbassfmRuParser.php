<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class KuzbassfmRuParser implements ParserInterface
{
    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'http://kuzbassfm.ru/';

    protected static $posts = [];

    public static function run(): array
    {
        $curl = Helper::getCurl();
        $xml  = $curl->get(self::SITE_URL.'rss/news.xml');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler(html_entity_decode($xml));

        $feed->filter('item')->each(function ($node) {
            $tz = new DateTimeZone('Asia/Krasnoyarsk');
            $dt = new DateTime($node->filter('pubDate')->text(), $tz);

            // Если нужно округлить время до начала часа, как на сайте
            // $dt->setTime($dt->format('H'), 0, 0);

            $post = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                $node->filter('description')->text(),
                $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
                $node->filter('link')->text(),
                null
            );

            self::$posts[] = $post;
        });

        return self::$posts;
    }
}
