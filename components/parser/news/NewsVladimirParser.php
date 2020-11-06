<?php


namespace app\components\parser\news;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class NewsVladimirParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://newsvladimir.ru/feed/all';
    private const DOMAIN = 'https://newsvladimir.ru';
    private const COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $parser = self::getParser(self::LINK);
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
                $description = trim(strip_tags($item->find('description')->text()));
                $originalParser = self::getParser($original);
                $image = $originalParser->find('.img-responsive.post-image')->attr('src');
                if (empty($image)) {
                    $image = null;
                }
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

    /**
     * @param string $link
     * @param string $cookie
     * @return PhpQueryObject
     */
    private static function getParser(string $link): PhpQueryObject
    {
        $options = [
            'http' => [
                'method' => "GET",
                'header' => "Accept-language: ru\r\n" .
                    "Host: newsvladimir.ru\r\n" .
                    sprintf('Origin:%s%s', self::DOMAIN, "\r\n") .
                    sprintf('Referer:%s%s', self::DOMAIN, "\r\n")
            ],
        ];

        $context = stream_context_create($options);
        $content = file_get_contents($link, false, $context);

        return PhpQuery::newDocument($content);
    }

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $p = $parser->find('.post-content p');
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
