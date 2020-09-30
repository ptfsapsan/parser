<?php

namespace app\components;

/**
 * TODO Временно, пока не работает настоящий Curl
 * @deprecated
 */
class Curl
{

    /**
     * Получить содержимое страницы
     * @param string $url
     * @return false|string
     */
    public function get(string $url)
    {
        return file_get_contents($url);
    }

}