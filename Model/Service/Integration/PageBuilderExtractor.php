<?php
namespace Merlin\ImageRemover\Model\Service\Integration;

use Magento\Framework\App\ResourceConnection;

class PageBuilderExtractor
{
    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function collect(): array
    {
        $refs = [];
        $refs = array_merge($refs, $this->scanCms('page'));
        $refs = array_merge($refs, $this->scanCms('block'));
        $refs = array_merge($refs, $this->scanEavHtml('catalog_category', ['description']));
        $refs = array_merge($refs, $this->scanEavHtml('catalog_product', ['description','short_description']));
        return array_values(array_unique($refs));
    }

    private function scanCms(string $type): array
    {
        $conn = $this->resource->getConnection();
        $table = $type === 'page' ? $this->resource->getTableName('cms_page') : $this->resource->getTableName('cms_block');
        $rows = $conn->fetchCol("SELECT content FROM `{$table}` WHERE content LIKE '%data-content-type=%' OR content LIKE '%data-background-images=%'");
        $out = [];
        foreach ($rows as $html) {
            $out = array_merge($out, $this->extractFromHtml((string)$html));
        }
        return $out;
    }

    private function scanEavHtml(string $entityTypeCode, array $attrCodes): array
    {
        $conn = $this->resource->getConnection();
        $etype = $this->resource->getTableName('eav_entity_type');
        $eav = $this->resource->getTableName('eav_attribute');

        $entityTypeId = (int)$conn->fetchOne("SELECT entity_type_id FROM `{$etype}` WHERE entity_type_code = " . $conn->quote($entityTypeCode));
        if (!$entityTypeId) return [];

        $in = implode(',', array_map(function($c) use ($conn) { return $conn->quote($c); }, $attrCodes));
        $attrRows = $conn->fetchAll("SELECT attribute_id, backend_type FROM `{$eav}` WHERE entity_type_id = {$entityTypeId} AND attribute_code IN ({$in})");

        $refs = [];
        foreach ($attrRows as $ar) {
            $attrId = (int)$ar['attribute_id'];
            $bt = (string)$ar['backend_type'];
            $table = $this->resource->getTableName($entityTypeCode . '_entity_' . ($bt or 'text'));
            if (!$conn->isTableExists($table)) {
                $table = $this->resource->getTableName($entityTypeCode . '_entity_text');
            }
            $vals = $conn->fetchCol("SELECT value FROM `{$table}` WHERE attribute_id = {$attrId} AND value IS NOT NULL AND value != '' AND (value LIKE '%data-content-type=%' OR value LIKE '%data-background-images=%')");
            foreach ($vals as $html) {
                $refs = array_merge($refs, $this->extractFromHtml((string)$html));
            }
        }
        return $refs;
    }

    private function extractFromHtml(string $html): array
    {
        $refs = [];

        if (preg_match_all('#data-background-images\\s*=\\s*([\'"])(.*?)\\1#is', $html, $m)) {
            foreach ($m[2] as $jsonMaybe) {
                $decoded = html_entity_decode($jsonMaybe, ENT_QUOTES | ENT_HTML5);
                $this->collectStringsFromMixedJson($decoded, $refs);
            }
        }

        if (preg_match_all('#\\b(?:src|data-src|data-original)\\s*=\\s*["\\\']([^"\\\']+)["\\\']#i', $html, $m2)) {
            foreach ($m2[1] as $u) { $refs[] = $u; }
        }

        if (preg_match_all('#\\bsrcset\\s*=\\s*["\\\']([^"\\\']+)["\\\']#i', $html, $m3)) {
            foreach ($m3[1] as $list) {
                foreach (preg_split('/\\s*,\\s*/', $list) as $entry) {
                    $parts = preg_split('/\\s+/', trim($entry));
                    if (!empty($parts[0])) $refs[] = $parts[0];
                }
            }
        }

        if (preg_match_all('#background-image\\s*:\\s*url\\(([^)]+)\\)#i', $html, $m4)) {
            foreach ($m4[1] as $u) {
                $u = trim($u, " '\"");
                if ($u !== '') $refs[] = $u;
            }
        }

        if (preg_match_all('#\\{\\{\\s*media\\s+url\\s*=\\s*["\\\']([^"\\\']+)["\\\']\\s*\\}\\}#i', $html, $m5)) {
            foreach ($m5[1] as $u) { $refs[] = 'media/' . ltrim($u, '/'); }
        }

        # Also parse general urls inside quotes that point at /media/
        if (preg_match_all('#["\\\'](https?://[^"\\\']*/media/[^"\\\']+)["\\\']#i', $html, $m6)) {
            foreach ($m6[1] as $u) { $refs[] = $u; }
        }
        if (preg_match_all('#["\\\'](/?media/[^"\\\']+)["\\\']#i', $html, $m7)) {
            foreach ($m7[1] as $u) { $refs[] = $u; }
        }

        return array_values(array_unique($refs));
    }

    private function collectStringsFromMixedJson(string $maybeJson, array &$out): void
    {
        $maybeJson = trim($maybeJson);
        if ($maybeJson === '') return;
        if (!in_array($maybeJson[0], ['{','['])) return;
        $data = json_decode($maybeJson, true);
        if (!is_array($data)) return;

        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($data));
        foreach ($it as $v) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') { $out[] = $v; }
            }
        }
    }
}
