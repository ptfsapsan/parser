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

class VGorodeKiroveParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://vgorodekirove.ru/news';
    private const DOMAIN = 'https://vgorodekirove.ru';
    private const COUNT = 10;
    private const TIMEZONE = '+0300';
    private static $mainImageSrc = null;
    private static $description = null;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('a.article_item');
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
                $title = $item->find('.bu_text .bu_head')->text();
                $original = $item->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $originalParser = self::getParser($original, $curl);
                $createDate = $item->find('.bu_info time')->text();
                $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($createDate));
                $image = $originalParser->find('.user_content img:first')->attr('src');
                self::$mainImageSrc = $image;
                $image = empty($image) ? null : sprintf('%s%s', self::DOMAIN, $image);
                $description = self::getDescription($originalParser);
                $description = $description ?? $title;
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

    private static function getDescription(PhpQueryObject $parser): ?string
    {
        $paragraphs = $parser->find('.user_content > div');
        $paragraphs->find('[style=color:#999999;]')->remove();
        if (count($paragraphs)) {
            foreach (current($paragraphs->get())->childNodes as $paragraph) {
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;', ' ', $text));
                $text = html_entity_decode($text);
                if (!empty($text)) {
                    self::$description = $text;
                    return $text;
                }
            }
        }

        return null;
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
        $paragraphs = $parser->find('.user_content > div');
        $paragraphs->find('span:first')->remove();
        $paragraphs->find('[style=color:#999999;]')->remove();
        if (count($paragraphs)) {
            foreach (current($paragraphs->get())->childNodes as $paragraph) {
                if ($paragraph instanceof DOMElement) {
                    self::setImage($paragraph, $post);
                    self::setLink($paragraph, $post);
                }
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;', ' ', $text));
                $text = html_entity_decode($text);
                if (!empty($text) && $text != self::$description) {
                    if ($paragraph->nodeName == 'blockquote') {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_QUOTE,
                                $text,
                            )
                        );
                    } else {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                $text,
                            )
                        );
                    }
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
        if (empty($src) || self::$mainImageSrc == $src) {
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
        if (!preg_match('/^(\d+) (\w+), (\d+):(\d+)$/ui', trim($time), $matches)) {
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
        $time = strtotime(sprintf('%d-%d-%d %d:%d:00 %s', $matches[1], $month, date('Y'), $matches[3], $matches[4], self::TIMEZONE));

        return $time ?? time();
    }
}
