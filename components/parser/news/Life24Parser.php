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

class Life24Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://life24.pro/rss/';
    private const DOMAIN = 'https://life24.pro';
    private const COUNT = 10;
    private static $description = null;
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
        $items = $parser->find('item');
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
                $title = trim($item->find('title')->text());
                $original = $item->find('link')->text();
                $createDate = date('d.m.Y H:i:s', strtotime($item->find('pubDate')->text()));
                $originalParser = self::getParser($original, $curl);
                $description = self::getDescription($originalParser) ?? $title;
                $image = $originalParser->find('img.n24_image')->attr('data-src');
                self::$mainImageSrc = $image;
                $image = empty($image)
                    ? null
                    : (strpos($image, 'http') === false ? sprintf('%s%s', self::DOMAIN, $image) : $image);
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
        $paragraphs = $parser->find('.n24_text');
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
        $paragraphs = $parser->find('.n24_text');
        if (count($paragraphs)) {
            foreach (current($paragraphs->get())->childNodes as $paragraph) {
                if ($paragraph instanceof DOMElement) {
                    self::setImage($paragraph, $post);
                    self::setLink($paragraph, $post);
                }
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;', ' ', $text));
                $text = html_entity_decode($text);
                if (!empty($text)) {
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
            if (strpos($src, '//') === 0) {
                $src = sprintf('https:%s', $src);
            } else {
                $src = sprintf('%s%s', self::DOMAIN, $src);
            }
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
