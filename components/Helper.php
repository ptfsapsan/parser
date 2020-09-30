<?php

namespace app\components;

use linslin\yii2\curl\Curl;

class Helper
{
    /**
     * Prepare string to valid format
     * @param $string
     * @return string|null
     */
    public static function prepareString($string)
    {
        if ($string == '')
            return null;
        return strip_tags(trim($string));
    }

    /**
     * Prepare URL to valid format
     * @param $url
     * @return string
     */
    public static function prepareUrl($url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $link = preg_replace("(^https?://)", "", $url);
        $url = '';
        $url .= $scheme ? $scheme . '://' : 'https://';
        $url .= $link;
        return $url;
    }

    /**
     * Get curl object
     * @return Curl
     */
    public static function getCurl(): Curl
    {
        return new Curl();
    }

    /**
     * Обработать Url и превратить его в абсолютный,
     * если он таковым не является
     * @param string $url Урл, который обработать
     * @param string $baseUrl Бозовый урл страницы
     */
    public static function handleUrl(string &$url, string $baseUrl)
    {
        if (substr($baseUrl, strlen($baseUrl) - 1, 1) !== '/') {
            $baseUrl .= '/';
        }
        if ($url && substr($url, 0, 1) === '/') {
            /** Если есть фотка, то делаем абсолютный Url */
            $url = $baseUrl . substr($url, 1);
        }
    }

}

