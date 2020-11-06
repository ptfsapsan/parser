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

class NarodVestParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://narodvest.ru/';
    private const DOMAIN = 'http://narodvest.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('div#allEntries > div:not(.catPages1) .ShortNews');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $original = sprintf('%s%s', self::DOMAIN, $item->find('a')->attr('href'));
                $time = $item->find('time.ShortNewsDate span:first')->text();
                $date = $item->find('time.ShortNewsDate span:last')->text();
                if ($date == 'Вчера') {
                    $date = date('d.m.Y', strtotime('-1 day'));
                } elseif ($date == 'Сегодня') {
                    $date = date('d.m.Y');
                }
                $createDate = sprintf('%s %s', $date, $time);
                $image = null;
                $style = $item->find('.ShortNewsImg div')->attr('style');
                if (!empty($style)) {
                    $src = str_replace(['background-image: url(\'', '\');'], '', $style);
                    $src = (sprintf('%s%s', self::DOMAIN, $src));
                    if (filter_var($src, FILTER_VALIDATE_URL)) {
                        $image = $src;
                    }
                }
                $title = $item->find('.ShortNewsMess h3')->text();
                $description = $item->find('.ShortNewsMess p')->text();
                if (empty($description)) {
                    $description = $title;
                }
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $originalParser = self::getParser($original, $curl);
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
        $text = $parser->find('#siteContent .fullNews p')->text();
        if (!empty($text)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    trim($text),
                )
            );
        }
        $images = $parser->find('.morePhotos a');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('href');
                if (!empty($src)) {
                    $src = sprintf('%s%s', self::DOMAIN, $src);
                    if (filter_var($src, FILTER_VALIDATE_URL)) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_IMAGE,
                                null,
                                $src,
                            )
                        );
                    }
                }
            }
        }

        return $post;
    }
}