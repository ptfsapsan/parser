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

class SpbDnevnikParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://spbdnevnik.ru/';
    private const DOMAIN = 'https://spbdnevnik.ru';
    private const COUNT = 10;
    private const TIMEZONE = '+0300';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl);
        $items = $parser->find('li.live__item .live__article');
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
                $a = $item->find('h3 a');
                $title = trim($a->text());
                $original = $a->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $originalParser = self::getParser($original, $curl);
                $createDate = $item->find('.live__article-time')->text();
                $createDate = str_replace('Сегодня в', date('d.m.Y'), $createDate);
                $createDate = str_replace('Вчера в', date('d.m.Y', strtotime('-1day')), $createDate);
                $createDate = date('d.m.Y H:i:s', strtotime(sprintf('%s %s', $createDate, self::TIMEZONE)));
                $image = $originalParser->find('.news-full--element-image img')->attr('src');
                $description = $originalParser->find('.news-full--element-subtitle')->text();
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
        $paragraphs = $parser->find('.news-full--element-text-wrapper p');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                $text = trim($paragraph->textContent);
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
        $images = $paragraphs->find('img');
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
        $links = $paragraphs->find('a');
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
