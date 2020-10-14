<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;


class ExemplaireParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    const ROOT_SRC = "https://exemplaire.ru";

    const FEED_SRC = "/news";
    const LIMIT = 100;

    const NEWS_PER_PAGE = 12;
    const EMPTY_DESCRIPTION = "empty";

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $curl = Helper::getCurl();
        $posts = [];


        $counter = 0;
        for ($pageId = 1; $pageId <= ceil(self::LIMIT / self::NEWS_PER_PAGE); $pageId++) {

            $listSourcePath = self::ROOT_SRC . self::FEED_SRC . "?page=" . $pageId;

            $listSourceData = $curl->get("$listSourcePath");

            $crawler = new Crawler($listSourceData);
            $content = $crawler->filter(".view-content div.row div.grid_col");

            foreach ($content as $newsItem) {
                $node = new Crawler($newsItem);
                $newsPost = self::inflatePost($node);
                $posts[] = $newsPost;
                $counter++;
                if ($counter >= self::LIMIT) {
                    break 2;
                }
            }
        }

        foreach ($posts as $post) {
            self::inflatePostContent($post, $curl);
        }
        return $posts;
    }

    /**
     * Собираем исходные данные из ответа API
     *
     * @param Crawler $entityData
     *
     * @return NewsPost
     * @throws Exception
     */
    private static function inflatePost(Crawler $entityData): NewsPost
    {
        $imageUrl = null;
        $image = $entityData->filter("img");
        if ($image->count() != 0) {
            $imageUrl = $image->attr("src");
        }

        $title = $entityData->filter("h3")->text();
        $description = $entityData->filter(".views-field-field-anons")->text();

        $dateContainer = $entityData->filter("span.date-display-single");
        $createDate = new DateTime($dateContainer->attr("content"));
        $createDate->setTimezone(new DateTimeZone("UTC"));
        $original = self::ROOT_SRC . $entityData->filter("h3 a")->attr("href");

        if (empty($description)) {
            $description = self::EMPTY_DESCRIPTION;
        }
        return new NewsPost(
            self::class,
            $title,
            $description,
            $createDate->format("Y-m-d H:i:s"),
            $original,
            $imageUrl
        );

    }

    /**
     * @param NewsPost $post
     * @param          $curl
     */
    private static function inflatePostContent(NewsPost $post, $curl)
    {
        $url = $post->original;

        $pageData = $curl->get($url);

        $crawler = new Crawler($pageData);

        $content = $crawler->filter("div.main-container section");

        $header = $content->filter("h1");
        if ($header->count() !== 0) {
            self::addHeader($post, $header->text(), 1);
        }

        $anonse = $content->filter("div.field-name-field-anons");
        if ($anonse->count() !== 0) {
            self::addHeader($post, $anonse->text(), 4);
        }

        $image = $content->filter("div.field-name-field-image img");
        if ($image->count() !== 0) {
            $image->each(function (Crawler $imageNode) use ($post) {
                self::addImage($post, $imageNode->attr("src"));
            });
        }


        $body = $content->filter("div.field-name-body div.field-item")->children("p, blockquote");

        $body->each(function (Crawler $node) use ($post) {
            if (empty(trim($node->text(), "\xC2\xA0"))) {
                return;
            }

            if ($node->nodeName() === "blockquote") {
                self::addQuote($post, $node->text());
            }
            if ($node->nodeName() === "p" && $node->children("img")->count() != 0) {
                self::addImage($post, self::ROOT_SRC . $node->children("img")->attr("src"));
            } elseif ($node->nodeName() === "p") {
                self::addText($post, $node->text());

                if ($post->description === self::EMPTY_DESCRIPTION) {
                    $post->description = Helper::prepareString($node->text());
                }
            }

        });
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     * @param int      $level
     */
    protected static function addHeader(NewsPost $post, string $content, int $level): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_HEADER,
                $content,
                null,
                null,
                $level,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addImage(NewsPost $post, string $content): void
    {
        print_r($content);
        print_r("\n");
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_IMAGE,
                null,
                $content,
                null,
                1,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addText(NewsPost $post, string $content): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_TEXT,
                $content,
                null,
                null,
                null,
                null
            ));
    }

    /**
     * @param NewsPost $post
     * @param string   $content
     */
    protected static function addQuote(NewsPost $post, string $content): void
    {
        $post->addItem(
            new NewsPostItem(
                NewsPostItem::TYPE_QUOTE,
                $content,
                null,
                null,
                null,
                null
            ));
    }
}

