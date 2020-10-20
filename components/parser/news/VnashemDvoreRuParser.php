<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use yii\helpers\ArrayHelper;

/**
 * Парсер новостей с сайта http://vnashemdvore.ru/
 */
class VnashemDvoreRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://vnashemdvore.ru';

    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/news';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $url = self::SITE_URL . $newsUrl;
            $contentPage = $this->getPageContent($url);
            if (!$contentPage) {
                continue;
            }

            $itemCrawler = new Crawler($contentPage);
            $title = $itemCrawler->filterXPath("//h2[@class='title']")->text();

            $date = $this->getDate($itemCrawler->filterXPath("//div[@class='submitted']")->text());
            $content = $itemCrawler->filterXPath("//div[@class='clear-block']/*/div[@class='content']");
            $image = null;
            $imgSrc = $content->filterXPath("//div[@class='all-attached-images']//*//img");
            if ($imgSrc->getNode(0)) {
                $image = $this->getHeadUrl($imgSrc->attr('src'));
            }

            $description = $content->filterXPath('//p')->text();

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $newContentCrawler = (new Crawler($content->html()))->filterXPath('//body')->children();
            foreach ($newContentCrawler as $key=>$content) {
                if ($key < 1) {
                    continue;
                }
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = $this->clearText($childNode->nodeValue);
                    if (!$nodeValue) {
                        continue;
                    }
                    if ($childNode->nodeName == 'span') {
                        $spanCrawler = (new Crawler($childNode))->children();
                        if (!$spanCrawler->getNode(0)) {
                            $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);
                            continue;
                        }
                        foreach ($spanCrawler as $spanItem) {
                            foreach ($spanItem->childNodes as $spanValue) {
                                $spanNode = $this->clearText($spanValue->nodeValue);
                                if ($spanValue->nodeName == 'a' && strpos($spanValue->getAttribute('href'), 'http') !== false) {

                                    $this->addItemPost($post, NewsPostItem::TYPE_LINK, $spanNode, null, $spanValue->getAttribute('href'));

                                } elseif ($spanNode) {
                                    $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $spanNode);
                                }
                            }
                        }
                    } else {
                        $this->addItemPost($post, NewsPostItem::TYPE_TEXT, $nodeValue);
                    }
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * @param NewsPost $post
     * @param int $type
     * @param string|null $text
     * @param string|null $image
     * @param string|null $link
     * @param int|null $headerLevel
     * @param string|null $youtubeId
     */
    protected function addItemPost(NewsPost $post, int $type, string $text = null, string $image = null,
                                   string $link = null, int $headerLevel = null, string $youtubeId = null): void
    {
        $post->addItem(
            new NewsPostItem(
                $type,
                $text,
                $image,
                $link,
                $headerLevel,
                $youtubeId
            ));
    }

    /**
     *
     * @param string $date
     *
     * @return string
     */
    protected function getDate(string $date): string
    {
        $str = explode(',', $date);
        $newDate = ArrayHelper::getValue($str, 1, '');
        $newDate = str_ireplace(['-', '/'], ['','.'], $newDate);
        $newDate = new \DateTime($newDate);
        $newDate->setTimezone(new \DateTimeZone("UTC"));
        return $newDate->format("Y-m-d H:i:s");
    }

    /**
     *
     * @param string $url
     *
     * @return string
     */
    protected function getHeadUrl($url): string
    {
        $url = strpos($url, 'http') === false
            ? self::SITE_URL . $url
            : $url;
        return $this->encodeUrl($url);
    }

    /**
     * Русские буквы в ссылке
     *
     * @param string $url
     *
     * @return string
     */
    protected function encodeUrl(string $url): string
    {
        if (preg_match('/[А-Яа-яЁё\s]/iu', $url)) {
            preg_match_all('/[А-Яа-яЁё\s]/iu', $url, $result);
            $search = [];
            $replace = [];
            foreach ($result as $item) {
                foreach ($item as $key=>$value) {
                    $search[$key] = $value;
                    $replace[$key] = urlencode($value);
                }
            }
            $url = str_replace($search, $replace, $url);
        }
        return $url;
    }

    /**
     * Получение списка ссылок на страницы новостей
     *
     * @param string $page
     *
     * @return array
     */
    protected function getListNews(string $page): array
    {
        $records = [];

        $crawler = new Crawler($page);
        $list = $crawler->filterXPath("//div[@class='item-list']")->filterXPath("//span[@class='field-content']/a");
        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
        }

        return $records;
    }

    /**
     *
     * @param string $uri
     *
     * @return string
     * @throws RuntimeException|\Exception
     */
    private function getPageContent(string $uri): string
    {
        $curl = Helper::getCurl();

        $result = $curl->get($uri);
        $responseInfo = $curl->getInfo();

        $httpCode = $responseInfo['http_code'] ?? null;

        if ($httpCode >= 200 && $httpCode < 400) {
            return $result;
        }

        throw new RuntimeException("Не удалось скачать страницу {$uri}, код ответа {$httpCode}");
    }

    /**
     *
     * @param string $text
     *
     * @return string
     */
    protected function clearText(string $text): string
    {
        $text = htmlentities($text);
        $text = str_replace("&nbsp;",' ',$text);
        $text = html_entity_decode($text);
        if (strpos($text, 'Добавить комментарий') !== false) {
            $text = '';
        }
        return trim($text);
    }
}