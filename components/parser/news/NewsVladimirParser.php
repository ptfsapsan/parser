<?php


namespace app\components\parser\news;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DOMElement;
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
        $paragraphs = $parser->find('.post-content p');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;','',$text));
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

        return $post;
    }

    private static function setImage(DOMElement $paragraph, NewsPost $post)
    {
        try {
            $item = PhpQuery::pq($paragraph);
        } catch (Exception $e) {
            return;
        }
        $src = $item->find('img')->attr('src');
        if (empty($src)) {
            return;
        }
        if (strpos($src, 'http') === false) {
            $src = sprintf('%s%s', self::DOMAIN, $src);
        }
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $src,
            )
        );
    }

    private static function setLink(DOMElement $paragraph, NewsPost $post)
    {
        try {
            $item = PhpQuery::pq($paragraph);
        } catch (Exception $e) {
            return;
        }
        $href = $item->find('a')->attr('href');
        if (empty($href)) {
            return;
        }
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
