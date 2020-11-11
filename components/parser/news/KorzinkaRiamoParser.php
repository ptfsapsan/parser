<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DOMElement;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class KorzinkaRiamoParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://korzinka.riamo.ru/info/news/';
    private const DOMAIN = 'https://korzinka.riamo.ru';
    private const COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.card');
        if (count($items)) {
            $n = 0;
            foreach ($items as $item) {
                if ($n >= self::COUNT) {
                    break;
                }
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $a = $item->find('h4.card--title a');
                $title = trim($a->text());
                $original = $a->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $description = $item->find('.card--text')->text();
                $createDate = $item->find('.card--date')->text();
                if (strpos($createDate, 'сегодня') !== false || strpos($createDate, 'вчера') !== false) {
                    $createDate = str_replace('сегодня', date('d.m.Y'), $createDate);
                    $createDate = str_replace('вчера', date('d.m.Y', strtotime('-1 day')), $createDate);
                } else {
                    $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($createDate));
                }
                $originalParser = self::getParser($original, $curl);
                $image = $originalParser->find('.photo-box--in img')->attr('src');
                $image = empty($image) ? null : sprintf('%s%s', self::DOMAIN, $image);
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($originalParser, $post);
                $n++;
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
        $content = $curl->get(Helper::prepareUrl($link));

        return PhpQuery::newDocument($content);
    }

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $paragraphs = $parser->find('.richtext p');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;','',$text));
                $text = html_entity_decode($text);
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

    private static function setImage(DOMElement $paragraph, NewsPost $post)
    {
        try {
            $item = PhpQuery::pq($paragraph);
        } catch (Exception $e) {
            return;
        }
        $src = $item->find('img')->attr('src');
        if (empty($src)) {
            return;
        }
        if (strpos($src, 'http') === false) {
            $src = sprintf('%s%s', self::DOMAIN, $src);
        }
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $src,
            )
        );
    }

    private static function setLink(DOMElement $paragraph, NewsPost $post)
    {
        try {
            $item = PhpQuery::pq($paragraph);
        } catch (Exception $e) {
            return;
        }
        $href = $item->find('a')->attr('href');
        if (empty($href)) {
            return;
        }
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_LINK,
                null,
                null,
                $href,
            )
        );
    }

    private static function getTimestampFromString(string $time): string
    {
        if (!preg_match('/^(\d+) (\w+)\W+(\d+):(\d+)$/ui', trim($time), $matches)) {
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
        $time = strtotime(sprintf('%d-%d-%d %d:%d:00', $matches[1], $month, date('Y'), $matches[3], $matches[4]));

        return $time ?? time();
    }
}
