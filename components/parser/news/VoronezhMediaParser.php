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

class VoronezhMediaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://www.voronezh-media.ru/';
    private const DOMAIN = 'http://www.voronezh-media.ru';

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('td#content table:first tr');
        $n = 0;
        if (count($items)) {
            foreach ($items as $el) {
                $n++;
                if ($n < 3) {
                    continue;
                }
                try {
                    $item = PhpQuery::pq($el);
                } catch (Exception $e) {
                    continue;
                }
                $image = $item->find('td img')->attr('src');
                if (!empty($image)) {
                    $image = sprintf('%s/%s', self::DOMAIN, trim($image));
                }
                $a = $item->find('td p a');
                $title = $a->text();
                $original = $a->attr('href');
                if (empty($original)) {
                    continue;
                }
                $original = sprintf('%s/%s', self::DOMAIN, $original);
                $parser = self::getParser($original, $curl);
                $basic = $parser->find('td.basic');
                $basic->find('.sign')->remove();
                $createDate = $item->find('td p')->get(0)->firstChild->textContent;
                $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($createDate));
                $description = $basic->find('h1')->text();
                if (empty($description)) {
                    $description = $basic->find('p:first')->text();
                }
                $images = [];
                $imgs = $basic->find('p img:gt(0)');
                if (count($imgs)) {
                    foreach ($imgs as $img) {
                        $src = $img->getAttribute('src');
                        if (!empty($src)) {
                            $images[] = sprintf('%s/%s', self::DOMAIN, trim($src));
                        }
                    }
                }

                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }

                $paragraphs = $basic->find('p');
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
                $posts[] = $post;
            }
        }
        return $posts;
    }

    /**
     * @param string $link
     * @param Curl $curl
     * @return PhpQueryObject
     * @throws Exception
     */
    private static function getParser(string $link, Curl $curl): PhpQueryObject
    {
        $link = trim($link);
        $content = $curl->get(Helper::prepareUrl($link));

        return PhpQuery::newDocument($content);
    }

    private static function getTimestampFromString(string $time): string
    {
        if (!preg_match('/^(\d+) (\w+) (\d+), (\d+):(\d+)$/ui', trim($time), $matches)) {
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
