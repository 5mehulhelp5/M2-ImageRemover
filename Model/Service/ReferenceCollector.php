<?php
namespace Merlin\ImageRemover\Model\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Merlin\ImageRemover\Model\Service\Integration\AmastyMegaMenuExtractor;
use Merlin\ImageRemover\Model\Service\Integration\PageBuilderExtractor;
use Merlin\ImageRemover\Model\Service\Config\ConfigExtractor;

class ReferenceCollector
{
    /** @var ResourceConnection */
    private $resource;

    /** @var DbScanner */
    private $dbScanner;

    /** @var ModuleManager */
    private $moduleManager;

    /** @var AmastyMegaMenuExtractor */
    private $amastyExtractor;

    /** @var PageBuilderExtractor */
    private $pageBuilderExtractor;

    /** @var ConfigExtractor */
    private $configExtractor;

    public function __construct(
        ResourceConnection $resource,
        DbScanner $dbScanner,
        ModuleManager $moduleManager,
        AmastyMegaMenuExtractor $amastyExtractor,
        PageBuilderExtractor $pageBuilderExtractor,
        ConfigExtractor $configExtractor
    ) {
        $this->resource = $resource;
        $this->dbScanner = $dbScanner;
        $this->moduleManager = $moduleManager;
        $this->amastyExtractor = $amastyExtractor;
        $this->pageBuilderExtractor = $pageBuilderExtractor;
        $this->configExtractor = $configExtractor;
    }

    public function collectAll(bool $scanWholeDb = true, bool $intensiveDb = true): array
    {
        $refs = [];
        $refs = array_merge($refs, $this->collectProductImages());
        $refs = array_merge($refs, $this->collectCategoryImages());
        $refs = array_merge($refs, $this->collectCmsContent('page'));
        $refs = array_merge($refs, $this->collectCmsContent('block'));

        // Page Builder specific
        $refs = array_merge($refs, $this->pageBuilderExtractor->collect());

        if ($scanWholeDb) {
            $refs = array_merge($refs, $this->dbScanner->scan($intensiveDb));
        }

        if ($this->moduleManager->isEnabled('Amasty_MegaMenu')) {
            $refs = array_merge($refs, $this->amastyExtractor->collect());
        }

        // Store config (logos, favicons, email logos)
        $refs = array_merge($refs, $this->configExtractor->collect());

        // Normalize + dedupe
        $norm = [];
        foreach ($refs as $ref) {
            $r = $this->normalize($ref);
            if ($r) $norm[$r] = true;
        }
        return array_keys($norm);
    }

    private function normalize(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));

        $path = preg_replace('#^https?://[^/]+/#i', '', $path);
        $path = preg_replace('#^/?pub/media/#i', '', $path);
        $path = preg_replace('#^/?media/#i', '', $path);

        $path = ltrim($path, '/');

        if ($path === '' || substr($path, -1) === '/') {
            return null;
        }

        if (preg_match('#^catalog/product/cache/#i', $path)) {
            return null;
        }

        if (preg_match('#^(.htaccess|placeholder/|captcha/|tmp/)#i', $path)) {
            return null;
        }

        return $path;
    }

    private function collectProductImages(): array
    {
        $conn = $this->resource->getConnection();
        $tableVarchar = $this->resource->getTableName('catalog_product_entity_varchar');
        $tableAttr = $this->resource->getTableName('eav_attribute');
        $tableGallery = $this->resource->getTableName('catalog_product_entity_media_gallery');

        $attrCodes = ['image','small_image','thumbnail','swatch_image','base_image'];
        $in = implode(',', array_map(function($c){return $this->quote($c);}, $attrCodes));
        $sql = "SELECT v.value
                FROM {$tableVarchar} v
                INNER JOIN {$tableAttr} a ON a.attribute_id = v.attribute_id
                WHERE a.attribute_code IN ({$in})
                  AND v.value IS NOT NULL AND v.value != ''";
        $rows = $conn->fetchCol($sql);

        $gallery = $conn->fetchCol("SELECT value FROM {$tableGallery} WHERE value IS NOT NULL AND value != ''");

        return array_merge($rows, $gallery);
    }

    private function collectCategoryImages(): array
    {
        $conn = $this->resource->getConnection();
        $tableVarchar = $this->resource->getTableName('catalog_category_entity_varchar');
        $tableAttr = $this->resource->getTableName('eav_attribute');

        $attrCodes = ['image','thumbnail'];
        $in = implode(',', array_map(function($c){return $this->quote($c);}, $attrCodes));
        $sql = "SELECT v.value
                FROM {$tableVarchar} v
                INNER JOIN {$tableAttr} a ON a.attribute_id = v.attribute_id
                WHERE a.attribute_code IN ({$in})
                  AND v.value IS NOT NULL AND v.value != ''";
        return $conn->fetchCol($sql);
    }

    private function collectCmsContent(string $type): array
    {
        $conn = $this->resource->getConnection();
        $table = $type === 'page' ? $this->resource->getTableName('cms_page') : $this->resource->getTableName('cms_block');
        $col = 'content';

        $rows = $conn->fetchCol("SELECT {$col} FROM {$table} WHERE {$col} IS NOT NULL AND {$col} != ''");
        $results = [];

        foreach ($rows as $html) {
            $results = array_merge($results, $this->extractMediaFromContent($html));
        }
        return $results;
    }

    private function extractMediaFromContent(string $content): array
    {
        $refs = [];

        if (preg_match_all('#\\b(?:src|data-src|data-original)\\s*=\\s*["\\\']([^"\\\']+)["\\\']#i', $content, $m)) {
            foreach ($m[1] as $u) { $refs[] = $u; }
        }

        if (preg_match_all('#\\bsrcset\\s*=\\s*["\\\']([^"\\\']+)["\\\']#i', $content, $m2)) {
            foreach ($m2[1] as $list) {
                foreach (preg_split('/\\s*,\\s*/', $list) as $entry) {
                    $parts = preg_split('/\\s+/', trim($entry));
                    if (!empty($parts[0])) $refs[] = $parts[0];
                }
            }
        }

        if (preg_match_all('#url\\(([^)]+)\\)#i', $content, $m3)) {
            foreach ($m3[1] as $u) {
                $u = trim($u, " '\"");
                if ($u !== '') $refs[] = $u;
            }
        }

        if (preg_match_all('#\\{\\{\\s*media\\s+url\\s*=\\s*["\\\']([^"\\\']+)["\\\']\\s*\\}\\}#i', $content, $m4)) {
            foreach ($m4[1] as $u) { $refs[] = 'media/' . ltrim($u, '/'); }
        }

        return $refs;
    }

    private function quote(string $s): string
    {
        return "'" . addslashes($s) . "'";
    }
}
