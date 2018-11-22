<?php

namespace Raulr\GooglePlayScraper;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use Raulr\GooglePlayScraper\Exception\RequestException;
use Raulr\GooglePlayScraper\Exception\NotFoundException;

/**
 * @author Raul Rodriguez <raul@raulr.net>
 */
class Scraper
{
    const BASE_URL = 'https://play.google.com';

    protected $client;
    protected $delay = 1000;
    protected $lastRequestTime;
    protected $lang = 'en';
    protected $country = 'us';

    protected $anchor = [
        'last_updated' => [
            'en_US' => 'Updated',
            'zh_CN' => '更新日期',
            'zh_TW' => '更新日期',
            'ja_JP' => '更新日',
            'ko_KR' => '업데이트 날짜',
        ],
        'size' => [
            'en_US' => 'Size',
            'zh_CN' => '大小',
            'zh_TW' => '大小',
            'ja_JP' => 'サイズ',
            'ko_KR' => '크기',
        ],
        'downloads' => [
            'en_US' => 'Installs',
            'zh_CN' => '安装次数',
            'zh_TW' => '安裝次數',
            'ja_JP' => 'インストール',
            'ko_KR' => '설치 수',
        ],
        'version' => [
            'en_US' => 'Current Version',
            'zh_CN' => '当前版本',
            'zh_TW' => '目前版本',
            'ja_JP' => '現在のバージョン',
            'ko_KR' => '현재 버전',
        ],
        'supported_os' => [
            'en_US' => 'Requires Android',
            'zh_CN' => 'Android 系统版本要求',
            'zh_TW' => 'Android 最低版本需求',
            'ja_JP' => 'Android 要件',
            'ko_KR' => '필요한 Android 버전',
        ],
        'content_rating' => [
            'en_US' => 'Content Rating',
            'zh_CN' => '内容分级',
            'zh_TW' => '內容分級',
            'ja_JP' => 'コンテンツのレーティング',
            'ko_KR' => '콘텐츠 등급',
        ],
        'author' => [
            'en_US' => 'Offered By',
            'zh_CN' => '提供者',
            'zh_TW' => '提供者',
            'ja_JP' => '提供元',
            'ko_KR' => '제공',
        ],
        'author_link' => [
            'en_US' => 'Visit website',
            'zh_CN' => '访问网站',
            'zh_TW' => '造訪網站',
            'ja_JP' => 'ウェブサイトにアクセス',
            'ko_KR' => '웹사이트 방문',
        ],
        'whatsnew' => [
            'en_US' => "What's New",
            'zh_CN' => '新变化',
            'zh_TW' => '最新異動',
            'ja_JP' => '新機能',
            'ko_KR' => '변경사항',
        ],
    ];

    public function __construct(GuzzleClientInterface $guzzleClient = null)
    {
        $this->client = new Client();
        if ($guzzleClient) {
            $this->client->setClient($guzzleClient);
        }
    }

    public function setDelay($delay)
    {
        $this->delay = intval($delay);
    }

    public function getDelay()
    {
        return $this->delay;
    }

    public function setDefaultLang($lang)
    {
        $this->lang = $lang;
    }

    public function getDefaultLang()
    {
        return $this->lang;
    }

    public function setDefaultCountry($country)
    {
        $this->country = $country;
    }

    public function getDefaultCountry()
    {
        return $this->country;
    }

    public function getCategories()
    {
        $crawler = $this->request('apps', array(
            'hl' => 'en',
            'gl' => 'us',
        ));

        $collections = $crawler
            ->filter('.child-submenu-link')
            ->reduce(function ($node) {
                return strpos($node->attr('href'), '/store/apps') === 0;
            })
            ->each(function ($node) {
                $href = $node->attr('href');
                $hrefParts = explode('/', $href);
                $collection = end($hrefParts);
                $collection = preg_replace('/\?.*$/', '', $collection);

                return $collection;
            });
        $collections = array_unique($collections);

        return $collections;
    }

    public function getCollections()
    {
        return array(
            'topselling_free',
            'topselling_paid',
            'topselling_new_free',
            'topselling_new_paid',
            'topgrossing',
            'movers_shakers',
        );
    }

