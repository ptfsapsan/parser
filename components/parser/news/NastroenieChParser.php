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

class NastroenieChParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://nastroenie-ch.ru/?module=news&action=list';
    private const DOMAIN = 'http://nastroenie-ch.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('ul.articles-list li');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $a = $item->find('.preview a');
                $title = $a->attr('title');
                $original = $a->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $originalParser = self::getParser($original, $curl);
                $createDate = $item->find('div.meta')->text();
                $createDate = sprintf('%s %s', date('d.m.Y', strtotime($createDate)), date('H:i:s'));
                $image = $item->find('.preview a img')->attr('src');
                if (empty($image) || strpos($image, 'nopreview.jpg') !== false) {
                    $image = null;
                } else {
                    $image = sprintf('%s%s', self::DOMAIN, $image);
                }
                $description = $originalParser->find('.full-text p:first')->text();
                if (empty($description)) {
                    $description = $title;
                }
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
        $paragraphs = $parser->find('.full-text p:gt(0)');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;','',$text));
                $text = html_entity_decode($text);
                if (filter_var($text, FILTER_VALIDATE_URL)) {
                    continue;
                }
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
