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

class Ngs70Parser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://newsapi.ngs70.ru/v1/pages/jtnews/main/?regionId=70';

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
                $image = $originalParser->find('figure picture img')->attr('src');
                $createDate = $originalParser->find('time')->attr('datetime');
                $createDate = date('d.m.Y H:i:s', strtotime($createDate));
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
        $t = $block->find('p:not([itemprop="author"])');
        $t->find('span[itemprop="caption"]')->remove();
        $text = trim($t->text());
        if (!empty($text)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    $text,
                )
            );
        }
        $images = $block->find('img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src) || filter_var($src, FILTER_VALIDATE_URL)) {
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
        $links = $block->find('a');
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
