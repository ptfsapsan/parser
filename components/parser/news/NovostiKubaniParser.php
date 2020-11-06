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

class NovostiKubaniParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://novostikubani.ru';

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.glavn-list1 ul.menu li');
        foreach ($items as $item) {
            try {
                $it = PhpQuery::pq($item);
            } catch (Exception $e) {
                continue;
            }
            $text = $it->find('a')->text();
            $textDif = explode(' - ', $text);
            $title = $textDif[1];
            $dateDif = explode(' - ', $text);
            $createDate = str_replace(',', '', current($dateDif));
            $original = $it->find('a')->attr('href') ?? '';
            $originalParser = self::getParser($original, $curl);
            $description = $originalParser->find('.article_odin1 p:first')->text();
            $description = empty($description) ? $title : $description;
            $image = $originalParser->find('.article_odin1 img:first')->attr('src');
            $image = empty($image) ? null : $image;
            try {
                $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
            } catch (Exception $e) {
                continue;
            }

//                $itemParser = PhpQuery::newDocumentHTML($content, 'utf8');
            $itemHtml = $originalParser->find('.article_odin1');
            try {
                $detail = PhpQuery::pq($itemHtml);
            } catch (Exception $e) {
                break;
            }

            self::getItemText($detail, $post);
            self::getItemImages($detail, $post);
            self::getItemSocialLinks($detail, $post);

            $posts[] = $post;
        }

        return $posts;
    }

    private static function getItemText(PhpQueryObject $detail, NewsPost $post)
    {
        $paragraphs = $detail->find('p:gt(0):not(.wp-caption-text)');
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
    }

    private static function getItemImages(PhpQueryObject $detail, NewsPost $post)
    {
        $images = $detail->find('img:gt(0)');
        if (!empty($images)) {
            foreach ($images as $image) {
                $src = $image->getAttribute('src');
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
    }

    private static function getItemSocialLinks(PhpQueryObject $detail, NewsPost $post)
    {
        $links = $detail->find('.posle_meta2 div a');
        if (!empty($links)) {
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

        return PhpQuery::newDocumentHTML($content, 'utf8');
    }

}
