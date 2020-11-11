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

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
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
                $originalParser = self::getParser($original, $curl);
                $description = $originalParser->find('.news-section .frame p:first')->text();
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

    /**
     * @param PhpQueryObject $parser
     * @param NewsPost $post
     * @return NewsPost
     */
    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        // text
        $p = $parser->find('.news-section .frame p:gt(0)');
        if (count($p)) {
            foreach ($p as $item) {
                $text = htmlentities($item->textContent);
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
}
