<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * Парсер новостей с сайта http://nowcrimea.ru/
 */
class NowCrimeaRuParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;
    const SITE_URL = 'http://nowcrimea.ru';


    public static function run(): array
    {
        $parser = new self();
        return $parser->parse();
    }

    public function parse(): array
    {
        $posts = [];

        $urlNews = self::SITE_URL . '/novosti-kryma/';
        $newsPage = $this->getPageContent($urlNews);
        $newsList = $this->getListNews($newsPage);
        foreach ($newsList as $newsUrl) {
            $contentPage = $this->getPageContent(self::SITE_URL . $newsUrl);
            $itemCrawler = new Crawler($contentPage);

            $title = $itemCrawler->filterXPath("//*[@class='detail__title']")->text();
            $date = $itemCrawler->filterXPath("//*[@class='detail__date']")->text();
            $description = $itemCrawler->filterXPath("//*[@class='detail__main-content']")->text();
            $image = self::SITE_URL . $itemCrawler->filterXPath("//*[@class='detail__img']")->attr('src');
            $url = self::SITE_URL . $newsUrl;

            $post = new NewsPost(
                self::class,
                $title,
                $description,
                $date,
                $url,
                $image
            );

            $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $title, null, null, 1, null));

            $newContentCrawler = (new Crawler($itemCrawler->filterXPath("//*[@class='detail__main-content']")->html()))->filterXPath('//body')->children();

            foreach ($newContentCrawler as $content) {
                foreach ($content->childNodes as $childNode) {
                    $nodeValue = trim($childNode->nodeValue);
                    if (in_array($childNode->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_HEADER,
                                $nodeValue,
                                null,
                                null,
                                (int) substr($childNode->nodeName, 1),
                                null
                            ));
                    } if ($childNode->nodeName == 'a' && strpos($childNode->getAttribute('href'), 'http') !== false) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_LINK,
                                $nodeValue,
                                null,
                                $childNode->getAttribute('href'),
                                null,
                                null
                            ));
                    } elseif ($childNode->nodeName == 'iframe') {
                        $src = $childNode->getAttribute('src');
                        if (strpos($src, 'youtube') !== false) {
                            $post->addItem(
                                new NewsPostItem(
                                    NewsPostItem::TYPE_VIDEO,
                                    $childNode->getAttribute('title'),
                                    null,
                                    null,
                                    null,
                                    basename(parse_url($src, PHP_URL_PATH))
                                ));
                        }
                    } elseif ($childNode->nodeName == 'ul') {
                        $ulCrawler = (new Crawler($newContentCrawler->filterXPath("//ul")->html()))->filterXPath('//body')->children();
                        foreach ($ulCrawler as $ulNode) {
                            foreach ($ulNode->childNodes as $liChildNode) {
                                if ($liChildNode->nodeName == 'a') {
                                    foreach ($liChildNode->childNodes as $imgNode) {
                                        if ($imgNode->nodeName == 'img') {
                                            $srcImg = strpos($imgNode->getAttribute('src'), 'http') === false ? self::SITE_URL . $imgNode->getAttribute('src') : $imgNode->getAttribute('src');
                                            $post->addItem(
                                                new NewsPostItem(
                                                    NewsPostItem::TYPE_IMAGE,
                                                    null,
                                                    $srcImg,
                                                ));
                                        }
                                    }
                                }
                            }
                        }
                    } elseif ($nodeValue) {
                        $post->addItem(
                            new NewsPostItem(
                                NewsPostItem::TYPE_TEXT,
                                $nodeValue,
                                null,
                                null,
                                null,
                                null
                            ));
                    }
                }
            }

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * Получение значения элемента страницы
     *
     * @param DOMXPath $xpath
     * @param string $search
     * @param string $attribute
     *
     * @return string
     */
    protected function getBlockValue(DOMXPath $xpath, string $search, string $attribute = ''): string
    {
        $value = '';
        $elements = $xpath->query($search);
        foreach ($elements as $item) {
            if ($attribute) {
                $value = $item->getAttribute($attribute);
            } else {
                $value = trim(str_replace(array("\r\n", "\r", "\n", "\t"), '',  $item->nodeValue));
            }
        }

        return $value;
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
        // главная новость
        $listMain = $crawler->filterXPath("//*[@class='item__info col-md-6']/a");
        foreach ($listMain as $item) {
            $records[] = $item->getAttribute("href");
        }

        $list = $crawler->filterXPath("//*[@class='item__info']/a");
        foreach ($list as $item) {
            $records[] = $item->getAttribute("href");
        }

        return $records;
    }

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

}