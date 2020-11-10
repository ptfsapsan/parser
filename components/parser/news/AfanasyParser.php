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
        $paragraphs = $parser->find('.text-block p');
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
        $images = $parser->find('.text-block img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = trim($img->getAttribute('src'));
                if (!empty($src)) {
                    if (strpos($src, 'http:') === false && strpos($src, '//') !== false) {
                        $src = sprintf('http:%s', $src);
                    }
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
        $links = $parser->find('.text-block a');
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
