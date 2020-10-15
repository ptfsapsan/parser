<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Parser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class InfoshosRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'http://www.infoshos.ru/';

    protected static $posts = [];
    protected static $post;

    public static function run(): array
    {
        $xml = self::request(self::SITE_URL.'rss/rss_ru.xml');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler($xml);
        $feed->filter('item')->each(function ($node) {
            $dt = new DateTime($node->filter('pubDate')->text());
            $url = $node->filter('link')->text();

            self::$post = new NewsPost(
                self::class,
                html_entity_decode($node->filter('title')->text()),
                html_entity_decode($node->filter('description')->text()),
                $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
                $url,
                null,
            );

            self::loadPost($url);
        });

        return static::$posts;
    }

    protected static function loadPost($url)
    {
        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);

        $image = $crawler->filter('.center-column-wrap td.text .border-round div')->first();

        if ($image->count()
            && preg_match('/background: url\(([^\)]+)\)/', $image->attr('style'), $m)
        ) {
            $url = trim($m[1], '\'"');

            if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                $url = self::SITE_URL.ltrim($url, '/');
            }

            self::$post->image = $url;
        }

        $items = (new Parser())->parseMany($crawler->filter('.center-column-wrap td.text > *'));

        foreach ($items as $item) {
            if (!self::$post->image && $item['type'] === NewsPostItem::TYPE_IMAGE) {
                self::$post->image = $item['image'];

                continue;
            }

            self::$post->addItem(new NewsPostItem(...array_values($item)));
        }

        self::$posts[] = self::$post;
    }
}
