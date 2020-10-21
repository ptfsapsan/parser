<?php

namespace app\components\helper\metallizzer;

use Exception;
use League\Uri\Uri;

class Url
{
    public static function encode(string $url = null, array $replace = [])
    {
        if (!is_string($url) or empty(Text::trim($url))) {
            return '';
        }

        if (false === $parts = parse_url($url)) {
            return null;
        }

        if (!empty($parts['path'])) {
            $parts['path'] = implode('/', array_map('rawurlencode', explode('/', $parts['path'])));

            if (count($replace) > 0) {
                $parts['path'] = strtr($parts['path'], $replace);
            }
        }

        $uri = static::build($parts);

        if (!$uri || $uri === '' || !filter_var($uri, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $uri;
    }

    public static function build(array $parts)
    {
        try {
            return (string) Uri::createFromComponents($parts);
        } catch (Exception $e) {
            return null;
        }
    }
}
