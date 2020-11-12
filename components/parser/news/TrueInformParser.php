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

class TrueInformParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://trueinform.ru/Laid.html';
    private const DOMAIN = 'http://trueinform.ru';
    private const COUNT = 10;
    private static $mainImageSrc = null;
    private static $tempLink = null;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('span.date');
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
                $createDate = $item->attr('title');
                $a = $item->parent()->parent()->parent()->parent()->parent()->parent()->next()->find('a');
                $original = $a->attr('href');
                $original = sprintf('%s/%s', self::DOMAIN, $original);
                $title = $a->attr('title');
                if (empty($title)) {
                    continue;
                }
                $originalParser = self::getParser($original, $curl);
                $image = $originalParser->find('h2')->parent()->find('p:not(.author) img:first')->attr('src');
                $image = empty($image) ? null : $image;
                self::$mainImageSrc = $image;
                $description = trim($a->next('p.simple')->text());
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
        $paragraphs = $parser->find('p.author')->siblings('p:not(.author)');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                self::setYoutube($paragraph, $post);
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
        self::$tempLink = $src;
    }

    private static function setLink(DOMElement $paragraph, NewsPost $post)
    {
        try {
            $item = PhpQuery::pq($paragraph);
        } catch (Exception $e) {
            return;
        }
        $href = $item->find('a')->attr('href');
        if (empty($href) || self::$tempLink == $href) {
            return;
        }
        if (strpos($href, 'http') === false) {
            $href = sprintf('%s%s', self::DOMAIN, $href);
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

    private static function setYoutube(DOMElement $paragraph, NewsPost $post)
    {
        try {
            $item = PhpQuery::pq($paragraph);
        } catch (Exception $e) {
            return;
        }
        $src = $item->find('iframe')->attr('src');
        $pos = strpos($src, 'youtube.com/embed/');
        if (empty($src) || $pos === false) {
            return;
        }
        $code = substr($src, ($pos + 18), 11);
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_VIDEO,
                null,
                null,
                null,
                null,
                $code,
            )
        );
    }
}
