<?php


namespace app\components\parser\news;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DOMElement;
use Exception;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class PlanetaNovostiParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://www.planetanovosti.com/news/';
    private const DOMAIN = 'https://www.planetanovosti.com';
    private const COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $content = file_get_contents(self::LINK);
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('.eTitle');
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
                $title = trim($item->find('a b')->text());
                $original = $item->find('a')->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $d = $item->prev('.eDetails')->find('.e-date .ed-value');
                $time = $d->attr('title');
                $date = trim($d->text());
                $date = str_replace('&nbsp;', '', htmlentities($date));
                $date = str_replace('Вчера', date('d.m.Y', strtotime('-1 day')), $date);
                $date = str_replace('Сегодня', date('d.m.Y'), $date);
                $createDate = sprintf('%s %s', $date, $time);
                $image = $item->prev()->prev()->find('img')->attr('src');
                $image = empty($image) ? null : sprintf('%s%s', self::DOMAIN, $image);
                $content = file_get_contents($original);
                $originalParser = PhpQuery::newDocument($content);
                $description = trim($originalParser->find('.eText p:first')->text());
                $description = str_replace('...', '', $description);
                $description = empty($description) ? $title : $description;
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

    private static function setOriginalData(PhpQueryObject $parser, NewsPost $post): NewsPost
    {
        $paragraphs = $parser->find('.eText p:gt(0)');
        if (count($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                self::setImage($paragraph, $post);
                self::setLink($paragraph, $post);
                $text = htmlentities($paragraph->textContent);
                $text = trim(str_replace('&nbsp;', ' ', $text));
                $text = html_entity_decode($text);
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
