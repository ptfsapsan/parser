<?php


namespace app\components\parser\news;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
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
                $description = trim($item->next('.eMessage')->text());
                $description = str_replace('...', '', $description);
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
        $p = $parser->find('.eText p');
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
                if (strpos($href, 'mailto:') !== false) {
                    continue;
                }
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
