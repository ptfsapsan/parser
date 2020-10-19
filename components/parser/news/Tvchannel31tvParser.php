<?php
/**
 *
 * @author MediaSfera <info@media-sfera.com>
 * @author FingliGroup <info@fingli.ru>
 * @author Vitaliy Moskalyuk <flanker@bk.ru>
 *
 * @note Данный код предоставлен в рамках оказания услуг, для выполнения поставленных задач по сбору и обработке данных. Переработка, адаптация и модификация ПО без разрешения правообладателя является нарушением исключительных прав.
 *
 */

namespace app\components\parser\news;

use app\components\mediasfera\MediasferaNewsParser;
use app\components\mediasfera\NewsPostWrapper;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

class Tvchannel31tvParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://31tv.ru/';
    public const NEWSLIST_URL = 'https://31tv.ru/feed/';

//    public const TIMEZONE = '+0500';
    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_CONTENT = '//content:encoded';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'hideme' => false,
            'clear' => false,
            'read-more' => false,
        ],
        'id' => [
            'read-more' => false,
        ],
        'href' => [
            'https://goo.gl/oIqt5t' => false,
            'https://goo.gl/CN7xkq' => false
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filterXPath(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeData('text', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);

            $html = html_entity_decode(static::filterNode($node, self::NEWSLIST_CONTENT)->html());

            $articleCrawler = new Crawler('<body><div>'.$html.'</div></body>');

            static::parse($articleCrawler);

            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $key => $item) {
                if($removeNext) {
                    unset($newsPost->items[$key]);
                    continue;
                }

                $text = trim($item->text);

                if(strpos($text, 'Следите за главными новостями региона на нашей странице в') !== false) {
                    unset($newsPost->items[$key]);
                    $removeNext = true;
                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
