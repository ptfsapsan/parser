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

class BloknotKrymskParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://bloknotkrymsk.ru/';
    private const DOMAIN = 'http://bloknotkrymsk.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.bigline li');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $a = $item->find('a.sys');
                $title = $a->text();
                $original = $a->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $image = $item->find('img.preview_picture')->attr('src');
                $image = empty($image) ? null : sprintf('http:%s', $image);
                $originalParser = self::getParser($original, $curl);
                $createDate = $originalParser->find('.news-item-info span.news-date-time')->text();
                $createDate = sprintf('%s%s', trim($createDate), ':00');
                $description = $originalParser->find('#news-text p:first')->text();
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
        $paragraphs = $parser->find('#news-text > p:not([style^=text]):gt(0)');
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
        $images = $parser->find('#news-text p img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src)) {
                    $src = sprintf('http:%s', $src);
                    if (filter_var($src, FILTER_VALIDATE_URL)) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_IMAGE,
                                null,
                                $src,
                            )
                        );
                    }
                }
            }
        }
        $links = $parser->find('#news-text p a');
        if (count($links)) {
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                if (!empty($href) && filter_var($href, FILTER_VALIDATE_URL)) {
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
        }

        return $post;
    }

}
