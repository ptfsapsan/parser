<?php


namespace app\components\parser\news;


use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use Exception;
use PhpQuery\PhpQuery;

class OhtaPressParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    private const LINK = 'https://ohtapress.ru';

    public static function run(): array
    {
        $posts = self::getRss();
        $categoryLinks = self::getCategoryLinks();
        foreach ($categoryLinks as $categoryLink) {
            $content = file_get_contents($categoryLink);
            $parser = PhpQuery::newDocument($content);
            $detail = $parser->find('#main-content .archive-grid article div a');
            if (count($detail)) {
                foreach ($detail as $item) {
                    $title = $item->getAttribute('title');
                    $description = $title;
                    $original = $item->getAttribute('href');
                    $image = $item->getElementsByTagName('img')->item(0)->getAttribute('src');
                    $createDate = time();
                    $originalData = [];
                    if (!empty($original) && filter_var($original, FILTER_VALIDATE_URL)) {
                        $originalData = self::getOriginalData($original);
                        $createDate = sprintf('%s %s', trim($originalData['date']), date('H:i:s'));
                    }

                    try {
                        $post = new NewsPost(self::class, $title, $description, $createDate, $original, $image);
                    } catch (Exception $e) {
                        continue;
                    }
                    $posts[] = self::setPostData($post, $originalData);
                }
            }
        }

        return $posts;
    }

    private static function getRss(): array
    {
        $posts = [];
        $content = file_get_contents(self::LINK);
        $parser = PhpQuery::newDocument($content);
        $a = $parser->find('li.ticker-item a');
        if (count($a)) {
            foreach ($a as $item) {
                $text = $item->getAttribute('title');
                $original = $item->getAttribute('href');
                $createDate = date('d.m.Y H:i:s');
                $image = null;
                $originalData = [];
                if (!empty($original) && filter_var($original, FILTER_VALIDATE_URL)) {
                    $originalData = self::getOriginalData($original);
                    $createDate = sprintf('%s %s', trim($originalData['date']), date('H:i:s'));
                    $image = count($originalData['images']) ? current($originalData['images']) : null;
                }
                try {
                    $post = new NewsPost(self::class, $text, $text, $createDate, $original, $image);
                } catch (Exception $e) {
                    continue;
                }

                $posts[] = self::setPostData($post, $originalData);
            }
        }

        return $posts;
    }

    private static function getOriginalData(string $original): array
    {
        $itemText = '';
        $images = [];
        $content = file_get_contents($original);
        $parser = PhpQuery::newDocument($content);
        $texts = $parser->find('.entry-content p');
        if (count($texts)) {
            foreach ($texts as $text) {
                $itemText .= ' ' . $text->textContent;
            }
        }
        $mainImage = $parser->find('.entry-thumbnail img')->attr('src');
        if (!empty($mainImage)) {
            $images[] = $mainImage;
        }
        $postImages = $parser->find('p img');
        if (count($postImages)) {
            foreach ($postImages as $postImage) {
                $src = $postImage->getAttribute('src');
                if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
                    $images[] = $src;
                }
            }
        }

        return [
            'title' => $parser->find('header.entry-header .entry-title')->text(),
            'date' => $parser->find('.entry-meta .entry-meta-date')->text(),
            'text' => $itemText,
            'images' => $images,
        ];
    }

    private static function setPostData(NewsPost $post, array $data): NewsPost
    {
        if (!empty($data['text'])) {
            $post->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_TEXT,
                    trim($data['text']),
                )
            );
        }
        if (count($data['images'])) {
            foreach ($data['images'] as $itemImage) {
                $post->addItem(
                    new NewsPostItem(
                        NewsPostItem::TYPE_IMAGE,
                        null,
                        $itemImage,
                    )
                );
            }
        }

        return $post;
    }

    private static function getCategoryLinks(): array
    {
        $links = [];
        $content = file_get_contents(self::LINK);
        $parser = PhpQuery::newDocument($content);
        $items = $parser->find('nav.main-nav div ul li a');
        foreach ($items as $item) {
            $link = $item->getAttribute('href');
            if (strpos($link, '/category/')) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
