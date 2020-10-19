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

class BloknotAstrakhanParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://bloknot-astrakhan.ru/';
    public const NEWSLIST_URL = 'https://bloknot-astrakhan.ru/rss_news.php';

//    public const TIMEZONE = '+0000';
    public const DATEFORMAT = 'D, d M Y H:i:s O';

    public const NEWSLIST_POST = '//rss/channel/item';
    public const NEWSLIST_TITLE = '//title';
    public const NEWSLIST_LINK = '//link';
    public const NEWSLIST_DATE = '//pubDate';
    public const NEWSLIST_DESC = '//description';
    public const NEWSLIST_IMG = '//enclosure';

    public const ARTICLE_HEADER = '#news-detail article h1';
    public const ARTICLE_IMAGE = '#news-detail article .news-picture img.detail_picture';
    public const ARTICLE_TEXT = '#news-detail article .news-text';

    public const ARTICLE_BREAKPOINTS = [
        'class' => [
            'hideme' => false,
            'clear' => false,
            'read-more' => false,
        ],
        'id' => [
            'read-more' => false,
            'pollFrame' => true,
        ],
        'href' => [
            'https://vk.com/bloknotastrakhan' => false,
            'https://ok.ru/group/59303206584359' => false,
            'https://www.facebook.com/bloknotastrakhan' => false,
            'https://www.instagram.com/bloknotastrakhan/' => false,
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

                self::$post->itemHeader = [self::getNodeData('text', $articleCrawler, self::ARTICLE_HEADER), 1];

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $newsPost = self::$post->getNewsPost();

            $removeNext = false;

            foreach ($newsPost->items as $key => $item) {
                if($removeNext) {
                    unset($newsPost->items[$key]);
                    continue;
                }

                $text = ltrim($item->text, static::CHECK_CHARS);

                if($item->type == NewsPostItem::TYPE_TEXT) {
                    if(!$text) {
                        unset($newsPost->items[$key]);
                        continue;
                    }

                    if(strpos($text, '"') === 0 || strpos($text, '«') === 0) {
                        $newsPost->items[$key]->type = NewsPostItem::TYPE_QUOTE;
                    }
                    else if(strpos($text, 'Если Вам есть что рассказать') !== false) {
                        unset($newsPost->items[$key]);
                        $removeNext = true;
                    }

                }
            }

            $posts[] = $newsPost;
        });

        return $posts;
    }
}