    public function getAppVersionOne($id, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;

        $params = array(
            'id' => $id,
            'hl' => $lang,
            'gl' => $country,
        );
        $crawler = $this->request(array('apps', 'details'), $params);

        $info = array();
        $info['id'] = $id;
        $info['url'] = $crawler->filter('[itemprop="url"]')->attr('content');
        $info['image'] = $this->getAbsoluteUrl($crawler->filter('[itemprop="image"]')->attr('src'));
        $info['title'] = $crawler->filter('[itemprop="name"] > div')->text();
        $info['author'] = $crawler->filter('[itemprop="author"] [itemprop="name"]')->text();
        $info['author_link'] = $this->getAbsoluteUrl($crawler->filter('[itemprop="author"] > [itemprop="url"]')->attr('content'));
        $info['categories'] = $crawler->filter('[itemprop="genre"]')->each(function ($node) {
            return $node->text();
        });
        $priceNode = $crawler->filter('[itemprop="offers"] > [itemprop="price"]');
        if ($priceNode->count()) {
            $price = $priceNode->attr('content');
        } else {
            $price = null;
        }
        $info['price'] = $price == '0' ? null : $price;
        $full_price_section = $crawler->filter('jsl > .full-price');
        $full_price = $full_price_section->count()?$full_price_section->text():null;
        if ($full_price) {
            $info['full_price'] = $full_price;
        } else {
            $info['full_price'] = null;
        }
        $info['screenshots'] = $crawler->filter('[itemprop="screenshot"]')->each(function ($node) {
            return $this->getAbsoluteUrl($node->filter('img')->attr('data-src'));
        });
        $desc = $this->cleanDescription($crawler->filter('[itemprop="description"] > div'));
        $info['description'] = $desc['text'];
        $info['description_html'] = $desc['html'];
        $ratingNode = $crawler->filter('[itemprop="aggregateRating"] > [itemprop="ratingValue"]');
        if ($ratingNode->count()) {
            $rating = floatval($ratingNode->attr('content'));
        } else {
            $rating = 0.0;
        }
        $info['rating'] = $rating;
        $votesNode = $crawler->filter('[itemprop="aggregateRating"] > [itemprop="ratingCount"]');
        if ($votesNode->count()) {
            $votes = intval($votesNode->attr('content'));
        } else {
            $votes = 0;
        }
        $info['votes'] = $votes;
        $info['last_updated'] = $this->safeGet($crawler, '[itemprop="datePublished"]');
        $info['size'] = $this->safeGet($crawler, '[itemprop="fileSize"]');
        $info['downloads'] = $this->safeGet($crawler, '[itemprop="numDownloads"]');
        $info['version'] = $this->safeGet($crawler, '[itemprop="softwareVersion"]');
        $info['supported_os'] = $this->safeGet($crawler, '[itemprop="operatingSystems"]');
        $info['content_rating'] = $this->safeGet($crawler, '[itemprop="contentRating"]');
        $whatsneNode = $crawler->filter('.recent-change');
        if ($whatsneNode->count()) {
            $info['whatsnew'] = implode("\n", $whatsneNode->each(function ($node) {
                return $node->text();
            }));
        } else {
            $info['whatsnew'] = null;
        }
        $videoNode = $crawler->filter('.details-trailer');
        if ($videoNode->count()) {
            $info['video_link'] = $this->getAbsoluteUrl($videoNode->filter('.play-action-container')->attr('data-video-url'));
            $info['video_image'] = $this->getAbsoluteUrl($videoNode->filter('.video-image')->attr('src'));
        } else {
            $info['video_link'] = null;
            $info['video_image'] = null;
        }

        return $info;
    }

    public function getApps($ids, $lang = null, $country = null)
    {
        $ids = (array) $ids;
        $apps = array();

        foreach ($ids as $id) {
            $apps[$id] = $this->getApp($id, $lang, $country);
        }

        return $apps;
    }

    public function getListChunk($collection, $category = null, $start = 0, $num = 60, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;

        if (!is_int($start)) {
            throw new \InvalidArgumentException('"start" must be an integer');
        }
        if ($start < 0 || $start > 500) {
            throw new \RangeException('"start" must be a number between 0 and 500');
        }
        if (!is_int($num)) {
            throw new \InvalidArgumentException('"num" must be an integer');
        }
        if ($num < 0 || $num > 120) {
            throw new \RangeException('"num" must be a number between 0 and 120');
        }

        $path = array('apps');
        if ($category) {
            array_push($path, 'category', $category);
        }
        array_push($path, 'collection', $collection);
        $params = array(
            'hl' => $lang,
            'gl' => $country,
            'start' => $start,
            'num' => $num,
        );
        $crawler = $this->request($path, $params);

        return $this->parseAppList($crawler);
    }

