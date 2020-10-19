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

class VremyanParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'http://www.vremyan.ru/';
    public const NEWSLIST_URL = 'http://www.vremyan.ru/rss/news.rss';

    //    public const TIMEZONE = '+0000';
    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_TEXT =   '.content article';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'labels-wrap' => false,
            'desc' => false,
            'aside' => false,
            'aside-banner' => false,
            'news-rel-link' => true,
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
            self::$post->image = self::getNodeImage('url', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $key => $item) {
                if($removeNext) {
                    unset($newsPost->items[$key]);
                    continue;
                }

                $text = trim($item->text);
                if($item->type == NewsPostItem::TYPE_TEXT) {
                    if(strpos($text, 'Ранее сообщалось') === 0) {
                        unset($newsPost->items[$key]);
                        $removeNext = true;
                    }
                    if(strpos($text, 'Также сообщалось') === 0) {
                        unset($newsPost->items[$key]);
                        $removeNext = true;
                    }
                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        $nodeName = $node->nodeName();

        $qouteClasses = [
            'quote'
        ];

        $classes = array_filter(explode(' ', $node->attr('class')));

        foreach ($classes as $class) {
            if(in_array($class, $qouteClasses)) {
                static::$post->itemQuote = $node->text();
                return;
            }
        }

        parent::parseNode($node);
    }
}
