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

class ParkInfoTvParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://parkinfo-tv.ru/';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('#imContent div:first div:first a.imCssLink');
        if (count($items)) {
            foreach ($items as $item) {
                $original = $item->getAttribute('href');
                $title = trim($item->textContent);
                $originalParser = self::getParser($original, $curl);
                $image = $originalParser->find('img[id^=imObjectImage_]:first')->attr('src');
                $t = $originalParser->find('.text-inner:first p:eq(1) span:gt(1)');
                $createDate = '';
                foreach ($t as $el) {
                    $createDate .= trim($el->textContent);
                }
                $createDate = sprintf('%s %s:00', $createDate, date('H:i'));
                $description = trim($originalParser->find('.text-inner:first p:gt(1) b')->text());
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
        $paragraphs = $parser->find('#imContent .ff1:not(.fs22):not(.cf3)');
        $paragraphs->find('a')->remove();
        $paragraphs->find('i')->remove();
        foreach ($paragraphs as $paragraph) {
            if ($paragraph->childNodes->count() > 0) {
                foreach ($paragraph->childNodes as $childNode) {
                    $text = htmlentities($childNode->textContent);
                    $text = trim(str_replace('&nbsp;', '', $text));
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
        }

        return $post;
    }
}
