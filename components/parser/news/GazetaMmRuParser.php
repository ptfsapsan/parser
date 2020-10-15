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

class GazetaMmRuParser implements ParserInterface
{
    use \app\components\helper\metallizzer\Cacheable;

    const USER_ID  = 2;
    const FEED_ID  = 2;
    const SITE_URL = 'https://gazeta-mm.ru/';

    protected static $posts = [];

    public static function run(): array
    {
        $html = self::request(self::SITE_URL);

        if (!$html) {
            throw new Exception('Не удалось загрузить сайт.');
        }

        $crawler = new Crawler($html, self::SITE_URL);

        $crawler->filter('.ns2-title a')->each(function ($node) {
            self::loadPost($node->link()->getUri());
        });

        return static::$posts;
    }

    protected static function loadPost($url)
    {
        if (!$html = self::request($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'.");
        }

        $crawler = new Crawler($html, $url);

        $json = $crawler->filter('script[type="application/ld+json"]');
        if (!$json->count()) {
            throw new Exception("Не удалось разобрать страницу '{$url}'.");
        }

        $data = json_decode($json->first()->text(), true);

        $tz = new DateTimeZone('Europe/Moscow');
        $dt = new DateTime($data['datePublished'], $tz);

        $post = new NewsPost(
            self::class,
            html_entity_decode($crawler->filter('title')->first()->text()),
            html_entity_decode($crawler->filter('meta[name="description"]')->first()->attr('content')),
            $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            $url,
            $data['image'][0] ?? ''
        );

        $items = (new Parser())->parseMany($crawler->filterXpath('//div[@class="itemFullText"]/node()'));

        foreach ($items as $item) {
            $post->addItem(new NewsPostItem(...array_values($item)));
        }

        self::$posts[] = $post;
    }
}
