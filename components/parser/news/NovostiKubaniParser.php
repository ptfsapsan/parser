<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class NovostiKubaniParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://novostikubani.ru';

    public static function run(): array
    {
        $posts = [];

        $curl = Helper::getCurl();
        try {
            $content = $curl->get(self::LINK);
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocumentHTML($content, 'utf8');
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
            $description = $title;
            $dateDif = explode(' - ', $text);
            $createDate = str_replace(',', '', current($dateDif));
            $original = $it->find('a')->attr('href') ?? '';

            try {
                $post = new NewsPost(self::class, $title, $description, $createDate, $original, null);
            } catch (Exception $e) {
                continue;
            }

            if (!empty($original)) {
                try {
                    $content = $curl->get($original);
                } catch (Exception $e) {
                    break;
                }

                $itemParser = PhpQuery::newDocumentHTML($content, 'utf8');
                $itemHtml = $itemParser->find('.article_odin1');
                try {
                    $detail = PhpQuery::pq($itemHtml);
                } catch (Exception $e) {
                    break;
                }

                self::getItemText($detail, $post);
                self::getItemImages($detail, $post);
                self::getItemSocialLinks($detail, $post);
            }

            $posts[] = $post;
        }

        return $posts;
    }

    private static function getItemText(PhpQueryObject $detail, NewsPost $post)
    {
        $allText = '';
        $texts = $detail->find('p');
        if (!empty($texts)) {
            foreach ($texts as $text) {
                try {
                    $t = PhpQuery::pq($text);
                } catch (Exception $e) {
                    continue;
                }
                $allText .= ' ' . $t->text();
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
    }

    private static function getItemImages(PhpQueryObject $detail, NewsPost $post)
    {
        $images = $detail->find('img');
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
}
