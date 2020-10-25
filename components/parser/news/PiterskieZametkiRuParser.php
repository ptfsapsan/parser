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


/**
 * @fullhtml
 */
class PiterskieZametkiRuParser extends MediasferaNewsParser implements ParserInterface
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public const NEWS_LIMIT = 100;

    public const SITE_URL = 'https://piterskie-zametki.ru/';
    public const NEWSLIST_URL = 'https://piterskie-zametki.ru/';

    public const IS_CURRENT_TIME = true;
    public const DATEFORMAT = 'd m, Y';

    public const NEWSLIST_POST =  'section.content article.post';
    public const NEWSLIST_TITLE = '.post-title';
    public const NEWSLIST_LINK =  '.post-title a';
    public const NEWSLIST_IMAGE = '.leftimg img';
    public const NEWSLIST_DATE =  '.fa.fa-clock-o';

    public const ARTICLE_IMAGE = 'article.post .entry img';
    public const ARTICLE_TEXT = 'article.post .articleq';

    public const ARTICLE_BREAKPOINTS = [];

    protected static NewsPostWrapper $post;

    public static function run(): array
    {
        $posts = [];

        $listContent = self::getPage(self::NEWSLIST_URL);

        $listCrawler = new Crawler($listContent);

        $listCrawler->filter(self::NEWSLIST_POST)->slice(0, self::NEWS_LIMIT)->each(function (Crawler $node) use (&$posts) {

            /**
             * Пропускаем вставки
             */
            if(!$node->filter(self::NEWSLIST_LINK)->count()) {
                return;
            }

            self::$post = new NewsPostWrapper();

            self::$post->title = self::getNodeData('text', $node, self::NEWSLIST_TITLE);
            self::$post->original = self::getNodeLink('href', $node, self::NEWSLIST_LINK);
            self::$post->image = self::getNodeImage('src', $node, self::NEWSLIST_IMAGE);

            $date = $node->filter(self::NEWSLIST_DATE)->closest('li')->text();
            self::$post->createDate = self::fixDate($date);


            $articleContent = self::getPage(self::$post->original);

            if (!empty($articleContent)) {

                $articleCrawler = new Crawler($articleContent);

                self::parse($articleCrawler->filter(self::ARTICLE_TEXT));

                $posts[] = self::$post->getNewsPost();
            }


        });

        return $posts;
    }


    public static function fixDate(string $date) : ?string
    {
        $replace = [
            'Янв' => '01',
            'Фев' => '02',
            'Мар' => '03',
            'Апр' => '04',
            'Мая' => '05',
            'Июн' => '06',
            'Июл' => '07',
            'Авг' => '08',
            'Сен' => '09',
            'Окт' => '10',
            'Ноя' => '11',
            'Дек' => '12',
        ];

        $date = trim(str_ireplace(array_keys($replace), $replace, $date));

        return parent::fixDate($date);
    }


    protected static function parseNode(Crawler $node, ?string $filter = null) : void
    {
        $node = static::filterNode($node, $filter);

        $nodeName = $node->nodeName();

        switch ($nodeName)
        {
            case 'img' :
                static::$post->itemImage = [
                    $node->attr('alt') ?? $node->attr('title') ?? null,
                    static::getNodeImage('data-lazy-src', $node)
                ];
                break;

            case 'picture' :
                if($node->filter('img')->count()) {
                    static::$post->itemImage = [
                        $node->filter('img')->attr('alt') ?? $node->filter('img')->attr('title') ?? null,
                        static::getNodeImage('data-lazy-src', $node->filter('img'))
                    ];
                }
                break;

            case 'figure' :
                if($node->filter('img')->count() == 1) {
                    static::$post->itemImage = [
                        $node->text() ?? $node->filter('img')->attr('alt') ?? $node->filter('img')->attr('title') ?? null,
                        static::getNodeImage('data-lazy-src', $node->filter('img'))
                    ];
                }
                else {
                    static::parseSection($node);
                }
                break;

            case 'a' :
                if($node->filter('img')->count() == 1) {
                    static::$post->itemImage = [
                        $node->attr('alt') ?? $node->attr('title') ?? $node->text() ?? null,
                        static::getNodeImage('data-lazy-src', $node->filter('img')),
                    ];
                }
                else {
                    static::$post->itemLink = [
                        $node->text() ?? $node->attr('href'),
                        static::getNodeLink('href', $node)
                    ];
                }
                break;
            default :
                parent::parseNode($node, $filter);
        }
    }
}
