<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;

class PresskaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://presska.ru/';

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
        $items = $parser->find('main#main article');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $original = $item->find('.featured-thumb a')->attr('href');
                $image = $item->find('.featured-thumb a img')->attr('src');
                $createDate = $item->find('.out-thumb .meta-date')->text();
                $createDate = sprintf('%s %s', date('d.m.Y', self::getTimestampFromString($createDate)), date('H:i:s'));
                $title = $item->find('.out-thumb .entry-title a')->text();
                $description = $item->find('.out-thumb .entry-excerpt')->text();
                $description = str_replace('...', '', $description);
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
        $header = $parser->find('.entry-content p:first')->text();
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
        $paragraphs = $parser->find('.entry-content > p:gt(0)');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                $text = trim($paragraph->textContent);
                if (!empty($text)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_TEXT,
                            $text,
                        )
                    );
                }
            }
        }

        return $post;
    }

    private static function getTimestampFromString(string $time): string
    {
        $time = mb_strtolower($time);
        if (!preg_match('/^(\w+) (\d+), (\d+)$/ui', $time, $matches)) {
            return time();
        }
        switch ($matches[1]) {
            case 'янв':
                $month = '01';
                break;
            case 'фев':
                $month = '02';
                break;
            case 'мар':
            case 'март':
                $month = '03';
                break;
            case 'апр':
                $month = '04';
                break;
            case 'май':
            case 'мая':
                $month = '05';
                break;
            case 'июн':
                $month = '06';
                break;
            case 'июл':
                $month = '07';
                break;
            case 'авг':
                $month = '08';
                break;
            case 'сен':
                $month = '09';
                break;
            case 'окт':
                $month = '10';
                break;
            case 'ноя':
                $month = '11';
                break;
            case 'дек':
                $month = '12';
                break;
            default:
                $month = '01';
        }
        $time = strtotime(sprintf('%d-%d-%d', $matches[2], $month, $matches[3]));

        return $time ?? time();
    }
}
