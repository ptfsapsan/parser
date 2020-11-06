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

class NMoskNewsParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://www.nmosknews.ru/';
    private const DOMAIN = 'http://www.nmosknews.ru';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.75 Safari/537.36 Edg/86.0.622.38';


    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $curl->setOption(CURLOPT_USERAGENT, self::USER_AGENT);
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('.main_news_list .block-border #main_news_list_item');
        if (count($items)) {
            foreach ($items as $item) {
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $a = $item->find('.descr a');
                $title = $a->text();
                $original = $a->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $image = $item->find('.list_img a')->attr('style');
                $image = str_replace(['background-image: url(\'', '\');'], '', $image);
                $image = empty($image) ? null : sprintf('%s%s', self::DOMAIN, $image);
                $createDate = $item->find('.date nobr')->text();
                $createDate = str_replace(['Сегодня', 'Вчера'], [date('d.m.Y'), date('d.m.Y', strtotime('-1day'))], $createDate);
                $description = trim($item->find('p')->text());
                $originalParser = self::getParser($original, $curl);
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
        $p = $parser->find('div[itemprop="articleBody"] p');
        $text = $p->text();
        if (!empty($text)) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    trim($text),
                )
            );
        }
        $images = $p->find('img');
        if (count($images)) {
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src)) {
                    $src = sprintf('%s%s', self::DOMAIN, $src);
                    if (filter_var($src, FILTER_VALIDATE_URL)) {
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
        $links = $p->find('a');
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
