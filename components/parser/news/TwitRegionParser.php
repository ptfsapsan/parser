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

class TwitRegionParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://twitregion.ru/feed/';

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
        foreach ($items as $item) {
            $title = $original = $createDate = $description = '';
            foreach ($item->childNodes as $childNode) {
                switch ($childNode->nodeName) {
                    case 'title':
                        $title = $childNode->textContent;
                        break;
                    case 'pubdate':
                        $createDate = date('d.m.Y H:i:s', strtotime($childNode->textContent));
                        break;
                    case 'link':
                        $original = $childNode->nextSibling->textContent;
                        break;
                }
            }
            $originalParser = self::getParser($original, $curl);
            $description = $originalParser->find('article.post .post-content p:first')->text();
            $image = $originalParser->find('.post-gallery img')->attr('src');
            $image = empty($image) ? null : $image;
            try {
                $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
            } catch (Exception $e) {
                continue;
            }

            $paragraphs =  $originalParser->find('article.post .post-content p:gt(0)');
            if (count($paragraphs)) {
                foreach ($paragraphs as $paragraph) {
                    self::setImage($paragraph, $post);
                    self::setLink($paragraph, $post);
                    $text = htmlentities($paragraph->textContent);
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

            $posts[] = $post;
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
        $link = trim($link);
        $content = $curl->get(Helper::prepareUrl($link));
        $content = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $content);

        return PhpQuery::newDocument($content);
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
