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

class BankTechParser implements ParserInterface
{

    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://banktech.ru/news/';
    private const TIMEZONE = '+0300';

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.container:eq(3) .row .col-md-9 .row');
        foreach ($items as $item) {
            try {
                $it = PhpQuery::pq($item);
            } catch (Exception $e) {
                continue;
            }
            $date = $it->find('span.date')->text();
            $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($date));
            $a = $it->find('a.title');
            $title = $a->text();
            $original = $a->attr('href');
            $originalParser = self::getParser($original, $curl);
            $description = trim($originalParser->find('.container:eq(3) > div > div p:first')->text());
            $detail = $originalParser->find('.container:eq(3) > div > div');
            $image = $detail->find('img:first')->attr('src');
            $image = empty($image) ? null : $image;
            try {
                $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
            } catch (Exception $e) {
                continue;
            }
            self::getItemText($detail, $post);

            $posts[] = $post;
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


    private static function getItemText(PhpQueryObject $detail, NewsPost $post)
    {
        $paragraphs = $detail->find('p:gt(0)');
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
                            trim($text),
                        )
                    );

                }
            }
        }
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

    private static function getTimestampFromString(string $time): int
    {
        if (!preg_match('/^(\d+) (\w+) (\d+) (\d+):(\d+)$/ui', $time, $matches)) {
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
        $time = strtotime(sprintf('%d-%d-%d %d:%d %s', $matches[3], $month, $matches[1], $matches[4], $matches[5], self::TIMEZONE));

        return $time ?? time();
    }

}
