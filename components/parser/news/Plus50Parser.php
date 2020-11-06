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

class Plus50Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const DOMAIN = 'https://www.50plus.ru';
    private const LINK = 'https://www.50plus.ru/news/';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        try {
            $content = $curl->get(Helper::prepareUrl(self::LINK));
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('section.content div.news-box');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $original = $item->find('a.news-img')->attr('href');
                if (!empty($original)) {
                    $original = sprintf('%s%s', self::DOMAIN, $original);
                }
                $image = self::getImage($item);
                $createDate = $item->find('.news-inner .news-date:last')->text();
                $createDate = sprintf('%s %s', trim($createDate), date('H:i:s'));
                $title = $item->find('a.news-title')->text();
                $description = $item->find('div.news-text')->text();
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($original, $curl, $post);
            }
        }


        return $posts;
    }

    private static function getImage(PhpQueryObject $item)
    {
        $style = $item->find('a.news-img img')->attr('style');
        $elements = explode(';', $style);
        $src = '';
        foreach ($elements as $element) {
            if (strpos($element, 'background-image') !== false) {
                $src = str_replace('background-image: url(\'', '', $element);
                $src = str_replace('\')', '', $src);
                $src = trim($src);
                $src = sprintf('%s%s', self::DOMAIN, $src);
            }
        }

        return $src;
    }

    private static function setOriginalData(string $original, Curl $curl, NewsPost $post): NewsPost
    {
        if (empty($original)) {
            return $post;
        }
        try {
            $content = $curl->get(Helper::prepareUrl($original));
        } catch (Exception $e) {
            return $post;
        }
        $parser = PhpQuery::newDocument($content);

        // text
        $allText = '';
        $p = $parser->find('.news-section .frame p');
        if (count($p)) {
            foreach ($p as $item) {
                $allText .= ' ' . $item->textContent;
            }
        }
        $span = $parser->find('.news-section .frame span');
        if (count($span)) {
            foreach ($span as $item) {
                $allText .= ' ' . $item->textContent;
            }
        }
        if (!empty($allText)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    trim($allText),
                )
            );
        }

        // images
        $img = $parser->find('.news-section .frame p img');
        if (count($img)) {
            foreach ($img as $item) {
                $src = $item->getAttribute('src');
                $src = sprintf('%s%s', self::DOMAIN, trim($src));
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

        // links
        $a = $parser->find('.news-section .frame p a');
        if (count($a)) {
            foreach ($a as $item) {
                $href = trim($item->getAttribute('href'));
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
