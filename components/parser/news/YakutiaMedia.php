<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей из RSS ленты yakutiamedia.ru
 *
 */
class YakutiaMedia implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    /**
     * CSS  класс для параграфов - цитат
     */
    const QUOTE_CSS_CLASS = 'fn-quote';

    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'https://yakutiamedia.ru/rss/feed';

    /**
     *  Максимальная глубина для парсинга <div> тегов
     */
    const DIV_MAX_PARSE_DEPTH = 3;

    /**
     * Префикс для элементов списков (ul, ol и т.п.)
     * при преобразовании в текст
     * @see parseUl()
     */
    const UL_PREFIX = '-';

    /**
     * Кол-во новостей, которое необходимо парсить
     */
    const MAX_NEWS_COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $rss = $curl->get(self::FEED_URL);

        $crawler = new Crawler($rss);
        $crawler->filter('rss channel item')->slice(0, self::MAX_NEWS_COUNT)->each(function ($node) use (&$curl, &$posts) {
            $newPost = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                $node->filter('description')->text(),
                $node->filter('pubDate')->text(),
                $node->filter('link')->text(),
                $node->filter('enclosure')->attr('url')
            );

            /**
             * Получаем полный html новости
             */
            $newsContent = $curl->get($newPost->original);
            if (!empty($newsContent)) {
                $newsContent = (new Crawler($newsContent))->filter('.page-fullnews');
                /**
                 * Основное фото ( всегда одно в начале статьи)
                 */
                $mainImage = $newsContent->filter('.main_foto img');
                if ($mainImage->count()) {
                    $newPost->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_IMAGE,
                            null,
                            $mainImage->attr('src'),
                            null,
                            null,
                            null
                        ));

                    /**
                     * Подпись под основным фото
                     */
                    $annotation = $newsContent->filter('.main_foto')->siblings()->filter('small');
                    if ($annotation->count()) {
                        $newPost->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                $annotation->text(),
                                null,
                                null,
                                null,
                                null
                            ));
                    }
                }

                /**
                 * Текст статьи, может содержать цитаты ( все полезное содержимое в тегах <p> )
                 * Не знаю нужно или нет, но сделал более универсально, с рекурсией
                 */
                $articleContent = $newsContent->filter('.io-article-body');
                if ($articleContent->count()) {
                    $articleContent->each(function ($node) use ($newPost) {
                        self::parseNode($node, $newPost, self::DIV_MAX_PARSE_DEPTH);
                    });
                }
            }

            $posts[] = $newPost;
        });

        return $posts;
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth): void
    {
        $maxDepth--;
        if (!$maxDepth) {
            return;
        }

        switch ($node->nodeName()) {
            case 'div': //запускаем рекурсивно на дочерние ноды, если есть, если нет то там обычно ненужный шлак
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth) {
                        self::parseNode($node, $newPost, $maxDepth);
                    });
                }
                break;
            case 'p':
                self::parseParagraph($node, $newPost);
                break;
            case 'img':
                self::parseImage($node, $newPost);
                break;
            case 'video':
                $videoId = self::extractYouTubeId($node->filter('source')->first()->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'a':
                self::parseLink($node, $newPost);
                break;
            case 'iframe':
                $videoId = self::extractYouTubeId($node->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'ul':
            case 'ol':
                self::parseUl($node, $newPost);
                break;
        }
    }

    /**
     * Парсер для тегов <ul>, <ol> и т.п.
     * Разбирает списки в текст с переносом строки
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseUl(Crawler $node, NewsPost $newPost): void
    {
        $parsedUl = '';
        $node->filter('li')->each(function ($node) use (&$parsedUl) {
            $parsedUl .= self::UL_PREFIX . $node->text() . PHP_EOL;
        });
        $newPost->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_TEXT,
                $parsedUl,
                null,
                null,
                null,
                null
            ));
    }

    /**
     * Добавляет элемент "видео" в статью
     * @param string $videoId
     * @param NewsPost $newPost
     */
    protected static function addVideo(string $videoId, NewsPost $newPost)
    {
        if ($videoId) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_VIDEO,
                    null,
                    null,
                    null,
                    null,
                    $videoId
                ));
        }
    }

    /**
     * Парсер для тегов <a>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseLink(Crawler $node, NewsPost $newPost)
    {
        $newPost->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_LINK,
                null,
                null,
                $node->attr('href'),
                null,
                null
            ));
    }

    /**
     * Парсер для тегов <img>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseImage(Crawler $node, NewsPost $newPost)
    {
        $newPost->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $node->attr('src'),
                null,
                null,
                null
            ));
    }

    /**
     * Парсер для тегов <p>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    private static function parseParagraph(Crawler $node, NewsPost $newPost)
    {
        $type = NewsPostItem::TYPE_TEXT;
        if (stristr($node->attr('class'), self::QUOTE_CSS_CLASS)) {
            $type = NewsPostItem::TYPE_QUOTE;
        }
        $newPost->addItem(
            new NewsPostItem(
                $type,
                $node->text(),
                null,
                null,
                null,
                null
            ));
    }

    /**
     * Возвращает id видео на youtube из url, если он есть
     * @param string $str
     * @return string|null
     */
    protected static function extractYouTubeId(string $str): ?string
    {
        /**
         * @see https://stackoverflow.com/questions/2936467/parse-youtube-video-id-using-preg-match
         */
        $pattern = '/(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})/i';
        if (preg_match($pattern, $str, $match)
        ) {
            return $match[1];
        }
        return null;
    }

}