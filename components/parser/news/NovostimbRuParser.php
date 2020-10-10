<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\metallizzer\Text;
use app\components\helper\metallizzer\Url;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class NovostimbRuParser implements ParserInterface
{
    const USER_ID       = 2;
    const FEED_ID       = 2;
    const SITE_URL      = 'https://novostimb.ru/';
    const YOUTUBE_REGEX = '/(youtu\.be\/|youtube\.com\/(watch\?(.*&)?v=|(embed|v)\/))([\w-]{11})/';
    const SKIP_FIRST    = true; // Пропускать первый параграф, он совпадает с описанием

    protected static $posts = [];

    public static function run(): array
    {
        $curl = Helper::getCurl();
        $xml  = $curl->get(self::SITE_URL.'news/feed');

        if (!$xml) {
            throw new Exception('Не удалось загрузить rss ленту.');
        }

        $feed = new Crawler(html_entity_decode($xml));

        $feed->filter('item')->each(function ($item) {
            $url = $item->filter('link')->text();

            $tz = new DateTimeZone('UTC');
            $dt = new DateTime($item->filter('pubDate')->text(), $tz);

            $post = new NewsPost(
                self::class,
                $title = $item->filter('title')->text(),
                $item->filter('description')->text() ?: '~',
                $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
                $url,
                self::getPostImage($url)
            );

            $items = self::parsePostContent($item->filter('content|encoded'));

            foreach ($items as $item) {
                if ($post->description === '~' && $item['type'] == NewsPostItem::TYPE_TEXT) {
                    $post->description = $item['text'];

                    continue;
                }

                $post->addItem(new NewsPostItem(...array_values($item)));
            }

            if ($post->description == '~') {
                $post->description = $post->title;
            }

            self::$posts[] = $post;
        });

        return self::$posts;
    }

    protected function parsePostContent($node)
    {
        $crawler = new Crawler($node->html());

        return array_filter($crawler->filter('p')->each(function ($node, $i) {
            $item = [
                'type'        => NewsPostItem::TYPE_TEXT,
                'text'        => null,
                'image'       => null,
                'link'        => null,
                'headerLevel' => null,
                'youtubeId'   => null,
            ];

            $iframe = $node->filter('iframe');
            if ($iframe->count()
                and preg_match(self::YOUTUBE_REGEX, $iframe->attr('src') ?? '', $m)
            ) {
                $item['type'] = NewsPostItem::TYPE_VIDEO;
                $item['youtubeId'] = $m[5];
            } else {
                if (self::SKIP_FIRST && $i == 0) {
                    return;
                }

                $item['text'] = Text::normalizeWhitespace($node->text(null, false));

                if (!$item['text']) {
                    return;
                }
            }

            return $item;
        }));
    }

    protected static function getPostImage($url)
    {
        $curl = Helper::getCurl();

        if (!$html = $curl->get($url)) {
            throw new Exception("Не удалось загрузить страницу '{$url}'");
        }

        $crawler = new Crawler($html, $url);

        $image = $crawler->filter('article .attachment-post-thumbnail');
        if (!$image->count()) {
            return;
        }

        return Url::encode($image->first()->image()->getUri());
    }
}
