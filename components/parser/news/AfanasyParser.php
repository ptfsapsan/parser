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

class AfanasyParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://www.afanasy.biz/';
    private const DOMAIN = 'https://www.afanasy.biz';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.news-line__list .news-line__item .line-card ');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $title = trim($item->find('.line-card__title span')->text());
                $original = $item->find('a.line-card__link')->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $originalParser = self::getParser($original, $curl);
                $createDate = trim($originalParser->find('.single-news__meta.news-meta .news-meta__item')->text());
                $image = $originalParser->find('.text-block img:first')->attr('src');
                if (strpos($image, 'http') === false) {
                    if (strpos($image, '//') !== false) {
                        $image = sprintf('http:%s', $image);
                    } else {
                        $image = sprintf('%s%s', self::DOMAIN, $image);
                    }
                }
                $description = $originalParser->find('.text-block p:first')->text();
                $description = empty($description) ? $title : $description;
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($originalParser, $post);
            }
        }

        return $posts;
    }

    private static function getParser(string $link, Curl $curl): PhpQueryObject
    {
        try {
            $content = $curl->get(Helper::prepareUrl($link));
        } catch (Exception $e) {
            return null;
        }

        return PhpQuery::newDocument($content);
    }

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $paragraphs = $parser->find('.text-block');
        $paragraphs->find('span')->remove();
        if (count($paragraphs)) {
            foreach (current($paragraphs->get())->childNodes as $paragraph) {
                if ($paragraph instanceof DOMElement) {
                    self::setImage($paragraph, $post);
                    self::setLink($paragraph, $post);
                }
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
}
