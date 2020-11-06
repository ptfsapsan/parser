<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;

class Saratov24Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const DOMAIN = 'https://saratov24.tv';
    private const LINK = 'https://saratov24.tv/news/';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        try {
            $content = $curl->get(Helper::prepareUrl(self::LINK));
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('.content-grid div:first a');
        if (count($items)) {
            foreach ($items as $item) {
                $original = $item->getAttribute('href');
                if (!empty($original)) {
                    $original = sprintf('%s%s', self::DOMAIN, trim($original));
                }
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $img = $item->find('img');
                $image = $img->attr('src');
                if (!empty($image)) {
                    $image = sprintf('%s%s', self::DOMAIN, trim($image));
                }
                $title = $img->attr('alt');

                $createDate = $item->find('.post-block--date')->text();
                $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($createDate));
                $description = $title;
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($original, $curl, $post);
            }
        }

        return $posts;
    }

    private static function setOriginalData(string $original, Curl $curl, NewsPost $post): NewsPost
    {
        if (empty($original) || !filter_var($original, FILTER_VALIDATE_URL)) {
            return $post;
        }
        try {
            $content = $curl->get(Helper::prepareUrl($original));
        } catch (Exception $e) {
            return $post;
        }
        $parser = PhpQuery::newDocument($content);
        $header = $parser->find('.article-body h4')->text();
        if (!empty($header)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_HEADER,
                    trim($header),
                    null,
                    null,
                    1,
                )
            );
        }
        $text = $parser->find('.article-body p')->text();
        if (!empty($text)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    trim($text),
                )
            );
        }
        $images = $parser->find('.article-layout .fotorama img');
        if (!empty($images) && count($images)) {
            foreach ($images as $image) {
                $src = $image->getAttribute('src');
                if (!empty($src)) {
                    $src = sprintf('%s%s', self::DOMAIN, trim($src));
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

        return $post;
    }

    private static function getTimestampFromString(string $time): string
    {
        if (!preg_match('/^(\d+) (\w+), (\d+) (\d+):(\d+)$/ui', $time, $matches)) {
            return time();
        }
        switch ($matches[2]) {
            case 'янв':
            case 'январь':
            case 'января':
                $month = '01';
                break;
            case 'фев':
            case 'февраль':
            case 'февраля':
                $month = '02';
                break;
            case 'мар':
            case 'март':
            case 'марта':
                $month = '03';
                break;
            case 'апр':
            case 'апрель':
            case 'апреля':
                $month = '04';
                break;
            case 'май':
            case 'мая':
                $month = '05';
                break;
            case 'июн':
            case 'июнь':
            case 'июня':
                $month = '06';
                break;
            case 'июл':
            case 'июль':
            case 'июля':
                $month = '07';
                break;
            case 'авг':
            case 'август':
            case 'августа':
                $month = '08';
                break;
            case 'сен':
            case 'сентябрь':
            case 'сентября':
                $month = '09';
                break;
            case 'окт':
            case 'октябрь':
            case 'октября':
                $month = '10';
                break;
            case 'ноя':
            case 'ноябрь':
            case 'ноября':
                $month = '11';
                break;
            case 'дек':
            case 'декабрь':
            case 'декабря':
                $month = '12';
                break;
            default:
                $month = '01';
        }
        $time = strtotime(sprintf('%d-%d-%d %d:%d:00', $matches[3], $month, $matches[1], $matches[4], $matches[5]));

        return $time ?? time();
    }
}
