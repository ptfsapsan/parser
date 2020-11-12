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

class Ngs70Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://newsapi.ngs70.ru/v1/pages/jtnews/main/?regionId=70';
    private const TIMEZONE = '+0300';
    private static $mainImageSrc = null;

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $data = self::getData(self::LINK, $curl);
        $items = $data['result']['data']['news.block1']['data'];
        if (count($items)) {
            foreach ($items as $item) {
                $title = $item['header'];
                $description = $item['subheader'];
                $original = $item['urls']['urlCanonical'];
                $originalParser = self::getParser($original, $curl);
                $image = $originalParser->find('figure picture img:first')->attr('src');
                $image = empty($image) ? null : $image;
                self::$mainImageSrc = $image;
                $createDate = $originalParser->find('time')->attr('datetime');
                $createDate = date('d.m.Y H:i:s', strtotime(sprintf('%s %s', $createDate, self::TIMEZONE)));
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

    private static function getData(string $link, Curl $curl): array
    {
        try {
            $content = $curl->get(Helper::prepareUrl($link));
        } catch (Exception $e) {
            return null;
        }

        return json_decode($content, true);
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
        $block = $parser->find('.central-column-container div[itemprop="articleBody"]');
        $block->find('p[itemprop="author"]')->remove();
        $block->find('span[itemprop="caption"]')->remove();
        $t = $block->find('div');
        $paragraphs = $t->find('p');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;', '', $text));
                $text = html_entity_decode($text);
                $text = str_replace('Поделиться', '', $text);
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
