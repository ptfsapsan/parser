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

class BloknotTsimlyanskaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://bloknot-tsimlyanska.ru/';
    private const DOMAIN = 'http://bloknot-tsimlyanska.ru';
    private const TIMEZONE = '+0300';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('ul.bigline li');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $original = $item->find('a.sys')->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $title = $item->find('a.sys')->text();
                $originalParser = self::getParser($original, $curl);
                $image = $originalParser->find('.news-picture img')->attr('src');
                if (!empty($image)) {
                    $image = str_replace('//', 'http://', $image);
                }
                $description = $originalParser->find('#news-text p:first')->text();
                $createDate = $originalParser->find('.news-item-info span.news-date-time')->text();
                $createDate = date('d.m.Y H:i:s', strtotime(sprintf('%s %s', $createDate, self::TIMEZONE)));
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
        $paragraphs = $parser->find('#news-text p:not([style="text-align: right;"]):gt(0)');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                $text = trim($paragraph->textContent);
                $text = str_replace('Если вам есть, чем поделиться, хотите высказать свое мнение, рассказать о проблемных ситуациях или просто поделиться наболевшем, пишите нам на номер WhatsApp: 8-928-117-7705', '', $text);
                $text = htmlentities($text);
                $text = trim(str_replace('&nbsp;', '', $text));
                $text = html_entity_decode($text);
                if (!empty($text) && $text != '.') {
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
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_IMAGE,
                            null,
                            sprintf('%s%s', self::DOMAIN, $src),
                        )
                    );
                }
            }
        }
        $links = $parser->find('dd.text p a');
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
