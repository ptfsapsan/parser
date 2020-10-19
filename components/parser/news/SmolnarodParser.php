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
use app\components\parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

class SmolnarodParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://smolnarod.ru/';
    public const NEWSLIST_URL = 'https://smolnarod.ru/all-news/';

    public const TIMEZONE = '+0300';
    public const DATEFORMAT = 'd.m.Y H:i';

    public const NEWSLIST_POST = '#section_weekly_news_wrapper .section_russia_news_item';
    public const NEWSLIST_TITLE = '.section_russia_news_item_title_link a';
    public const NEWSLIST_LINK = '.section_russia_news_item_title_link a';
    public const NEWSLIST_DATE = '.news___chrono__item__category_date_time span:last-child';
    public const NEWSLIST_DESC = '.section_russia_news_item_excerpt';
    public const NEWSLIST_IMG = '.img-fluid';

    public const ARTICLE_HEADER = '#single_article_wrapper_inner h1';
    public const ARTICLE_TEXT = '.single_article_content_wrapper .single_article_content_inner';

    public const ARTICLE_BREAKPOINTS = [
        'text' => [
            'Читать также:' => true,
        ],
        'id' => [
            'add_your_news_alert' => false,
        ],
        'class' => [
            'widget_text' => false,
            'd-none' => false,
            'd-sm-block' => false,
            'd-md-none' => false,
            'd-block' => false,
            'd-sm-none' => false,
            'clear' => false,
            'add_your_news_alert' => false,
            'navhold' => false,
        ]
    ];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->createDate = self::getNodeDate('text', $node, self::NEWSLIST_DATE);
            self::$post->description = self::getNodeData('text', $node, self::NEWSLIST_DESC);
            self::$post->image = self::getNodeImage('src', $node, self::NEWSLIST_IMG);

            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::$post->itemHeader = [self::getNodeData('text', $articleCrawler, self::ARTICLE_HEADER), 1];

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));
            }

            $posts[] = self::$post->getNewsPost();
        });

        return $posts;
    }


    protected static function resolveUrl($url) : string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        if(isset($params['src'], $params['w'], $params['h'])) {
            $url = $params['src'];
        }

        return parent::resolveUrl($url);
    }
}