    public function getList($collection, $category = null, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;
        $start = 0;
        $num = 60;
        $apps = array();
        $appsChunk = array();

        do {
            $appsChunk = $this->getListChunk($collection, $category, $start, $num, $lang, $country);
            $apps = array_merge($apps, $appsChunk);
            $start += $num;
        } while (count($appsChunk) == $num && $start <= 500);

        return $apps;
    }

    public function getDetailListChunk($collection, $category = null, $start = 0, $num = 60, $lang = null, $country = null)
    {
        $apps = $this->getListChunk($collection, $category, $start, $num, $lang, $country);
        $ids = array_map(function ($app) {
            return $app['id'];
        }, $apps);

        return $this->getApps($ids);
    }

    public function getDetailList($collection, $category = null, $lang = null, $country = null)
    {
        $apps = $this->getList($collection, $category, $lang, $country);
        $ids = array_map(function ($app) {
            return $app['id'];
        }, $apps);

        return $this->getApps($ids);
    }

    public function getSearch($query, $price = 'all', $rating = 'all', $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;
        $priceValues = array(
            'all' => null,
            'free' => 1,
            'paid' => 2,
        );
        $ratingValues = array(
            'all' => null,
            '4+' => 1,
        );

        if (!is_string($query) || empty($query)) {
            throw new \InvalidArgumentException('"query" must be a non empty string');
        }

        if (array_key_exists($price, $priceValues)) {
            $price = $priceValues[$price];
        } else {
            throw new \InvalidArgumentException('"price" must contain one of the following values: '.implode(', ', array_keys($priceValues)));
        }

        if (array_key_exists($rating, $ratingValues)) {
            $rating = $ratingValues[$rating];
        } else {
            throw new \InvalidArgumentException('"rating" must contain one of the following values: '.implode(', ', array_keys($ratingValues)));
        }

        $apps = array();
        $path = array('search');
        $params = array(
            'q' => $query,
            'c' => 'apps',
            'hl' => $lang,
            'gl' => $country,
        );
        if ($price) {
            $params['price'] = $price;
        }
        if ($rating) {
            $params['rating'] = $rating;
        }

        do {
            $crawler = $this->request($path, $params);
            $apps = array_merge($apps, $this->parseAppList($crawler));
            unset($params['pagTok']);
            foreach ($crawler->filter('script') as $scriptNode) {
                if (preg_match('/\\\x22(GAE.+?)\\\x22/', $scriptNode->textContent, $matches)) {
                    $params['pagTok'] = preg_replace('/\\\\\\\u003d/', '=', $matches[1]);
                    break;
                }
            }
        } while (array_key_exists('pagTok', $params));

        return $apps;
    }

    public function getDetailSearch($query, $price = 'all', $rating = 'all', $lang = null, $country = null)
    {
        $apps = $this->getSearch($query, $price, $rating, $lang, $country);
        $ids = array_map(function ($app) {
            return $app['id'];
        }, $apps);

        return $this->getApps($ids);
    }

    protected function request($path, array $params = array())
    {
        // handle delay
        if (!empty($this->delay) && !empty($this->lastRequestTime)) {
            $currentTime = microtime(true);
            $delaySecs = $this->delay / 1000;
            $delay = max(0, $delaySecs - $currentTime + $this->lastRequestTime);
            usleep($delay * 1000000);
        }
        $this->lastRequestTime = microtime(true);

        if (is_array($path)) {
            $path = implode('/', $path);
        }
        $path = ltrim($path, '/');
        $path = rtrim('/store/'.$path, '/');
        $url = self::BASE_URL.$path;
        $query = http_build_query($params);
        if ($query) {
            $url .= '?'.$query;
        }
        $crawler = $this->client->request('GET', $url);
        $status_code = $this->client->getResponse()->getStatus();
        if ($status_code == 404) {
            throw new NotFoundException('Requested resource not found');
        } elseif ($status_code != 200) {
            throw new RequestException(sprintf('Request failed with "%d" status code', $status_code), $status_code);
        }

        return $crawler;
    }

