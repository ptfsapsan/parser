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

class VestiPkParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://vestipk.ru/';
    private const DOMAIN = 'http://vestipk.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('td.news_frame');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                if (empty($item->find('.news_subj')->text())) {
                    continue;
                }
                $original = $item->find('a.news_subj')->attr('href');
                $original = sprintf('%s/%s', self::DOMAIN, $original);
                $title = $item->find('a.news_subj')->text();
                $createDate = $item->find('.news_bar:first')->text();
                $createDate = date('d.m.Y H:i:s', strtotime($createDate));
                $image = null;
                $description = $item->find('.news_bar:last')->text();
                if (empty($description)) {
                    $description = $title;
                }
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $originalParser = self::getParser($original, $curl);
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
        $paragraphs = $parser->find('.news_frame .news_bar:last');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                foreach ($paragraph->childNodes as $childNode) {
                    $text = trim($childNode->textContent);
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
        }
        $images = $parser->find('.news_frame .news_bar:last img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
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

        return $post;
    }
}
