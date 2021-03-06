<?php namespace RainLab\Sitemap\Models;

use Url;
use Cms;
use Model;
use Event;
use Request;
use DOMDocument;
use Config;
use Cms\Classes\Theme;
use Cms\Classes\Page;
use RainLab\Sitemap\Classes\DefinitionItem;

/**
 * Definition Model
 */
class Definition extends Model
{
    /**
     * Maximum URLs allowed (Protocol limit is 50k)
     */
    const MAX_URLS = 50000;

    /**
     * Maximum generated URLs per type
     */
    const MAX_GENERATED = 10000;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'rainlab_sitemap_definitions';

    /**
     * @var array Guarded fields
     */
    protected $guarded = [];

    /**
     * @var array Fillable fields
     */
    protected $fillable = [];

    /**
     * @var integer A tally of URLs added to the sitemap
     */
    protected $urlCount = 0;

    /**
     * @var array List of attribute names which are json encoded and decoded from the database.
     */
    protected $jsonable = ['data'];

    /**
     * @var array The sitemap items.
     * Items are objects of the \RainLab\Sitemap\Classes\DefinitionItem class.
     */
    public $items;

    /**
     * @var DOMDocument element
     */
    protected $urlSet;

    /**
     * @var DOMDocument
     */
    protected $xmlObject;

    public function beforeSave()
    {
        $this->data = (array) $this->items;
    }

    public function afterFetch()
    {
        $this->items = DefinitionItem::initFromArray($this->data);
    }

    public function generateSitemap()
    {
        if (!$this->items) {
            return;
        }

        $currentUrl = Request::path();
        $theme = Theme::load($this->theme);

        $alternateLocales = [];
        if (class_exists('\RainLab\Translate\Classes\Translator')){
            $translator = \RainLab\Translate\Classes\Translator::instance();
            $defaultLocale = \RainLab\Translate\Models\Locale::getDefault()->code;
            $alternateLocales = array_keys(\RainLab\Translate\Models\Locale::listEnabled());
            $translator->setLocale($defaultLocale, false);
        }

        /*
         * Cycle each page and add its URL
         */
        foreach ($this->items as $item) {

            /*
             * Explicit URL
             */
            if ($item->type == 'url') {
                $this->addItemToSet($item, Url::to($item->url));
            }
            /*
             * Registered sitemap type
             */
            else {

                $apiResult = Event::fire('pages.menuitem.resolveItem', [$item->type, $item, $currentUrl, $theme]);

                if (!is_array($apiResult)) {
                    continue;
                }

                foreach ($apiResult as $itemInfo) {
                    if (!is_array($itemInfo)) {
                        continue;
                    }

                    /*
                     * Single item
                     */
                    if (isset($itemInfo['url'])) {
                        $url = $itemInfo['url'];
                        $alternateLocaleUrls = [];
                        if ($item->type == 'cms-page' && count($alternateLocales)) {
                            $page = Page::loadCached($theme, $item->reference);
                            if ($page->hasTranslatablePageUrl($defaultLocale)) {
                                $page->rewriteTranslatablePageUrl($defaultLocale);
                            }
                            $url = Cms::url($translator->getPathInLocale($page->url, $defaultLocale));
                            foreach ($alternateLocales as $locale) {
                                if ($page->hasTranslatablePageUrl($locale)) {
                                    $page->rewriteTranslatablePageUrl($locale);
                                }
                                $alternateLocaleUrls[$locale] = Cms::url($translator->getPathInLocale($page->url, $locale));
                            }
                        }
                        if (isset($itemInfo['alternate_locale_urls'])) {
                            $alternateLocaleUrls = $itemInfo['alternate_locale_urls'];
                        }
                        $this->addItemToSet($item, $url, array_get($itemInfo, 'mtime'), $alternateLocaleUrls);
                    }

                    /*
                     * Multiple items
                     */
                    if (isset($itemInfo['items'])) {

                        $parentItem = $item;

                        $itemIterator = function($items) use (&$itemIterator, $parentItem)
                        {
                            foreach ($items as $item) {
                                if (isset($item['url'])) {
                                    $alternateLocaleUrls = [];
                                    if (isset($item['alternate_locale_urls'])) {
                                        $alternateLocaleUrls = $item['alternate_locale_urls'];
                                    }
                                    $this->addItemToSet($parentItem, $item['url'], array_get($item, 'mtime'), $alternateLocaleUrls);
                                }

                                if (isset($item['items'])) {
                                    $itemIterator($item['items']);
                                }
                            }
                        };

                        $itemIterator($itemInfo['items']);
                    }
                }

            }

        }

        $urlSet = $this->makeUrlSet();
        $xml = $this->makeXmlObject();
        $xml->appendChild($urlSet);

        return $xml->saveXML();
    }

    protected function makeXmlObject()
    {
        if ($this->xmlObject !== null) {
            return $this->xmlObject;
        }

        $xml = new DOMDocument;
        $xml->encoding = 'UTF-8';

        return $this->xmlObject = $xml;
    }

    protected function makeUrlSet()
    {
        if ($this->urlSet !== null) {
            return $this->urlSet;
        }

        $xml = $this->makeXmlObject();
        $urlSet = $xml->createElement('urlset');
        $urlSet->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlSet->setAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $urlSet->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $urlSet->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd');

        return $this->urlSet = $urlSet;
    }

    protected function addItemToSet($item, $url, $mtime = null, $alternateLocaleUrls = [])
    {
        if ($mtime instanceof \DateTime) {
            $mtime = $mtime->getTimestamp();
        }

        $xml = $this->makeXmlObject();
        $urlSet = $this->makeUrlSet();
        $mtime = $mtime ? date('c', $mtime) : date('c');

        if ($alternateLocaleUrls) {
            foreach ($alternateLocaleUrls as $alternateLocaleUrl) {
                $urlElement = $this->makeUrlElement(
                    $xml,
                    $alternateLocaleUrl,
                    $mtime,
                    $item->changefreq,
                    $item->priority,
                    $alternateLocaleUrls
                );
                if ($urlElement) {
                    $urlSet->appendChild($urlElement);
                }
            }
        } else {
            $urlElement = $this->makeUrlElement(
                $xml,
                $url,
                $mtime,
                $item->changefreq,
                $item->priority
            );
            if ($urlElement) {
                $urlSet->appendChild($urlElement);
            }
        }

        return $urlSet;
    }

    protected function makeUrlElement($xml, $pageUrl, $lastModified, $frequency, $priority, $alternateLocaleUrls = [])
    {
        if ($this->urlCount >= self::MAX_URLS) {
            return false;
        }

        $this->urlCount++;

        $url = $xml->createElement('url');
        $url->appendChild($xml->createElement('loc', $pageUrl));
        $url->appendChild($xml->createElement('lastmod', $lastModified));
        $url->appendChild($xml->createElement('changefreq', $frequency));
        $url->appendChild($xml->createElement('priority', $priority));
        foreach ($alternateLocaleUrls as $locale => $locale_url) {
            $alternateUrl = $xml->createElement('xhtml:link');
            $alternateUrl->setAttribute('rel', 'alternate');
            $alternateUrl->setAttribute('hreflang', $locale);
            $alternateUrl->setAttribute('href', $locale_url);
            $url->appendChild($alternateUrl);
        }

        return $url;
    }
}