    protected function getAbsoluteUrl($url)
    {
        $urlParts = parse_url($url);
        $baseParts = parse_url(self::BASE_URL);
        $absoluteParts = array_merge($baseParts, $urlParts);

        $absoluteUrl = $absoluteParts['scheme'].'://'.$absoluteParts['host'];
        if (isset($absoluteParts['path'])) {
            $absoluteUrl .= $absoluteParts['path'];
        } else {
            $absoluteUrl .= '/';
        }
        if (isset($absoluteParts['query'])) {
            $absoluteUrl .= '?'.$absoluteParts['query'];
        }
        if (isset($absoluteParts['fragment'])) {
            $absoluteUrl .= '#'.$absoluteParts['fragment'];
        }

        return $absoluteUrl;
    }

    protected function parseAppList(Crawler $crawler)
    {
        return $crawler->filter('.card')->each(function ($node) {
            $app = array();
            $app['id'] = $node->attr('data-docid');
            $app['url'] = self::BASE_URL.$node->filter('a')->attr('href');
            $app['title'] = $node->filter('a.title')->attr('title');
            $app['image'] = $this->getAbsoluteUrl($node->filter('img.cover-image')->attr('data-cover-large'));
            $app['author'] = $node->filter('a.subtitle')->attr('title');
            $ratingNode = $node->filter('.current-rating');
            if (!$ratingNode->count()) {
                $rating = 0.0;
            } elseif (preg_match('/\d+(\.\d+)?/', $node->filter('.current-rating')->attr('style'), $matches)) {
                $rating = floatval($matches[0]) * 0.05;
            } else {
                throw new \RuntimeException('Error parsing rating');
            }
            $app['rating'] = $rating;
            $priceNode = $node->filter('.display-price');
            if (!$priceNode->count()) {
                $price = null;
            } elseif (!preg_match('/\d/', $priceNode->text())) {
                $price = null;
            } else {
                $price = $priceNode->text();
            }
            $app['price'] = $price;

            return $app;
        });
    }

    protected function cleanDescription(Crawler $descriptionNode)
    {
        $descriptionNode->filter('a')->each(function ($node) {
            $domElement = $node->getNode(0);
            $href = $domElement->getAttribute('href');
            while (strpos($href, 'https://www.google.com/url?q=') === 0) {
                $parts = parse_url($href);
                parse_str($parts['query'], $query);
                $href = $query['q'];
            }
            $domElement->setAttribute('href', $href);
        });
        $html = $descriptionNode->html();
        $text = trim($this->convertHtmlToText($descriptionNode->getNode(0)));

        return array(
            'html' => $html,
            'text' => $text,
        );
    }

    protected function convertHtmlToText(\DOMNode $node)
    {
        if ($node instanceof \DOMText) {
            $text = preg_replace('/\s+/', ' ', $node->wholeText);
        } else {
            $text = '';

            foreach ($node->childNodes as $childNode) {
                $text .= $this->convertHtmlToText($childNode);
            }

            switch ($node->nodeName) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                case 'p':
                case 'ul':
                case 'div':
                    $text = "\n\n".$text."\n\n";
                    break;
                case 'li':
                    $text = '- '.$text."\n";
                    break;
                case 'br':
                    $text = $text."\n";
                    break;
            }

            $text = preg_replace('/\n{3,}/', "\n\n", $text);
        }

