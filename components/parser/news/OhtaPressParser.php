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

class OhtaPressParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://ohtapress.ru/feed/';
    private const COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('item');
        if (count($items)) {
            $n = 0;
            foreach ($items as $item) {
                if ($n >= self::COUNT) {
                    break;
                }
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $title = trim($item->find('title')->text());
                $original = $item->find('link')->text();
                $createDate = date('d.m.Y H:i:s', strtotime($item->find('pubDate')->text()));
                $originalParser = self::getParser($original, $curl);
                $description = $originalParser->find('.entry-content p:first strong')->text();
                if (empty($description)) {
                    $descriptionHtml = $item->find('description')->text();
                    $descriptionParser = PhpQuery::newDocument($descriptionHtml);
                    $description = $descriptionParser->find('p:first')->text();
                    $description = str_replace('...', '', $description);
                }
                $image = $originalParser->find('.entry-thumbnail img')->attr('src');
                $image = empty($image) ? null : $image;
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = self::setOriginalData($originalParser, $post);
                $n++;
            }
        }

        return $posts;
    }


    private static function setOriginalData(PhpQueryObject $original, NewsPost $post): NewsPost
    {
        $paragraphs = $original->find('.entry-content');
        $paragraphs->find('p:first strong')->remove();
        $paragraphs = $paragraphs->find('p');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                $text = $paragraph->textContent;
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

        $images = $paragraphs->find('img');
        if (count($images)) {
            foreach ($images as $image) {
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_IMAGE,
                        null,
                        $image->getAttribute('src'),
                    )
                );
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
        $content = $curl->get(Helper::prepareUrl($link));

        return PhpQuery::newDocument($content);
    }

}
