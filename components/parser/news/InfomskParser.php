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

class InfomskParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://infomsk.ru/news/';
    private const COUNT = 10;
    private static $mainImageSrc = null;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('#main .news-item');
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
                $original = $item->find('.news-item-header a')->attr('href');
                $originalParser = self::getParser($original, $curl);
                $title = $originalParser->find('h1')->text();
                $description = trim($originalParser->find('.fulltext p:first')->text());
                $description = empty($description) ? $title : $description;
                $createDate = $originalParser->find('.news-top-links p')->text();
                $image = $originalParser->find('.fulltext img:first')->attr('src');
                $image = empty($image) ? null : $image;
                self::$mainImageSrc = $image;
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
        $paragraphs = $parser->find('.fulltext p');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;','',$text));
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
        if (empty($src) || self::$mainImageSrc == $src) {
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
}
