<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class YarmaxParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://www.yarmax.ru/news/';
    private const DOMAIN = 'http://www.yarmax.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.news-list .news-item');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $a = $item->find('a');
                $original = sprintf('%s%s', self::DOMAIN, $a->attr('href'));
                $title = $a->text();
                $image = $item->find('a img')->attr('src');
                $image = empty($image) ? null : sprintf('%s%s', self::DOMAIN, $image);
                $description = $title;
                $originalParser = self::getParser($original, $curl);
                $createDate = $originalParser->find('span.news-date-time')->text();
                $createDate = sprintf('%s %s', $createDate, date('H:i:s'));
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($originalParser, $post);
            }
        }

        return $posts;
    }

    private static function getParser(string $link, Curl $curl): PhpQueryObject
    {
        try {
            $content = $curl->get(Helper::prepareUrl($link));
        } catch (Exception $e) {
            return null;
        }

        return PhpQuery::newDocument($content);
    }

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $t = $parser->find('.news-detail');
        $t->find('.news-date-time')->remove();
        $t->find('h3')->remove();
        $t->find('p[align="right"]')->remove();
        $t->find('div')->remove();
        $t->find('script')->remove();
        $text = trim($t->text());
        if (!empty($text)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    trim($text),
                )
            );
        }
        $images = $parser->find('.news-detail img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_IMAGE,
                            null,
                            sprintf('%s%s', self::DOMAIN, trim($src)),
                        )
                    );
                }
            }
        }
        $links = $parser->find('.news-detail a');
        if (count($links)) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                if (!empty($href) && filter_var($href, FILTER_VALIDATE_URL)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_LINK,
                            null,
                            null,
                            $href,
                        )
                    );
                }
            }
        }


        return $post;
    }
}
