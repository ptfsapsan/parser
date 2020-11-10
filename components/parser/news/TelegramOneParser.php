<?php


namespace app\components\parser\news;


use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\ParserInterface;
use Exception;
use linslin\yii2\curl\Curl;
use PhpQuery\PhpQuery;
use PhpQuery\PhpQueryObject;

class TelegramOneParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://ru.telegram.one/upd2020.php?p=1&r=ru_news';
    private const LINK2 = 'https://ru.telegram.one/upd2020.php?p=2&r=ru_news';
    private const LINK3 = 'https://ru.telegram.one/upd2020.php?p=2&r=ru_news';
    private const DOMAIN = 'https://ru.telegram.one';
    private const COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $parser = self::getParser(self::LINK, $curl, self::LINK2, self::LINK3);
        $items = $parser->find('.tgme_widget_message');
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
                $title = trim($item->find('.tgme_widget_message_text.js-message_text b:first')->text());
                if (empty($title)) {
                    $title = trim($item->find('.tgme_widget_message_text.js-message_text a:first')->text());
                }
                if (empty($title)) {
                    continue;
                }
                $original = $item->find('.icon_link')->parent()->attr('href');
                $original = sprintf('%s%s', self::DOMAIN, $original);
                $originalParser = self::getParser($original, $curl);
                $original = $originalParser->find('#copyTarget')->attr('value');
                $createDate = $item->find('.post_date')->text();
                $createDate = date('d.m.Y H:i:s', self::getTimestampFromString($createDate));
                $image = $item->find('.tgme_widget_message_photo_wrap:first')->attr('href');
                $image = empty($image) ? null : $image;
                $description = $item->find('.tgme_widget_message_text.js-message_text')->text();
                try {
                    $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }
                $posts[] = $post;
                $n++;
            }
        }

        return $posts;
    }

    /**
     * @param string $link
     * @param Curl $curl
     * @param string|null $link2
     * @param string|null $link3
     * @return PhpQueryObject
     * @throws Exception
     */
    private static function getParser(string $link, Curl $curl, ?string $link2 = null, ?string $link3 = null): PhpQueryObject
    {
        $content = $curl->get(Helper::prepareUrl($link));
        if ($link2) {
            $content .= $curl->get(Helper::prepareUrl($link2));
        }
        if ($link3) {
            $content .= $curl->get(Helper::prepareUrl($link3));
        }

        return PhpQuery::newDocument($content);
    }

    private static function getTimestampFromString(string $time): string
    {
        if (preg_match('/^(\d+) минут(\w+) назад$/ui', trim($time), $matches)) {
            return strtotime(sprintf('-%d minute', $matches[1]));
        } elseif ($time == 'менее минуты назад') {
            return strtotime('-1 minute');
        } elseif ($time == 'менее часа назад') {
            return strtotime('-1 hour');
        } elseif (preg_match('/^(\d+) час(\w+) назад$/ui', trim($time), $matches)) {
            return strtotime(sprintf('-%d hour', $matches[1]));
        }

        return time();
    }
}
