<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;

class VoronezhMediaParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://www.voronezh-media.ru/';
    private const DOMAIN = 'http://www.voronezh-media.ru';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        try {
            $content = $curl->get(Helper::prepareUrl(self::LINK));
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('td#content table:first tr');
        $n = 0;
        if (count($items)) {
            foreach ($items as $el) {
                $n++;
                if ($n < 3) {
                    continue;
                }
                try {
                    $item = PhpQuery::pq($el);
                } catch (Exception $e) {
                    continue;
                }
                $image = $item->find('td img')->attr('src');
                if (!empty($image)) {
                    $image = sprintf('%s/%s', self::DOMAIN, trim($image));
                }
                $a = $item->find('td p a');
                $title = $a->text();
                $original = $a->attr('href');
                if (empty($original)) {
                    continue;
                }
                $original = sprintf('%s/%s', self::DOMAIN, $original);

                try {
                    $content = $curl->get($original);
                } catch (Exception $e) {
                    continue;
                }
                $parser = PhpQuery::newDocument($content);
                $basic = $parser->find('td.basic');
                $p = $basic->find('p.sign')->text();
                $createDate = str_replace(',', '', substr($p, -17));
                $itemText = $basic->find('p:not(.sign)')->text();
                $description = $basic->find('p:eq(1)')->text();
                if (empty($description)) {
                    $description = $itemText;
                    $itemText = null;
                }
                $images = [];
                $imgs = $basic->find('p img');
                if (count($imgs)) {
                    foreach ($imgs as $img) {
                        $src = $img->getAttribute('src');
                        if (!empty($src)) {
                            $images[] = sprintf('%s/%s', self::DOMAIN, trim($src));
                        }
                    }
                }

                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }

                if (!empty($itemText)) {
                    $post->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_TEXT,
                            trim($itemText),
                        )
                    );
                }

                if (count($images)) {
                    foreach ($images as $image) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_IMAGE,
                                null,
                                $image,
                            )
                        );
                    }
                }
                $posts[] = $post;
            }
        }
        return $posts;
    }
}
