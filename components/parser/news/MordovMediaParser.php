<?php

namespace app\components\parser\news;

use app\components\parser\ParserInterface;
use app\components\parser\NewsPostItem;
use app\components\parser\NewsPost;
use lanfix\parser\src\Element;
use yii\base\ErrorException;
use lanfix\parser\Parser;

class MordovMediaParser implements ParserInterface
{

    const USER_ID = 2;
    const FEED_ID = 2;

    /**
     * Корневой урл
     */
    const SRC = 'https://www.mordovmedia.ru/';

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        /**
         * Вырубаем нотисы
         */
        error_reporting(E_ALL & ~E_NOTICE);
        /**
         * ### Стадия #1 - Получение ссылок на статьи
         * Получаем содержимое страницы новостей
         */
        $newsPageParser = new Parser(static::SRC . 'news/');
        $newsPageBody = $newsPageParser->document->getBody();
        $newsContainer = $newsPageBody->findOne('.news-list');
        /** Если проблемы с получением, то выбрасываем ошибку */
        if (!$newsContainer) {
            throw new ErrorException('Не удалось получить список новостей');
        }
        /** Ищем новостные плашки на странице */
        $newsCardsOnPage = $newsContainer->find('.news_item');
        /** Получаем данные по конкретным статьям */
        $newsPagesParams = array_map(function (Element $card) {
            $newLinkNode = $card->findOne('.news-title, .news_title');
            foreach ($card->find('meta') as $meta) {
                if ($meta->getAttribute('itemprop') === 'datePublished') {
                    $newTime = $meta->getAttribute('content') ?: '';
                    $newTime = date('Y-m-d H:i:s', strtotime($newTime));
                    break;
                }
            }
            return [
                'link' => ($newLinkNode->getAttribute('href') ?: ''),
                'time' => $newTime ?? '',
            ];
        }, $newsCardsOnPage);
        /** Удаляем пустые ссылки */
        $newsPagesParamsFiltered = array_filter($newsPagesParams, function ($params) {
            return (bool)($params['link'] ?? false);
        });
        /**
         * ### Стадия #2 - Получение данных со страниц статей
         * Получаем страницы новостей по отдельности
         */
        foreach ($newsPagesParamsFiltered as $pageParams) {
            /**
             * Создание парсера и получение данных со страницы
             * конкретной новости
             */
            $newPageParser = new Parser($pageParams['link']);
            $newPageBody = $newPageParser->document->getBody();
            $newPageHead = $newPageParser->document->getHead();
            /**
             * Пропускаем статью если нема контента
             */
            if (!$newContain = $newPageBody->findOne('.news_detail')) {
                continue;
            }
            /**
             * Дергаем заголовок
             */
            $titleHtmlNode = $newContain->findOne('#news-title');
            if (!$header = ($titleHtmlNode ? $titleHtmlNode->asText() : '')) {
                continue;
            }
            /**
             * Получаем описание
             */
            $description = '';
            foreach ($newPageHead->find('meta') ?? [] as $meta) {
                if ($meta->getAttribute('name') === 'description') {
                    $description = $meta->getAttribute('content') ?: '';
                    break;
                }
            }
            /**
             * Получаем URL до главной фотки
             */
            $photoUrl = '';
            if ($photoHtmlNode = $newContain->findOne('.img-cont img')) {
                $photoUrl = trim($photoHtmlNode->getAttribute('src') ?: '');
            }
            if ($photoUrl && substr($photoUrl, 0, 1) === '/') {
                /** Если есть фотка, то делаем абсолютный Url */
                $photoUrl = static::SRC . substr($photoUrl, 1);
            }
            /**
             * Получаем текстовое содержимое
             */
            $textOfCurrentNew = $newContain->findOne('.news-text');
            $textBlocks = array_map(function (Element $textNode) {
                return $textNode->asText();
            }, $textOfCurrentNew->find('p'));
            $realTextBlocks = array_filter($textBlocks, function ($paragraph) {
                return !preg_match('/использовании материала гиперссылка обязательна/ui', $paragraph);
            });
            /**
             * Генерируем новостной пост, который в конечном
             * счете и является результатом работы кода
             */
            $post = new NewsPost(static::class, $header, $description,
                $pageParams['time'], $pageParams['link'], $photoUrl);
            /** Накидываем текстовые блоки в статью */
            foreach ($realTextBlocks as $realTextBlock) {
                $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $realTextBlock,
                    null, null, null, null));
            }
            /** Докидываем пост в список */
            $posts[] = $post;
        }
        return $posts ?? [];
    }


}