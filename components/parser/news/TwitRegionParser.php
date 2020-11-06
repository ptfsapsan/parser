<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;

class TwitRegionParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://twitregion.ru/o-proekte/';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        try {
            $content = $curl->get(self::LINK);
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('.marquee-wrapper .marquee .item');
        foreach ($items as $item) {
            try {
                $it = PhpQuery::pq($item);
            } catch (Exception $e) {
                continue;
            }
            $a = $it->find('a');
            $title = $a->text() ?? '';
            $description = $title;
            $original = $a->attr('href');
            $createDate = date('d.m.Y H:i:s');
            $image = null;
            $images = [];
            $allText = '';
            if (!empty($original)) {
                try {
                    $content = $curl->get($original);
                } catch (Exception $e) {
                    break;
                }
                $itemParser = PhpQuery::newDocument($content);
                $date = $itemParser->find('.post-meta ul li:first')->text();
                $createDate = sprintf('%s %s', date('d.m.Y', self::getTimestampFromString($date)), date('H:i:s'));
                $imgs = $itemParser->find('.post-gallery.nolink img');
                if (!empty($imgs)) {
                    foreach ($imgs as $img) {
                        $images[] = $img->getAttribute('src');
                    }
                }
                if (count($images)) {
                    $image = current($images);
                }

                $texts = $itemParser->find('section article.type-post .post-content p');
                if (!empty($texts)) {
                    foreach ($texts as $text) {
                        try {
                            $t = PhpQuery::pq($text);
                        } catch (Exception $e) {
                            continue;
                        }
                        $allText .= ' ' . $t->text();
                    }
                }
            }

            try {
                $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
            } catch (Exception $e) {
                continue;
            }

            if (count($images)) {
                foreach ($images as $image) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_IMAGE,
                            null,
                            $image,
                        )
                    );
                }
            }
            if (!empty($allText)) {
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_TEXT,
                        trim($allText),
                    )
                );
            }

            $posts[] = $post;
        }

        return $posts;
    }

    private static function getTimestampFromString(string $time): string
    {
        if (!preg_match('/^(\d+) (\w+) (\d+)$/ui', $time, $matches)) {
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
        $time = strtotime(sprintf('%d-%d-%d', $matches[3], $month, $matches[1]));

        return $time ?? time();
    }
}