        return $text;
    }

    protected function safeGet($crawler, $filter)
    {
        $node = $crawler->filter($filter);
        if ($node->count()) {
            return trim($node->text());
        } else {
            return null;
        }
    }

    public function getApp($id, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;

        $params = array(
            'id' => $id,
            'hl' => $lang,
            'gl' => $country,
        );
        $crawler = $this->request(array('apps', 'details'), $params);

        $info = array();
        $info['id'] = $id;
        $info['url'] = $crawler->filter('[property="og:url"]')->attr('content');
        $info['image'] = $this->getAbsoluteUrl($crawler->filter('[itemprop="image"]')->attr('src'));
        $info['title'] = $crawler->filter('[itemprop="name"] > span')->text();

        $info['categories'] = $crawler->filter('[itemprop="genre"]')->each(function ($node) {
            return $node->text();
        });
        $priceNode = $crawler->filter('[itemprop="offers"] > [itemprop="price"]');
        if ($priceNode->count()) {
            $price = $priceNode->attr('content');
        } else {
            $price = null;
        }
        $info['price'] = $price == '0' ? null : $price;
        $full_price_section = $crawler->filter('jsl > .full-price');
        $full_price = $full_price_section->count()?$full_price_section->text():null;
        if ($full_price) {
            $info['full_price'] = $full_price;
        } else {
            $info['full_price'] = null;
        }

        $info['screenshots'] = $crawler->filterXPath('.//img[@class="T75of lxGQyd"][@itemprop="image"][@alt]')->each(function ($node) {
            $src = $node->attr('data-src') ?: $node->attr('src');
            return $this->getAbsoluteUrl($src);
        });
        $desc = $this->cleanDescription($crawler->filter('[itemprop="description"] > content > div'));
        $info['description'] = $desc['text'];
        $info['description_html'] = $desc['html'];

        $ratingNode = $crawler->filterXPath(".//div[@class='K9wGie']");
        if ($ratingNode->count() > 0){
            $rating = $ratingNode->filterXPath('.//div[@class="BHMmbe"]')->text();
            $info['rating'] = $rating;
            $votes = $ratingNode->filterXPath('.//span[@class="EymY4b"]/span[@aria-label]')->text();
            $info['votes'] = $votes;
        }else{
            $info['rating'] = null;
            $info['votes'] = null;
        }

        $more_info = $crawler->filterXPath('.//div[@class="hAyfc"]');
        $info['last_updated'] = $this->safeGetMoreInfo($more_info, $lang, 'last_updated');
        $info['size'] = $this->safeGetMoreInfo($more_info, $lang, 'size');
        $info['downloads'] = $this->safeGetMoreInfo($more_info, $lang, 'downloads');
        $info['version'] = $this->safeGetMoreInfo($more_info, $lang, 'version');
        $info['supported_os'] = $this->safeGetMoreInfo($more_info, $lang, 'supported_os');
        $info['content_rating'] = $this->safeGetMoreInfo($more_info, $lang, 'content_rating');
        $info['author'] = $this->safeGetMoreInfo($more_info, $lang, 'author');

        $author_link = $crawler->filterXPath('.//a[@class="hrTbp "]')->reduce(function($node) use ($lang){
            return str_contains($node->text(), $this->anchor['author_link'][$lang]);
        });
        $info['author_link'] = $author_link->count()>0 ? $author_link->attr('href') : null;

        $whatsneNode = $crawler->filterXPath('.//div[@class="W4P4ne "]')->reduce(function ($node) use ($lang){
            /* @var Crawler $node*/
            return str_contains($node->filterXPath('div/div[@class="wSaTQd"]')->text(), $this->anchor['whatsnew'][$lang]);
        })->filterXPath('div/div/div[@itemprop="description"]/content');
        if ($whatsneNode->count()) {
            $info['whatsnew'] = implode("\n", $whatsneNode->each(function ($node) {
                return $node->text();
            }));
        } else {
            $info['whatsnew'] = null;
        }

        $videoNode = $crawler->filterXPath('.//button[@data-trailer-url]');
        if ($videoNode->count()) {
            $info['video_link'] = $this->getAbsoluteUrl($videoNode->attr('data-trailer-url'));
            $info['video_image'] = $this->getAbsoluteUrl($videoNode->filterXPath('button/../../img')->attr('src'));
        } else {
            $info['video_link'] = null;
            $info['video_image'] = null;
        }

//        dd($info);

        return $info;
    }

    /**
     * @param Crawler $more_info
     * @param $lang
     * @param $field
     * @return null
     */
    protected function safeGetMoreInfo($more_info, $lang, $field){

        $fieldNode = $more_info->reduce(function ($node) use ($lang, $field){
            /* @var Crawler $node*/
            if ($node->filterXPath("div/div")->count() > 0){
                return str_contains($node->filterXPath("div/div")->text(), $this->anchor[$field][$lang]);
            }
        });

        if ($fieldNode->count() > 0){
            $field = $fieldNode->filterXPath('div/span/div/span[@class="htlgb"]')->getNode(0);
            $text = '';
            foreach ($field->childNodes as $childNode) {
                if ($childNode instanceof \DOMText){
                    $text .= $childNode->wholeText. ' ';
                }elseif ($childNode instanceof \DOMElement){
                    foreach ($childNode->childNodes as $_chileNode){
                        if ($_chileNode instanceof \DOMText) {
                            $text .= $_chileNode->wholeText. ' ';
                        }
                    }
                }
            }
            return trim($text);
        }
        return null;
    }
}
