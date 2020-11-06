<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;

class MalorosParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'http://maloros.org/';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.75 Safari/537.36 Edg/86.0.622.38';

    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        try {
            $curl->setOption(CURLOPT_USERAGENT, self::USER_AGENT);
            $content = $curl->get(Helper::prepareUrl(self::LINK));
        } catch (Exception $e) {
            return [];
        }
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('.post.medium-post');
        $n = -1;
        if (count($items)) {
            foreach ($items as $item) {
                $n++;
                if ($n % 9 > 2) {
                    continue;
                }
                try {
                    $item = PhpQuery::pq($item);
                } catch (Exception $e) {
                    continue;
                }
                $original = $item->find('.entry-header .entry-thumbnail a')->attr('href');
                $image = $item->find('.entry-header .entry-thumbnail a img')->attr('src');
                if (empty($image) || !filter_var($image, FILTER_VALIDATE_URL)) {
                    $image = null;
                }
                $title = $item->find('.post-content .entry-title a')->text();
                $createDate = $item->find('.entry-meta .publish-date')->text();
                $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($createDate));
                $description = $title;
                if (!empty($original)) {
                    try {
                        $content = $curl->get(Helper::prepareUrl($original));
                    } catch (Exception $e) {
                        return [];
                    }
                    $parser = PhpQuery::newDocument($content);
                    $desc = $parser->find('.entry-content p.bgtext_f')->text();
                    if (!empty($desc)) {
                        $description = $desc;
                    }
                }
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }

                if (!empty($original)) {
                    $text = $parser->find('.entry-content p')->text();
                    if (!empty($text)) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                trim($text),
                            )
                        );
                    }
                    $images = $parser->find('.entry-content p img');
                    if (count($images)) {
                        foreach ($images as $img) {
                            $src = $img->getAttribute('src');
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
                    $links = $parser->find('.entry-content p a:not(.highslide)');
                    if (count($links)) {
                        foreach ($links as $link) {
                            $href = $link->getAttribute('href');
                            if (!empty($href) && filter_var($href, FILTER_VALIDATE_URL)) {
                                if (strpos($href, 'youtu.be/') !== false) {
                                    $pos = strpos($href, 'youtu.be/') + 9;
                                    $youtubeId = substr($href, $pos, 11);
                                    if (strlen($youtubeId) == 11) {
                                        $post->addItem(
                                            new NewsPostItem(
                                                NewsPostItem::TYPE_VIDEO,
                                                null,
                                                null,
                                                null,
                                                null,
                                                $youtubeId,
                                            )
                                        );
                                        continue;
                                    }
                                }
                                if (strpos($href, 'youtube.com') !== false) {
                                    $pos = strpos($href, 'v=') + 2;
                                    $youtubeId = substr($href, $pos, 11);
                                    if (strlen($youtubeId) == 11) {
                                        $post->addItem(
                                            new NewsPostItem(
                                                NewsPostItem::TYPE_VIDEO,
                                                null,
                                                null,
                                                null,
                                                null,
                                                $youtubeId,
                                            )
                                        );
                                        continue;
                                    }
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
                    }
                }
                $posts[] = $post;
            }
        }

        return $posts;
    }

    private static function getTimestampFromString(string $time): string
    {
        if (!preg_match('/^(\d+) (\w+) (\d+), (\d+):(\d+)$/ui', trim($time), $matches)) {
            return time();
        }
        switch ($matches[2]) {
            case 'янв':
            case 'январь':
            case 'января':
                $month = '01';
                break;
            case 'фев':
            case 'февраль':
            case 'февраля':
                $month = '02';
                break;
            case 'мар':
            case 'март':
            case 'марта':
                $month = '03';
                break;
            case 'апр':
            case 'апрель':
            case 'апреля':
                $month = '04';
                break;
            case 'май':
            case 'мая':
                $month = '05';
                break;
            case 'июн':
            case 'июнь':
            case 'июня':
                $month = '06';
                break;
            case 'июл':
            case 'июль':
            case 'июля':
                $month = '07';
                break;
            case 'авг':
            case 'август':
            case 'августа':
                $month = '08';
                break;
            case 'сен':
            case 'сентябрь':
            case 'сентября':
                $month = '09';
                break;
            case 'окт':
            case 'октябрь':
            case 'октября':
                $month = '10';
                break;
            case 'ноя':
            case 'ноябрь':
            case 'ноября':
                $month = '11';
                break;
            case 'дек':
            case 'декабрь':
            case 'декабря':
                $month = '12';
                break;
            default:
                $month = '01';
        }
        $time = strtotime(sprintf('%d-%d-%d %d:%d:00', $matches[3], $month, $matches[1], $matches[4], $matches[5]));

        return $time ?? time();
    }
}
